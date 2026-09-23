<?php

declare(strict_types=1);

use Docuccino\Core\Spec\UirSpec;
use Docuccino\Core\SpecValidation\Validator;

/*
 * A published schema has to validate an instance with nothing beside it.
 *
 * That is not a nicety. A user vendors `schema.json` into their repository and points their own
 * validator at it, and a reference by absolute `$id` then costs them either an outbound fetch to
 * spec.docuccino.app on every CI run — which is what `check-jsonschema` does by default — or an
 * unresolved-reference failure where the tool declines to make one. Splitting the family into two
 * published files destroyed the property silently, because Docuccino's own `Validator` registered
 * the bundled copy under the referenced id and so never took either path.
 *
 * Draft 2020-12's answer is an embedded schema resource: the sibling, verbatim, under a `$defs`
 * member and carrying its own `$id`, so the reference resolves inside the file it was written in.
 * `composer sync-schema` generates it, for the reason every second copy in this repository is
 * generated. Two properties hold it, and they fail in opposite directions:
 *
 *   - nothing the file references leaves it, so a vendored copy is complete; and
 *   - what it embeds equals what the family publishes standalone, so the copy cannot go stale.
 *
 * The rule is stated here rather than asked of the tool that writes it. A guard that reads the
 * generator's own answer agrees with whatever the generator does.
 */

/**
 * Every absolute `$ref` target stated anywhere in $path, deduplicated, the fragment dropped — one
 * resource answers `…/extension.schema.json` and `…#/$defs/node` alike.
 *
 * @return list<string>
 */
function absoluteSchemaReferences(string $path): array
{
    $found = [];

    $walk = static function (mixed $node) use (&$walk, &$found): void {
        foreach ((array) $node as $key => $value) {
            if ($key === '$ref' && is_string($value) && str_contains($value, '://')) {
                $found[explode('#', $value)[0]] = true;
            } elseif (is_object($value) || is_array($value)) {
                $walk($value);
            }
        }
    };

    $walk(decodedSchema($path));

    return array_keys($found);
}

/**
 * Every schema resource EMBEDDED in $path — each subschema carrying an `$id` of its own, keyed by it
 * and valued as canonical text so the comparison below is exact.
 *
 * The root's own `$id` is excluded: it names the file rather than anything inside it.
 *
 * @return array<string, string>
 */
function embeddedSchemaResources(string $path): array
{
    $found = [];

    $walk = static function (mixed $node, bool $root) use (&$walk, &$found): void {
        if (is_object($node) && ! $root && is_string($node->{'$id'} ?? null)) {
            $found[$node->{'$id'}] = (string) json_encode($node, JSON_THROW_ON_ERROR);
        }

        foreach ((array) $node as $value) {
            if (is_object($value) || is_array($value)) {
                $walk($value, false);
            }
        }
    };

    $walk(decodedSchema($path), true);

    return $found;
}

/**
 * The references $path states that resolve to nothing inside it. The predicate the guards turn on,
 * kept apart from the files so the refusals below can be executed against copies written to fail it.
 *
 * @return list<string>
 */
function referencesLeavingSchema(string $path): array
{
    $resolvable = array_keys(embeddedSchemaResources($path));

    $own = decodedSchema($path)->{'$id'} ?? null;

    if (is_string($own)) {
        $resolvable[] = $own;
    }

    return array_values(array_diff(absoluteSchemaReferences($path), $resolvable));
}

/** Objects, never associative arrays: an empty `{}` decoded to an array comes back `[]`, a different schema. */
function decodedSchema(string $path): object
{
    $decoded = json_decode((string) file_get_contents($path), false, flags: JSON_THROW_ON_ERROR);

    expect($decoded)->toBeInstanceOf(stdClass::class, $path);

    return $decoded;
}

/** $path as canonical text, for comparing an embedded copy against the file it was generated from. */
function canonicalSchemaText(string $path): string
{
    return (string) json_encode(decodedSchema($path), JSON_THROW_ON_ERROR);
}

it('states absolute references at all, so the scans below are over something', function (): void {
    // Anti-vacuity. A file with no absolute reference in it is trivially self-contained, and a walk
    // that stopped recognising `$ref` would report every schema in the family clean for ever.
    $references = absoluteSchemaReferences(dirname(__DIR__, 4).'/spec/uir/2.0/schema.json');

    expect($references)->toBe([UirSpec::extensionSchemaUrl()])
        ->and(count(embeddedSchemaResources(dirname(__DIR__, 4).'/spec/uir/2.0/schema.json')))->toBe(1);
});

