<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

/**
 * One documented path template (`/api/invoices/{invoice}`), able to match a concrete request path and
 * hand back the path-parameter values it bound.
 *
 * Where two templates both match a concrete path — `/api/invoices/recent` and
 * `/api/invoices/{invoice}` — the winner is decided by {@see literalMask()}: a literal segment beats a
 * placeholder at the first position they differ. That is a function of the templates themselves, never
 * of the order the document happened to list them.
 *
 * @internal
 */
final readonly class PathTemplate
{
    /**
     * @param  list<string>  $segments  the template's `/`-separated segments, placeholders included
     */
    private function __construct(
        public string $template,
        private array $segments,
    ) {}

    public static function parse(string $template): self
    {
        return new self($template, self::split($template));
    }

    /**
     * The bound path parameters, or null when this template does not describe the path at all.
     *
     * @return array<string, string>|null
     */
    public function bind(string $path): ?array
    {
        $actual = self::split($path);

        if (count($actual) !== count($this->segments)) {
            return null;
        }

        $bound = [];
        foreach ($this->segments as $index => $segment) {
            $name = self::placeholder($segment);

            if ($name === null) {
                if ($segment !== $actual[$index]) {
                    return null;
                }

                continue;
            }

            // A placeholder never spans a `/`, and an empty segment is not a value.
            if ($actual[$index] === '') {
                return null;
            }

            $bound[$name] = rawurldecode($actual[$index]);
        }

        return $bound;
    }

    /**
     * `1` per literal segment, `0` per placeholder. Two templates that matched the same concrete path
     * have the same length, so comparing masks as strings orders them totally: the greater mask is the
     * more specific template.
     */
    public function literalMask(): string
    {
        $mask = '';
        foreach ($this->segments as $segment) {
            $mask .= self::placeholder($segment) === null ? '1' : '0';
        }

        return $mask;
    }

    /** The placeholder's name, or null when the segment is a literal. */
    private static function placeholder(string $segment): ?string
    {
        if (! str_starts_with($segment, '{') || ! str_ends_with($segment, '}')) {
            return null;
        }

        $name = substr($segment, 1, -1);

        return $name === '' ? null : $name;
    }

    /**
     * Segments of a path, normalised so `/api/forms`, `api/forms` and `/api/forms/` are one path. The
     * query string and fragment are not part of the path.
     *
     * @return list<string>
     */
    private static function split(string $path): array
    {
        $path = (string) preg_replace('/[?#].*$/', '', $path);
        $path = trim($path, '/');

        return $path === '' ? [] : explode('/', $path);
    }
}
