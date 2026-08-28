<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

/**
 * What one documented parameter gives a reader to hold its values to: which of the four answers
 * ({@see ParameterSchemaKind}, which says why they are one value rather than a nullable schema) the
 * contract made, and — where it published a schema — the keywords a wire value is read back against.
 *
 * Those keywords are PRIVATE and there is no reading of them but {@see read()}. They are null for a
 * boolean schema as well as for every answer that is not one, so a caller holding them would be back
 * at the null that meant three things: the kind is the question, and this is the only way to ask it.
 *
 * @internal
 */
final readonly class ParameterSchema
{
    /** @param  array<string, mixed>|null  $node */
    private function __construct(
        public ParameterSchemaKind $kind,
        private ?array $node,
    ) {}

    /**
     * Read off a parameter object — or a response header object, which OAS defines as one — with its
     * `$ref` already followed.
     *
     * The member being PRESENT is not the question, on either side: a `schema` no validator can take is
     * exactly as uncheckable as one nobody wrote, and a `content` that is not a map of media types is
     * not the content object whose name the note would use. Both still count as WRITTEN, which is the
     * distinction {@see Refs::malformed()} draws for `requestBody` and this asks it for. `[]` counts as
     * a schema, because that is how associative decoding spells `{}`.
     *
     * A readable answer outranks a written-but-unreadable one, so a `schema` this can check wins over a
     * `content` beside it and both win over the member that would not decode.
     *
     * @param  array<string, mixed>  $definition
     */
    public static function of(array $definition): self
    {
        $schema = $definition['schema'] ?? null;

        if (is_array($schema)) {
            /** @var array<string, mixed> $schema */
            return new self(ParameterSchemaKind::Schema, $schema);
        }

        // `true` and `false` ARE schemas; there are simply no keywords on them to read a value back
        // against. The validator is handed the schema by POINTER ({@see ContractParameter::schemaSegments()}),
        // so a boolean is still checked — this is only the reading.
        if (is_bool($schema)) {
            return new self(ParameterSchemaKind::Schema, null);
        }

        if (is_array($definition['content'] ?? null)) {
            return new self(ParameterSchemaKind::Content, null);
        }

        return new self(
            Refs::malformed($definition, 'schema') || Refs::malformed($definition, 'content')
                ? ParameterSchemaKind::Malformed
                : ParameterSchemaKind::Absent,
            null,
        );
    }

    /**
     * One wire value read back as the type the document says it is, so the validator is handed `2`
     * rather than `'2'` where the contract published `type: integer` ({@see ParameterValue}).
     *
     * @param  array<string, mixed>  $document  the whole contract, so a local `$ref` resolves
     */
    public function read(mixed $value, array $document): mixed
    {
        return ParameterValue::coerce($value, $this->node, $document);
    }
}
