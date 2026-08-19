<?php

declare(strict_types=1);

namespace Docuccino\Core\Lint;

/**
 * Options for {@see SensitiveFieldLint}: the off-switch, a safelist (by property name or JSON
 * pointer), and the heuristics table. The default table lives in core so the reference CLI and
 * other-language producers run the identical rule — Laravel-flavoured rows like `remembertoken` are
 * just neutral entries. Extend it with {@see withPatterns()}.
 */
final readonly class SensitiveFieldLintOptions
{
    /**
     * Normalised token (lower-cased, non-alphanumerics stripped) → label. Most specific first, since
     * a name matches when it *contains* a token and the first hit wins.
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
     * A copy with extra heuristics merged in; existing tokens keep their label.
     *
     * @param  array<string, string>  $patterns
     */
    public function withPatterns(array $patterns): self
    {
        return new self($this->enabled, $this->allow, $this->patterns + $patterns);
    }

    /**
     * Label of the first heuristic the name matches, null when it looks safe. Names normalise to
     * lower-case alphanumerics first, so `api_key`, `apiKey` and `API-KEY` all read as one token.
     */
    public function match(string $name): ?string
    {
        $normalized = self::normalize($name);
        if ($normalized === '') {
            return null;
        }

        foreach ($this->patterns as $token => $label) {
            if ($token !== '' && str_contains($normalized, $token)) {
                return $label;
            }
        }

        return null;
    }

    /**
     * Label of the heuristic a name IS rather than one it merely contains: `token` matches, `token_count`
     * does not. The stronger reading, for a caller judging a value whose own content cannot speak for it.
     */
    public function matchExact(string $name): ?string
    {
        $normalized = self::normalize($name);

        return $normalized === '' ? null : ($this->patterns[$normalized] ?? null);
    }

    /** Lower-case alphanumerics, so `api_key`, `apiKey` and `API-KEY` are one token. */
    private static function normalize(string $name): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $name));
    }
}
