<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
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
 * answers both, so the differ cannot disagree with the emitter about what a spelling means.
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
     * $old and $new, the newer one downlevelled where exactly one of them is OpenAPI 3.0.
     *
     * @return array{UirDocument, UirDocument}
     */
    public static function of(UirDocument $old, UirDocument $new): array
    {
        $was30 = self::is30($old);
        $is30 = self::is30($new);

        if ($was30 === $is30) {
            return [$old, $new];
        }

        return $was30 ? [$old, self::downlevel($new)] : [self::downlevel($old), $new];
    }

    private static function is30(UirDocument $document): bool
    {
        return preg_match('/^3\.0(\.|$)/', $document->openapi) === 1;
    }

    /**
     * Read back as an uploaded 3.0 document is, from its canonical bytes, so the two sides hydrate alike.
     */
    private static function downlevel(UirDocument $document): UirDocument
    {
        $diagnostics = [];
        $array = (new OpenApi30DownlevelEmitter)->toOpenApiArray($document, $diagnostics, new EmitOptions(keepIds: true));
        $decoded = JsonValue::decode((new CanonicalJsonSerializer)->serialize((new Canonicalizer)->canonicalize($array)));

        return UirDocument::fromArray(Arr::stringKeyed(is_array($decoded) ? $decoded : []));
    }
}