it('leaves no reference outside the file it is written in', function (string $version, string $name): void {
    expect(referencesLeavingSchema(dirname(__DIR__, 4).'/spec/uir/'.$version.'/'.$name))->toBe([]);
})->with(publishedSchemaFiles());

it('embeds each referenced sibling as the bytes that sibling publishes', function (string $version, string $name): void {
    $root = dirname(__DIR__, 4).'/spec/uir/'.$version;

    // What the version publishes standalone, by the `$id` each file declares.
    $published = [];
    foreach (schemaVersionsUnder(dirname(__DIR__, 4).'/spec/uir')[$version] as $sibling) {
        $published[(string) decodedSchema($root.'/'.$sibling)->{'$id'}] = $root.'/'.$sibling;
    }

    foreach (embeddedSchemaResources($root.'/'.$name) as $id => $embedded) {
        expect(isset($published[$id]))->toBeTrue($name.' embeds '.$id.', which this version does not publish');

        expect($embedded)->toBe(canonicalSchemaText($published[$id]), $name.' has drifted from '.basename($published[$id]).' — run composer sync-schema');
    }
})->with(publishedSchemaFiles());

it('holds the copy the validator actually loads to both properties', function (): void {
    // The drift guard makes the three copy roots byte-equal, but this is the file a build reads, so
    // it is asserted rather than inferred.
    expect(referencesLeavingSchema(Validator::defaultSchemaPath()))->toBe([])
        ->and(embeddedSchemaResources(Validator::defaultSchemaPath()))
        ->toBe([UirSpec::extensionSchemaUrl() => canonicalSchemaText(Validator::defaultExtensionSchemaPath())]);
});

/*
 * Both halves executed, against files written to fail them. The equality is the half a stale copy
 * trips, and it has to fail from EITHER side — a hand-edit to the embedded resource and a hand-edit
 * to the standalone file are the same defect seen from two directions, and a guard that only reads
 * one of them passes for the other.
 */
it('calls a reference that leaves the file, and a copy edited on either side', function (): void {
    $tmp = sys_get_temp_dir().'/docuccino-self-containment-'.uniqid();
    @mkdir($tmp.'/2.0', 0755, true);

    $copy = static function (string $name) use ($tmp): string {
        copy(dirname(__DIR__, 4).'/spec/uir/2.0/'.$name, $tmp.'/2.0/'.$name);

        return $tmp.'/2.0/'.$name;
    };

    $document = $copy('schema.json');
    $extension = $copy('extension.schema.json');

    $embedded = static fn (): array => embeddedSchemaResources($document);

    // Positive control: the faithful pair satisfies both halves.
    expect(referencesLeavingSchema($document))->toBe([])
        ->and($embedded()[UirSpec::extensionSchemaUrl()])->toBe(canonicalSchemaText($extension));

    // One: the embedded resource edited. The reference still resolves; the copy no longer agrees.
    file_put_contents($document, str_replace(
        '"Docuccino Extension"',
        '"Docuccino Extension (hand-edited)"',
        (string) file_get_contents($document),
    ));

    expect(referencesLeavingSchema($document))->toBe([])
        ->and($embedded()[UirSpec::extensionSchemaUrl()])->not->toBe(canonicalSchemaText($extension));

    $document = $copy('schema.json');

    // Two: the standalone file edited instead, which is the direction a reader is far likelier to
    // take — it is the file the spec page links.
    file_put_contents($extension, str_replace(
        '"Docuccino Extension"',
        '"Docuccino Extension (hand-edited)"',
        (string) file_get_contents($extension),
    ));

    expect($embedded()[UirSpec::extensionSchemaUrl()])->not->toBe(canonicalSchemaText($extension));

    // Three: the embedded resource removed, which is the shape the split shipped — the reference is
    // still written, and there is nothing in the file for it to land on.
    file_put_contents($document, (string) preg_replace(
        '/\n    "extension": \{.*\n    \},(?=\n    "info")/sU',
        '',
        (string) file_get_contents($document),
    ));

    expect(embeddedSchemaResources($document))->toBe([])
        ->and(referencesLeavingSchema($document))->toBe([UirSpec::extensionSchemaUrl()]);

    array_map('unlink', (array) glob($tmp.'/2.0/*'));
    @rmdir($tmp.'/2.0');
    @rmdir($tmp);
});
