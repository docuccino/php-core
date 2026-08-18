<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit\Postman;

use Docuccino\Core\Support\Arr;

/**
 * Maps an OpenAPI security scheme onto Postman's `auth` block, and picks which scheme a requirement
 * carries when Postman can only hold one.
 *
 * Two choices here are fixed in advance rather than read off the document, because both would
 * otherwise depend on JSON object order — which no author wrote:
 *
 * - the oauth2 grant type, chosen by {@see FLOW_PREFERENCE} rather than "the first flow declared";
 * - which scheme an AND-composed requirement contributes, chosen by {@see TYPE_PREFERENCE}.
 *
 * Credential values are `{{variable}}` references named from the scheme's own published key, so the
 * name a consumer fills in is a function of the document, never of position.
 *
 * @internal
 */
final readonly class Auth
{
    /** Which oauth2 grant to publish when a scheme declares several. Most complete first. */
    private const array FLOW_PREFERENCE = ['authorizationCode', 'clientCredentials', 'password', 'implicit'];

    /** Which scheme wins when one requirement AND-composes several. */
    private const array TYPE_PREFERENCE = ['http:bearer', 'oauth2', 'apiKey', 'http:basic', 'http:digest'];

    /**
     * The Postman `auth` block for one scheme, or null when the format cannot express it.
     *
     * @param  array<string, mixed>  $scheme  a `components.securitySchemes` entry
     * @param  list<string>  $scopes  the requirement's scopes, for oauth2
     * @return array<string, mixed>|null
     */
    public function block(string $key, array $scheme, array $scopes = []): ?array
    {
        $type = is_string($scheme['type'] ?? null) ? $scheme['type'] : '';

        return match ($type) {
            'http' => $this->http($key, $scheme),
            'apiKey' => $this->apiKey($key, $scheme),
            'oauth2' => $this->oauth2($key, $scheme, $scopes),
            default => null,
        };
    }

    /**
     * Whether Postman can carry this scheme at all — `openIdConnect`, `mutualTLS` and anything
     * unrecognised cannot, and an `apiKey` in a cookie travels as a header instead.
     *
     * @param  array<string, mixed>  $scheme
     */
    public function expressible(array $scheme): bool
    {
        $type = is_string($scheme['type'] ?? null) ? $scheme['type'] : '';

        if ($type === 'http') {
            return in_array($this->httpScheme($scheme), ['bearer', 'basic', 'digest'], true);
        }

        if ($type === 'apiKey') {
            return ($scheme['in'] ?? null) !== 'cookie';
        }

        return $type === 'oauth2';
    }

    /**
     * The scheme within one requirement that Postman will carry, by fixed type preference. A
     * requirement is a MAP — an AND of schemes — and map order is not something the author chose, so
     * it may not decide which credential the collection publishes.
     *
     * @param  array<string, mixed>  $requirement  scheme key => scopes
     * @param  array<string, mixed>  $schemes  `components.securitySchemes`
     * @return string|null the winning scheme key
     */
    public function preferred(array $requirement, array $schemes): ?string
    {
        $ranked = [];

        foreach (array_keys($requirement) as $key) {
            $key = (string) $key;
            $scheme = Arr::stringKeyed(is_array($schemes[$key] ?? null) ? $schemes[$key] : []);

            if ($scheme === [] || ! $this->expressible($scheme)) {
                continue;
            }

            $rank = array_search($this->signature($scheme), self::TYPE_PREFERENCE, true);
            $ranked[] = [$rank === false ? count(self::TYPE_PREFERENCE) : $rank, $key];
        }

        if ($ranked === []) {
            return null;
        }

        // Ties break on the scheme key, so the answer never depends on iteration order.
        usort($ranked, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $ranked[0][1];
    }

    /**
     * @param  array<string, mixed>  $scheme
     */
    private function signature(array $scheme): string
    {
        $type = is_string($scheme['type'] ?? null) ? $scheme['type'] : '';

        return $type === 'http' ? 'http:'.$this->httpScheme($scheme) : $type;
    }

    /**
     * @param  array<string, mixed>  $scheme
     */
    private function httpScheme(array $scheme): string
    {
        return strtolower(is_string($scheme['scheme'] ?? null) ? $scheme['scheme'] : '');
    }

    /**
     * @param  array<string, mixed>  $scheme
     * @return array<string, mixed>|null
     */
    private function http(string $key, array $scheme): ?array
    {
        return match ($this->httpScheme($scheme)) {
            'bearer' => ['type' => 'bearer', 'bearer' => [$this->entry('token', '{{'.$key.'}}')]],
            'basic' => ['type' => 'basic', 'basic' => [
                $this->entry('username', '{{'.$key.'Username}}'),
                $this->entry('password', '{{'.$key.'Password}}'),
            ]],
            'digest' => ['type' => 'digest', 'digest' => [
                $this->entry('username', '{{'.$key.'Username}}'),
                $this->entry('password', '{{'.$key.'Password}}'),
            ]],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $scheme
     * @return array<string, mixed>|null
     */
    private function apiKey(string $key, array $scheme): ?array
    {
        $in = is_string($scheme['in'] ?? null) ? $scheme['in'] : 'header';
        if (! in_array($in, ['header', 'query'], true)) {
            return null;
        }

        return ['type' => 'apikey', 'apikey' => [
            $this->entry('key', is_string($scheme['name'] ?? null) ? $scheme['name'] : $key),
            $this->entry('value', '{{'.$key.'}}'),
            $this->entry('in', $in),
        ]];
    }

    /**
     * @param  array<string, mixed>  $scheme
     * @param  list<string>  $scopes
     * @return array<string, mixed>|null
     */
    private function oauth2(string $key, array $scheme, array $scopes): ?array
    {
        $flows = Arr::stringKeyed(is_array($scheme['flows'] ?? null) ? $scheme['flows'] : []);

        $flow = null;
        $name = null;
        foreach (self::FLOW_PREFERENCE as $candidate) {
            if (is_array($flows[$candidate] ?? null)) {
                $name = $candidate;
                $flow = Arr::stringKeyed($flows[$candidate]);
                break;
            }
        }

        if ($name === null || $flow === null) {
            return null;
        }
        $declared = is_array($flow['scopes'] ?? null) ? array_map(strval(...), array_keys($flow['scopes'])) : [];
        $granted = $scopes === [] ? $declared : $scopes;
        sort($granted, SORT_STRING);

        $auth = [
            $this->entry('grant_type', $name),
            $this->entry('clientId', '{{'.$key.'ClientId}}'),
            $this->entry('clientSecret', '{{'.$key.'ClientSecret}}'),
            $this->entry('addTokenTo', 'header'),
        ];

        foreach (['authorizationUrl' => 'authUrl', 'tokenUrl' => 'accessTokenUrl', 'refreshUrl' => 'refreshTokenUrl'] as $from => $to) {
            if (is_string($flow[$from] ?? null) && $flow[$from] !== '') {
                $auth[] = $this->entry($to, $flow[$from]);
            }
        }

        if ($granted !== []) {
            $auth[] = $this->entry('scope', implode(' ', $granted));
        }

        return ['type' => 'oauth2', 'oauth2' => $auth];
    }

    /**
     * @return array<string, string>
     */
    private function entry(string $key, string $value): array
    {
        return ['key' => $key, 'value' => $value, 'type' => 'string'];
    }
}
