<?php

declare(strict_types=1);

namespace Docuccino\Core\Lint;

/**
 * Options for {@see SensitiveFieldLint}: the off-switch, the per-property safelist (by property name
 * or by JSON pointer), and the heuristics table itself (normalized token → human label). The default
 * table lives here in core so the reference CLI, other-language producers and the SaaS all run the
 * identical rule; Laravel-flavoured rows (e.g. `remembertoken`) are just neutral table entries. The
 * table is extensible without reshaping via {@see withPatterns()}.
 */
final readonly class SensitiveFieldLintOptions
{
    /**
     * Normalized token (lower-cased, non-alphanumerics stripped) → label. Ordered most-specific-first
     * so the reported label is the precise one (a name matches when it CONTAINS a token).
     *
     * @var array<string, string>
     */
    public const DEFAULT_PATTERNS = [
        'remembertoken' => 'a remember-me token',
        'accesstoken' => 'an access token',
        'refreshtoken' => 'a refresh token',
        'clientsecret' => 'a client secret',
        'apisecret' => 'an API secret',
        'apikey' => 'an API key',
        'privatekey' => 'a private key',
        'password' => 'a password',
        'passwd' => 'a password',
        'creditcard' => 'a credit-card number',
        'cardnumber' => 'a card number',
        'internalid' => 'an internal identifier',
        'secret' => 'a secret',
        'token' => 'a token',
        'ssn' => 'a social-security number',
        'cvv' => 'a card verification value',
    ];

    /**
     * @param  list<string>  $allow  property names or `#/…` pointers to silence
     * @param  array<string, string>  $patterns  normalized token → label
     */
    public function __construct(
        public bool $enabled = true,
        public array $allow = [],
        public array $patterns = self::DEFAULT_PATTERNS,
    ) {}

    /**
     * A copy with extra heuristics merged over the current table (existing tokens keep their label).
     *
     * @param  array<string, string>  $patterns
     */
    public function withPatterns(array $patterns): self
    {
        return new self($this->enabled, $this->allow, $this->patterns + $patterns);
    }
}
