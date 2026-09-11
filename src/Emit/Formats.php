<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\Postman\CollectionEmitter;
use InvalidArgumentException;

/**
 * The emitters, by format id: the one place that knows which formats exist, what each can serialise,
 * and which the viewer can serve.
 *
 * One table rather than a list per consumer. The CLI validates `--format` against it, a document's
 * configured export targets validate against it, and the viewer picks an artifact from it — three
 * readers that used to mean three copies drifting apart.
 *
 * {@see TABLE} order is load-bearing twice over: it is the order {@see ids()} lists formats in a
 * diagnostic, and it IS the viewer's preference order ({@see viewerPreference()}). Keep the most
 * faithful format first.
 *
 * @internal
 */
final class Formats
{
    /** What a bare `docuccino:export` writes when nothing names a format. */
    public const string DEFAULT = 'openapi-3.2';

    /**
     * format id => [emitter, serialises YAML, the viewer can serve it, held to a published schema].
     *
     * A format that cannot serialise YAML is one whose consumer parses JSON and nothing else: a `.yaml`
     * path on such a target is rejected rather than filled with JSON, because a file that lies about
     * its own extension is worse than no file.
     *
     * The last column is whether the emitter reads its own output back against a published
     * specification for the version it claims. Only the OpenAPI formats have one: UIR answers to its
     * own schema on every build, before any emission, and a Postman collection has no published schema
     * at all. It is a column rather than an implicit fact about which emitter calls what, because a
     * caller asking "is this artifact sound" needs to know when the answer is that nobody can say.
     *
     * @var array<string, array{class-string<ReportingEmitter>, bool, bool, bool}>
     */
    private const array TABLE = [
        'openapi-3.2' => [OpenApi32Emitter::class, true, true, true],
        'openapi-3.1' => [OpenApi31DownlevelEmitter::class, true, true, true],
        'openapi-3.0' => [OpenApi30DownlevelEmitter::class, true, true, true],
        'uir' => [UirEmitter::class, false, true, false],
        'postman' => [CollectionEmitter::class, false, false, false],
    ];

    /**
     * Formats a committed artifact can be read back as the contract itself, best first. UIR leads
     * because provenance only survives there — an OpenAPI artifact still describes the contract, it
     * just cannot say who wrote a schema — so this is an order of its own rather than the table's.
     * Postman is absent: a collection is a client, not a contract.
     *
     * @var list<string>
     */
    private const array CONTRACT_PREFERENCE = ['uir', 'openapi-3.2', 'openapi-3.1', 'openapi-3.0'];

    /**
     * Every known format id, in table order — the order a "valid values are…" message lists them.
     *
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_keys(self::TABLE);
    }

    public static function supports(string $format): bool
    {
        return isset(self::TABLE[$format]);
    }

    /** Whether this format has a YAML serialisation at all. Unknown formats: false. */
    public static function serialisesYaml(string $format): bool
    {
        return self::TABLE[$format][1] ?? false;
    }

    /**
     * Whether emitting this format holds the bytes it wrote to a published schema for the version they
     * claim, so a caller can tell a clean check from one nobody ran. Unknown formats: false.
     */
    public static function checksEmittedArtifact(string $format): bool
    {
        return self::TABLE[$format][3] ?? false;
    }

    /**
     * {@see CONTRACT_PREFERENCE}, for the contract assertions: which artifact they read, and which they
     * can compare semantically at all. A function of the table, never of the order a user happened to
     * list their export targets in.
     *
     * @return list<string>
     */
    public static function contractPreference(): array
    {
        return self::CONTRACT_PREFERENCE;
    }

    /**
     * Formats the viewer can serve as OpenAPI, best first. A function of the table, never of the order
     * a user happened to list their export targets in.
     *
     * @return list<string>
     */
    public static function viewerPreference(): array
    {
        return array_keys(array_filter(self::TABLE, static fn (array $row): bool => $row[2]));
    }

    /**
     * Emits $document in $format. Callers validate the id first ({@see supports()}); an unknown one is
     * a programming error, not a user-facing degradation, so it throws rather than falling back — a
     * silent fallback here would ship an artifact in a format nobody asked for.
     */
    public static function emit(string $format, UirDocument $document, EmitOptions $options): EmitResult
    {
        $row = self::TABLE[$format] ?? throw new InvalidArgumentException(sprintf(
            'Unknown emit format "%s"; expected one of: %s.',
            $format,
            implode(', ', self::ids()),
        ));

        return (new $row[0])->emitWithReport($document, $options);
    }
}
