<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

use Closure;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\JsonPointer;
use Opis\JsonSchema\Parsers\SchemaParser;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Uri;
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
 * The validator walks everything it is handed before it validates anything, and parses what it reaches
 * afresh for every root, so handing each check the whole of `components/schemas` made it cost the document
 * rather than the schema. So where a subject names components only by plain pointer, every one of them
 * there, it reaches each through a document of that component's own which every check shares, walked and
 * parsed the first time a check reaches it ({@see shared()}). Otherwise its root carries the components it
 * can reach as `$defs` of its own ({@see ReachableDefs}): a dangling reference fails in the words it
 * always did, a subject's own `$defs` still shadow, and where it names a schema some way a pointer cannot
 * say, every component travels.
 *
 * Every walk here reads a member by where it stands, never by its name ({@see SchemaMembers}): a property
 * called `default` holds a schema like any other, and a `$ref` inside a `const` is a value, never rewritten.
 * Subjects arrive from wherever the caller had them — an artifact somebody hand-edited, a draft nothing
 * canonicalised — so an empty array standing where an object belongs is repaired on the way in
 * ({@see SchemaMembers::emptyIsObject()}) rather than handed to a validator that would throw over it.
 *
 * @internal
 */
final class SchemaCheck
{
    /** Enough failures to see the shape of the problem, few enough to read. */
    private const int MAX_ERRORS = 25;

    private const string DIALECT = 'https://json-schema.org/draft/2020-12/schema';

    private const string COMPONENT_PREFIX = '#/components/schemas/';

    /**
     * Where each component's shared document lives ({@see sharedDocument()}), numbered by the component's
     * place in the document; no generated id takes this form. Nothing reads one back: a pointer that would
     * fail through a shared document is left as written ({@see intoShared()}), so no refusal names one.
     */
    private const string SHARED = 'schema:///components/';

    /** @var list<array-key>|null each component's name, by its place in the document */
    private ?array $names = null;

    /** @var array<array-key, int> each component's place in the document, by name */
    private array $places = [];

    /**
     * Each component schema rewritten for `$defs` ({@see rewrite()}), the first time a check reaches it
     * rather than all of them for every checker: a contract suite builds a checker per assertion, and one
     * that read the whole document cost every assertion the document. Shared by every check: the
     * validator writes to the instance it validates, never to a schema it is handed.
     *
     * @var array<array-key, mixed>
     */
    private array $defs = [];

    /** @var array<array-key, list<string>|null> each component's own {@see ReachableDefs::of()}, as it is reached */
    private array $reaches = [];

    /** @var array<array-key, list<string>|null> each component's own {@see ReachableDefs::within()}, as it is reached */
    private array $reachesWithin = [];

    /**
     * Whether each component a subject names can be reached through the shared documents, every one it
     * reaches in turn included — a fact about the component, so asked once rather than once per check.
     *
     * @var array<array-key, bool>
     */
    private array $sharableDefs = [];

    /** @var array<int, stdClass> each shared document built so far, by the place of its component */
    private array $documents = [];

    /** The validator every check reaching the shared components runs on, which parses each of them once. */
    private ?OpisValidator $shared = null;

    /**
     * One parser for every check: it holds configuration and nothing a document leaves behind, while
     * building one is most of what a fresh validator costs. Each check still gets a loader of its own.
     */
    private ?SchemaParser $parser = null;

    /**
     * @param  (Closure(): OpisValidator)|null  $validators  where each check's validator comes from; overridable
     *                                                       so what a check hands it can be observed
     */
    public function __construct(
        private readonly ContractIndex $index,
        private readonly ?Closure $validators = null,
    ) {}

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

        [$validator, $root] = $this->prepared($subject);
        $validator->setMaxErrors(self::MAX_ERRORS);

        $result = $validator->validate($data, $root);
        $error = $result->error();

        if ($error === null) {
            return [];
        }

