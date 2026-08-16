<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

use Docuccino\Core\Document\NodeIdentity;
use Docuccino\Core\Document\UirDocument;

/**
 * Which kinds of node the two sides name in common. Identity pairing keys on ids, so a kind both sides
 * carry several ids for and yet share not one of is the signature of a pairing failure rather than a diff:
 * every one of those nodes reads as removed AND re-added, and the changeset calls an unchanged API
 * breaking. Reported per kind, since operations pairing tells you nothing about whether parameters did.
 *
 * It is a signature, never a proof — the same emptiness is what a wholesale rewrite of every node of that
 * kind looks like — so the caller must present it as something to check, not as a verdict. Hence the floor
 * of {@see MIN_EVIDENCE}: with one id a side, "no id in common" says only that the single node changed,
 * which is the commonest real diff there is and no evidence of anything.
 *
 * @internal
 */
final class IdentityOverlap
{
    /** Ids a side must carry of a kind before their disjointness means anything. */
    private const int MIN_EVIDENCE = 2;

    /**
     * @return list<string> the {@see ChangeTarget} values both sides identify and share no id for
     */
    public static function disjointKinds(UirDocument $old, UirDocument $new): array
    {
        $oldIds = self::identities($old);
        $newIds = self::identities($new);

        // In the fixed, alphabetical order identities() lists its kinds in.
        $out = [];

        foreach ($oldIds as $kind => $ids) {
            if (count($ids) < self::MIN_EVIDENCE || count($newIds[$kind]) < self::MIN_EVIDENCE) {
                continue;
            }

            if (array_intersect_key($ids, $newIds[$kind]) === []) {
                $out[] = $kind;
            }
        }

        return $out;
    }

    /**
     * @return array<string, array<string, true>>
     */
    private static function identities(UirDocument $document): array
    {
        $operations = [];
        $parameters = [];
        $schemas = [];

        // Through the same resolver the pairing uses, or a document whose parameters are all `$ref`s looks
        // to carry no parameter identity at all and the warning goes quiet on the pairing failures it exists
        // to flag.
        $refs = ComponentRefs::of($document);

        foreach ($document->paths ?? [] as $item) {
            // A path item's parameters belong to the operations under it, and the pairing compares them
            // there, so they count as parameter identities here too.
            foreach ($item->operations as $operation) {
                $id = NodeIdentity::of($operation->docuccino, $operation->rest);
                if ($id !== null) {
                    $operations[$id] = true;
                }

                foreach ([...$item->parameters, ...$operation->parameters] as $parameter) {
                    $parameter = $refs->resolveParameter($parameter);
                    $id = NodeIdentity::of($parameter->docuccino, $parameter->rest);
                    if ($id !== null) {
                        $parameters[$id] = true;
                    }
                }
            }
        }

        $components = $document->components;
        if ($components !== null) {
            foreach ($components->schemas as $schema) {
                $id = NodeIdentity::inArray($schema->toArray());
                if ($id !== null) {
                    $schemas[$id] = true;
                }
            }
        }

        return [
            ChangeTarget::Operation->value => $operations,
            ChangeTarget::Parameter->value => $parameters,
            ChangeTarget::Schema->value => $schemas,
        ];
    }
}
