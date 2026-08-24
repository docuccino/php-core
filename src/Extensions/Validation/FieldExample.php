<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Support\FormatSamples;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator as OpisValidator;

/**
 * The `example` a validated leaf field earns from what its rules pinned. Every write operation used to
 * publish a body with nothing in it, so a try-it panel and a generated snippet both started empty.
 *
 * Two invariants, and the second is why this is worth having at all:
 *
 *   - **Deterministic.** Every value below is a constant or arithmetic on the field's own keywords.
 *     No clock, no locale, no randomness, nothing from another field — so the same rules always
 *     produce the same bytes, and adding a field never moves another field's example.
 *   - **True.** Whatever is about to be published is validated against the very keywords it was
 *     derived from before it is published, by the same JSON Schema validator the contract layer
 *     audits authored examples with. A contradictory or unmodellable rule set therefore publishes
 *     NOTHING rather than an example the endpoint's own validator would reject.
 *
 * A document's own {@see RepresentationPolicy::$formatSamples} is held to the same second invariant: a
 * configured sample is validated against the field's finished keywords like everything else, and one
 * that fails falls back to the built-in sample with a diagnostic — a setting that silently did nothing
 * would be worse than no setting.
 *
 * A rule can also pin a value the schema cannot carry — a `date_format` pattern, a timezone
 * identifier — or rule out any value at all, a file upload or a decimal-places constraint. Those are
 * the rule's own knowledge, so they arrive as a {@see ValidationField::proposeExample()} proposal
 * from the transformer that owns the rule; a proposal is verified exactly like a derived value.
 *
 * Nothing is synthesized where a rule pinned only the base type: `type: string` already tells a
 * generator everything a `"string"` example would, and publishing one on every property is bytes
 * without a fact. `boolean` is the exception the whole domain fits into.
 *
 * @internal
 */
final class FieldExample
{
    /**
     * The length-bounded sample is a PREFIX of this, so it never has to repeat itself: 64 characters,
     * the longest sample published, and readable at every truncation.
     */
    private const string FILLER = 'example-value-0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMN';

    /** What a sample is as long as the bounds allow — `example`, the prefix a reader recognises. */
    private const int PREFERRED_LENGTH = 7;

    /** Past this a conforming sample is noise rather than an illustration, so none is published. */
    private const int MAX_LENGTH = 64;

    /** The neutral starting point for a bounded number, moved onto the nearest legal value. */
    private const int NUMBER_SEED = 1;

    /**
     * Publish an example on this node, where one is owed and one can be proved. Called for LEAF nodes
     * only — an object or array example would restate its children.
     *
     * Returns the diagnostics deriving it raised — today only a configured format sample the field's own
     * rules reject. `$path` names the field in those, and is prose only.
     *
     * @return list<Diagnostic>
     */
    public static function attach(FieldNode $node, RepresentationPolicy $policy = new RepresentationPolicy, string $path = ''): array
    {
        // An author's own example (a `#[RuleSchema]` example rule, say) is the contract as they stated
        // it; it is never second-guessed here, and the example audit is what holds it to the schema.
        if (array_key_exists('example', $node->keywords)) {
            return [];
        }

        if ($node->exampleSuppressed) {
            return [];
        }

        /** @var list<Diagnostic> $diagnostics */
        $diagnostics = [];
        $candidate = $node->exampleProposal ?? self::derive($node->keywords, $policy, $path, $diagnostics);

        if ($candidate === null || self::rejection($node->keywords, $candidate[0]) !== null) {
            return $diagnostics;
        }

        $node->keywords['example'] = $candidate[0];

        return $diagnostics;
    }

