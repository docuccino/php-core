<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Document\UirDocument;

/**
 * Emits a {@see UirDocument} as pure OpenAPI 3.2 (JSON or YAML): every `x-docuccino` member is
 * stripped, along with the UIR-only top-level `$schema` and `uir` version. Options may re-emit
 * ids as flat `x-docuccino-id` members and map schema mock hints to a faker member; provenance is
 * always dropped. The content layer (`x-docuccino.content`) has nowhere to live in OAS and is
 * dropped — `info.description`/tag descriptions already sit in standard fields.
 *
 * Output flows through the shared canonical serializer, so 3.2 emission is byte-deterministic
 * and, with default options, round-trips losslessly against the x-docuccino-stripped UIR.
 *
 * @internal
 */
final readonly class OpenApi32Emitter implements Emitter
{
    public function __construct(
        private Canonicalizer $canonicalizer = new Canonicalizer,
        private CanonicalJsonSerializer $serializer = new CanonicalJsonSerializer,
        private YamlSerializer $yaml = new YamlSerializer,
    ) {}

    public function format(): string
    {
        return 'openapi-3.2';
    }

    public function emit(UirDocument $document, EmitOptions $options = new EmitOptions): string
    {
        $canonical = $this->canonicalizer->canonicalize($this->toOpenApiArray($document, $options));

        return $options->yaml
            ? $this->yaml->serialize($canonical)
            : $this->serializer->serialize($canonical);
    }

    /**
     * The pure OpenAPI 3.2 array (pre-canonicalisation), reused by the 3.1 downlevel emitter.
     *
     * @return array<string, mixed>
     */
    public function toOpenApiArray(UirDocument $document, EmitOptions $options = new EmitOptions): array
    {
        $array = $document->toArray();

        unset($array['$schema'], $array['uir']);

        /** @var array<string, mixed> $stripped */
        $stripped = $this->strip($array, $options);

        return $stripped;
    }

    private function strip(mixed $node, EmitOptions $options): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        if (array_is_list($node)) {
            return array_map(fn (mixed $item): mixed => $this->strip($item, $options), $node);
        }

        $docuccino = $node['x-docuccino'] ?? null;
        unset($node['x-docuccino']);

        $out = [];
        foreach ($node as $key => $value) {
            $out[(string) $key] = str_starts_with((string) $key, 'x-')
                ? $value
                : $this->strip($value, $options);
        }

        if (is_array($docuccino)) {
            $this->projectDocuccino($out, $docuccino, $options);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $out
     * @param  array<mixed, mixed>  $docuccino
     */
    private function projectDocuccino(array &$out, array $docuccino, EmitOptions $options): void
    {
        if ($options->keepIds && isset($docuccino['id']) && is_string($docuccino['id'])) {
            $out['x-docuccino-id'] = $docuccino['id'];
        }

        if ($options->mockFakerKey !== null
            && isset($docuccino['mock']) && is_array($docuccino['mock'])
            && isset($docuccino['mock']['faker']) && is_string($docuccino['mock']['faker'])
        ) {
            $out[$options->mockFakerKey] = $docuccino['mock']['faker'];
        }
    }
}
