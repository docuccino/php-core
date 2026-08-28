<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

/**
 * One operation of the documented contract: its stable `x-docuccino.id`, the method and path template
 * it answers, and the raw OAS operation object.
 *
 * Path-item-level parameters are already merged into {@see $parameters} (an operation-level parameter
 * of the same name and location wins, as OAS requires), so a caller never has to remember the
 * inheritance rule.
 */
final readonly class ContractOperation
{
    /** Where a response key that is none of the three OAS forms sorts — last, and out of every count. */
    private const int UNREACHABLE = 3;

    private PathTemplate $template;

    /**
     * @param  string|null  $id  the `x-docuccino.id`, absent only when the artifact carries no identities
     * @param  string  $path  the path TEMPLATE, `/api/invoices/{invoice}`
     * @param  array<string, mixed>  $operation
     * @param  list<ContractParameter>  $parameters
     * @param  list<string>  $segments  document pointer segments addressing this operation
     */
    public function __construct(
        public ?string $id,
        public string $method,
        public string $path,
        public array $operation,
        public array $parameters,
        public array $segments,
    ) {
        $this->template = PathTemplate::parse($path);
    }

    /** `GET /api/invoices/{invoice}` — how a failure message and a coverage row name the operation. */
    public function label(): string
    {
        return $this->method.' '.$this->path;
    }

    /**
     * The path parameters a concrete request path binds to this template, or null when the template
     * does not describe that path at all.
     *
     * @return array<string, string>|null
     */
    public function bind(string $path): ?array
    {
        return $this->template->bind($path);
    }

    /**
     * How specific this template is, for choosing between two that both matched — `/api/invoices/recent`
     * beats `/api/invoices/{invoice}`. Comparable as a string against another matched template.
     *
     * @internal
     */
    public function literalMask(): string
    {
        return $this->template->literalMask();
    }

    /**
     * The documented response for a status code and the pointer segments that address it. A `$ref` into
     * `components/responses` is followed, so the segments name where a reader would actually go and look;
     * one that lands nowhere comes back as the third element, for the caller to fail on
     * ({@see Refs::follow()}).
     *
     * @param  array<string, mixed>  $document
     * @return array{0: array<string, mixed>, 1: list<string>, 2: string|null}|null
     */
    public function responseFor(array $document, int $status): ?array
    {
        $key = $this->responseKeyFor($status);

        if ($key === null) {
            return null;
        }

        /** @var array<string, mixed> $response */
        $response = $this->responses()[$key];

        return Refs::follow($document, $response, [...$this->segments, 'responses', $key]);
    }

    /**
     * Which documented response a status resolves to: the exact code first, then the OAS range (`2XX`),
     * then `default` — null when the contract documents no response this status could be.
     *
     * This is the operation's one status grammar, and {@see responseKeys()} is the same grammar read
     * the other way round: everything this can return is listed there, and nothing else is. So a
     * coverage row, a denominator and a failure message can never disagree about which response a 422
     * belonged to, nor name one a status could never have reached.
     *
     * @internal
     */
    public function responseKeyFor(int $status): ?string
    {
        $responses = $this->responses();

        foreach (self::candidates($status) as $key) {
            if (is_array($responses[$key] ?? null)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * The documented response keys a status can actually reach, ordered exact codes ascending, then
     * ranges, then `default` — a function of the key set alone, so two runs list them identically.
     *
     * A key outside the grammar is {@see unreachableResponseKeys()} instead of appearing here, because
     * anything counting responses would otherwise carry a row nothing can ever fill.
     *
     * @return list<string>
     *
     * @internal
     */
    public function responseKeys(): array
    {
        return $this->keys(true);
    }

    /**
     * The documented response keys no status can ever reach — `4xx` in lower case, `ok`, `twohundred`.
     * Each is valid JSON and passes the OAS meta-schema, and each is still a promise nothing can be
     * checked against or recorded as met.
     *
     * They are excluded from every count rather than dropped from sight: {@see Coverage\CoverageReport}
     * names them, which is where a reader wondering why a denominator is short will be looking.
     *
     * @return list<string>
     *
     * @internal
     */
    public function unreachableResponseKeys(): array
    {
        return $this->keys(false);
    }

    /** The documented status keys, for a "the contract documents 200, 404" message. */
    public function documentedStatuses(): string
    {
        $keys = $this->responseKeys();

        return $keys === [] ? 'none' : implode(', ', $keys);
    }

    /**
     * The documented request body and the segments addressing it, `$ref` followed — with the reference
     * that landed nowhere as the third element, as {@see responseFor()} has it.
     *
     * @param  array<string, mixed>  $document
     * @return array{0: array<string, mixed>, 1: list<string>, 2: string|null}|null
     */
    public function requestBody(array $document): ?array
    {
        return Refs::member($document, $this->operation, 'requestBody', $this->segments);
    }

    /**
     * The `responses` map as written, or empty where the operation has none. Keys come back as the
     * document spelled them, which for `200` is an int — PHP normalises a numeric string key.
     *
     * @return array<array-key, mixed>
     */
    private function responses(): array
    {
        $responses = $this->operation['responses'] ?? null;

        return is_array($responses) ? $responses : [];
    }

    /**
     * The documented keys on one side of the grammar or the other, sorted. Every array-valued member of
     * `responses` is on exactly one side, so the two together are the whole map and neither can quietly
     * lose a key.
     *
     * @return list<string>
     */
    private function keys(bool $reachable): array
    {
        $keys = [];
        foreach ($this->responses() as $key => $response) {
            if (is_array($response) && (self::rank((string) $key) !== self::UNREACHABLE) === $reachable) {
                $keys[] = (string) $key;
            }
        }

        usort($keys, static fn (string $a, string $b): int => [self::rank($a), self::code($a), $a] <=> [self::rank($b), self::code($b), $b]);

        return $keys;
    }

    /**
     * The keys a status could name, most specific first. Only a three-digit status has an exact key or a
     * range — OAS spells both in three digits — so anything else can reach `default` alone: reading the
     * first digit of 1000 as a family would resolve it to `1XX`, which no coverage entry can carry, and
     * the checker and the report would disagree about it forever.
     *
     * @return list<string>
     */
    private static function candidates(int $status): array
    {
        return $status < 100 || $status > 999
            ? ['default']
            : [(string) $status, intdiv($status, 100).'XX', 'default'];
    }

    /**
     * Which family a response key belongs to, lowest first: exact code, range, `default`, anything else.
     *
     * The range is matched case-SENSITIVELY, because OAS spells it `4XX` and a document writing `4xx`
     * has written a key no status resolves to — reading it loosely here and strictly in
     * {@see candidates()} is how a response gets counted and can never be exercised.
     */
    private static function rank(string $key): int
    {
        return match (true) {
            preg_match('/^\d{3}$/D', $key) === 1 => 0,
            preg_match('/^\dXX$/D', $key) === 1 => 1,
            $key === 'default' => 2,
            default => self::UNREACHABLE,
        };
    }

    /** The status a key sorts at within its family — `4XX` sorts where `400` would. */
    private static function code(string $key): int
    {
        if (preg_match('/^(\d)XX$/D', $key, $matches) === 1) {
            return ((int) $matches[1]) * 100;
        }

        return preg_match('/^\d{3}$/D', $key) === 1 ? (int) $key : 0;
    }
}
