<?php

declare(strict_types=1);

namespace Docuccino\Core\Pipeline;

use Docuccino\Core\Contract\Refs;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\IgnoredHeaders;
use Docuccino\Core\Lint\LintOperation;
use Docuccino\Core\Support\Arr;

/**
 * Tells the author about a header declaration OAS says every reader SHALL ignore
 * ({@see IgnoredHeaders}), because it is the one thing the document carries that says nothing.
 *
 * The declaration stays in the document. Deleting what an author explicitly wrote, in the artifact
 * they cannot watch us build, would substitute our reading of their intent for theirs — and a
 * conforming consumer is already told to ignore it, so carrying it misleads nobody. What the author
 * gets instead is this, which is where guidance for them belongs; every reader here that ACTS on the
 * rule — the Postman emitter, the contract checker — reads it from one place so they cannot disagree
 * about whether a parameter was declared at all.
 *
 * The unit of reporting is the SITE a declaration is written at, never the operation that reaches it.
 * One `components.parameters` entry pointed at from four hundred operations is one thing the author
 * wrote and one thing they can change, so it earns one diagnostic naming the component; four hundred
 * copies of it would be four hundred hits with one action between them, which is how a channel stops
 * being read. Path items and inline declarations were always their own site; a `$ref` is now one too.
 *
 * The severity is the difference between prose lost and a fact lost. Where the operation publishes
 * the same fact in the member OAS reserves for it — a request body's media type, a response's media
 * type, a security requirement — the declaration was redundant and only its wording is gone. Where it
 * does not, the document now says nothing about a header the author believes it describes, and a
 * generated client has no way to send one. A shared declaration takes the louder of what its sites
 * earn: as soon as ONE operation pointing at it publishes the fact nowhere, the document has lost the
 * fact there, and calling that an info would understate it everywhere.
 *
 * Reads the assembled document rather than the fragments, so it sees what overlays, transformers and
 * config all ended up saying, and answers the same on a warm cache hit where no route ran.
 *
 * @internal
 */
final class IgnoredHeaderAudit
{
    /**
     * Every diagnostic the finished document's header declarations earn, one per site: shared
     * components first in pointer order, then the sites that reach them — path items in path order and
     * operations in signature order, each site's own declarations in name order. Broadest declaration
     * to narrowest, and no group ordered by anything but its own content.
     *
     * @param  array<string, mixed>  $document
     * @return list<Diagnostic>
     */
    public static function report(array $document): array
    {
        /** @var array<string, array{0: string, 1: string, 2: bool}> $sharedParameters */
        $sharedParameters = [];
        /** @var array<string, bool> $sharedResponses */
        $sharedResponses = [];
        $local = [];

        foreach (self::pathItemParameters($document) as [$site, $parameters]) {
            foreach (self::ignoredParameters($document, $parameters) as $name => $pointer) {
                // A path item's parameters apply to every operation under it, so the fact they state
                // would have to be published by all of them; nothing in the document can stand in for
                // that, which is why this half never softens to an info.
                if ($pointer !== null) {
                    self::share($sharedParameters, $pointer, $name, false);

                    continue;
                }

                $local[] = self::parameterDiagnostic($site, $name, Severity::Warning);
            }
        }

        foreach (LintOperation::all($document) as $operation) {
            foreach (self::ignoredParameters($document, $operation->operation['parameters'] ?? null) as $name => $pointer) {
                $publishes = self::published($document, $operation, $name);

                if ($pointer !== null) {
                    self::share($sharedParameters, $pointer, $name, $publishes);

                    continue;
                }

                $local[] = self::parameterDiagnostic(
                    $operation->signature,
                    $name,
                    $publishes ? Severity::Info : Severity::Warning,
                );
            }

            foreach (self::ignoredResponseHeaders($document, $operation) as [$status, $carriesContent, $pointer]) {
                if ($pointer !== null) {
                    // Every reference reaches the same node, so what it carries is a property of the
                    // component and not of who pointed at it.
                    $sharedResponses[$pointer] = $carriesContent;

                    continue;
                }

                $local[] = self::responseDiagnostic(
                    sprintf('The %s response of %s', $status, $operation->signature),
                    $carriesContent,
                );
            }
        }

        ksort($sharedParameters);
        ksort($sharedResponses);

        $shared = [];

        foreach ($sharedParameters as [$pointer, $name, $publishes]) {
            $shared[] = self::parameterDiagnostic(
                'The component '.$pointer,
                $name,
                $publishes ? Severity::Info : Severity::Warning,
            );
        }

        foreach ($sharedResponses as $pointer => $carriesContent) {
            $shared[] = self::responseDiagnostic('The component '.$pointer, $carriesContent);
        }

        return [...$shared, ...$local];
    }

    /**
     * Folds one reference to a shared declaration into the site that holds it. The site earns an info
     * only where EVERY reference to it publishes the fact elsewhere.
     *
     * @param  array<string, array{0: string, 1: string, 2: bool}>  $sites
     */
    private static function share(array &$sites, string $pointer, string $name, bool $publishes): void
    {
        $key = $pointer."\0".$name;

        $sites[$key] = isset($sites[$key])
            ? [$pointer, $name, $sites[$key][2] && $publishes]
            : [$pointer, $name, $publishes];
    }

    private static function parameterDiagnostic(string $site, string $name, Severity $severity): Diagnostic
    {
        return new Diagnostic(
            severity: $severity,
            code: 'document.ignored-header-declaration',
            message: sprintf(
                '%s declares a header parameter named "%s", which OpenAPI says every reader SHALL ignore, so nothing in the document carries it — the description with it included.',
                $site,
                $name,
            ),
            help: match (strtolower($name)) {
                'content-type' => 'The media type of a request body is what requestBody.content says; describe it there and drop the parameter.',
                'accept' => 'The media types an operation returns are what its responses\' content says; describe them there and drop the parameter.',
                default => 'A credential is described by a security scheme and asked for by a security requirement; declare one and drop the parameter.',
            },
        );
    }

