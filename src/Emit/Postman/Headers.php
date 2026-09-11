<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit\Postman;

use Docuccino\Core\Document\IgnoredHeaders;

/**
 * The header list of a Postman request or of a saved response: one entry per header NAME, because a
 * name is case-insensitive on the wire and Postman sends every enabled entry it holds — two entries
 * for one name are two headers on the request.
 *
 * A contested slot, merged member-wise in the asymmetric form docs/design/uir-and-extensions.md §2
 * "A contested published slot" describes. A DERIVED contributor states a fact about the message this
 * collection actually carries — the media type of the body written beside it, the media type the
 * saved response is written in, the cookie parameters assembled into one `Cookie`; a DECLARED one
 * restates a parameter or a response-header object, and the two are not the same kind of claim. So
 * the derived side takes the members it is a fact about, the spelling and the value — a request whose
 * `Content-Type` disagrees with its own body cannot work — the declared side keeps the prose only it
 * has, and `enabled` is the union, because either contributor asking for the header is reason enough
 * to send it.
 *
 * {@see IgnoredHeaders} settles the commonest contests before they ever reach a slot.
 *
 * @internal
 */
final class Headers
{
    /** @var array<string, array{key: string, value: string, enabled: bool, description: string}> */
    private array $slots = [];

    /** A header the collection itself carries, so its value describes the message being sent. */
    public function derived(string $key, string $value, bool $enabled = true): void
    {
        $this->place($key, $value, $enabled, '', true);
    }

    /** A header the document declares, so its prose is the document's and its value a placeholder. */
    public function declared(string $key, string $value, bool $enabled, string $description): void
    {
        $this->place($key, $value, $enabled, $description, false);
    }

    /**
     * Insertion order, so the two headers a consumer always forgets stay at the top and a slot a
     * declaration later joins keeps the position it was opened at.
     *
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->slots as $slot) {
            $entry = ['key' => $slot['key'], 'value' => $slot['value']];

            if (! $slot['enabled']) {
                $entry['disabled'] = true;
            }

            if ($slot['description'] !== '') {
                $entry['description'] = $slot['description'];
            }

            $out[] = $entry;
        }

        return $out;
    }

    private function place(string $key, string $value, bool $enabled, string $description, bool $derived): void
    {
        $name = strtolower($key);
        $held = $this->slots[$name] ?? null;

        if ($held === null) {
            $this->slots[$name] = ['key' => $key, 'value' => $value, 'enabled' => $enabled, 'description' => $description];

            return;
        }

        $this->slots[$name] = [
            // The derived side names the header the way the wire does, so it keeps the spelling and the
            // value whichever contributor opened the slot. Every path that reaches a contest today
            // places the derived contribution second, which is why the other order is written out
            // rather than assumed: which contributor is right is not a fact about which arrived first.
            'key' => $derived ? $key : $held['key'],
            'value' => $derived ? $value : $held['value'],
            'enabled' => $held['enabled'] || $enabled,
            'description' => $held['description'] !== '' ? $held['description'] : $description,
        ];
    }
}
