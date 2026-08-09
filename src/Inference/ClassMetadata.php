<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

/**
 * The reflected + docblock-driven shape of a class (Data / Resource / Model):
 * its FQCN, an optional class-level summary, and its properties. Produced lazily
 * and memoised per class per run by {@see TypeEngine::classMetadata()}.
 */
final readonly class ClassMetadata
{
    /**
     * @param  list<PropertyMetadata>  $properties
     * @param  list<string>  $dependencyFiles  the file(s) the class shape was reflected from — fed
     *                                         into the fragment cache key so editing the class
     *                                         (adding/retyping a property) invalidates the fragment
     */
    public function __construct(
        public string $fqcn,
        public array $properties = [],
        public ?string $summary = null,
        public array $dependencyFiles = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $deps = array_values(array_unique($this->dependencyFiles));
        sort($deps);

        $out = [
            'fqcn' => $this->fqcn,
            'properties' => array_map(static fn (PropertyMetadata $p): array => $p->toArray(), $this->properties),
        ];

        if ($this->summary !== null) {
            $out['summary'] = $this->summary;
        }

        if ($deps !== []) {
            $out['dependencyFiles'] = $deps;
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $fqcn = $data['fqcn'] ?? '';
        $properties = $data['properties'] ?? [];
        $summary = $data['summary'] ?? null;
        $deps = $data['dependencyFiles'] ?? [];

        return new self(
            is_string($fqcn) ? $fqcn : '',
            is_array($properties)
                ? array_values(array_map(
                    static fn (mixed $p): PropertyMetadata => is_array($p) ? PropertyMetadata::fromArray($p) : PropertyMetadata::fromArray([]),
                    $properties,
                ))
                : [],
            is_string($summary) ? $summary : null,
            is_array($deps) ? array_values(array_filter($deps, 'is_string')) : [],
        );
    }
}