    /**
     * A value the keywords pin, wrapped so a legitimately falsy one is distinguishable from "nothing
     * to say"; null where they pin only a type, or a shape no scalar illustrates.
     *
     * @param  array<string, mixed>  $keywords
     * @param  list<Diagnostic>  $diagnostics
     * @return array{mixed}|null
     */
    private static function derive(array $keywords, RepresentationPolicy $policy, string $path, array &$diagnostics): ?array
    {
        // A `const` IS the value; an example beside it would only repeat it.
        if (array_key_exists('const', $keywords)) {
            return null;
        }

        $enum = $keywords['enum'] ?? null;
        if (is_array($enum) && $enum !== []) {
            // First member: a list's order is authored, and every other reader of the document — a
            // viewer, an SDK generator — shows the same one.
            return [reset($enum)];
        }

        $type = $keywords['type'] ?? null;

        return match ($type) {
            'boolean' => [true],
            'string' => self::string($keywords, $policy, $path, $diagnostics),
            'integer', 'number' => self::number($keywords, $type === 'integer'),
            default => null,
        };
    }

    /**
     * A format's sample first — it is the fact the rule contributed. Failing that, a prefix of the
     * filler at a length the bounds allow; a `binary` upload and a bare unbounded string get nothing.
     *
     * @param  array<string, mixed>  $keywords
     * @param  list<Diagnostic>  $diagnostics
     * @return array{mixed}|null
     */
    private static function string(array $keywords, RepresentationPolicy $policy, string $path, array &$diagnostics): ?array
    {
        $format = $keywords['format'] ?? null;
        if ($format === 'binary') {
            // A file's bytes are not an illustration, and `''` would document an empty upload.
            return null;
        }

        if (is_string($format)) {
            $sample = self::formatSample($keywords, $format, $policy, $path, $diagnostics);
            if ($sample !== null) {
                return [$sample];
            }
        }

        $min = self::intKeyword($keywords, 'minLength');
        $max = self::intKeyword($keywords, 'maxLength');

        // Only the base type was pinned. A `pattern` counts as pinning something — the filler prefix
        // either matches it (`alpha`, `alpha_dash`) or is refused by the check below.
        if ($min === null && $max === null && ! isset($keywords['pattern'])) {
            return null;
        }

        $length = self::PREFERRED_LENGTH;
        if ($max !== null) {
            $length = min($length, $max);
        }
        if ($min !== null) {
            $length = max($length, $min);
        }

        // Bounds that cross, or a floor so high the sample stops illustrating anything.
        if (($max !== null && $length > $max) || $length > self::MAX_LENGTH) {
            return null;
        }

        return [substr(self::FILLER, 0, $length)];
    }

    /**
     * The lowest legal value at or above the seed, so a `min:18` documents 18 and a `max:5` still
     * documents something a client can send. Bounds that cross, or a `multipleOf` with no room under
     * the ceiling, publish nothing.
     *
     * @param  array<string, mixed>  $keywords
     * @return array{mixed}|null
     */
    private static function number(array $keywords, bool $integer): ?array
    {
        $lower = self::numberKeyword($keywords, 'minimum');
        $upper = self::numberKeyword($keywords, 'maximum');
        $step = self::numberKeyword($keywords, 'multipleOf');

        $exclusiveLower = self::numberKeyword($keywords, 'exclusiveMinimum');
        if ($exclusiveLower !== null) {
            $lower = max($lower ?? $exclusiveLower + 1, $exclusiveLower + 1);
        }

        $exclusiveUpper = self::numberKeyword($keywords, 'exclusiveMaximum');
        if ($exclusiveUpper !== null) {
            $upper = min($upper ?? $exclusiveUpper - 1, $exclusiveUpper - 1);
        }

        // Only the type was pinned; `type: integer` says that already.
        if ($lower === null && $upper === null && $step === null) {
            return null;
        }

        $value = self::NUMBER_SEED;
        if ($lower !== null && $value < $lower) {
            $value = $lower;
        }
        if ($upper !== null && $value > $upper) {
            $value = $upper;
        }

        if ($step !== null && $step > 0) {
            $value = ceil($value / $step) * $step;
        }

        if (($lower !== null && $value < $lower) || ($upper !== null && $value > $upper)) {
            return null;
        }

        // A whole float publishes as an integer literal, which validates against `number` just the same
        // and keeps the bytes the shortest true form.
        if (is_float($value) && $value === floor($value) && abs($value) < (float) PHP_INT_MAX) {
            $value = (int) $value;
        }

        return [$integer ? (int) $value : $value];
    }

