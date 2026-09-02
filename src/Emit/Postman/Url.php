<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit\Postman;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Emit\SchemaExampleFactory;
use Docuccino\Core\Emit\ServerVariables;
use Docuccino\Core\Support\Arr;
use Docuccino\Core\Support\Hydrate;

/**
 * Builds Postman's split `url` object, the request headers, and the collection variables the servers
 * imply.
 *
 * `raw` is rebuilt from the very arrays that produced `host`/`path`/`query`, so the two halves of a
 * Postman URL can never disagree.
 *
 * @internal
 */
final readonly class Url
{
    private const string BASE = 'baseUrl';

    public function __construct(
        private SchemaExampleFactory $examples = new SchemaExampleFactory,
    ) {}

    /**
     * Bound to a document's configured format samples, so a variable's value illustrates a `format` the
     * way the rest of the document does. `$this` when they change nothing.
     *
     * @param  array<string, string>  $samples
     */
    public function withFormatSamples(array $samples): self
    {
        $examples = $this->examples->withFormatSamples($samples);

        return $examples === $this->examples ? $this : new self($examples);
    }

    /**
     * Path-item and operation parameters merged, the operation winning on `(in, name)` as OAS
     * requires, then ordered `in`-rank first so path variables read before query strings.
     *
     * @param  list<mixed>  $shared
     * @param  list<mixed>  $own
     * @param  array<string, mixed>  $components
     * @return list<array<string, mixed>>
     */
    public function merge(array $shared, array $own, array $components): array
    {
        $merged = [];

        foreach ([...$shared, ...$own] as $written) {
            if (! is_array($written)) {
                continue;
            }

            [$parameter, , $unresolved] = Ref::follow(Arr::stringKeyed($written), $components);

            // A reference landing nowhere says nothing about where a value goes, so the parameter is
            // dropped: a query string named after a pointer would be worse than its absence.
            if ($unresolved !== null) {
                continue;
            }

            $name = $parameter['name'] ?? null;
            $in = $parameter['in'] ?? null;

            if (is_string($name) && is_string($in)) {
                $merged[$in.':'.$name] = $parameter;
            }
        }

        return array_values($merged);
    }

    /**
     * @param  list<array<string, mixed>>  $parameters
     * @param  array<string, mixed>  $components
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    public function url(string $template, array $parameters, array $components, string $signature, array &$diagnostics): array
    {
        $segments = $this->segments($template, $signature, $diagnostics);
        $variables = $this->pathVariables($template, $parameters, $components);
        $query = $this->query($parameters, $components);

        $url = [
            'raw' => $this->raw($segments, $query),
            'host' => ['{{'.self::BASE.'}}'],
            'path' => $segments,
        ];

        if ($query !== []) {
            $url['query'] = $query;
        }

        if ($variables !== []) {
            $url['variable'] = $variables;
        }

        return $url;
    }

    /**
     * `{id}` becomes Postman's `:id`. A template that only covers PART of a segment is left literal:
     * Postman matches `:name` to a whole segment, so `/files/{name}.json` would otherwise swallow the
     * extension.
     *
     * @param  list<Diagnostic>  $diagnostics
     * @return list<string>
     */
    private function segments(string $template, string $signature, array &$diagnostics): array
    {
        $segments = [];

        foreach (explode('/', ltrim($template, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }

            if (preg_match('/^\{([^{}]+)\}$/', $segment, $matches) === 1) {
                $segments[] = ':'.$matches[1];

                continue;
            }

            if (str_contains($segment, '{')) {
                $diagnostics[] = new Diagnostic(
                    severity: Severity::Warning,
                    code: 'postman.path-template-partial',
                    message: sprintf(
                        'The `%s` segment templates only part of itself, and a Postman path variable stands for a whole segment — it is left literal, so the URL needs editing before the request will run.',
                        $segment,
                    ),
                    routeSignature: $signature,
                );
            }

            $segments[] = $segment;
        }

        return $segments;
    }

    /**
     * @param  list<array<string, mixed>>  $parameters
     * @param  array<string, mixed>  $components
     * @return list<array<string, mixed>>
     */
    private function pathVariables(string $template, array $parameters, array $components): array
    {
        $declared = [];
        foreach ($parameters as $parameter) {
            if (($parameter['in'] ?? null) === 'path' && is_string($parameter['name'] ?? null)) {
                $declared[$parameter['name']] = $parameter;
            }
        }

        // Template order first — the order a reader scans the URL — then anything declared but absent
        // from the template, sorted by name so the tail never depends on declaration order.
        $ordered = [];
        if (preg_match_all('/\{([^{}]+)\}/', $template, $matches) !== false) {
            foreach ($matches[1] as $name) {
                if (isset($declared[$name])) {
                    $ordered[$name] = $declared[$name];
                }
            }
        }

        $remaining = array_diff_key($declared, $ordered);
        ksort($remaining, SORT_STRING);

        $out = [];
        foreach ([...array_values($ordered), ...array_values($remaining)] as $parameter) {
            $entry = ['key' => self::nameOf($parameter), 'value' => $this->sample($parameter, $components)];

            $description = $this->describe($parameter);
            if ($description !== '') {
                $entry['description'] = $description;
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Query entries, sorted by name so they read the same way the OpenAPI document does. An optional
     * parameter arrives disabled, so a consumer can press Send and have the request work.
     *
     * @param  list<array<string, mixed>>  $parameters
     * @param  array<string, mixed>  $components
     * @return list<array<string, mixed>>
     */
    private function query(array $parameters, array $components): array
    {
        $query = array_values(array_filter($parameters, static fn (array $p): bool => ($p['in'] ?? null) === 'query'));
        usort($query, static fn (array $a, array $b): int => strcmp(self::nameOf($a), self::nameOf($b)));

        $out = [];
        foreach ($query as $parameter) {
            foreach ($this->expand($parameter, $components) as $entry) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * One query parameter as one or more entries. A `deepObject` names each declared property, which
     * is what the consumer actually types; everything else is one entry.
     *
     * @param  array<string, mixed>  $parameter
     * @param  array<string, mixed>  $components
     * @return list<array<string, mixed>>
     */
    private function expand(array $parameter, array $components): array
    {
        $name = self::nameOf($parameter);
        $required = ($parameter['required'] ?? false) === true;
        $schema = self::schemaOf($parameter);

        // A parameter whose schema admits no value is not one the consumer may send, so it gets no
        // entry at all rather than an empty one they would try to fill in.
        if ($this->examples->member($parameter['schema'] ?? null, $components) === null) {
            return [];
        }

        if (($parameter['style'] ?? null) === 'deepObject') {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            $keys = array_map(strval(...), array_keys($properties));
            sort($keys, SORT_STRING);

            if ($keys === []) {
                return [$this->entry(
                    $name.'[key]',
                    '',
                    false,
                    sprintf('Repeat with each filterable field name, e.g. `%s[status]=draft`.', $name),
                )];
            }

            $out = [];
            foreach ($keys as $key) {
                $member = $this->examples->member($properties[$key] ?? null, $components);

                // A member the schema forbids is not a key the consumer may type.
                if ($member === null) {
                    continue;
                }

                $property = Arr::stringKeyed(is_array($properties[$key] ?? null) ? $properties[$key] : []);
                $out[] = $this->entry(
                    $name.'['.$key.']',
                    Value::text($member[0]),
                    false,
                    $this->describe(['schema' => $property] + ['description' => $property['description'] ?? null]),
                );
            }

            return $out;
        }

        return [$this->entry($name, $this->sample($parameter, $components), $required, $this->describe($parameter))];
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $key, string $value, bool $required, string $description): array
    {
        $entry = ['key' => $key, 'value' => $value];

        if (! $required) {
            $entry['disabled'] = true;
        }

        if ($description !== '') {
            $entry['description'] = $description;
        }

        return $entry;
    }

    /**
     * Header parameters, plus the two a consumer always forgets: the body's `Content-Type` and an
     * `Accept` naming what the operation returns.
     *
     * @param  list<array<string, mixed>>  $parameters
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $components
     * @return list<array<string, mixed>>
     */
    public function headers(array $parameters, array $operation, ?string $contentType, array $components): array
    {
        $headers = [];

        if ($contentType !== null) {
            $headers[] = ['key' => 'Content-Type', 'value' => $contentType];
        }

        $accept = $this->accept($operation, $components);
        if ($accept !== null) {
            $headers[] = ['key' => 'Accept', 'value' => $accept];
        }

        $declared = array_values(array_filter($parameters, static fn (array $p): bool => ($p['in'] ?? null) === 'header'));
        usort($declared, static fn (array $a, array $b): int => strcmp(self::nameOf($a), self::nameOf($b)));

        foreach ($declared as $parameter) {
            $headers[] = $this->entry(
                self::nameOf($parameter),
                $this->sample($parameter, $components),
                ($parameter['required'] ?? false) === true,
                $this->describe($parameter),
            );
        }

        // Postman has no cookie-jar member on a request, so declared cookies travel as one header —
        // which is exactly what the wire carries anyway, so nothing is lost.
        $cookies = array_values(array_filter($parameters, static fn (array $p): bool => ($p['in'] ?? null) === 'cookie'));
        usort($cookies, static fn (array $a, array $b): int => strcmp(self::nameOf($a), self::nameOf($b)));

        if ($cookies !== []) {
            $pairs = array_map(
                fn (array $p): string => sprintf('%s=%s', self::nameOf($p), $this->sample($p, $components)),
                $cookies,
            );

            $headers[] = $this->entry(
                'Cookie',
                implode('; ', $pairs),
                array_any($cookies, static fn (array $p): bool => ($p['required'] ?? false) === true),
                '',
            );
        }

        return $headers;
    }

    /**
     * What the operation says it returns, from the lowest 2xx that declares any media type. The
     * response is `$ref` followed first: a shape shared into `components.responses` describes the same
     * payload as one written inline, and a request that carried an `Accept` only when the document
     * happened not to share it would content-negotiate differently for the same endpoint.
     *
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $components
     */
    private function accept(array $operation, array $components): ?string
    {
        $responses = Arr::stringKeyed(is_array($operation['responses'] ?? null) ? $operation['responses'] : []);

        $codes = array_values(array_filter(
            array_map(strval(...), array_keys($responses)),
            static fn (string $c): bool => ctype_digit($c) && $c[0] === '2',
        ));
        sort($codes, SORT_STRING);

        foreach ($codes as $code) {
            $written = Arr::stringKeyed(is_array($responses[$code] ?? null) ? $responses[$code] : []);
            [$response, , $unresolved] = Ref::follow($written, $components);

            $content = $unresolved === null && is_array($response['content'] ?? null) ? $response['content'] : [];
            $types = array_map(strval(...), array_keys($content));

            if ($types !== []) {
                return Body::preferred($types, ['application/json']);
            }
        }

        return null;
    }

    /**
     * The collection variables: `baseUrl` from the first server, plus one per server variable so
     * switching tenant or version is a single edit rather than a search-and-replace.
     *
     * @param  list<mixed>  $servers
     * @param  array<string, mixed>  $components
     * @param  list<Diagnostic>  $diagnostics
     * @return list<array<string, mixed>>
     */
    public function variables(array $servers, array $components, array &$diagnostics): array
    {
        $server = is_array($servers[0] ?? null) ? Arr::stringKeyed($servers[0]) : null;

        if ($server === null) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'postman.no-server',
                message: 'The document declares no servers, so `baseUrl` is empty — every request needs a host filling in before it will run.',
            );

            return [[...$this->variable(self::BASE, ''), 'description' => 'The API base URL.']];
        }

        $url = is_string($server['url'] ?? null) ? $server['url'] : '';
        $variables = [$this->variable(self::BASE, preg_replace('/\{([^{}]+)\}/', '{{$1}}', $url) ?? $url)];

        foreach ($this->serverVariables($server, $diagnostics) as $variable) {
            $variables[] = $variable;
        }

        foreach ($this->credentials($components) as $credential) {
            $variables[] = $credential;
        }

        return $variables;
    }

    /**
     * @param  array<string, mixed>  $server
     * @param  list<Diagnostic>  $diagnostics
     * @return list<array<string, mixed>>
     */
    private function serverVariables(array $server, array &$diagnostics): array
    {
        $declared = Arr::stringKeyed(is_array($server['variables'] ?? null) ? $server['variables'] : []);

        $names = array_map(strval(...), array_keys($declared));
        sort($names, SORT_STRING);

        $out = [];
        foreach ($names as $name) {
            if ($name === self::BASE) {
                $diagnostics[] = new Diagnostic(
                    severity: Severity::Warning,
                    code: 'postman.variable-name-collision',
                    message: sprintf('A server variable is named `%s`, which the collection already uses for the base URL; it is not published as a variable of its own.', self::BASE),
                );

                continue;
            }

            $variable = Arr::stringKeyed(is_array($declared[$name] ?? null) ? $declared[$name] : []);
            $default = ServerVariables::resolve($variable);

            if (! ServerVariables::declaresDefault($variable)) {
                // The same fact the OpenAPI emitters report, at the same code: a collection cannot leave
                // the variable out the way a document can, so it is published blank instead.
                $diagnostics[] = ServerVariables::noDefault(
                    $name,
                    $variable,
                    'it declares no `enum` to take one from either, so the collection publishes it blank for you to fill in.',
                );
            }

            $entry = $this->variable($name, $default ?? '');
            $description = is_string($variable['description'] ?? null) ? $variable['description'] : '';
            if ($description !== '') {
                $entry['description'] = $description;
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * One empty variable per credential a security scheme needs, named from the scheme's own key.
     *
     * @param  array<string, mixed>  $components
     * @return list<array<string, mixed>>
     */
    private function credentials(array $components): array
    {
        $schemes = Arr::stringKeyed(is_array($components['securitySchemes'] ?? null) ? $components['securitySchemes'] : []);

        $names = array_map(strval(...), array_keys($schemes));
        sort($names, SORT_STRING);

        $out = [];
        foreach ($names as $name) {
            foreach (Description::credentials($name, Arr::stringKeyed(is_array($schemes[$name] ?? null) ? $schemes[$name] : [])) as $key => $description) {
                $out[] = [...$this->variable($key, ''), 'description' => $description];
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function variable(string $key, string $value): array
    {
        return ['key' => $key, 'value' => $value, 'type' => 'string'];
    }

    /**
     * @param  list<string>  $segments
     * @param  list<array<string, mixed>>  $query
     */
    private function raw(array $segments, array $query): string
    {
        $raw = '{{'.self::BASE.'}}'.($segments === [] ? '/' : '/'.implode('/', $segments));

        $enabled = array_values(array_filter($query, static fn (array $q): bool => ($q['disabled'] ?? false) !== true));
        if ($enabled === []) {
            return $raw;
        }

        $pairs = array_map(
            static fn (array $q): string => sprintf('%s=%s', Hydrate::stringOrNull($q['key'] ?? null) ?? '', Hydrate::stringOrNull($q['value'] ?? null) ?? ''),
            $enabled,
        );

        return $raw.'?'.implode('&', $pairs);
    }

    /**
     * @param  array<string, mixed>  $parameter
     * @param  array<string, mixed>  $components
     */
    private function sample(array $parameter, array $components): string
    {
        // A Parameter Object illustrates itself the same way a media type does, beside its schema rather
        // than inside it, and what the author wrote there beats anything derived from the shape.
        $stated = $this->examples->illustration($parameter);

        return Value::text($stated === null
            ? $this->examples->value(self::schemaOf($parameter), $components)
            : $stated[0]);
    }

    /**
     * The parameter's own schema, taken as written: a `$ref` here would need no resolving, since a
     * deepObject's properties are always inline and everything else hands the schema on to
     * {@see SchemaExampleFactory}, which resolves against the components itself.
     *
     * @param  array<string, mixed>  $parameter
     * @return array<string, mixed>
     */
    private static function schemaOf(array $parameter): array
    {
        $schema = $parameter['schema'] ?? null;

        return is_array($schema) ? Arr::stringKeyed($schema) : [];
    }

    /**
     * A parameter's declared name, or '' when it has none worth reading.
     *
     * @param  array<string, mixed>  $parameter
     */
    private static function nameOf(array $parameter): string
    {
        return Hydrate::stringOrNull($parameter['name'] ?? null) ?? '';
    }

    /**
     * @param  array<string, mixed>  $parameter
     */
    private function describe(array $parameter): string
    {
        return Description::parameter($parameter);
    }
}