    private static function responseDiagnostic(string $site, bool $carriesContent): Diagnostic
    {
        return new Diagnostic(
            severity: $carriesContent ? Severity::Info : Severity::Warning,
            code: 'document.ignored-header-declaration',
            message: sprintf(
                '%s declares a Content-Type header, which OpenAPI says every reader SHALL ignore, so nothing in the document carries it — the description with it included.',
                $site,
            ),
            help: 'The media type a response is written in is what that response\'s content says; describe it there and drop the header.',
        );
    }

    /**
     * Whether the operation states the same fact in the member OAS reserves for it. `Accept` is
     * answered by any response that names a representation, since that is the set a client negotiates
     * over; `Authorization` by an effective security requirement, the operation's own where it has one
     * and the document's otherwise — an operation opting OUT with an empty list states that it needs
     * no credential, which is a published fact and not a missing one.
     *
     * @param  array<string, mixed>  $document
     */
    private static function published(array $document, LintOperation $operation, string $name): bool
    {
        return match (strtolower($name)) {
            'content-type' => self::hasContent($operation->operation['requestBody'] ?? null),
            'accept' => self::anyResponseHasContent($document, $operation->operation['responses'] ?? null),
            default => is_array($operation->operation['security'] ?? null)
                || is_array($document['security'] ?? null),
        };
    }

    /**
     * Each path item's own `parameters`, which apply to every operation under it, with the site a
     * message names it by. A path item written as a `$ref` is followed for the same reason
     * {@see LintOperation} follows one.
     *
     * @param  array<string, mixed>  $document
     * @return list<array{0: string, 1: mixed}>
     */
    private static function pathItemParameters(array $document): array
    {
        $sites = [];

        foreach ([['paths', ''], ['webhooks', 'webhooks.']] as [$member, $prefix]) {
            $items = $document[$member] ?? null;
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $key => $written) {
                if (! is_array($written)) {
                    continue;
                }

                /** @var array<string, mixed> $written */
                [$item, , $unresolved] = Refs::follow($document, $written, []);

                if ($unresolved !== null || ! isset($item['parameters'])) {
                    continue;
                }

                $sites[] = ['The path item '.$prefix.$key, $item['parameters']];
            }
        }

        usort($sites, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return $sites;
    }

    /**
     * The ignored names one `parameters` list declares, in name order and each named once — a list
     * holding `Accept` twice is one thing to say twice over, and OAS forbids the repeat anyway — with
     * the pointer the declaration was written at, or null where it was written inline.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, string|null>
     */
    private static function ignoredParameters(array $document, mixed $parameters): array
    {
        if (! is_array($parameters)) {
            return [];
        }

        $names = [];
        foreach ($parameters as $written) {
            if (! is_array($written)) {
                continue;
            }

            /** @var array<string, mixed> $written */
            [$parameter, $segments, $unresolved] = Refs::follow($document, $written, []);

            $name = $parameter['name'] ?? null;

            if ($unresolved !== null || ! is_string($name) || ($parameter['in'] ?? null) !== 'header') {
                continue;
            }

            // Keyed by the resolved pointer rather than by what the `$ref` spelled, so two spellings of
            // one component are one site; empty segments mean nothing was followed.
            if (IgnoredHeaders::parameter($name) && ! array_key_exists($name, $names)) {
                $names[$name] = $segments === [] ? null : self::pointer($segments);
            }
        }

        ksort($names);

        return $names;
    }

    /**
     * The responses whose `headers` map declares an ignored name, in status order, each with whether
     * that response names a representation of its own and the pointer it was written at — null where
     * the response is written inline on the operation.
     *
     * @param  array<string, mixed>  $document
     * @return list<array{0: string, 1: bool, 2: string|null}>
     */
    private static function ignoredResponseHeaders(array $document, LintOperation $operation): array
    {
        $responses = $operation->operation['responses'] ?? null;
        if (! is_array($responses)) {
            return [];
        }

        $out = [];
        foreach (Arr::stringKeyed($responses) as $status => $written) {
            if (! is_array($written)) {
                continue;
            }

            /** @var array<string, mixed> $written */
            [$response, $segments, $unresolved] = Refs::follow($document, $written, []);

            $headers = $unresolved === null ? ($response['headers'] ?? null) : null;
            if (! is_array($headers)) {
                continue;
            }

            foreach (array_keys($headers) as $name) {
                if (IgnoredHeaders::responseHeader((string) $name)) {
                    $out[] = [$status, self::hasContent($response), $segments === [] ? null : self::pointer($segments)];

                    break;
                }
            }
        }

        usort($out, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return $out;
    }

    /**
     * The JSON pointer a message names a shared declaration by — the spelling the author wrote in the
     * `$ref` and can search their own document for.
     *
     * @param  list<string>  $segments
     */
    private static function pointer(array $segments): string
    {
        return '#/'.implode('/', $segments);
    }

    private static function hasContent(mixed $node): bool
    {
        return is_array($node) && is_array($node['content'] ?? null) && $node['content'] !== [];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private static function anyResponseHasContent(array $document, mixed $responses): bool
    {
        if (! is_array($responses)) {
            return false;
        }

        foreach ($responses as $written) {
            if (! is_array($written)) {
                continue;
            }

            /** @var array<string, mixed> $written */
            [$response, , $unresolved] = Refs::follow($document, $written, []);

            if ($unresolved === null && self::hasContent($response)) {
                return true;
            }
        }

        return false;
    }
}
