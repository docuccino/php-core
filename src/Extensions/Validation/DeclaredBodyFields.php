<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

use Docuccino\Attributes\BodyParameter;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\SchemaKeywords;
use Docuccino\Core\Extensions\Contracts\TypeSchemaConverter;
use Docuccino\Core\Provenance\Source;
use Docuccino\Core\TypeGrammar\TypeStringParser;

/**
 * Writes `#[BodyParameter]` declarations into a request-body schema. ONE reader, two declaration
 * sites: the adapter's route attribute bag, where a declaration is one operation's, and the request
 * source CLASS, where it is the type's ({@see RecoveredRequest::withDeclarations()}). A second copy of
 * this walk would be a second grammar — a path folding `\.` in one reader and not the other means the
 * same string names two different fields depending on who read it.
 *
 * The name is a field PATH, split by {@see FieldPath}: `meta.validation_overrides` documents
 * `validation_overrides` inside `meta`, `items.*.id` documents `id` on an item, and `\.` names a field
 * whose own name holds a dot. That is the grammar the recovered body was assembled from, so the
 * declaration that patches one of its properties reads a name the way its producer wrote one.
 * Declarations are applied shallowest first, so a parent named by one is in place before a child named
 * by another, whichever order the two were written in.
 *
 * A path only reaches somewhere the body can carry it. Where the parent it names is a scalar, a
 * composition, or a `$ref` to a shared component — which every other operation using that component
 * would inherit the new property from — nothing is written and the refusal is reported.
 *
 * Naming a key inside a container also SETTLES what that container is. Laravel has one word for both
 * array shapes, so a bare `array` rule leaves a field a JSON array or a JSON object and the document
 * says both; an author naming a key inside it has answered the question, and a declaration outranks the
 * inference and the integration that left it open, which stops raising `validation.container-undecided`
 * for it. What it settles is only that question — `null` survives the descent, because a field the
 * server takes as null does not stop being one for having a key documented inside it.
 *
 * @phpstan-type BodyPathRefusal array{container: string, says: string, shared: bool}
 * @phpstan-type BodyFieldsResult array{0: array<string, mixed>, 1: bool, 2: list<Diagnostic>}
 */
final class DeclaredBodyFields
{
    /** What a `$ref` parent is called in the refusal, and the answer that picks its remedy. */
    private const string SHARED = 'a reference to a shared component';

    public function __construct(
        private readonly TypeStringParser $types = new TypeStringParser,
    ) {}

    /**
     * `$schema` with every declaration written into it, whether the body is required, and what could
     * not be written.
     *
     * `$site` is the class a TYPE-level declaration was written on, named in the refusals so the author
     * is sent to the file holding the declaration; null is the operation's own bag, where the source and
     * the route signature already say where to go.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<BodyParameter>  $declarations
     * @return BodyFieldsResult
     */
    public function apply(array $schema, array $declarations, TypeSchemaConverter $converter, ?string $site = null, ?Source $source = null, ?string $routeSignature = null): array
    {
        $required = false;
        $diagnostics = [];

        foreach (self::shallowestFirst($declarations) as $declaration) {
            // A field the server insists on is a body the server insists on, however deep the field
            // sits — and only the top level of `required` is on the root schema to say so. A written
            // `required: false` says nothing about the body: the request still carries one.
            $documented = $this->write($schema, $declaration, $converter, $site, $source, $routeSignature, $diagnostics);
            $required = $required || ($documented && $declaration->required === true);
        }

        return [$schema, $required, $diagnostics];
    }

    /**
     * The declarations with every parent ahead of its children, source order otherwise. A path descends
     * into whatever its parent is by the time it runs, so leaving that to the order the declarations
     * happen to be written in would let a `#[BodyParameter('meta')]` replace the `meta` that a
     * `#[BodyParameter('meta.x')]` above it had just filled in.
     *
     * @param  list<BodyParameter>  $declarations
     * @return list<BodyParameter>
     */
    private static function shallowestFirst(array $declarations): array
    {
        // Stable since PHP 8.0, which is what keeps two declarations at the same depth in the order
        // their author wrote them.
        usort(
            $declarations,
            static fn (BodyParameter $a, BodyParameter $b): int => count(FieldPath::segments($a->name)) <=> count(FieldPath::segments($b->name)),
        );

        return $declarations;
    }

