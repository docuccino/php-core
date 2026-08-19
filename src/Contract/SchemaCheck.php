<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator as OpisValidator;
use stdClass;

/**
 * Validates one payload against one schema inside the document, and maps every failure back to the
 * document node that produced it.
 *
 * The document's `components/schemas` travel with the subject as `$defs`, with `#/components/schemas/X`
 * references rewritten to `#/$defs/X`, so a `$ref` resolves without inlining anything — recursive model
 * schemas stay recursive. Opis reports the schema path it failed on, so the mapping back is exact even
 * inside a `oneOf` branch, which a hand walk of the instance pointer could only guess at.
 *
 * @internal
 */
final class SchemaCheck
{
    /** Enough failures to see the shape of the problem, few enough to read. */
    private const int MAX_ERRORS = 25;

    private const string DIALECT = 'https://json-schema.org/draft/2020-12/schema';

    private const string COMPONENT_PREFIX = '#/components/schemas/';

    public function __construct(private readonly ContractIndex $index) {}

    /**
     * @param  list<string>  $schemaSegments  document pointer segments addressing the schema to check
     * @param  string  $location  what to call the payload in a message (`the response body`, `?page`)
     * @return list<Violation>
     */
    public function check(mixed $data, array $schemaSegments, string $location): array
    {
        $subject = $this->subject($schemaSegments);

        if ($subject === null) {
            return [];
        }

        $validator = new OpisValidator;
        $validator->setMaxErrors(self::MAX_ERRORS);

        $result = $validator->validate($data, $this->root($subject));
        $error = $result->error();

        if ($error === null) {
            return [];
        }

        return $this->violations($error, $schemaSegments, $location);
    }

    /**
     * The schema at those pointer segments, from the object graph. Booleans are schemas too.
     *
     * @param  list<string>  $segments
     */
    private function subject(array $segments): object|bool|null
    {
        $node = Pointer::readGraph($this->index->graph(), $segments);

        return is_object($node) || is_bool($node) ? $node : null;
    }

    /**
     * The subject with the document's component schemas alongside it as `$defs`. A subject that already
     * declares `$defs` keeps its own entries — its names shadow the component names, which is what a
     * lexical `$defs` would do anyway.
     */
    private function root(object|bool $subject): object|bool
    {
        if (is_bool($subject)) {
            return $subject;
        }

        $root = new stdClass;
        $root->{'$schema'} = self::DIALECT;

        // Rewrite the subject as a WHOLE object: a subject that is itself `{"$ref": "#/components/…"}`
        // carries the reference on its own top-level member, which walking its values would step over.
        $rewritten = $this->rewrite($subject);

        if (is_object($rewritten)) {
            foreach (get_object_vars($rewritten) as $member => $value) {
                $root->{$member} = $value;
            }
        }

        $defs = $this->componentDefs();

        if (isset($root->{'$defs'}) && is_object($root->{'$defs'})) {
            foreach (get_object_vars($root->{'$defs'}) as $name => $value) {
                $defs->{$name} = $value;
            }
        }

        if (get_object_vars($defs) !== []) {
            $root->{'$defs'} = $defs;
        }

        return $root;
    }

    private function componentDefs(): stdClass
    {
        $graph = $this->index->graph();
        $components = $graph->components ?? null;
        $schemas = is_object($components) ? ($components->schemas ?? null) : null;

        $defs = new stdClass;

        if (! is_object($schemas)) {
            return $defs;
        }

        foreach (get_object_vars($schemas) as $name => $schema) {
            $defs->{$name} = $this->rewrite($schema);
        }

        return $defs;
    }

    /** A deep copy with every `#/components/schemas/X` reference re-pointed at `#/$defs/X`. */
    private function rewrite(mixed $node): mixed
    {
        if (is_array($node)) {
            return array_map($this->rewrite(...), $node);
        }

        if (! is_object($node)) {
            return $node;
        }

        $copy = new stdClass;

        foreach (get_object_vars($node) as $member => $value) {
            $copy->{$member} = $member === '$ref' && is_string($value) && str_starts_with($value, self::COMPONENT_PREFIX)
                ? '#/$defs/'.substr($value, strlen(self::COMPONENT_PREFIX))
                : $this->rewrite($value);
        }

        return $copy;
    }

    /**
     * @param  list<string>  $schemaSegments
     * @return list<Violation>
     */
    private function violations(ValidationError $error, array $schemaSegments, string $location): array
    {
        $formatter = new ErrorFormatter;
        $document = $this->index->document();

        $violations = [];
        foreach ($this->leaves($error) as $leaf) {
            $pointer = $this->instancePointer($leaf);
            $message = $formatter->formatErrorMessage($leaf);
            $segments = $this->documentSegments($leaf, $schemaSegments);

            $violations[$pointer."\0".$message] = new Violation(
                location: $location,
                pointer: $pointer,
                message: $message,
                schemaPointer: Pointer::of($segments),
                provenance: ProvenanceTrail::at($document, $segments),
            );
        }

        return array_values($violations);
    }

    /**
     * The failing leaves of the error tree: an inner node only restates that its children failed.
     *
     * @return list<ValidationError>
     */
    private function leaves(ValidationError $error): array
    {
        $sub = $error->subErrors();

        if ($sub === []) {
            return [$error];
        }

        $leaves = [];
        foreach ($sub as $child) {
            if (! $child instanceof ValidationError) {
                continue;
            }

            foreach ($this->leaves($child) as $leaf) {
                $leaves[] = $leaf;
            }
        }

        return $leaves;
    }

    private function instancePointer(ValidationError $error): string
    {
        return Pointer::of(self::steps($error->data()->fullPath()));
    }

    /**
     * Where in the DOCUMENT the failing schema node lives. A path under `$defs` is a component schema
     * we moved there; anything else is inside the subject.
     *
     * @param  list<string>  $schemaSegments
     * @return list<string>
     */
    private function documentSegments(ValidationError $error, array $schemaSegments): array
    {
        $path = self::steps($error->schema()->info()->path());

        if (($path[0] ?? null) === '$defs' && isset($path[1])) {
            return ['components', 'schemas', ...array_slice($path, 1)];
        }

        return [...$schemaSegments, ...$path];
    }

    /**
     * Opis reports paths as a mix of member names and array indexes; a pointer wants strings.
     *
     * @param  array<array-key, mixed>  $path
     * @return list<string>
     */
    private static function steps(array $path): array
    {
        $steps = [];
        foreach ($path as $step) {
            $steps[] = is_scalar($step) ? (string) $step : '';
        }

        return $steps;
    }
}
