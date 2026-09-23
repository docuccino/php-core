<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\Arazzo\ArazzoEmitter;
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
     * format id => [emitter, serialises YAML, the viewer can serve it, held to a published schema,
     * publishes plain OpenAPI].
     *
     * A format that cannot serialise YAML is one whose consumer parses JSON and nothing else: a `.yaml`
     * path on such a target is rejected rather than filled with JSON, because a file that lies about
     * its own extension is worse than no file.
     *
     * The last column is whether the emitter reads its own output back against a published
     * specification for the version it claims. Only the plain OpenAPI formats do, and that is not the
     * same as being the only ones with a schema to answer to: `full` answers to the UIR schema on
     * every build, before any emission, and an Arazzo description answers to the Arazzo schema in the
     * suite rather than at run time. A Postman collection is the one row with no published schema at
     * all. So a reader of this column may say the BYTES were not read back and nothing more — which
     * is what `docuccino:validate` prints. It is a column rather than an implicit fact about which
     * emitter calls what, because a caller asking "is this artifact sound" needs to know when the
     * answer is that nobody can say.
     *
     * `full` and `openapi-3.2` are one document emitted twice: `full` retains the `x-docuccino`
     * extension, `openapi-3.2` strips it. The ids say so, because a reader choosing between them
     * cannot be expected to know that is the whole difference. `full` names no OAS version because it
     * has none to name: it is whatever version the document is natively built at, and a downlevel
     * emitter strips the extension by definition, so there is no `openapi-3.1-full` for it to be told
     * apart from.
     *
     * The fifth column is that difference, asked as a question: does this format publish PLAIN
     * OpenAPI — a description with the extension stripped? A column rather than a test on the
     * spelling of the id, which is the shape the three readers it replaced all had. The ids happen to
     * agree with `str_starts_with($id, 'openapi-')` again today; agreeing is exactly how that proxy
     * survived to be wrong, and what this column answers is a fact about the artifact, which no
     * renaming of the table can move.
     *
     * @var array<string, array{class-string<ReportingEmitter>, bool, bool, bool, bool}>
     */
    private const array TABLE = [
        'openapi-3.2' => [OpenApi32Emitter::class, true, true, true, true],
        'openapi-3.1' => [OpenApi31DownlevelEmitter::class, true, true, true, true],
        'openapi-3.0' => [OpenApi30DownlevelEmitter::class, true, true, true, true],
        'full' => [UirEmitter::class, false, true, false, false],
        'postman' => [CollectionEmitter::class, false, false, false, false],
        'arazzo' => [ArazzoEmitter::class, true, false, false, false],
    ];

    /**
     * Formats a committed artifact can be read back as the contract itself, best first. The full
     * artifact leads because provenance only survives there — a plain OpenAPI artifact still
     * describes the contract, it just cannot say who wrote a schema — so this is an order of its own
     * rather than the table's. Postman is absent: a collection is a client, not a contract. Arazzo is
     * absent for a different reason — it describes sequences OVER the contract and carries no
     * operation's shape at all, so a reader handed one has nothing to check a response against.
     *
     * @var list<string>
     */
    private const array CONTRACT_PREFERENCE = ['full', 'openapi-3.2', 'openapi-3.1', 'openapi-3.0'];

    /**
     * Format ids this version no longer answers to, and what replaced each. NOT an alias table —
     * nothing here resolves to an emitter, and {@see supports()} still says false. It exists so the
     * error a user meets on upgrade names the id to type, rather than leaving them to guess which
     * entry of a six-item list used to be theirs. `--format=uir` is in people's CI pipelines, and
     * this message is the first thing that upgrade shows them.
     *
     * @var array<string, string>
     */
    private const array RENAMED = ['uir' => 'full'];

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
     * Whether this format publishes PLAIN OpenAPI: a description carrying no `x-docuccino`, which is
     * what an artifact meant for consumers points at and what a meta-schema is vendored for. Unknown
     * formats: false.
     *
     * `full` answers false. It is a valid OpenAPI description, but it retains provenance —
     * source file, line, symbol — so it is the artifact a build keeps, never the one it hands out.
     */
    public static function publishesPlainOpenApi(string $format): bool
    {
        return self::TABLE[$format][4] ?? false;
    }

    /**
     * {@see publishesPlainOpenApi()} over the whole table, in table order.
     *
     * For the SUITE. Production asks about one format at a time and has no use for the list; this exists
     * so a test that wants "the plain OpenAPI formats" reads the column instead of filtering the ids by
     * their spelling, which is what three of them used to do.
     *
     * @return list<string>
     */
    public static function plainOpenApi(): array
    {
        return array_values(array_filter(self::ids(), self::publishesPlainOpenApi(...)));
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
     * The sentence an unknown-format message appends: the replacement id when the name is one this
     * version retired, and the empty string otherwise. Every reader that rejects a format id says it,
     * so the CLI, the export-target diagnostic and {@see emit()} cannot answer the same typo
     * differently.
     */
    public static function replacementHint(string $format): string
    {
        $replacement = self::RENAMED[$format] ?? null;

        return $replacement === null ? '' : sprintf(' "%s" is now "%s".', $format, $replacement);
    }

    /**
     * Emits $document in $format. Callers validate the id first ({@see supports()}); an unknown one is
     * a programming error, not a user-facing degradation, so it throws rather than falling back — a
     * silent fallback here would ship an artifact in a format nobody asked for.
     */
    public static function emit(string $format, UirDocument $document, EmitOptions $options): EmitResult
    {
        $row = self::TABLE[$format] ?? throw new InvalidArgumentException(sprintf(
            'Unknown emit format "%s"; expected one of: %s.%s',
            $format,
            implode(', ', self::ids()),
            self::replacementHint($format),
        ));

        return (new $row[0])->emitWithReport($document, $options);
    }
}
