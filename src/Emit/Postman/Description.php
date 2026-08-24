<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit\Postman;

use Docuccino\Core\Draft\DeprecationNote;
use Docuccino\Core\Support\Arr;

/**
 * The prose that goes into a collection.
 *
 * Every string here is read by someone holding the collection and nothing else — they cannot see the
 * application, its config or its attributes. So none of this mentions Docuccino, a config key or a
 * way to change the output. The pull is strongest around `baseUrl`, where "set this in your config"
 * is the natural sentence and the wrong one: the reader's job is to paste a URL, not to edit a file
 * they do not have.
 *
 * @internal
 */
final class Description
{
    /**
     * The collection description: the document's own prose, then the things Postman has nowhere else
     * to put — the servers past the first, what each variable wants, and the contact/licence block.
     *
     * @param  array<string, mixed>  $info
     * @param  list<mixed>  $servers
     * @param  list<array<string, mixed>>  $variables
     */
    public static function collection(array $info, array $servers, array $variables): string
    {
        $sections = [self::text($info['description'] ?? null) ?: self::text($info['summary'] ?? null)];

        $alternates = self::alternateServers($servers);
        if ($alternates !== '') {
            $sections[] = "## Servers\n\n".$alternates;
        }

        $fillable = self::fillable($variables);
        if ($fillable !== '') {
            $sections[] = "## Variables\n\nSet these collection variables before sending a request:\n\n".$fillable;
        }

        $about = self::about($info);
        if ($about !== '') {
            $sections[] = "## About\n\n".$about;
        }

        return implode("\n\n", array_values(array_filter($sections, static fn (string $s): bool => $s !== '')));
    }

    /**
     * @param  list<mixed>  $servers
     */
    private static function alternateServers(array $servers): string
    {
        $lines = [];

        foreach (array_slice($servers, 1) as $server) {
            $server = is_array($server) ? Arr::stringKeyed($server) : [];
            $url = self::text($server['url'] ?? null);

            if ($url === '') {
                continue;
            }

            $description = self::text($server['description'] ?? null);
            $lines[] = $description === ''
                ? sprintf('- `%s`', $url)
                : sprintf('- `%s` — %s', $url, $description);
        }

        return $lines === [] ? '' : "This collection targets the first server. The API is also served at:\n\n".implode("\n", $lines);
    }

    /**
     * @param  list<array<string, mixed>>  $variables
     */
    private static function fillable(array $variables): string
    {
        $lines = [];

        foreach ($variables as $variable) {
            // Only the ones left blank need a human; anything with a value already works.
            if (($variable['value'] ?? '') !== '') {
                continue;
            }

            $key = self::text($variable['key'] ?? null);
            if ($key === '') {
                continue;
            }

            $description = self::text($variable['description'] ?? null);
            $lines[] = $description === '' ? sprintf('- `%s`', $key) : sprintf('- `%s` — %s', $key, $description);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private static function about(array $info): string
    {
        $lines = [];

        $contact = Arr::stringKeyed(is_array($info['contact'] ?? null) ? $info['contact'] : []);
        $name = self::text($contact['name'] ?? null);
        $email = self::text($contact['email'] ?? null);
        $url = self::text($contact['url'] ?? null);

        if ($name !== '' || $email !== '' || $url !== '') {
            $parts = array_values(array_filter([
                $name,
                $email === '' ? '' : sprintf('<%s>', $email),
                $url === '' ? '' : sprintf('<%s>', $url),
            ], static fn (string $s): bool => $s !== ''));

            $lines[] = '- Contact: '.implode(' ', $parts);
        }

        $license = Arr::stringKeyed(is_array($info['license'] ?? null) ? $info['license'] : []);
        $licenseName = self::text($license['name'] ?? null);
        if ($licenseName !== '') {
            $licenseUrl = self::text($license['url'] ?? null);
            $lines[] = '- Licence: '.($licenseUrl === '' ? $licenseName : sprintf('[%s](%s)', $licenseName, $licenseUrl));
        }

        $terms = self::text($info['termsOfService'] ?? null);
        if ($terms !== '') {
            $lines[] = '- Terms of service: '.$terms;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $tag
     */
    public static function folder(array $tag): string
    {
        return self::text($tag['description'] ?? null) ?: self::text($tag['summary'] ?? null);
    }

    /**
     * The operation's own prose. The summary is already the request's name, so repeating it here would
     * just make every request say itself twice.
     *
     * @param  array<string, mixed>  $operation
     */
    public static function request(array $operation): string
    {
        $sections = [];
        $description = self::text($operation['description'] ?? null);

        // Postman has no deprecated flag, so prose is the only truthful carrier — which is also why
        // there is no diagnostic for it: nothing was lost. Where the description already carries the
        // reason, that paragraph says it and says why, so the generic line would only say it twice.
        if (($operation['deprecated'] ?? false) === true && ! DeprecationNote::marks($description)) {
            $sections[] = '> **Deprecated.** This endpoint may be removed in a future version of the API.';
        }

        if ($description !== '') {
            $sections[] = $description;
        }

        $externalDocs = Arr::stringKeyed(is_array($operation['externalDocs'] ?? null) ? $operation['externalDocs'] : []);
        $url = self::text($externalDocs['url'] ?? null);
        if ($url !== '') {
            $label = self::text($externalDocs['description'] ?? null) ?: 'Further reading';
            $sections[] = sprintf('[%s](%s)', $label, $url);
        }

        return implode("\n\n", $sections);
    }

    /**
     * @param  array<string, mixed>  $parameter
     */
    public static function parameter(array $parameter): string
    {
        $description = self::text($parameter['description'] ?? null);

        $schema = is_array($parameter['schema'] ?? null) ? Arr::stringKeyed($parameter['schema']) : [];
        $enum = is_array($schema['enum'] ?? null) ? array_values($schema['enum']) : [];

        if ($enum !== []) {
            $values = array_values(array_filter(array_map(
                static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
                $enum,
            ), static fn (string $s): bool => $s !== ''));

            if ($values !== []) {
                $one = sprintf('One of: %s.', implode(', ', $values));
                $description = $description === '' ? $one : rtrim($description, '.').'. '.$one;
            }
        }

        return $description;
    }

    /**
     * The collection variables a security scheme needs filled in, keyed by variable name. Each name is
     * a function of the scheme's own published key, never of position.
     *
     * @param  array<string, mixed>  $scheme
     * @return array<string, string>
     */
    public static function credentials(string $key, array $scheme): array
    {
        $type = self::text($scheme['type'] ?? null);
        $httpScheme = strtolower(self::text($scheme['scheme'] ?? null));

        if ($type === 'http' && $httpScheme === 'bearer') {
            return [$key => 'The bearer token sent in the `Authorization` header.'];
        }

        if ($type === 'http' && in_array($httpScheme, ['basic', 'digest'], true)) {
            return [
                $key.'Username' => 'The username to authenticate with.',
                $key.'Password' => 'The password to authenticate with.',
            ];
        }

        if ($type === 'apiKey') {
            $name = self::text($scheme['name'] ?? null);
            $in = self::text($scheme['in'] ?? null) ?: 'header';

            return [$key => $name === ''
                ? 'The API key.'
                : sprintf('The API key, sent as the `%s` %s.', $name, $in)];
        }

        if ($type === 'oauth2') {
            return [
                $key.'ClientId' => 'The OAuth client id.',
                $key.'ClientSecret' => 'The OAuth client secret.',
            ];
        }

        return [];
    }

    /** A trimmed string, or '' for anything that is not usable prose. */
    public static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
