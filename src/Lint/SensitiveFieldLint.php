<?php

declare(strict_types=1);

namespace Docuccino\Core\Lint;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;

/**
 * A whole-document data-leakage lint: scans every emitted schema for property names matching the
 * sensitive-field heuristics ({@see SensitiveFieldLintOptions}) and warns with the exact
 * schema/property JSON pointer, so an accidentally-exposed `password`/`remember_token`/`api_key`
 * shows up before it ships. Diagnostics only — it never mutates the document.
 *
 * Illustrative values (`example`, `examples`, `const`, `enum`, `default`) are scanned too, against
 * {@see CredentialShapes} — a name heuristic can't see a real secret folded from a class constant into
 * an example under an innocent member name, and examples survive emit while provenance doesn't.
 *
 * Parameter names are the third subject, at all three positions a document publishes one — an
 * operation's `parameters`, a path item's, and `components.parameters`. A name in a URL is the same
 * heuristic reading the more exposed position: `?api_key=…` is a well-known anti-pattern precisely
 * because a query string is recorded in access logs, proxy logs, browser history and outbound
 * `Referer` headers, where a header never is. Only `query` and `path` are read, for that reason —
 * {@see self::EXPOSED_LOCATIONS}.
 *
 * It reads only the emitted UIR, no framework deps, so the reference CLI and other-language
 * producers run the identical rule; the Laravel adapter just maps `lint.leakage` config onto the
 * options and registers it. Pinned to run last so the pointers it publishes are the pointers the
 * emitted document has — a body hoisted into a shared component is reported once, at the component.
 *
 * @phpstan-type LeakFinding array{name: string, pointer: string, label: string, kind: 'property'|'value'|'parameter', in: string|null}
 */
#[ExtensionOrder(priority: Priorities::LAST)]
final class SensitiveFieldLint implements DocumentTransformer
{
    /** The members whose contents are published illustrative values rather than structure. */
    private const VALUE_KEYS = ['example', 'examples', 'const', 'enum', 'default'];

    /**
     * The parameter locations where a sensitive NAME is the defect rather than the design. A
     * credential is supposed to travel in a header, and often in a cookie, so a finding on
     * `X-Api-Key` would fire on every correctly-secured API — a diagnostic firing where the reader
     * has nothing to fix is what teaches people to ignore the channel. A query string and a path
     * segment are the two positions the URL itself carries, and a URL is written down all the way
     * along the request's path.
     */
    private const EXPOSED_LOCATIONS = ['query', 'path'];

    public function __construct(
        private readonly SensitiveFieldLintOptions $options = new SensitiveFieldLintOptions,
    ) {}

    public function transform(UirDocumentDraft $document, DocumentContext $context): void
    {
        if (! $this->options->enabled) {
            return;
        }

        $findings = [];
        $this->walk($document->toArray(), [], $findings);

        foreach ($findings as $finding) {
            if ($this->silenced($finding['name'], $finding['pointer'])) {
                continue;
            }

            $context->report(new Diagnostic(
                severity: Severity::Warning,
                code: 'lint.data-leakage',
                message: match ($finding['kind']) {
                    'value' => sprintf('The value at %s looks like %s and may be a real credential.', $finding['pointer'], $finding['label']),
                    'property' => sprintf('Property "%s" (%s) looks like %s and may leak sensitive data.', $finding['name'], $finding['pointer'], $finding['label']),
                    'parameter' => sprintf('The %s parameter "%s" (%s) looks like %s and may leak sensitive data.', $finding['in'] ?? '', $finding['name'], $finding['pointer'], $finding['label']),
                },
                help: match ($finding['kind']) {
                    'value' => 'Replace the example with a placeholder or, if it is genuinely public, safelist the pointer under lint.leakage.allow.',
                    'property' => 'Take the field out of the shape that publishes it — #[Hidden] removes a property from response schemas, #[HiddenFromRequest] from a request body — or, if intentional, safelist it under lint.leakage.allow.',
                    'parameter' => 'Carry the value in a header instead: a URL is recorded in access logs, browser history and outbound Referer headers. If it really is public, safelist the name or the pointer under lint.leakage.allow.',
                },
            ));
        }
    }