    /**
     * @param  array<string, mixed>  $keywords
     */
    private static function intKeyword(array $keywords, string $name): ?int
    {
        $value = $keywords[$name] ?? null;

        return is_int($value) && $value >= 0 ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $keywords
     */
    private static function numberKeyword(array $keywords, string $name): int|float|null
    {
        $value = $keywords[$name] ?? null;

        return is_int($value) || is_float($value) ? $value : null;
    }

    /**
     * The sample for this format: the document's configured one where it holds up against the field's
     * finished keywords, else the built-in constant.
     *
     * A configured sample is checked HERE rather than left to the gate in {@see attach()}, because the
     * gate can only drop — and dropping would leave the setting looking inert on a field where the
     * built-in sample would have published fine. So a rejected sample is named, and the built-in one is
     * published in its place; where the format has no built-in sample there is nothing to fall back to,
     * and the honest answer is no example.
     *
     * @param  array<string, mixed>  $keywords
     * @param  list<Diagnostic>  $diagnostics
     */
    private static function formatSample(array $keywords, string $format, RepresentationPolicy $policy, string $path, array &$diagnostics): ?string
    {
        $configured = $policy->formatSamples[$format] ?? null;
        $default = FormatSamples::for($format);

        if ($configured === null || $configured === $default) {
            return $default;
        }

        $rejection = self::rejection($keywords, $configured);
        if ($rejection === null) {
            return $configured;
        }

        $diagnostics[] = new Diagnostic(
            severity: Severity::Warning,
            code: 'config.format-sample-rejected',
            message: sprintf(
                'The example configured for format "%s" (%s) does not satisfy the rules on %s: %s. %s',
                $format,
                json_encode($configured),
                $path === '' ? 'the field' : sprintf('field "%s"', $path),
                $rejection,
                $default === null
                    ? 'The format has no built-in sample to fall back on, so the field publishes none.'
                    : sprintf('The built-in sample (%s) is published instead.', json_encode($default)),
            ),
            help: sprintf(
                'Set representation.examples.formats.%s to a value every field carrying that format accepts, or drop the key.',
                $format,
            ),
        );

        return $default;
    }

    /**
     * Why the value fails the keywords it is about to be published beside — the failing keyword names,
     * innermost first — or null when it validates. The whole point of the class: a derived value can
     * still be wrong once a later rule narrows the field (`alpha` then `min:12`), a proposal knows only
     * its own rule, and a configured sample knows nothing about the field at all. Anything that does not
     * validate is simply not published.
     *
     * A pure function of the keywords and the value, so the reason is the same bytes every run.
     *
     * @param  array<string, mixed>  $keywords
     */
    private static function rejection(array $keywords, mixed $value): ?string
    {
        $encoded = json_encode($keywords);
        if ($encoded === false) {
            return 'the recovered rules could not be expressed as a schema';
        }

        $schema = json_decode($encoded);
        if (! is_object($schema)) {
            return 'the recovered rules could not be expressed as a schema';
        }

        $result = (new OpisValidator)->validate($value, $schema);
        if ($result->isValid()) {
            return null;
        }

        $error = $result->error();
        $keywordNames = $error === null ? [] : self::failingKeywords($error);

        return $keywordNames === [] ? 'the recovered rules reject it' : implode(', ', $keywordNames);
    }

    /**
     * The keywords a validation error blames, deepest first and deduped. An inner node only restates
     * that its children failed, so the leaves are what a reader can act on.
     *
     * @return list<string>
     */
    private static function failingKeywords(ValidationError $error): array
    {
        $sub = array_values(array_filter($error->subErrors(), static fn (mixed $child): bool => $child instanceof ValidationError));

        if ($sub === []) {
            return [$error->keyword()];
        }

        $keywords = [];
        foreach ($sub as $child) {
            foreach (self::failingKeywords($child) as $keyword) {
                $keywords[$keyword] = true;
            }
        }

        return array_keys($keywords);
    }
}
