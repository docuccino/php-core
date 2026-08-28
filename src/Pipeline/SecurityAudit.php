<?php

declare(strict_types=1);

namespace Docuccino\Core\Pipeline;

use Docuccino\Core\Contract\Refs;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Lint\LintOperation;
use Docuccino\Core\Support\Arr;

/**
 * Holds a finished document's `security` requirements to the schemes it actually publishes.
 *
 * A Security Requirement Object names a scheme by key rather than by `$ref`, so nothing in the
 * assembly path resolves it and nothing would notice a name that resolves to nothing: the
 * requirement is written where the author declared it and the catalogue is merged separately. A name
 * with no scheme behind it makes the document invalid — a consumer's generated client has nothing to
 * build the credential from — which is why the reference is reported rather than dropped. Dropping it
 * would leave a VALID document saying the operation is public, and that is the one answer worse than
 * an invalid one.
 *
 * Reads the assembled document rather than the fragments, so it sees what overlays, transformers and
 * config all ended up saying, and answers the same on a warm cache hit where no route ran.
 *
 * @internal
 */
final class SecurityAudit
{
    /**
     * Every diagnostic the finished document's security earns, in name order.
     *
     * @param  array<string, mixed>  $document
     * @return list<Diagnostic>
     */
    public static function report(array $document): array
    {
        $components = is_array($document['components'] ?? null) ? $document['components'] : [];
        $schemes = Arr::stringKeyed(is_array($components['securitySchemes'] ?? null) ? $components['securitySchemes'] : []);

        /** @var array<string, string> $undefined name => the first requirement site naming it */
        $undefined = [];
        /** @var array<string, array{0: string, 1: string, 2: string}> $undeclared "scheme\0scope" => [scheme, scope, site] */
        $undeclared = [];

        foreach (self::sites($document) as [$site, $security]) {
            foreach (self::names($security) as [$name, $scopes]) {
                if (! array_key_exists($name, $schemes)) {
                    $undefined[$name] ??= $site;

                    continue;
                }

                $declared = self::declaredScopes($document, $name, $schemes[$name]);
                if ($declared === null) {
                    continue;
                }

                foreach ($scopes as $scope) {
                    if (! in_array($scope, $declared, true)) {
                        $undeclared[$name."\0".$scope] ??= [$name, $scope, $site];
                    }
                }
            }
        }

        ksort($undefined);
        ksort($undeclared);

        $diagnostics = [];

        foreach ($undefined as $name => $site) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Error,
                code: 'security.undefined-scheme',
                message: sprintf('%s names the security scheme "%s", which components.securitySchemes never defines, so the document is invalid and a generated client has nothing to authenticate with.', $site, $name),
                help: self::defineHelp($schemes),
            );
        }

        foreach ($undeclared as [$name, $scope, $site]) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'security.undeclared-scope',
                message: sprintf('%s asks for the scope "%s" against the OAuth2 scheme "%s", whose flows declare no such scope, so the document contradicts itself and a client asking for it would be refused a token.', $site, $scope, $name),
                help: sprintf('Declare it under that scheme\'s flows.*.scopes, or ask for one it offers%s.', self::listed(self::declaredScopes($document, $name, $schemes[$name]) ?? [])),
            );
        }

        return $diagnostics;
    }

    /**
     * Every place a requirement is written: the document-wide one, then the operations in signature
     * order. The label is what the message names the site by.
     *
     * @param  array<string, mixed>  $document
     * @return list<array{0: string, 1: mixed}>
     */
    private static function sites(array $document): array
    {
        $sites = [];

        if (isset($document['security'])) {
            $sites[] = ['The document-level security requirement', $document['security']];
        }

        foreach (LintOperation::all($document) as $operation) {
            if (isset($operation->operation['security'])) {
                $sites[] = ['The security requirement on '.$operation->signature, $operation->operation['security']];
            }
        }

        return $sites;
    }

    /**
     * The `scheme => scopes` pairs one `security` value lists, skipping anything that isn't shaped like
     * a Security Requirement Object — a malformed one is somebody else's report, not a missing scheme.
     *
     * A Security Requirement Object keys by scheme NAME, so a positional key is not one: `[["bearer"]]`
     * writes the scheme where the scopes go and states no name at all. Reading the position as a name
     * invented the scheme "0" and failed the build over a typo nobody had made — while the shape itself
     * is already an error the schema check reports, at the pointer that locates it.
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    private static function names(mixed $security): array
    {
        if (! is_array($security)) {
            return [];
        }

        $pairs = [];
        foreach ($security as $requirement) {
            if (! is_array($requirement)) {
                continue;
            }

            foreach ($requirement as $name => $scopes) {
                if (! is_string($name)) {
                    continue;
                }

                $named = [];
                foreach (is_array($scopes) ? $scopes : [] as $scope) {
                    if (is_string($scope)) {
                        $named[] = $scope;
                    }
                }

                $pairs[] = [$name, $named];
            }
        }

        return $pairs;
    }

    /**
     * The scopes an OAuth2 scheme's flows declare, or null where the scheme is not one whose scopes the
     * document carries. Only `oauth2` declares them here: an `openIdConnect` scheme's scopes live in the
     * discovery document the build cannot read, and for every other type OAS 3.1+ reads the list as role
     * names that are "not otherwise defined or exchanged in-band" — checking either would be inventing a
     * rule the spec does not have.
     *
     * The catalogue entry may be a Reference Object, which the grammar permits wherever a Security Scheme
     * Object is written, so it is followed first: read raw, a hoisted `oauth2` scheme has no `type` on the
     * node and every scope check under it was skipped in silence.
     *
     * @param  array<string, mixed>  $document
     * @return list<string>|null
     */
    private static function declaredScopes(array $document, string $name, mixed $scheme): ?array
    {
        if (! is_array($scheme)) {
            return null;
        }

        [$scheme] = Refs::follow($document, Arr::stringKeyed($scheme), ['components', 'securitySchemes', $name]);

        if (($scheme['type'] ?? null) !== 'oauth2' || ! is_array($scheme['flows'] ?? null)) {
            return null;
        }

        $scopes = [];
        foreach ($scheme['flows'] as $flow) {
            if (! is_array($flow) || ! is_array($flow['scopes'] ?? null)) {
                continue;
            }

            foreach (array_keys($flow['scopes']) as $scope) {
                $scopes[(string) $scope] = true;
            }
        }

        $names = array_keys($scopes);
        sort($names);

        return $names;
    }

    /**
     * @param  array<string, mixed>  $schemes
     */
    private static function defineHelp(array $schemes): string
    {
        return $schemes === []
            ? 'This document defines no security schemes at all: add the one the requirement names under its security.schemes config, or drop the requirement.'
            : sprintf('Add it to this document\'s security.schemes config, or name one the document does define%s.', self::listed(array_keys($schemes)));
    }

    /**
     * @param  list<string>  $names
     */
    private static function listed(array $names): string
    {
        return $names === [] ? '' : ' ('.implode(', ', $names).')';
    }
}
