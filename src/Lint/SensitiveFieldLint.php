<?php

declare(strict_types=1);

namespace Docuccino\Core\Lint;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;

/**
 * A framework-agnostic whole-document lint (design §Phase 4 — data-leakage pass): it scans every
 * emitted schema for property names matching the sensitive-field heuristics ({@see
 * SensitiveFieldLintOptions}) and reports a warning naming the exact schema/property JSON pointer —
 * catching an accidentally-exposed `password`/`remember_token`/`api_key` before it ships. It NEVER
 * mutates the document (diagnostics only). Operates purely on the emitted UIR (zero framework deps),
 * so the reference CLI and other-language producers run the identical rule; the Laravel adapter only
 * maps its `lint.leakage` config onto the options and registers it. First member of the growing
 * `Docuccino\Core\Lint` family (a second document-level rule slots in without reshaping).
 */
final class SensitiveFieldLint implements DocumentTransformer
{
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
                message: sprintf('Property "%s" (%s) looks like %s and may leak sensitive data.', $finding['name'], $finding['pointer'], $finding['label']),
                help: 'Hide it (e.g. #[Hidden] / omit it from the resource) or, if intentional, safelist it under lint.leakage.allow.',
            ));
        }
    }

    /**
     * Depth-first walk collecting every `properties` map key that matches a heuristic, with a JSON
     * pointer to it (works uniformly for component schemas and inline ones).
     *
     * @param  list<string>  $path
     * @param  list<array{name: string, pointer: string, label: string}>  $findings
     */
    private function walk(mixed $node, array $path, array &$findings): void
    {
        if (! is_array($node)) {
            return;
        }

        $properties = $node['properties'] ?? null;
        if (is_array($properties)) {
            foreach ($properties as $name => $schema) {
                $label = $this->match((string) $name);
                if ($label !== null) {
                    $findings[] = [
                        'name' => (string) $name,
                        'pointer' => '#/'.implode('/', [...$path, 'properties', (string) $name]),
                        'label' => $label,
                    ];
                }
            }
        }

        foreach ($node as $key => $child) {
            if (is_array($child)) {
                $this->walk($child, [...$path, (string) $key], $findings);
            }
        }
    }

    /** The label of the first heuristic the (normalized) name matches, or null when it looks safe. */
    private function match(string $name): ?string
    {
        $normalized = strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $name));
        if ($normalized === '') {
            return null;
        }

        foreach ($this->options->patterns as $token => $label) {
            if ($token !== '' && str_contains($normalized, $token)) {
                return $label;
            }
        }

        return null;
    }

    private function silenced(string $name, string $pointer): bool
    {
        return in_array($name, $this->options->allow, true) || in_array($pointer, $this->options->allow, true);
    }
}
