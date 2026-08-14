<?php

declare(strict_types=1);

namespace Docuccino\Core\Lint;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;

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
 * It reads only the emitted UIR, no framework deps, so the reference CLI and other-language
 * producers run the identical rule; the Laravel adapter just maps `lint.leakage` config onto the
 * options and registers it.
 */
final class SensitiveFieldLint implements DocumentTransformer
{
    /** The members whose contents are published illustrative values rather than structure. */
    private const VALUE_KEYS = ['example', 'examples', 'const', 'enum', 'default'];

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
                message: $finding['value']
                    ? sprintf('The value at %s looks like %s and may be a real credential.', $finding['pointer'], $finding['label'])
                    : sprintf('Property "%s" (%s) looks like %s and may leak sensitive data.', $finding['name'], $finding['pointer'], $finding['label']),
                help: $finding['value']
                    ? 'Replace the example with a placeholder or, if it is genuinely public, safelist the pointer under lint.leakage.allow.'
                    : 'Hide it (e.g. #[Hidden] / omit it from the resource) or, if intentional, safelist it under lint.leakage.allow.',
            ));
        }
    }

    /**
     * Depth-first collect of every `properties` key matching a heuristic, plus a JSON pointer to it.
     * Component and inline schemas look the same from here.
     *
     * @param  list<string>  $path
     * @param  list<array{name: string, pointer: string, label: string, value: bool}>  $findings
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
                        'pointer' => '#/'.implode('/', [...$path, 'properties', (string) $name]),
                        'label' => $label,
                        'value' => false,
                    ];
                }
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
     * Every leaf string under a published value, checked against the credential shapes. The finding
     * names the member it sits under, never the matched text — a diagnostic that echoes the secret
     * has only moved it into the build log.
     *
     * @param  list<string>  $path
     * @param  list<array{name: string, pointer: string, label: string, value: bool}>  $findings
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
                'pointer' => '#/'.implode('/', $path),
                'label' => $label,
                'value' => true,
            ];
        }
    }

    private function silenced(string $name, string $pointer): bool
    {
        return in_array($name, $this->options->allow, true) || in_array($pointer, $this->options->allow, true);
    }
}
