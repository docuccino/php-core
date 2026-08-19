<?php

declare(strict_types=1);

namespace Docuccino\Core\Patch;

/**
 * The precedence layers. The backing integer is the rank — higher overrides lower. The lowercase case
 * name is the `layer` string written into provenance records, constrained by the UIR schema's `layer`
 * enum.
 */
enum Layer: int
{
    case Fallback = 5;
    case Inference = 10;
    case Integration = 20;
    case Docblock = 30;
    case Attribute = 40;
    case Overlay = 45;
    case Config = 50;

    /** The rung a provenance `layer` string names, or null when it names none we know. */
    public static function fromLabel(string $label): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->label() === $label) {
                return $case;
            }
        }

        return null;
    }

    /** The provenance `layer` string. */
    public function label(): string
    {
        return match ($this) {
            self::Fallback => 'fallback',
            self::Inference => 'inference',
            self::Integration => 'integration',
            self::Docblock => 'docblock',
            self::Attribute => 'attribute',
            self::Overlay => 'overlay',
            self::Config => 'config',
        };
    }
}