    /**
     * Writes one declaration into the body schema, or reports why the body cannot carry it. Returns
     * whether it documented anything.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<Diagnostic>  $diagnostics
     */
    private function write(array &$schema, BodyParameter $declaration, TypeSchemaConverter $converter, ?string $site, ?Source $source, ?string $routeSignature, array &$diagnostics): bool
    {
        if (! FieldPath::isWellFormed($declaration->name)) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'attribute.body-parameter-name',
                message: sprintf(
                    '#[BodyParameter(name: "%s")]%s names no body field — a field path has no empty segments — so no property was documented.',
                    $declaration->name,
                    self::on($site),
                ),
                source: $source,
                routeSignature: $routeSignature,
                help: 'Write the name as a validation rule key is written: `nickname`, `meta.validation_overrides`, `items.*.id`. A dot that belongs to the field name itself is escaped `\\.`.',
            );

            return false;
        }

        $refusal = $this->place($schema, FieldPath::segments($declaration->name), $this->property($declaration, $converter), $declaration->required, []);
        if ($refusal === null) {
            return true;
        }

        $diagnostics[] = new Diagnostic(
            severity: Severity::Warning,
            code: 'attribute.body-parameter-parent',
            message: sprintf(
                '#[BodyParameter(name: "%s")]%s nests under %s, documented as %s, so no property was documented.',
                $declaration->name,
                self::on($site),
                $refusal['container'],
                $refusal['says'],
            ),
            source: $source,
            routeSignature: $routeSignature,
            help: $refusal['shared']
                ? 'Every operation using that component would inherit the property, so it is not one operation\'s to add. Declare it where the component is defined, or patch this body with an overlay.'
                : 'Document the parent as an object first — a #[BodyParameter] naming it with `type: \'object\'` — or name a top-level field instead. A dot that belongs to the field name itself is escaped `\\.`.',
        );

        return false;
    }

    /** Where a type-level declaration was written, as the message names it; empty for the action's bag. */
    private static function on(?string $site): string
    {
        return $site === null ? '' : ' on '.$site;
    }

    /**
     * The schema one declaration publishes for the field it names.
     *
     * @return array<string, mixed>
     */
    private function property(BodyParameter $declaration, TypeSchemaConverter $converter): array
    {
        $property = $declaration->type !== null
            ? $converter->toSchema($this->types->parseDeclared($declaration->type))->schema
            : ['type' => 'string'];

        // After the type keywords, so an explicit format wins over one the type string implied.
        if ($declaration->format !== null) {
            $property['format'] = $declaration->format;
        }
        if ($declaration->description !== null) {
            $property['description'] = $declaration->description;
        }
        if ($declaration->example !== null) {
            $property['example'] = $declaration->example;
        }

        return $property;
    }

    /**
     * Places `$property` at `$segments` under `$node`, creating the containers on the way. Returns null
     * on success, and otherwise what stopped it — leaving `$node` exactly as it found it, because a
     * declaration that documented nothing should not leave a half-built container behind either.
     *
     * @param  array<string, mixed>  $node  the container the first segment is a member of
     * @param  non-empty-list<string>  $segments
     * @param  array<string, mixed>  $property
     * @param  list<string>  $walked  the segments already descended through, for the refusal
     * @return BodyPathRefusal|null
     */
    private function place(array &$node, array $segments, array $property, ?bool $required, array $walked): ?array
    {
        $segment = $segments[0];
        $rest = array_slice($segments, 1);

        $says = self::cannotCarry($node, $segment === '*' ? 'array' : 'object');
        if ($says !== null) {
            return [
                'container' => $walked === [] ? 'the request body' : '`'.implode('.', $walked).'`',
                'says' => $says,
                'shared' => $says === self::SHARED,
            ];
        }

        if ($segment === '*') {
            /** @var array<string, mixed> $child */
            $child = is_array($node['items'] ?? null) ? $node['items'] : [];

            if ($rest === []) {
                // A `*` leaf describes the element itself, so there is no object to mark it required on.
                $child = $property;
            } else {
                $refusal = $this->place($child, $rest, $property, $required, [...$walked, $segment]);
                if ($refusal !== null) {
                    return $refusal;
                }
            }

            $node['type'] = self::settledTo($node, 'array');
            $node['items'] = $child;

            return null;
        }

        /** @var array<string, mixed> $properties */
        $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];

        if ($rest !== []) {
            /** @var array<string, mixed> $child */
            $child = is_array($properties[$segment] ?? null) ? $properties[$segment] : [];

            $refusal = $this->place($child, $rest, $property, $required, [...$walked, $segment]);
            if ($refusal !== null) {
                return $refusal;
            }

            $properties[$segment] = $child;
            $node['type'] = self::settledTo($node, 'object');
            $node['properties'] = $properties;

            return null;
        }

        $properties[$segment] = $property;
        $node['type'] = self::settledTo($node, 'object');
        $node['properties'] = $properties;

        $existing = is_array($node['required'] ?? null)
            ? array_values(array_filter($node['required'], 'is_string'))
            : [];
        $names = self::withRequired($existing, $segment, $required);

        if ($names === []) {
            unset($node['required']);
        } else {
            $node['required'] = $names;
        }

        return null;
    }

    /**
     * The container's `type` once a declaration has said which container it is. A member the rules could
     * not decide between arrives here as `["array", "object"]` — Laravel has one word for both — and a
     * declaration naming a key inside it settles that, an attribute outranking the inference and the
     * integration that left it open. It settles ONLY that question: every other word stays, because
     * `null` is not an answer to "array or object" and dropping it would tell a consumer their `null` is
     * invalid when the server takes it.
     *
     * @param  array<string, mixed>  $node
     * @return string|list<string>
     */
    private static function settledTo(array $node, string $kind): string|array
    {
        $declared = $node['type'] ?? null;

        // The kind first, then everything the declaration says nothing about. Both container words drop
        // out of the tail — the kind is back at the head, and its rival is the half now answered.
        $others = array_values(array_filter(
            array_filter(is_array($declared) ? $declared : [$declared], 'is_string'),
            static fn (string $type): bool => $type !== 'array' && $type !== 'object',
        ));

        return $others === [] ? $kind : [$kind, ...$others];
    }

    /**
     * What stops this schema holding a nested member of `$kind`, in the words the refusal quotes — null
     * when nothing does. A schema with no `type` is taken as the container the path says it is: it
     * claims nothing the declaration contradicts.
     *
     * @param  array<string, mixed>  $node
     */
    private static function cannotCarry(array $node, string $kind): ?string
    {
        if (isset($node['$ref'])) {
            return self::SHARED;
        }

        // Drawn from the keyword table rather than listed again here: a schema whose shape is given by
        // a LIST of subschemas has no one branch a nested field belongs in.
        foreach (SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA_LIST) as $keyword) {
            if (isset($node[$keyword])) {
                return '`'.$keyword.'`';
            }
        }

        $declared = $node['type'] ?? null;
        if ($declared === null) {
            return null;
        }

        $types = array_values(array_filter(is_array($declared) ? $declared : [$declared], 'is_string'));
        if (in_array($kind, $types, true)) {
            return null;
        }

        return $types === [] ? 'something other than an '.$kind : '`'.implode('`/`', $types).'`';
    }

    /**
     * The `required` list once one declaration has had its say about `$name`, order-stable and
     * duplicate-free.
     *
     * The absent argument arrives as `null` and changes nothing: a declaration written to document a
     * TYPE says nothing about whether the server insists on the field, and reading it as "optional"
     * would quietly de-require what the recovered rules proved — a contract a consumer's generated
     * client can build a rejected request from. A written `false` is the opposite: the author's own
     * statement at a layer that outranks the recovery, and it is applied. Both directions can be wrong,
     * and they are not equally wrong — an over-wide body costs a consumer a field they need not have
     * sent, while an over-narrow one marks a request the server accepts as invalid.
     *
     * @param  list<string>  $required
     * @return list<string>
     */
    private static function withRequired(array $required, string $name, ?bool $isRequired): array
    {
        if ($isRequired === null) {
            return $required;
        }

        if (! $isRequired) {
            return array_values(array_filter($required, static fn (string $each): bool => $each !== $name));
        }

        return in_array($name, $required, true) ? $required : [...$required, $name];
    }
}