    /**
     * Depth-first collect of every `properties` key and published parameter name matching a
     * heuristic, plus a JSON pointer to it. Component and inline schemas look the same from here, and
     * so do the three positions a parameter is published at.
     *
     * @param  list<string>  $path
     * @param  list<LeakFinding>  $findings
     */
    private function walk(mixed $node, array $path, array &$findings): void
    {
        if (! is_array($node)) {
            return;
        }

        $properties = $node['properties'] ?? null;
        if (is_array($properties)) {
            foreach ($properties as $name => $schema) {
                $label = $this->options->match((string) $name);
                if ($label !== null) {
                    $findings[] = [
                        'name' => (string) $name,
                        'pointer' => '/'.implode('/', [...$path, 'properties', (string) $name]),
                        'label' => $label,
                        'kind' => 'property',
                        'in' => null,
                    ];
                }
            }
        }

        $parameters = $node['parameters'] ?? null;
        if (is_array($parameters)) {
            foreach ($parameters as $key => $parameter) {
                $this->scanParameter($parameter, [...$path, 'parameters', (string) $key], $findings);
            }
        }

        foreach (self::VALUE_KEYS as $key) {
            if (array_key_exists($key, $node)) {
                $this->scanValue($node[$key], $key, [...$path, $key], $findings);
            }
        }

        foreach ($node as $key => $child) {
            if (is_array($child)) {
                $this->walk($child, [...$path, (string) $key], $findings);
            }
        }
    }

    /**
     * One published parameter, matched on its name where the location exposes the value. The pointer
     * names the `name` member rather than the parameter node, the way a value finding points at the
     * leaf it read: it is the member the reader has to change.
     *
     * A `$ref` parameter carries no name at its use site, and none is invented here. A local ref
     * resolves inside this document, so the component it names is walked like any other node and the
     * finding lands once, at the declaration — the same reason a hoisted body is reported at its
     * component. A ref out of the document names nothing this document publishes, so there is no name
     * to read and nothing the finding could point the reader at.
     *
     * @param  list<string>  $path
     * @param  list<LeakFinding>  $findings
     */
    private function scanParameter(mixed $parameter, array $path, array &$findings): void
    {
        if (! is_array($parameter) || isset($parameter['$ref'])) {
            return;
        }

        $name = $parameter['name'] ?? null;
        $in = $parameter['in'] ?? null;

        // A parameter with no location is not a parameter this can judge: which positions expose the
        // value is the whole reason the rule fires, so an unreadable `in` widens nothing.
        if (! is_string($name) || ! in_array($in, self::EXPOSED_LOCATIONS, true)) {
            return;
        }

        $label = $this->options->match($name);
        if ($label !== null) {
            $findings[] = [
                'name' => $name,
                'pointer' => '/'.implode('/', [...$path, 'name']),
                'label' => $label,
                'kind' => 'parameter',
                'in' => $in,
            ];
        }
    }

    /**
     * Every leaf string under a published value, checked against the credential shapes. The finding
     * names the member it sits under, never the matched text — a diagnostic that echoes the secret
     * has only moved it into the build log.
     *
     * @param  list<string>  $path
     * @param  list<LeakFinding>  $findings
     */
    private function scanValue(mixed $value, string $name, array $path, array &$findings): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $this->scanValue($child, (string) $key, [...$path, (string) $key], $findings);
            }

            return;
        }

        $label = is_string($value) ? CredentialShapes::label($value) : null;
        if ($label !== null) {
            $findings[] = [
                'name' => $name,
                'pointer' => '/'.implode('/', $path),
                'label' => $label,
                'kind' => 'value',
                'in' => null,
            ];
        }
    }

    /** {@see LintSafelist} is why a safelist entry spelled `#/…` lands against the bare pointer minted above. */
    private function silenced(string $name, string $pointer): bool
    {
        return LintSafelist::matches($this->options->allow, $name, $pointer);
    }
}
