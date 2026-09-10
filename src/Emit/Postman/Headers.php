<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit\Postman;

/**
 * The header list of a Postman request or of a saved response: one entry per header NAME, because a
 * name is case-insensitive on the wire and Postman sends every enabled entry it holds — two entries
 * for one name are two headers on the request.
 *
 * Two kinds of contributor reach a slot. A DERIVED one states a fact about the message this collection
 * actually carries: the media type of the body written beside it, the media type the saved response is
 * written in, the cookie parameters assembled into one `Cookie`. A DECLARED one restates a parameter or
 * a response-header object. Where they contest a name the derived value wins — a request whose
 * `Content-Type` disagrees with its own body is a request that cannot work — while the declared side
 * keeps its prose, and either contributor asking for the header to be sent is enough to send it.
 *
 * OAS settles the commonest contests before they reach a slot, and {@see ignoredParameter()} and
 * {@see ignoredResponseHeader()} are those two rules: a header parameter named `Accept`, `Content-Type`
 * or `Authorization` SHALL be ignored, and so SHALL a response header named `Content-Type`. An ignored
 * declaration contributes nothing, prose included.
 *
 * @internal
 */
final class Headers
{
    /** @var list<string> the parameter names OAS says are not parameters, lowercased */
    private const array IGNORED_PARAMETERS = ['accept', 'content-type', 'authorization'];

    /** @var list<string> the response-header names OAS says are not headers, lowercased */
    private const array IGNORED_RESPONSE_HEADERS = ['content-type'];

    /** @var array<string, array{key: string, value: string, enabled: bool, description: string}> */
    private array $slots = [];

    public static function ignoredParameter(string $name): bool
    {
        return in_array(strtolower($name), self::IGNORED_PARAMETERS, true);
    }

    public static function ignoredResponseHeader(string $name): bool
    {
        return in_array(strtolower($name), self::IGNORED_RESPONSE_HEADERS, true);
    }

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
