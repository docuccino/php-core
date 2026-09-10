<?php

declare(strict_types=1);

namespace Docuccino\Core\Diagnostics;

use Docuccino\Core\Provenance\Source;
use Docuccino\Core\Support\Hydrate;
use Docuccino\Core\Support\PlainText;

/**
 * A single build diagnostic. The CLI is the primary channel; these are embedded in the
 * UIR document only under an explicit flag. Ordering is deterministic (never time-based).
 *
 * `code`, `message` and `help` are stated around text an application chose, and a diagnostic is
 * PUBLISHED as well as printed, so they are made safe here, once, and no producer owes the call.
 * {@see PlainText} is idempotent, so a producer that makes it anyway is harmless, and
 * {@see fromArray()} — how a diagnostic comes back off a warm fragment-cache hit — arrives through
 * this constructor. `help` keeps its line breaks ({@see PlainText::lines()}), which a console writer
 * turns into layout.
 *
 * `routeSignature` is exempt, and stays exactly as it was given. It is a key rather than a sentence
 * — sorted on, and compared against the signature a live route answers with — and its bytes are the
 * bytes the document already publishes as the `paths` key it names. So escaping it would make a
 * diagnostic name a route nothing can find while removing nothing from the artifact. `source` is a
 * {@see Source}, shared with the provenance trail, and belongs to that class for the same reason.
 *
 * Why the escaping sits here rather than at each producer, and why the exemption is sound, is in
 * `docs/design/defect-classes.md`.
 */
final readonly class Diagnostic
{
    public string $code;

    public string $message;

    public ?string $help;

    public function __construct(
        public Severity $severity,
        string $code,
        string $message,
        public ?Source $source = null,
        public ?string $routeSignature = null,
        ?string $help = null,
    ) {
        $this->code = PlainText::of($code);
        $this->message = PlainText::of($message);
        $this->help = $help === null ? null : PlainText::lines($help);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $severity = $data['severity'] ?? Severity::Info->value;
        $code = $data['code'] ?? '';
        $message = $data['message'] ?? '';

        return new self(
            severity: is_string($severity)
                ? (Severity::tryFrom($severity) ?? Severity::Info)
                : Severity::Info,
            code: is_string($code) ? $code : '',
            message: is_string($message) ? $message : '',
            source: Hydrate::objectOrNull($data['source'] ?? null, Source::fromArray(...)),
            routeSignature: Hydrate::stringOrNull($data['routeSignature'] ?? null),
            help: Hydrate::stringOrNull($data['help'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'severity' => $this->severity->value,
            'code' => $this->code,
            'message' => $this->message,
        ];

        if ($this->source !== null) {
            $out['source'] = $this->source->toArray();
        }

        if ($this->routeSignature !== null) {
            $out['routeSignature'] = $this->routeSignature;
        }

        if ($this->help !== null) {
            $out['help'] = $this->help;
        }

        return $out;
    }
}
