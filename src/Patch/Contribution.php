<?php

declare(strict_types=1);

namespace Docuccino\Core\Patch;

use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Provenance\Source;

/**
 * A single producer's attempt to write one or more fields on a node: its precedence layer,
 * its provenance `producer` string, and optional source/confidence metadata.
 *
 * `specificity` breaks ties *within* a layer so a more specific target beats a less specific
 * one (design §7: method attribute > class attribute). Two contributions compare on the
 * `(layer, specificity)` tuple; a strictly-greater tuple overrides, equal or lower shadows.
 */
final readonly class Contribution
{
    public function __construct(
        public Layer $layer,
        public string $producer,
        public ?Source $source = null,
        public ?float $confidence = null,
        public int $specificity = 0,
    ) {}

    public static function fallback(?Source $source = null): self
    {
        return new self(Layer::Fallback, 'fallback', $source);
    }

    public static function inference(?Source $source = null, ?float $confidence = null): self
    {
        return new self(Layer::Inference, 'inference', $source, $confidence);
    }

    public static function integration(string $name, ?Source $source = null, ?float $confidence = null): self
    {
        return new self(Layer::Integration, 'integration:'.$name, $source, $confidence);
    }

    public static function docblock(?Source $source = null): self
    {
        return new self(Layer::Docblock, 'docblock', $source);
    }

    public static function attribute(?Source $source = null, int $specificity = 0): self
    {
        return new self(Layer::Attribute, 'attribute', $source, specificity: $specificity);
    }

    public static function overlay(?Source $source = null): self
    {
        return new self(Layer::Overlay, 'overlay', $source);
    }

    public static function config(?Source $source = null): self
    {
        return new self(Layer::Config, 'config', $source);
    }

    /**
     * Build a contribution for a producer named at runtime (e.g. the winning
     * {@see ExceptionToResponse} mapper), mapping the producer
     * string to its precedence layer so a `fallback`-produced response can still be overridden by a
     * later `inference`/`integration` one.
     */
    public static function forProducer(string $producer, ?Source $source = null, ?float $confidence = null): self
    {
        $layer = match (true) {
            $producer === 'fallback' => Layer::Fallback,
            $producer === 'docblock' => Layer::Docblock,
            $producer === 'attribute' => Layer::Attribute,
            $producer === 'overlay' => Layer::Overlay,
            $producer === 'config' => Layer::Config,
            str_starts_with($producer, 'integration:') => Layer::Integration,
            default => Layer::Inference,
        };

        return new self($layer, $producer, $source, $confidence);
    }

    /**
     * True when this contribution outranks the incumbent and should overwrite it.
     */
    public function outranks(self $incumbent): bool
    {
        return [$this->layer->value, $this->specificity] > [$incumbent->layer->value, $incumbent->specificity];
    }

    /**
     * A stable key grouping fields that belong to the same provenance record.
     */
    public function recordKey(): string
    {
        $source = $this->source?->toArray();
        $encoded = json_encode([$this->producer, $this->layer->label(), $source, $this->confidence]);

        return $encoded === false ? $this->producer.'|'.$this->layer->label() : $encoded;
    }
}
