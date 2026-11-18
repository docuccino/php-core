<?php

declare(strict_types=1);

namespace Docuccino\Core\Links;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\DocumentGraph;
use Docuccino\Core\Document\ResponsePointer;
use Docuccino\Core\Provenance\ProvenanceRecord;

/**
 * Writes the links the code proves as OpenAPI response `links`, over a finished document and the facts
 * each operation recorded ({@see OperationLinks}), keyed by `METHOD /path` as the document names it.
 *
 * A derived link joins a response that gives a resource's column to an operation whose every path
 * parameter takes one: from the body where the response carries it, else from a segment of the request
 * the two operations share — the same column at the same place in both paths. At least one comes from the
 * body, or the link says nothing a client did not already send. A scoped child is filled from the body
 * only by an operation on its own collection or its own path, so the parent it must belong to is the one
 * in the request. Nothing is matched by name, and a target whose operationId is not the document's only
 * one is not linked, since that is all a link can name it by. Nor is an `x-internal` target from a public
 * source: filters cut the target from public output and would leave the link pointing at nothing. A
 * DELETE leads nowhere: the record it gives back no longer resolves. One
 * response carries at most {@see MOST_DERIVED} derived links, the rest reported.
 *
 * A declared link is checked against the document and reported where it cannot be written: an operation
 * the document does not publish, a status it does not document, a parameter the target does not declare,
 * a `$response.body` pointer the response contradicts. It outranks a derived link of the same name, and a
 * link the document already carries — an overlay's — outranks both.
 *
 * @internal
 */
final class ResponseLinks
{
    private const string BODY = '$response.body#';

    /** `GET /path`, as a declaration names an operation by its signature. */
    private const string SIGNATURE = '/^([A-Za-z]+)\s+(\S+)$/';

    private const string QUALIFIED = '/^(path|query|header|cookie)\.(.+)$/';

    /**
     * The most derived links one response carries. A model's own routes are four (show, put, patch,
     * destroy) and each collection under it adds two, so this covers a model with twenty-odd collections
     * and actions of its own, past what a reader follows from one response. Without it, N operations
     * sharing one model write N² links; with it, at most this many times N.
     */
    private const int MOST_DERIVED = 50;

    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, OperationLinks>  $facts  `METHOD /path` → that operation's facts
     * @return array{0: array<string, mixed>, 1: list<Diagnostic>}
     */
    public static function write(array $doc, array $facts): array
    {
        ksort($facts, SORT_STRING);

        $sites = self::sites($doc);
        $diagnostics = [];
        /** @var array<string, array<string, array<string, array<string, mixed>>>> $links signature → status → name → link */
        $links = [];

        foreach ($facts as $signature => $operation) {
            $site = $sites[$signature] ?? null;
            if ($site === null) {
                // Another document's operation: nothing here to write on or to report.
                continue;
            }

            foreach (self::derived($signature, $site, $operation, $facts, $sites, $doc) as $status => $byName) {
                if (count($byName) > self::MOST_DERIVED) {
                    ksort($byName, SORT_STRING);
                    $diagnostics[] = self::truncated($signature, (string) $status, count($byName));
                    $byName = array_slice($byName, 0, self::MOST_DERIVED, true);
                }

                foreach ($byName as $name => $link) {
                    $links[$signature][$status][$name] = $link;
                }
            }

            foreach ($operation->unreadable as $reason) {
                $diagnostics[] = self::unreadable($signature, $reason);
            }

            foreach ($operation->declared as $declared) {
                [$status, $name, $link, $problem] = self::declared($signature, $site, $declared, $sites, $doc);
                if ($problem !== null) {
                    $diagnostics[] = $problem;
                } elseif ($status !== null && $name !== null && $link !== null) {
                    $links[$signature][$status][$name] = $link;
                }
            }
        }

        foreach ($links as $signature => $byStatus) {
            $doc = self::place($doc, $sites[$signature]['keys'], $byStatus);
        }

        return [$doc, $diagnostics];
    }