        return $this->violations($error, $schemaSegments, $location);
    }

    /**
     * Whether there is a schema at those segments to check anything against at all.
     *
     * {@see check()} answering with no violations means "nothing disagreed", which is also what it
     * answers where the document put no schema there — and those are not the same claim. A caller that
     * counts what it PROVED has to be able to tell them apart.
     *
     * @param  list<string>  $segments
     */
    public function has(array $segments): bool
    {
        return $this->subject($segments) !== null;
    }

    /**
     * The schema at those pointer segments, from the object graph. Booleans are schemas too. Anything
     * else — no node there, or an empty array standing for `{}` — means every value passes, which is
     * the answer `null` already produces.
     *
     * @param  list<string>  $segments
     */
    private function subject(array $segments): object|bool|null
    {
        $node = Pointer::readGraph($this->index->graph(), $segments);

        return is_object($node) || is_bool($node) ? $node : null;
    }

    /**
     * The validator a check runs on and the root it hands it: the shared components where the subject can
     * reach them there ({@see sharable()}), else a root of its own ({@see root()}) on a fresh validator.
     *
     * @return array{OpisValidator, object|bool}
     */
    private function prepared(object|bool $subject): array
    {
        if (is_bool($subject)) {
            return [$this->fresh(), $subject];
        }

        // Rewrite the subject as a WHOLE object: a subject that is itself `{"$ref": "#/components/…"}`
        // carries the reference on its own top-level member, which walking its values would step over.
        $rewritten = $this->rewrite($subject, SchemaMembers::SCHEMA);

        if (is_object($rewritten) && $this->sharable($rewritten)) {
            $root = new stdClass;
            $root->{'$schema'} = self::DIALECT;

            $pointed = $this->pointedAtShared($rewritten, SchemaMembers::SCHEMA);
            foreach (is_object($pointed) ? get_object_vars($pointed) : [] as $member => $value) {
                $root->{$member} = $value;
            }

            return [$this->shared(), $root];
        }

        return [$this->fresh(), $this->root($rewritten)];
    }

    /**
     * Whether a subject reaches components only through pointers the shared documents answer alike: no
     * `$defs` of its own to shadow them, nothing named some other way, every name it reaches there, and none
     * of those components pointing into the root outside `$defs`, which in a shared document is not the
     * subject. All but the first are facts about the components it names, so each is asked once.
     */
    private function sharable(object $subject): bool
    {
        $names = property_exists($subject, '$defs') ? null : ReachableDefs::of($subject);

        foreach ($names ?? [] as $name) {
            if (! ($this->sharableDefs[$name] ??= ReachableDefs::closure([$name], $this->reachesWithin(...), complete: true) !== null)) {
                return false;
            }
        }

        return $names !== null;
    }

    /**
     * The one validator every sharable check runs on, where each component a sharable subject can reach is
     * a document of its own ({@see sharedDocument()}), built, walked and parsed the first time a check
     * reaches it. So a check costs what it reaches, and a component no check reaches is never walked —
     * which matters beyond cost, since the validator registers every id and anchor it walks past, instance
     * data included, and one claimed twice aborts the walk halfway. Its loader keeps each subject it is
     * handed alive, so no generated id is ever reused for another.
     */
    private function shared(): OpisValidator
    {
        if ($this->shared !== null) {
            return $this->shared;
        }

        $validator = $this->fresh();
        $validator->resolver()?->registerProtocol('schema', $this->sharedDocument(...));

        return $this->shared = $validator;
    }

    /**
     * The shared document at `$uri`: one component under `$defs`, as it sits in a root of its own, so a
     * failure inside it maps back as one there does ({@see documentSegments()}). Null for any other id, and
     * for a component whose own reading ({@see ReachableDefs::within()}) no sharable subject survives.
     */
    private function sharedDocument(Uri $uri): ?stdClass
    {
        if (preg_match('~^'.preg_quote(self::SHARED, '~').'(\d+)\.json#$~', (string) $uri, $match) !== 1) {
            return null;
        }

        $place = (int) $match[1];
        $name = $this->names()[$place] ?? null;

        if ($name === null || ! is_array($this->reachesWithin((string) $name))) {
            return null;
        }

        if (! isset($this->documents[$place])) {
            $defs = new stdClass;
            $defs->{(string) $name} = $this->pointedAtShared($this->def($name), SchemaMembers::SCHEMA);

            $document = new stdClass;
            $document->{'$schema'} = self::DIALECT;
            $document->{'$defs'} = $defs;

            $this->documents[$place] = $document;
        }

        return $this->documents[$place];
    }

    /** A validator with a loader of its own, on the one parser every check shares. */
    private function fresh(): OpisValidator
    {
        return $this->validators === null
            ? new OpisValidator(new SchemaLoader($this->parser ??= new SchemaParser, new SchemaResolver, true))
            : ($this->validators)();
    }

    /**
     * `$node`, read as `$in`, with every pointer into `$defs` made absolute into the shared document of the
     * component it names, read as {@see ReachableDefs} reads it. Instance data is handed back as the
     * rewrite left it: a `$ref` there is a value the instance is compared against, not a reference.
     */
    private function pointedAtShared(mixed $node, string $in): mixed
    {
        if ($in === SchemaMembers::DATA) {
            return $node;
        }

        if (is_array($node)) {
            return array_map(fn (mixed $item): mixed => $this->pointedAtShared($item, SchemaMembers::item($in)), $node);
        }

        if (! is_object($node)) {
            return $node;
        }

        $copy = new stdClass;

        foreach (get_object_vars($node) as $member => $value) {
            $member = (string) $member;

            $copy->{$member} = $this->intoShared($member, $value, $in)
                ?? $this->pointedAtShared($value, SchemaMembers::member($member, $in));
        }

        return $copy;
    }

    /**
     * The member `$member` of a value read as `$in`, made absolute into the shared document of the component
     * it names — or null where it is no reference into `$defs`, or where it points at a place the component
     * lacks: left as written, that fails in the words it always did rather than naming a document of ours.
     */
    private function intoShared(string $member, mixed $value, string $in): ?string
    {
        if (! SchemaMembers::isReference($member, $in) || ! is_string($value)) {
            return null;
        }

        $name = ReachableDefs::defNamed($value);
        $place = $name === null ? null : $this->placeOf($name);

        if ($name === null || $place === null) {
            return null;
        }

        // Where the validator itself looks: the pointer from a root holding the component as a `$def`.
        $root = new stdClass;
        $root->{'$defs'} = new stdClass;
        $root->{'$defs'}->{$name} = $this->def($name);
        $target = JsonPointer::parse(substr($value, 1))?->data($root);

        return is_object($target) || is_bool($target) ? self::SHARED.$place.'.json'.$value : null;
    }

    /**
     * The rewritten subject with the component schemas it can reach alongside it as `$defs`
     * ({@see componentDefs()}). A subject that already declares `$defs` keeps its own entries — its names
     * shadow the component names, which is what a lexical `$defs` would do anyway.
     */
    private function root(mixed $rewritten): object
    {
        $root = new stdClass;
        $root->{'$schema'} = self::DIALECT;

        if (is_object($rewritten)) {
            foreach (get_object_vars($rewritten) as $member => $value) {
                $root->{$member} = $value;
            }
        }

        $defs = $this->componentDefs($rewritten);

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

    /** The component schemas `$subject` can reach, in document order — every one of them where that cannot be read. */
    private function componentDefs(mixed $subject): stdClass
    {
        $reachable = ReachableDefs::closure(ReachableDefs::of($subject), $this->reaches(...));
        $defs = new stdClass;

        foreach ($this->names() as $name) {
            if ($reachable === null || isset($reachable[$name])) {
                $defs->{(string) $name} = $this->def($name);
            }
        }

        return $defs;
    }

    /**
     * Component `$name`'s own {@see ReachableDefs::of()}, read the first time a check reaches it — or false
     * where the document has no such component.
     *
     * @return list<string>|false|null
     */
    private function reaches(string $name): array|false|null
    {
        if ($this->placeOf($name) === null) {
            return false;
        }

        if (! array_key_exists($name, $this->reaches)) {
            $this->reaches[$name] = ReachableDefs::of($this->def($name));
        }

        return $this->reaches[$name];
    }

    /**
     * As {@see reaches()}, read as {@see ReachableDefs::within()} reads a component stored among others.
     *
     * @return list<string>|false|null
     */
    private function reachesWithin(string $name): array|false|null
    {
        if ($this->placeOf($name) === null) {
            return false;
        }

        if (! array_key_exists($name, $this->reachesWithin)) {
            $this->reachesWithin[$name] = ReachableDefs::within($this->def($name));
        }

        return $this->reachesWithin[$name];
    }

    /** Component `$name` rewritten for `$defs` ({@see rewrite()}), the first time anything asks for it. */
    private function def(int|string $name): mixed
    {
        if (! array_key_exists($name, $this->defs)) {
            $this->defs[$name] = $this->rewritten($this->schemas()->{(string) $name} ?? null, (string) $name, SchemaMembers::NAMES);
        }

        return $this->defs[$name];
    }

    /** Where component `$name` stands among them, or null where the document has none by that name. */
    private function placeOf(string $name): ?int
    {
        $this->names();

        return $this->places[$name] ?? null;
    }

    /**
     * Every component's name, in document order.
     *
     * @return list<array-key>
     */
    private function names(): array
    {
        if ($this->names === null) {
            $this->names = array_keys(get_object_vars($this->schemas()));
            $this->places = array_flip($this->names);
        }

        return $this->names;
    }

    /** The document's `components/schemas`, as the graph holds it. */
    private function schemas(): object
    {
        $components = $this->index->graph()->components ?? null;
        $schemas = is_object($components) ? ($components->schemas ?? null) : null;

        return is_object($schemas) ? $schemas : new stdClass;
    }

    /**
     * A deep copy of `$node`, read as `$in`, with every `#/components/schemas/X` reference re-pointed at
     * `#/$defs/X`. Only where {@see SchemaMembers} says a member IS a reference: the same `$ref` inside a
     * `const` or an `example` is instance data, and so is a list there, which is exactly what it says.
     */
    private function rewrite(mixed $node, string $in): mixed
    {
        if (is_array($node)) {
            return array_map(fn (mixed $item): mixed => $this->rewritten($item, null, $in), $node);
        }

        if (! is_object($node)) {
            return $node;
        }

        $copy = new stdClass;

        foreach (get_object_vars($node) as $member => $value) {
            $member = (string) $member;

            $copy->{$member} = SchemaMembers::isReference($member, $in) && is_string($value) && str_starts_with($value, self::COMPONENT_PREFIX)
                ? '#/$defs/'.substr($value, strlen(self::COMPONENT_PREFIX))
                : $this->rewritten($value, $member, $in);
        }

        return $copy;
    }

    /**
     * The member `$name` of a value read as `$in` — or, where `$name` is null, one of its items — rewritten
     * for where it stands, with an empty array standing where an object belongs restored to one.
     */
    private function rewritten(mixed $value, ?string $name, string $in): mixed
    {
        if ($value === [] && SchemaMembers::emptyIsObject($name, $in)) {
            return new stdClass;
        }

        return $this->rewrite($value, $name === null ? SchemaMembers::item($in) : SchemaMembers::member($name, $in));
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
