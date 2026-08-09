<?php

declare(strict_types=1);

namespace Docuccino\Core\Overlay;

use Docuccino\Core\Support\Arr;

/**
 * A parsed OpenAPI Overlay 1.0 document (`overlay: 1.0.0`, optional `info`, ordered `actions`).
 * Only the 1.0 line is accepted; anything else raises {@see InvalidOverlayException}.
 */
final readonly class OverlayDocument
{
    /**
     * @param  array<string, mixed>|null  $info
     * @param  list<OverlayAction>  $actions
     */
    public function __construct(
        public string $overlay,
        public array $actions = [],
        public ?array $info = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $version = $data['overlay'] ?? null;

        if (! is_string($version) || $version === '') {
            throw InvalidOverlayException::missingVersion();
        }

        if (! str_starts_with($version, '1.0')) {
            throw InvalidOverlayException::unsupportedVersion($version);
        }

        $info = null;
        if (isset($data['info']) && is_array($data['info'])) {
            $info = Arr::stringKeyed($data['info']);
        }

        $actions = [];
        if (array_key_exists('actions', $data)) {
            if (! is_array($data['actions']) || ! array_is_list($data['actions'])) {
                throw InvalidOverlayException::malformedActions();
            }

            $index = 0;
            foreach ($data['actions'] as $action) {
                if (is_array($action)) {
                    $actions[] = OverlayAction::fromArray(Arr::stringKeyed($action), $index);
                }
                $index++;
            }
        }

        return new self(
            overlay: $version,
            actions: $actions,
            info: $info,
        );
    }
}