    /**
     * The links one operation's responses earn from what they give.
     *
     * @param  array{keys: list<string>, path: string, operationId: string|null, operation: array<string, mixed>}  $site
     * @param  array<string, OperationLinks>  $facts
     * @param  array<string, array{keys: list<string>, path: string, operationId: string|null, operation: array<string, mixed>}>  $sites
     * @param  array<string, mixed>  $doc
     * @return array<string, array<string, array<string, mixed>>> status → name → link
     */
    private static function derived(string $signature, array $site, OperationLinks $source, array $facts, array $sites, array $doc): array
    {
        // What a DELETE hands back is gone: every route that would look it up answers 404.
        if (str_starts_with($signature, 'DELETE ')) {
            return [];
        }

        $ids = self::uniqueOperationIds($sites);
        $internal = self::internal($site['operation']);
        $out = [];

        foreach (self::givesByStatus($source->gives, $site['operation'], $doc) as $status => $gives) {
            foreach ($facts as $targetSignature => $target) {
                $targetSite = $sites[$targetSignature] ?? null;
                $operationId = $targetSite['operationId'] ?? null;
                if ($targetSignature === $signature || $targetSite === null || $operationId === null || ! isset($ids[$operationId])) {
                    continue;
                }

                // An internal operation is cut from public output, so a public one never leads there unasked.
                if (! $internal && self::internal($targetSite['operation'])) {
                    continue;
                }

                $parameters = self::filled($site['path'], $source->takes, $gives, $targetSite['path'], $target->takes);
                if ($parameters !== null) {
                    $out[$status][$operationId] = [
                        'operationId' => $operationId,
                        'parameters' => self::located($parameters, self::declaredParameters($targetSite['keys'], $targetSite['operation'], $doc)),
                        'x-docuccino' => ['provenance' => [(new ProvenanceRecord('inference', 'inference'))->toArray()]],
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * Each documented success status's gives, token → pointer. A token given at two places is dropped —
     * a link names one — and so is one whose pointer the published response contradicts, which is what a
     * later transformer renaming the member leaves behind.
     *
     * @param  list<BodyGive>  $gives
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $doc
     * @return array<string, array<string, string>>
     */
    private static function givesByStatus(array $gives, array $operation, array $doc): array
    {
        $byStatus = [];
        $contested = [];

        foreach ($gives as $give) {
            $response = self::response($operation, $give->status);
            if ($response === null || ResponsePointer::undocumentedIn($response, $doc, self::BODY.$give->pointer) !== null) {
                continue;
            }

            $held = $byStatus[$give->status][$give->token()] ?? null;
            if ($held !== null && $held !== $give->pointer) {
                $contested[$give->status][$give->token()] = true;
            }
            $byStatus[$give->status][$give->token()] = $give->pointer;
        }

        foreach ($contested as $status => $tokens) {
            $byStatus[$status] = array_diff_key($byStatus[$status], $tokens);
        }

        ksort($byStatus, SORT_STRING);

        return array_filter($byStatus, static fn (array $tokens): bool => $tokens !== []);
    }

    /**
     * The target's parameters, each filled from the body or from the source's request, or null where one
     * of them cannot be filled from what the code proves.
     *
     * @param  array<string, BindingTake>  $sourceTakes
     * @param  array<string, string>  $gives  token → pointer
     * @param  array<string, BindingTake>  $targetTakes
     * @return array<string, string>|null
     */
    private static function filled(string $sourcePath, array $sourceTakes, array $gives, string $targetPath, array $targetTakes): ?array
    {
        $names = self::pathParameters($targetPath);
        if ($names === []) {
            return null;
        }

        $tokens = [];
        foreach ($names as $name) {
            $take = $targetTakes[$name] ?? null;
            if ($take === null) {
                return null;
            }
            $tokens[$name] = $take->token();
        }

        // Two segments taking the same column of the same resource: a body carrying one value cannot say
        // which of them it is.
        if (count(array_unique($tokens)) !== count($tokens)) {
            return null;
        }

        $parameters = [];
        $fromBody = false;
        $sourceShape = self::shape($sourcePath, $sourceTakes);
        $targetShape = self::shape($targetPath, $targetTakes);

        foreach ($names as $name) {
            $take = $targetTakes[$name];
            $pointer = $gives[$take->token()] ?? null;

            if ($pointer !== null) {
                if ($take->scopedTo !== null && ! self::ownCollection($sourceShape, $targetShape, $name)) {
                    return null;
                }

                $parameters[$name] = self::BODY.$pointer;
                $fromBody = true;

                continue;
            }

            $shared = self::sharedSegment($sourceShape, $targetShape, $name);
            if ($shared === null) {
                return null;
            }

            $parameters[$name] = '$request.path.'.$shared;
        }

        return $fromBody ? $parameters : null;
    }

    /**
     * A path as its segments, a parameter spelled by the column it takes so two paths naming the same
     * parameter differently still read alike — and one taking nothing proven kept apart from everything.
     *
     * @param  array<string, BindingTake>  $takes
     * @return list<array{0: string, 1: string|null}> [what the segment is, the parameter it names]
     */
    private static function shape(string $path, array $takes): array
    {
        $segments = [];

        foreach (explode('/', trim($path, '/')) as $segment) {
            if (preg_match('/^\{([^}]+)\}$/', $segment, $match) === 1) {
                $take = $takes[$match[1]] ?? null;
                $segments[] = [$take === null ? "\0unproven" : "\0".$take->token(), $match[1]];
            } else {
                $segments[] = [$segment, null];
            }
        }

        return $segments;
    }

    /**
     * The source's parameter that fills the target's `$name`: one at the same depth whose path up to and
     * including it reads the same as the target's — the same resource, reached the same way.
     *
     * @param  list<array{0: string, 1: string|null}>  $source
     * @param  list<array{0: string, 1: string|null}>  $target
     */
    private static function sharedSegment(array $source, array $target, string $name): ?string
    {
        foreach ($target as $index => [, $parameter]) {
            if ($parameter !== $name) {
                continue;
            }

            $prefix = array_column(array_slice($target, 0, $index + 1), 0);
            $theirs = array_slice($source, 0, $index + 1);

            if (count($theirs) === $index + 1 && array_column($theirs, 0) === $prefix) {
                return $theirs[$index][1];
            }
        }

        return null;
    }

    /**
     * Whether the source is the target's own path, or the collection the target's `$name` is a member of
     * — the two operations whose response is the scoped child of the parent in their request.
     *
     * @param  list<array{0: string, 1: string|null}>  $source
     * @param  list<array{0: string, 1: string|null}>  $target
     */
    private static function ownCollection(array $source, array $target, string $name): bool
    {
        $sourceSegments = array_column($source, 0);
        $targetSegments = array_column($target, 0);

        if ($sourceSegments === $targetSegments) {
            return true;
        }

        foreach ($target as $index => [, $parameter]) {
            if ($parameter === $name) {
                return $sourceSegments === array_slice($targetSegments, 0, $index);
            }
        }

        return false;
    }

    /**
     * One declared link: its status, name and Link Object, or the diagnostic saying why it is not written.
     *
     * @param  array{keys: list<string>, path: string, operationId: string|null, operation: array<string, mixed>}  $site
     * @param  array<string, array{keys: list<string>, path: string, operationId: string|null, operation: array<string, mixed>}>  $sites
     * @param  array<string, mixed>  $doc
     * @return array{0: string|null, 1: string|null, 2: array<string, mixed>|null, 3: Diagnostic|null}
     */
    private static function declared(string $signature, array $site, DeclaredLink $declared, array $sites, array $doc): array
    {
        $target = self::target($declared->operation, $sites);
        $operationId = $target['operationId'] ?? null;
        if ($target === null || $operationId === null) {
            return [null, null, null, self::unknownOperation($signature, $declared)];
        }

        $status = $declared->status ?? self::firstSuccess($site['operation']);
        $response = $status === null ? null : self::response($site['operation'], $status);
        if ($status === null || $response === null) {
            return [null, null, null, self::unknownStatus($signature, $declared)];
        }

        $declares = self::declaredParameters($target['keys'], $target['operation'], $doc);
        foreach ($declared->parameters as $parameter => $value) {
            if (! self::declaresParameter($declares, (string) $parameter)) {
                return [null, null, null, self::parameterUndeclared($signature, $declared, (string) $parameter)];
            }

            // A bare name the target uses in two locations fills one of them, and nothing says which.
            $locations = self::locationsOf($declares, (string) $parameter);
            if (count($locations) > 1) {
                return [null, null, null, self::parameterAmbiguous($signature, $declared, (string) $parameter, $locations)];
            }

            if (is_string($value)) {
                $pointer = ResponsePointer::undocumentedIn($response, $doc, $value);
                if ($pointer !== null) {
                    return [null, null, null, self::pointerUndocumented($signature, $declared, $status, $pointer)];
                }
            }
        }

        $record = new ProvenanceRecord($declared->layer, $declared->layer, source: $declared->source);
        $link = ['operationId' => $operationId];
        if ($declared->parameters !== []) {
            $link['parameters'] = $declared->parameters;
        }
        if ($declared->description !== '') {
            $link['description'] = $declared->description;
        }
        $link['x-docuccino'] = ['provenance' => [$record->toArray()]];

        return [$status, $declared->name !== '' ? $declared->name : $operationId, $link, null];
    }

    /**
     * The operation a declaration names: by signature when it is spelled as one, else by operationId —
     * and only where that operationId names exactly one operation.
     *
     * @param  array<string, array{keys: list<string>, path: string, operationId: string|null, operation: array<string, mixed>}>  $sites
     * @return array{keys: list<string>, path: string, operationId: string|null, operation: array<string, mixed>}|null
     */
    private static function target(string $named, array $sites): ?array
    {
        $named = trim($named);

        if (preg_match(self::SIGNATURE, $named, $match) === 1) {
            return $sites[strtoupper($match[1]).' '.$match[2]] ?? null;
        }

        $found = array_values(array_filter($sites, static fn (array $site): bool => $site['operationId'] === $named));

        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * Every parameter the target declares, path-level ones included, as `in` → names.
     *
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $doc
     * @return array<string, array<string, true>>
     */
    private static function declaredParameters(array $keys, array $operation, array $doc): array
    {
        $item = DocumentGraph::at($doc, array_slice($keys, 0, -1));
        $lists = [is_array($item) ? ($item['parameters'] ?? null) : null, $operation['parameters'] ?? null];

        $declared = [];
        foreach ($lists as $list) {
            foreach (is_array($list) ? $list : [] as $parameter) {
                if (! is_array($parameter)) {
                    continue;
                }
                $ref = DocumentGraph::componentRef($parameter);
                $parameter = $ref === null ? $parameter : (DocumentGraph::componentBody($doc, $ref) ?? []);
                $in = $parameter['in'] ?? null;
                $name = $parameter['name'] ?? null;
                if (is_string($in) && is_string($name)) {
                    $declared[$in][$name] = true;
                }
            }
        }

        return $declared;
    }

    /**
     * Path parameters keyed as OpenAPI's Link Object reads them: by bare name, unless the target uses that
     * name in another location as well — a `page` in the path and a `page` in the query — where a bare key
     * names either and is qualified as `path.page`. Only those are, so a link whose names are unambiguous
     * keeps the key it has always published.
     *
     * @param  array<string, string>  $parameters  path parameter → value
     * @param  array<string, array<string, true>>  $declares
     * @return array<string, string>
     */
    private static function located(array $parameters, array $declares): array
    {
        $located = [];
        foreach ($parameters as $name => $value) {
            $located[count(self::locationsOf($declares, $name)) > 1 ? 'path.'.$name : $name] = $value;
        }

        return $located;
    }

    /**
     * The locations the target declares a parameter of this name in, sorted. A name qualified by its location
     * is no parameter's name, so it stands for none here and is never ambiguous.
     *
     * @param  array<string, array<string, true>>  $declares
     * @return list<string>
     */
    private static function locationsOf(array $declares, string $name): array
    {
        $locations = array_keys(array_filter($declares, static fn (array $names): bool => isset($names[$name])));
        sort($locations);

        return $locations;
    }

    /**
     * Whether `$parameter` — a bare name, or one qualified by its location as OpenAPI allows
     * (`path.id`) — is one the target declares.
     *
     * @param  array<string, array<string, true>>  $declares
     */
    private static function declaresParameter(array $declares, string $parameter): bool
    {
        if (preg_match(self::QUALIFIED, $parameter, $match) === 1 && isset($declares[$match[1]][$match[2]])) {
            return true;
        }

        foreach ($declares as $names) {
            if (isset($names[$parameter])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The links written onto each response, beside any the document already carries — which keep their
     * names, since what is written there was written by a higher layer than anything here.
     *
     * @param  array<string, mixed>  $doc
     * @param  list<string>  $keys
     * @param  array<string, array<string, array<string, mixed>>>  $byStatus
     * @return array<string, mixed>
     */
    private static function place(array $doc, array $keys, array $byStatus): array
    {
        foreach ($byStatus as $status => $links) {
            $at = [...$keys, 'responses', (string) $status];
            $response = DocumentGraph::at($doc, $at);
            if (! is_array($response) || isset($response['$ref'])) {
                continue;
            }

            $existing = is_array($response['links'] ?? null) ? $response['links'] : [];
            ksort($links, SORT_STRING);
            $response['links'] = $existing + $links;

            /** @var array<string, mixed> $doc */
            $doc = DocumentGraph::with($doc, $at, $response);
        }

        return $doc;
    }

    /**
     * Every operation under `paths`, by the signature the document names it with.
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, array{keys: list<string>, path: string, operationId: string|null, operation: array<string, mixed>}>
     */
    private static function sites(array $doc): array
    {
        $sites = [];

        foreach (DocumentGraph::operationSites($doc) as $site) {
            $operation = DocumentGraph::at($doc, $site['keys']);
            if ($site['signature'] === null || ! is_array($operation)) {
                continue;
            }

            /** @var array<string, mixed> $operation */
            $sites[$site['signature']] = [
                'keys' => $site['keys'],
                'path' => substr($site['signature'], strpos($site['signature'], ' ') + 1),
                'operationId' => $site['operationId'],
                'operation' => $operation,
            ];
        }

        return $sites;
    }

    /**
     * Whether an operation is flagged `x-internal`, the mark public filters cut it from the document by.
     *
     * @param  array<string, mixed>  $operation
     */
    private static function internal(array $operation): bool
    {
        return ($operation['x-internal'] ?? false) === true;
    }

    /**
     * The operationIds exactly one operation carries.
     *
     * @param  array<string, array{keys: list<string>, path: string, operationId: string|null, operation: array<string, mixed>}>  $sites
     * @return array<string, true>
     */
    private static function uniqueOperationIds(array $sites): array
    {
        $counts = array_count_values(array_filter(array_column($sites, 'operationId'), 'is_string'));

        return array_map(static fn (): bool => true, array_filter($counts, static fn (int $count): bool => $count === 1));
    }

    /**
     * @return list<string>
     */
    private static function pathParameters(string $path): array
    {
        preg_match_all('/\{([^}]+)\}/', $path, $matches);

        return $matches[1];
    }

    /**
     * A documented success response as written on the operation, or null — a status it does not
     * document, or a `$ref` to a shared one, which a link on this operation must not be written into.
     *
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>|null
     */
    private static function response(array $operation, string $status): ?array
    {
        if (preg_match('/^2\d\d$/', $status) !== 1) {
            return null;
        }

        $response = is_array($operation['responses'] ?? null) ? ($operation['responses'][$status] ?? null) : null;

        /** @var array<string, mixed>|null $response */
        return is_array($response) && ! isset($response['$ref']) ? $response : null;
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private static function firstSuccess(array $operation): ?string
    {
        $statuses = array_map(strval(...), array_keys(is_array($operation['responses'] ?? null) ? $operation['responses'] : []));
        $statuses = array_values(array_filter($statuses, static fn (string $status): bool => preg_match('/^2\d\d$/', $status) === 1));
        sort($statuses, SORT_STRING);

        return $statuses[0] ?? null;
    }

    private static function unknownOperation(string $signature, DeclaredLink $declared): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'response-link.unknown-operation',
            message: sprintf('A declared link leads to "%s", which names no operation this document publishes, so the link was left out.', $declared->operation),
            source: $declared->source,
            routeSignature: $signature,
            help: 'Name the operation by its operationId or as the document writes it, `GET /api/things/{thing}`. A link leads to an operation in the same document.',
        );
    }

    private static function unknownStatus(string $signature, DeclaredLink $declared): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'response-link.unknown-status',
            message: $declared->status === null
                ? sprintf('A declared link to "%s" has no success response to sit on, so it was left out.', $declared->operation)
                : sprintf('A declared link to "%s" sits on the %s response, which this operation does not document as a success, so the link was left out.', $declared->operation, $declared->status),
            source: $declared->source,
            routeSignature: $signature,
            help: 'A link sits on a 2xx response the operation documents. Leave `status` off to use its first one, or name one it has.',
        );
    }

    private static function parameterUndeclared(string $signature, DeclaredLink $declared, string $parameter): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'response-link.parameter-undeclared',
            message: sprintf('A declared link to "%s" fills a parameter "%s" that operation does not declare, so the link was left out.', $declared->operation, $parameter),
            source: $declared->source,
            routeSignature: $signature,
            help: 'Name the parameter the way the target operation declares it, or qualify it with its location (`path.id`).',
        );
    }

    /**
     * @param  list<string>  $locations
     */
    private static function parameterAmbiguous(string $signature, DeclaredLink $declared, string $parameter, array $locations): Diagnostic
    {
        $qualified = array_map(static fn (string $in): string => '`'.$in.'.'.$parameter.'`', $locations);

        return new Diagnostic(
            severity: Severity::Warning,
            code: 'response-link.parameter-ambiguous',
            message: sprintf('A declared link to "%s" fills "%s", which that operation declares in more than one location (%s), so a client could not tell which and the link was left out.', $declared->operation, $parameter, implode(', ', $locations)),
            source: $declared->source,
            routeSignature: $signature,
            help: sprintf('Qualify the parameter with its location: %s.', implode(' or ', $qualified)),
        );
    }

    private static function pointerUndocumented(string $signature, DeclaredLink $declared, string $status, string $pointer): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'response-link.pointer-undocumented',
            message: sprintf('A declared link to "%s" reads %s from the %s response body, which that response does not document, so the link was left out.', $declared->operation, $pointer, $status),
            source: $declared->source,
            routeSignature: $signature,
            help: 'Point at a member the response schema describes, or document the member.',
        );
    }

    private static function truncated(string $signature, string $status, int $proved): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Info,
            code: 'response-link.truncated',
            message: sprintf('The %s response leads to %d operations by links the code proves, more than the %d one response carries, so the first %d by name were written.', $status, $proved, self::MOST_DERIVED, self::MOST_DERIVED),
            routeSignature: $signature,
            help: 'Nothing, unless one you want was left out: declare it with #[ResponseLink], which is always written.',
        );
    }

    private static function unreadable(string $signature, string $reason): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'response-link.unreadable',
            message: sprintf('A declared link holds a value JSON cannot carry (%s), so this operation\'s declared links were left out.', $reason),
            routeSignature: $signature,
            help: 'Write the value the wire carries: scalars, arrays of them, and runtime expressions. A pure enum case is the usual cause; use its backing value.',
        );
    }
}
