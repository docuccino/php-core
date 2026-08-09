<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff\Policy;

/**
 * The result of a {@see VersioningPolicy} evaluation: whether the version delta satisfies the
 * policy, a stable machine `code`, a human message, and — when violated — the lowest version that
 * would satisfy it (so CLIs and the SaaS can suggest the fix). Deterministic and side-effect free.
 */
final readonly class PolicyVerdict
{
    public function __construct(
        public bool $satisfied,
        public string $policy,
        public string $code,
        public string $message,
        public ?string $requiredVersion = null,
    ) {}

    public static function ok(string $policy, string $code = 'ok', string $message = 'The version change satisfies the policy.'): self
    {
        return new self(true, $policy, $code, $message);
    }

    public static function violation(string $policy, string $code, string $message, ?string $requiredVersion = null): self
    {
        return new self(false, $policy, $code, $message, $requiredVersion);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'satisfied' => $this->satisfied,
            'policy' => $this->policy,
            'code' => $this->code,
            'message' => $this->message,
        ];

        if ($this->requiredVersion !== null) {
            $out['requiredVersion'] = $this->requiredVersion;
        }

        return $out;
    }
}
