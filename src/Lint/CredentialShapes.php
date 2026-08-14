<?php

declare(strict_types=1);

namespace Docuccino\Core\Lint;

/**
 * Known-credential shapes for the leakage lint's *value* scan — the patterns a string can only
 * plausibly match by being a real secret.
 *
 * Deliberately shape-only: no entropy scoring, because entropy fires on UUIDs, ULIDs, hashes and
 * base64 sample payloads, which are exactly what a good example value looks like. A benign member
 * name is no defence here, so this table is what catches a folded `self::SIGNING_KEY` sitting under
 * `type` or `detail`.
 */
final class CredentialShapes
{
    /**
     * Regex → label. Order is irrelevant; the first match wins and every entry is mutually exclusive.
     *
     * @var array<string, string>
     */
    public const PATTERNS = [
        '/-----BEGIN (?:[A-Z]+ )?PRIVATE KEY-----/' => 'a PEM private key',
        '/(?:AKIA|ASIA)[0-9A-Z]{16}/' => 'an AWS access key id',
        '/(?:ghp_|github_pat_)[A-Za-z0-9_]{20,}/' => 'a GitHub token',
        '/(?:sk|rk)_live_[A-Za-z0-9]{10,}/' => 'a live Stripe secret key',
        '/xox[baprs]-[A-Za-z0-9-]{10,}/' => 'a Slack token',
        '/eyJ[A-Za-z0-9_-]{4,}\.[A-Za-z0-9_-]{4,}\.[A-Za-z0-9_-]*/' => 'a JWT',
        '~[a-z][a-z0-9+.-]*://[^\s:/?#@]+:[^\s:/?#@]+@~' => 'a URL with embedded credentials',
    ];

    /** Label of the first shape the string matches, null when it looks like an ordinary value. */
    public static function label(string $value): ?string
    {
        foreach (self::PATTERNS as $pattern => $label) {
            if (preg_match($pattern, $value) === 1) {
                return $label;
            }
        }

        return null;
    }
}
