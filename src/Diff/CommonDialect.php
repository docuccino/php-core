<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi30Upgrade;
use Docuccino\Core\Support\Arr;
use Docuccino\Core\Support\JsonValue;

/**
 * Two documents read in one OpenAPI dialect, so a diff between them compares what they say rather than
 * how their versions spell it. OpenAPI 3.0 writes a nullable union as `nullable: true` where 3.1 writes a
 * `{type: null}` branch, pins a value with a one-member `enum` where 3.1 writes `const`, moves a `$ref`'s
 * siblings into an `allOf`, and has a dozen more spellings besides; compared keyword by keyword, the 3.0
 * and 3.1 exports of one unchanged API read as hundreds of breaking changes.
 *
 * Where one side is 3.0 and the other is not, the newer side goes through the same downlevel the 3.0
 * export does ({@see OpenApi30DownlevelEmitter}), ids kept, and the two compare as 3.0. One set of rewrites
 * answers both, so the differ cannot disagree with the emitter about what a spelling means. The 3.0 side
 * goes through it too, so what 3.0 ignores — a `$ref`'s siblings, a boolean bound qualifying no bound —
 * is not compared. It is read first as its own 3.2 export reads it ({@see OpenApi30Upgrade}): a
 * `nullable` with no `type` beside it has two readings, and the downlevel spells the one the newer side
 * states. Two 3.0 documents have no newer reading to agree with, so each keeps its own spelling and loses
 * only what 3.0 ignores, which is all their 3.0 exports would change.
 *
 * What 3.0 cannot say is out of the comparison: the newer side's webhooks, its pages and the schema
 * keywords 3.0 drops are not there to differ. Switching a document's export from 3.0 to 3.1 changes no
 * contract, and that is what the changeset then says.
 *
 * @internal
 */
final class CommonDialect
{
    /**
     * $old and $new read as 3.0 where either of them is, the 3.0 side in the newer side's meaning where
     * only one is.
     *
     * @return array{UirDocument, UirDocument}
     */
    public static function of(UirDocument $old, UirDocument $new): array
    {
        $was30 = self::is30($old);
        $is30 = self::is30($new);

        if (! $was30 && ! $is30) {
            return [$old, $new];
        }

        if ($was30 && $is30) {
            return [self::stripped($old), self::stripped($new)];
        }

        return [self::downlevel($old, newerReading: $was30), self::downlevel($new, newerReading: $is30)];
    }

    private static function is30(UirDocument $document): bool
    {
        return OpenApi30Upgrade::reads30($document->openapi);
    }

    /** A 3.0 document as its 3.0 export says it: what 3.0 ignores dropped, everything else as written. */
    private static function stripped(UirDocument $document): UirDocument
    {
        return UirDocument::fromArray(OpenApi30Upgrade::stripped($document->toArray()));
    }

    /** $document's 3.0 export, ids kept, a 3.0 source read first as its 3.2 export states it where `$newerReading`. */
    private static function downlevel(UirDocument $document, bool $newerReading = false): UirDocument
    {
        $diagnostics = [];

        return self::hydrate((new OpenApi30DownlevelEmitter)->toOpenApiArray($document, $diagnostics, new EmitOptions(keepIds: true), $newerReading));
    }

    /**
     * $array read back as an uploaded document is, from its canonical bytes, so the two sides hydrate alike.
     *
     * @param  array<string, mixed>  $array
     */
    private static function hydrate(array $array): UirDocument
    {
        $decoded = JsonValue::decode((new CanonicalJsonSerializer)->serialize((new Canonicalizer)->canonicalize($array)));

        return UirDocument::fromArray(Arr::stringKeyed(is_array($decoded) ? $decoded : []));
    }
}
