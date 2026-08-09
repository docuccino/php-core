<?php

declare(strict_types=1);

namespace Docuccino\Core\Identity;

/**
 * RFC 4648 base32 encoder, lowercase, no padding — the alphabet used for UIR identities.
 *
 * @internal
 */
final class Base32
{
    private const string ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';

    public static function encode(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }

        $output = '';
        $buffer = 0;
        $bitsLeft = 0;

        for ($i = 0, $len = strlen($bytes); $i < $len; $i++) {
            $buffer = ($buffer << 8) | ord($bytes[$i]);
            $bitsLeft += 8;

            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $output .= self::ALPHABET[($buffer >> $bitsLeft) & 0x1F];
            }
        }

        if ($bitsLeft > 0) {
            $output .= self::ALPHABET[($buffer << (5 - $bitsLeft)) & 0x1F];
        }

        return $output;
    }
}
