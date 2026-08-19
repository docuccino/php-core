<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

use Docuccino\Core\Provenance\ProvenanceRecord;

/**
 * Who put this bit of the contract there. Reading `x-docuccino.provenance` off the schema node a
 * failure landed on is what turns "the response doesn't match" into "the response doesn't match the
 * shape `integration:eloquent` read out of app/Models/Invoice.php:31".
 *
 * The trail is the DEEPEST node on the path that records any, walking outward: a property's own
 * contribution says more than the component's, and a component's says more than nothing. An OpenAPI
 * export carries no provenance at all, so the trail is empty and the message simply says less rather
 * than guessing.
 */
final readonly class ProvenanceTrail
{
    /**
     * @param  list<ProvenanceRecord>  $records
     * @param  string  $pointer  the node the records were found on, as a document pointer
     */
    private function __construct(
        public array $records,
        public string $pointer,
    ) {}

    public static function none(): self
    {
        return new self([], '');
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<string>  $segments  document pointer segments addressing the failing schema node
     */
    public static function at(array $document, array $segments): self
    {
        for ($depth = count($segments); $depth >= 0; $depth--) {
            $node = Pointer::read($document, array_slice($segments, 0, $depth));

            if (! is_array($node)) {
                continue;
            }

            $records = self::recordsOn($node);

            if ($records !== []) {
                return new self($records, Pointer::of(array_slice($segments, 0, $depth)));
            }
        }

        return self::none();
    }

    public function isEmpty(): bool
    {
        return $this->records === [];
    }

    /**
     * One line per contribution: `integration:eloquent (integration) — app/Models/Invoice.php:31`.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return array_map(static function (ProvenanceRecord $record): string {
            $line = $record->producer.' ('.$record->layer.')';
            $source = $record->source;

            if ($source === null || $source->file === '') {
                return $line;
            }

            $where = $source->file.($source->line === null ? '' : ':'.$source->line);

            return $line.' — '.$where.($source->symbol === null ? '' : ' in '.$source->symbol);
        }, $this->records);
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return list<ProvenanceRecord>
     */
    private static function recordsOn(array $node): array
    {
        $docuccino = $node['x-docuccino'] ?? null;
        $provenance = is_array($docuccino) ? ($docuccino['provenance'] ?? null) : null;

        if (! is_array($provenance)) {
            return [];
        }

        $records = [];
        foreach ($provenance as $record) {
            if (is_array($record)) {
                /** @var array<string, mixed> $record */
                $records[] = ProvenanceRecord::fromArray($record);
            }
        }

        return $records;
    }
}
