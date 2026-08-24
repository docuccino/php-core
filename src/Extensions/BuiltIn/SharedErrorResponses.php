<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Extensions\Schema\ComponentNames;
use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Core\Support\Arr;
use Docuccino\Core\Support\Json;

/**
 * Collapses a repeated error body into shared components, in two independent passes: the body SHAPE into
 * `components.schemas`, then — over the rewritten document — the whole RESPONSE into
 * `components.responses`. That order is load-bearing and the two are never alternatives; design §"Shared
 * error components" has the argument, and §2 "Component naming" the names.
 *
 * Identity survives both rewrites: an operation keeps its own response id and provenance beside the
 * `$ref`, and a hoisted component carries an id minted from the bytes it publishes — never a per-route
 * source, which has no business speaking for the other routes sharing it.
 *
 * What REPEATS decides whether a body is hoisted; what its producer DECLARED
 * ({@see ResponseDraft::claimComponentName()}) decides only what the component is called — so a
 * declaration can add a component and never take one away. It names the RESPONSE, and reaches the shape
 * inside it only where that shape is the whole of it ({@see schemaSites()}). Design §"Shared error
 * components".
 *
 * A media type's `example` and its `examples` map are how an operation ILLUSTRATES the body, not what the
 * body IS, so the second pass keeps both out of the key and republishes every arm's illustration on the
 * one shared response — design §"Shared error components". An author's own example NAMES survive that
 * merge and outrank the minted ones. A name two arms give to two DIFFERENT examples is held by neither:
 * each is published under a key minted from that name and its own content, so no illustration is lost, no
 * arm's example ends up behind another's label, and nothing about how a body is illustrated can cost the
 * document a component.
 *
 * A response's `summary` and `description` are prose about the same body for the same reason, so they are
 * out of the key too ({@see spoken()}): what they never do is retire the name of a contract they do not
 * change. The component publishes the wording the most arms state, and an arm that says something else
 * states its own on the reference that overrides it — `summary` and `description` are fixed fields of a
 * Reference Object from OAS 3.1 on.
 *
 * Deliberately narrow: 4xx/5xx only, only bodies that actually repeat, and only responses carrying
 * `content`. Anything already a `$ref` is left alone, which is what makes a second run a no-op.
 *
 * @phpstan-type Illustrations array<string, array<string, mixed>>
 * @phpstan-type Authored array<string, array<string, mixed>>
 * @phpstan-type Claimed array<string, array<string, array<string, mixed>>>
 * @phpstan-type Prose array<string, string>
 * @phpstan-type ProseVotes array<string, array{prose: Prose, count: int}>
 * @phpstan-type Occurrence array{scope: string, group: string, base: string, body: array<array-key, mixed>, illustrations: Illustrations, claimed: Claimed, prose: ProseVotes, count: int, rejected: array<string, array{string, string|null}>}
 */
final class SharedErrorResponses implements DocumentTransformer
{
    /** Below this a response isn't an error, so a shared error shape is none of its business. */
    private const MIN_STATUS = 400;

    /** The provenance key stripped from a hoisted body and kept on the referring node. */
    private const PROVENANCE = 'x-docuccino';

    /** The Media Type Object members that illustrate a body: one, and the map of several. */
    private const EXAMPLE = 'example';

    private const EXAMPLES = 'examples';

    /**
     * The Response Object members that are prose about a body rather than the body, in the order a
     * response states them — so one arm's wording is comparable with another's however each was built.
     */
    private const PROSE = ['summary', 'description'];

    /** The buckets this transformer publishes into, as the `$ref`s pointing at them spell them. */
    private const SCHEMAS = '#/components/schemas/';

    private const RESPONSES = '#/components/responses/';

    /**
     * How many occurrences make a body worth hoisting. Deliberately not a local boundary — a second
     * occurrence promotes the first from inline to `$ref` — which is a ranked trade, not an oversight:
     * design §"Shared error components".
     */
    private const MIN_OCCURRENCES = 2;

    public function transform(UirDocumentDraft $document, DocumentContext $context): void
    {
        if (! RepresentationPolicy::fromConfig($context->config->representation)->errorComponents) {
            return;
        }

        $doc = $document->toArray();
        $paths = $doc['paths'] ?? null;
        if (! is_array($paths)) {
            return;
        }

        $components = is_array($doc['components'] ?? null) ? $doc['components'] : [];

        $shapes = self::shareable(self::collect($paths, self::schemaSites(...), self::stated(...)));
        [$paths, $schemas, $schemaContests, $aliases] = self::shareShapes($paths, $shapes, self::bucket($components, 'schemas'));

        $bodies = self::shareable(self::collect($paths, self::responseSites(...), self::illustrated(...), $aliases));
        [$paths, $responses, $responseContests] = self::shareResponses($paths, $bodies, self::bucket($components, 'responses'));

        foreach ([...self::rejectedClaims($shapes, $bodies), ...$schemaContests, ...$responseContests] as $diagnostic) {
            $context->report($diagnostic);
        }

        if ($schemas === null && $responses === null) {
            return;
        }

        if ($schemas !== null) {
            $components['schemas'] = $schemas;
        }

        if ($responses !== null) {
            $components['responses'] = $responses;
        }

        $doc['paths'] = $paths;
        $doc['components'] = $components;

        $document->replace($doc);
    }

    /**
     * @param  array<array-key, mixed>  $components
     * @return array<string, mixed>
     */
    private static function bucket(array $components, string $kind): array
    {
        return is_array($components[$kind] ?? null) ? Arr::stringKeyed($components[$kind]) : [];
    }

    /**
     * Pass one: hoist every repeated body shape, rewriting each media type's `schema` to a `$ref`.
     * Hands pass two the ALIASES its shared shapes were published under, since two responses spelling
     * one shape under two names are still two statements of one body.
     *
     * @param  array<array-key, mixed>  $paths
     * @param  array<string, Occurrence>  $shapes
     * @param  array<string, mixed>  $existing
     * @return array{array<array-key, mixed>, array<string, mixed>|null, list<Diagnostic>, array<string, string>}
     */
    private static function shareShapes(array $paths, array $shapes, array $existing): array
    {
        if ($shapes === []) {
            return [$paths, null, [], []];
        }

        $identity = new IdentityGenerator;
        [$names, $schemas, $contests] = self::mint($shapes, $existing, static fn (array $occurrence): array => [
            self::PROVENANCE => ['id' => $identity->publishedSchemaId($occurrence['scope'], Arr::stringKeyed($occurrence['body']))],
        ] + $occurrence['body']);

        $aliases = [];
        foreach ($names as $key => $name) {
            $aliases[$name] = $shapes[$key]['group'];
        }

        return [
            self::rewrite($paths, $names, self::schemaSites(...), self::stated(...), self::SCHEMAS),
            $schemas,
            self::collisions($contests, $names, 'schemas'),
            $aliases,
        ];
    }

    /**
     * Pass two: hoist every response the rewritten document now states identically two or more times,
     * carrying the illustrations the arms did not agree on into the one component they now share, under
     * the prose the most of them state ({@see spoken()}).
     *
     * @param  array<array-key, mixed>  $paths
     * @param  array<string, Occurrence>  $bodies
     * @param  array<string, mixed>  $existing
     * @return array{array<array-key, mixed>, array<string, mixed>|null, list<Diagnostic>}
     */
    private static function shareResponses(array $paths, array $bodies, array $existing): array
    {
        if ($bodies === []) {
            return [$paths, null, []];
        }

        $disagreements = self::disagreements($bodies);

        $spoken = [];
        foreach ($bodies as $key => $occurrence) {
            $spoken[$key] = self::spoken($occurrence);
            $bodies[$key]['body'] = $spoken[$key] + $occurrence['body'];
        }

        [$names, $bucket, $contests] = self::mint(
            $bodies,
            $existing,
            static fn (array $occurrence): array => self::illustrate(
                $occurrence['body'],
                $occurrence['illustrations'],
                $occurrence['claimed'],
            ),
        );

        return [
            self::rewrite($paths, $names, self::responseSites(...), self::illustrated(...), self::RESPONSES, $spoken),
            $bucket,
            [...self::collisions($contests, $names, 'responses'), ...$disagreements],
        ];
    }

    /**
     * The prose a shared response publishes: the wording the most of its arms state.
     *
     * Two arms answering one status with one body in two wordings are one contract described twice, and
     * keying on the words made them two components neither of which could keep the plain name — so one
     * endpoint's private phrasing renamed the type every other endpoint's 404 published under. The
     * argument, and where the wordings this does not publish go, is design §"Shared error components".
     *
     * How many arms state a wording is all that decides it, ties settled by the wording itself: a
     * function of the set, never of the walk. The empty wording never wins, since a Response Object's
     * `description` is required and a body most of whose arms say nothing still has to publish one.
     *
     * @param  Occurrence  $occurrence
     * @return Prose
     */
    private static function spoken(array $occurrence): array
    {
        $votes = $occurrence['prose'];
        ksort($votes);

        $spoken = [];
        $stated = 0;
        foreach ($votes as $vote) {
            if ($vote['count'] > $stated) {
                $spoken = $vote['prose'];
                $stated = $vote['count'];
            }
        }

        return $spoken;
    }

    /**
     * One warning per body whose arms gave one name to two examples. An example name is the author's own,
     * so that is a question only they can settle: publishing either example under it would put one arm's
     * body behind the other's label, and dropping one loses an illustration. Both are published instead,
     * under keys minted from the contested name and their own content — so the name the author chose is
     * the one thing the document does not state, which is why saying nothing would leave them looking for
     * a key that is not there.
     *
     * @param  array<string, Occurrence>  $bodies
     * @return list<Diagnostic>
     */
    private static function disagreements(array $bodies): array
    {
        ksort($bodies);

        $out = [];
        foreach ($bodies as $occurrence) {
            $names = self::contested($occurrence['claimed']);
            if ($names === []) {
                continue;
            }

            $out[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'components.example-name-conflict',
                message: sprintf(
                    'Operations answering with the same error body give %s to more than one example, so each of those examples was published on the shared %s in components.responses under a key derived from its own content instead.',
                    implode(' and ', array_map(static fn (string $name): string => sprintf('the name "%s"', $name), $names)),
                    $occurrence['base'],
                ),
                help: 'Two examples under one name have to be the same example. Give each its own `name:`, or make the ones sharing a name agree, and the name is published as it was written.',
            );
        }

        return $out;
    }

    /**
     * Every hoistable node of one response, as `[pointer into the response, body, the name claimed for
     * it]`. A schema pass reads one per media type; a response pass reads the response itself.
     *
     * A producer's claim names the RESPONSE, and it reaches the shape underneath only where the response
     * states exactly ONE representation — there the shape IS the named error's body, and the two buckets
     * publish one concept under one name. A response offering several says nothing about which of them
     * the name belongs to, so each shape asks for its status instead: `components.schemas` and
     * `components.responses` hold different kinds of thing, and a shape named after the response
     * enclosing it asserts it is that error when it may be the alternative representation beside it.
     *
     * @param  array<array-key, mixed>  $response
     * @return list<array{list<array-key>, array<array-key, mixed>, string|null}>
     */
    private static function schemaSites(array $response): array
    {
        /** @var array<array-key, mixed> $content */
        $content = $response['content'];

        $claim = count($content) === 1 ? self::claimed($response) : null;

        $out = [];
        foreach ($content as $mediaType => $media) {
            $schema = is_array($media) ? ($media['schema'] ?? null) : null;
            if (is_array($schema) && self::isHoistable($schema)) {
                $out[] = [['content', $mediaType, 'schema'], $schema, $claim];
            }
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $response
     * @return list<array{list<array-key>, array<array-key, mixed>, string|null}>
     */
    private static function responseSites(array $response): array
    {
        return [[[], $response, self::claimed($response)]];
    }

    /**
     * What a hoistable node STATES, and how it ILLUSTRATES and DESCRIBES it — the split that decides
     * which half is dedupe key and which half travels into the shared component. A schema illustrates
     * nothing and describes nothing but itself, so the shape pass states all of it.
     *
     * @param  array<array-key, mixed>  $body
     * @return array{array<array-key, mixed>, Illustrations, Authored, Prose}
     */
    private static function stated(array $body): array
    {
        return [$body, [], [], []];
    }

    /**
     * The same split for a whole response, where a media type's `example` and its `examples` map are both
     * illustration: they come off the key, so two arms rendering one contract with two example bodies are
     * one response, and the examples travel with it ({@see illustrate()}).
     *
     * An authored map is lifted whole, under the names its author gave it. Leaving it in the key was the
     * defensible-looking answer — a document has published those names and nothing here may rename one —
     * and it is the wrong one: it makes the response's identity a function of who annotated it, so
     * naming an example on ONE route pushed an unrelated route's body back inline and retired the shared
     * component both were referencing. The names are kept instead of the key ({@see illustrate()}).
     *
     * Only BESIDE a schema. A media type stating an example and no shape has nothing to illustrate — the
     * example is the only claim it makes — so that one stays part of what the response is. One stating
     * BOTH members is left whole too: OpenAPI already calls that document wrong, and this pass has no
     * business tidying it by merging half of it away.
     *
     * The response's own `summary` and `description` come off the key with them ({@see spoken()}): they
     * are the words an operation puts to a body, and a body is what a component is.
     *
     * @param  array<array-key, mixed>  $response
     * @return array{array<array-key, mixed>, Illustrations, Authored, Prose}
     */
    private static function illustrated(array $response): array
    {
        $prose = [];
        foreach (self::PROSE as $member) {
            $value = $response[$member] ?? null;
            if (is_string($value)) {
                $prose[$member] = $value;
                unset($response[$member]);
            }
        }

        $content = $response['content'] ?? null;
        if (! is_array($content)) {
            return [$response, [], [], $prose];
        }

        $illustrations = [];
        $authored = [];

        foreach ($content as $mediaType => $media) {
            if (! is_array($media) || ! isset($media['schema'])) {
                continue;
            }

            $singular = array_key_exists(self::EXAMPLE, $media);
            $map = is_array($media[self::EXAMPLES] ?? null) && $media[self::EXAMPLES] !== []
                ? Arr::stringKeyed($media[self::EXAMPLES])
                : null;

            // Exactly one of the two, or there is nothing here this pass may touch.
            if ($singular === ($map !== null)) {
                continue;
            }

            if ($map === null) {
                $example = $media[self::EXAMPLE];
                $illustrations[(string) $mediaType] = [Json::stable($example) => $example];
                unset($media[self::EXAMPLE]);
            } else {
                $authored[(string) $mediaType] = $map;
                unset($media[self::EXAMPLES]);
            }

            $content[$mediaType] = $media;
        }

        $response['content'] = $content;

        return [$response, $illustrations, $authored, $prose];
    }

    /**
     * The shared response as it publishes: the body every arm agreed on, plus the illustrations they did
     * not. ONE illustration goes out as the media type's `example` — the simplest thing that says it, the
     * bytes an unmerged document already published, and a member no OpenAPI version this emits refuses.
     * Several go out as `examples`, whose keys {@see ComponentNames} mints from each body's own content.
     *
     * A content-derived key is opaque, which for a COMPONENT name would be a real cost — a generated
     * client is written against it. An example key is not: no code generator turns one into a type, so
     * this is the one place the invariant can be paid for in readability rather than in meaning. Every
     * MINTED key is a function of its own example alone, so an arm arriving or leaving never renames
     * another's.
     *
     * Nothing here can make an illustration false. The arms were merged on a key that keeps every media
     * type and every `schema` in it, so each example sits beside exactly the schema it sat beside before
     * — a merge widens no contract, and there is none to re-check against.
     *
     * Where an arm named its examples, the shared component publishes those names: an author's key is
     * already a function of their declaration, so it disturbs nothing, and it reads far better than a
     * hash. Minting climbs past the names they used, so neither kind of key can take the other's place
     * and no illustration is lost to a collision.
     *
     * A name two arms gave to two different examples is the one an author has to settle, and the ladder
     * every contested name in this document climbs is what settles it here too: each example takes that
     * name plus a hash of its own content ({@see disagreements()}).
     *
     * @param  array<array-key, mixed>  $body
     * @param  Illustrations  $illustrations
     * @param  Claimed  $claimed
     * @return array<array-key, mixed>
     */
    private static function illustrate(array $body, array $illustrations, array $claimed): array
    {
        if (($illustrations === [] && $claimed === []) || ! is_array($body['content'] ?? null)) {
            return $body;
        }

        [$authored, $disputed] = self::settle($claimed);

        $content = $body['content'];

        $mediaTypes = array_keys($illustrations + $claimed);
        sort($mediaTypes, SORT_STRING);

        foreach ($mediaTypes as $mediaType) {
            if (! is_array($content[$mediaType] ?? null)) {
                continue;
            }

            $media = $content[$mediaType];
            $values = $illustrations[$mediaType] ?? [];
            $names = $authored[$mediaType] ?? [];
            $contested = $disputed[$mediaType] ?? [];
            ksort($values);
            ksort($names);
            ksort($contested);

            if ($names === [] && $contested === [] && count($values) === 1) {
                $media[self::EXAMPLE] = reset($values);
                $content[$mediaType] = $media;

                continue;
            }

            $merged = $names + self::named($values, $contested, array_keys($names));
            ksort($merged);

            $media[self::EXAMPLES] = $merged;
            $content[$mediaType] = $media;
        }

        $body['content'] = $content;

        return $body;
    }

    /**
     * Every illustration nobody may publish under a name of their own, as an Example Object under a
     * minted key: one nobody named asks for `example`, and one whose name two arms gave to two examples
     * asks for that name. Filed in key order so even the bucket's insertion order is a function of the
     * bodies rather than of the walk that met them.
     *
     * @param  array<string, mixed>  $values  canonical bytes → the example they encode
     * @param  array<string, array<string, mixed>>  $contested  contested name → canonical bytes → the Example Object under it
     * @param  list<string>  $taken  names an author already gave an example on this media type
     * @return array<string, mixed>
     */
    private static function named(array $values, array $contested, array $taken): array
    {
        $claims = [];
        $bodies = [];

        // Filed under the name asked for and the bytes published — the pair that identifies one
        // illustration, and a key an author's own example name cannot collide with.
        foreach ($values as $content => $value) {
            $claim = Json::stable([null, $content]);
            $claims[$claim] = ['base' => self::EXAMPLE, 'identity' => null, 'content' => (string) $content];
            $bodies[$claim] = ['value' => $value];
        }

        foreach ($contested as $name => $examples) {
            ksort($examples);
            foreach ($examples as $content => $example) {
                $claim = Json::stable([(string) $name, $content]);
                $claims[$claim] = ['base' => (string) $name, 'identity' => null, 'content' => (string) $content];
                $bodies[$claim] = $example;
            }
        }

        if ($claims === []) {
            return [];
        }

        [$names] = ComponentNames::mint($claims, $taken);

        $examples = [];
        foreach ($names as $claim => $name) {
            $examples[$name] = $bodies[$claim];
        }

        ksort($examples);

        return $examples;
    }

    /**
     * What the arms named their examples, split into the names they agree on and the ones they do not: a
     * name is one arm's word for one example, so a name every arm that used it used for the SAME example
     * is published as written, and one used for two is published for neither ({@see named()}).
     *
     * The whole rule reads off the set of distinct examples per name, so it is a function of the arms and
     * not of the order they were walked — and a third arm restating a body already there changes nothing.
     *
     * @param  Claimed  $claimed
     * @return array{Authored, Claimed}
     */
    private static function settle(array $claimed): array
    {
        $authored = [];
        $disputed = [];

        foreach ($claimed as $mediaType => $names) {
            foreach ($names as $name => $examples) {
                if (count($examples) === 1) {
                    $authored[$mediaType][$name] = reset($examples);

                    continue;
                }

                $disputed[$mediaType][$name] = $examples;
            }
        }

        return [$authored, $disputed];
    }

    /**
     * The example names the arms of one body gave to more than one example, in name order.
     *
     * @param  Claimed  $claimed
     * @return list<string>
     */
    private static function contested(array $claimed): array
    {
        [, $disputed] = self::settle($claimed);

        $names = [];
        foreach ($disputed as $byName) {
            foreach (array_keys($byName) as $name) {
                $names[$name] = true;
            }
        }

        $contested = array_map(strval(...), array_keys($names));
        sort($contested, SORT_STRING);

        return $contested;
    }

    /**
     * Count what every hoistable node states, keyed by the scope it will PUBLISH under ({@see scope()})
     * and its canonical content, and filed under the GROUP that decides whether it publishes at all:
     * the status and the body, which no declaration has a say in ({@see shareable()}).
     *
     * How an arm words the body and how it illustrates it are both outside that key, so one bucket holds
     * every wording and every illustration of one contract, and nothing either kind of annotation says
     * can decide whether the contract publishes. What the arms name their examples is settled inside the
     * bucket instead: a name they agree on is kept, and one they do not is handed to neither of them
     * ({@see named()}). Deciding it here rather than while publishing keeps it a function of the SET of
     * arms — a third arm restating what a second contested changes nothing.
     *
     * @param  array<array-key, mixed>  $paths
     * @param  callable(array<array-key, mixed>): list<array{list<array-key>, array<array-key, mixed>, string|null}>  $sites
     * @param  callable(array<array-key, mixed>): array{array<array-key, mixed>, Illustrations, Authored, Prose}  $split
     * @param  array<string, string>  $aliases  the shapes pass one published, resolved away before grouping
     * @return array<string, Occurrence>
     */
    private static function collect(array $paths, callable $sites, callable $split, array $aliases = []): array
    {
        $out = [];

        foreach (self::responses($paths) as [$status, $response]) {
            if (! self::isShareable($status, $response)) {
                continue;
            }

            $rejected = self::rejected($response);

            foreach ($sites($response) as [, $body, $name]) {
                $scope = self::scope((string) $status, $name);
                [$stripped, $illustrations, $authored, $prose] = $split(self::stripProvenance($body));
                $key = self::key($scope, $stripped);

                $out[$key] ??= [
                    'scope' => $scope,
                    'group' => self::key((string) $status, self::withoutMintedNames($stripped, $aliases)),
                    'base' => $name ?? 'Error'.$status,
                    'body' => $stripped,
                    'illustrations' => [],
                    'claimed' => [],
                    'prose' => [],
                    'count' => 0,
                    'rejected' => [],
                ];
                $out[$key]['count']++;
                $out[$key]['rejected'] += $rejected;

                if ($prose !== []) {
                    $vote = Json::stable($prose);
                    $out[$key]['prose'][$vote] ??= ['prose' => $prose, 'count' => 0];
                    $out[$key]['prose'][$vote]['count']++;
                }

                foreach ($illustrations as $mediaType => $values) {
                    $out[$key]['illustrations'][$mediaType] = ($out[$key]['illustrations'][$mediaType] ?? []) + $values;
                }

                // Every name an arm gave an example, with the DISTINCT examples the arms put under it —
                // one of them and the name is theirs, two and it is nobody's ({@see settle()}).
                foreach ($authored as $mediaType => $map) {
                    foreach ($map as $exampleName => $example) {
                        $out[$key]['claimed'][$mediaType][(string) $exampleName][Json::stable($example)] = $example;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Every response the document states, as `[status, response]` — the walk `collect()` and the claim
     * check share. Anything that isn't the shape a document is supposed to have is walked past.
     *
     * @param  array<array-key, mixed>  $paths
     * @return iterable<int, array{array-key, array<array-key, mixed>}>
     */
    private static function responses(array $paths): iterable
    {
        foreach ($paths as $operations) {
            if (! is_array($operations)) {
                continue;
            }

            foreach ($operations as $operation) {
                if (! is_array($operation) || ! is_array($operation['responses'] ?? null)) {
                    continue;
                }

                foreach ($operation['responses'] as $status => $response) {
                    if (is_array($response)) {
                        yield [$status, $response];
                    }
                }
            }
        }
    }

    /**
     * The bodies worth hoisting: the ones whose GROUP repeats — status and body, every declaration
     * erased, which is the count a document nobody declared anything in would have had. Counting per
     * PUBLICATION would let one route naming its error put an unrelated route's body back inline:
     * design §"Shared error components".
     *
     * @param  array<string, Occurrence>  $bodies
     * @return array<string, Occurrence>
     */
    private static function shareable(array $bodies): array
    {
        $occurrences = [];
        foreach ($bodies as $body) {
            $occurrences[$body['group']] = ($occurrences[$body['group']] ?? 0) + $body['count'];
        }

        return array_filter($bodies, static fn (array $body): bool => $occurrences[$body['group']] >= self::MIN_OCCURRENCES);
    }

    /**
     * A response body with every reference to a shape this run just published replaced by the shape's
     * own group — so two responses that differ only in which NAME their identical shape went out under
     * are one body when the grouping counts them. Pass one has published nothing yet and hands an empty
     * map, which walks the body and changes none of it.
     *
     * @param  array<array-key, mixed>  $body
     * @param  array<string, string>  $aliases  published schema name → the group behind it
     * @return array<array-key, mixed>
     */
    private static function withoutMintedNames(array $body, array $aliases): array
    {
        foreach ($body as $key => $value) {
            if ($key === '$ref' && is_string($value) && str_starts_with($value, self::SCHEMAS)) {
                $body[$key] = $aliases[substr($value, strlen(self::SCHEMAS))] ?? $value;

                continue;
            }

            if (is_array($value)) {
                $body[$key] = self::withoutMintedNames($value, $aliases);
            }
        }

        return $body;
    }

    /**
     * The published name of every shared body, the bucket it was hoisted into, and the names that were
     * contested.
     *
     * The naming is {@see ComponentNames}'s, not this transformer's: every path that mints a component
     * name owes it that invariant, and a second implementation of "plain name, then content hash, then
     * a numeric tail" is how the two would come to disagree. Each body states a claim with no identity
     * to carry, so the bytes stand in for one and the ladder degenerates to exactly that pair.
     *
     * So a base name — `Error<status>`, or whatever the producer declared — belongs to a body only
     * while ONE claims it: two make it contested and each takes a name derived from its own content,
     * and a third arriving later disturbs neither. A component already holding a name with a DIFFERENT
     * body is `$taken` and cannot move — this pass runs after the registry's names are published — so
     * the shared body climbs past it instead. One holding an IDENTICAL body is not taken, which is what
     * keeps a rebuild over a restored document byte-identical.
     *
     * @param  array<string, Occurrence>  $bodies
     * @param  array<string, mixed>  $existing
     * @param  callable(Occurrence): array<array-key, mixed>  $publish
     * @return array{array<string, string>, array<string, mixed>, array<string, list<string>>}
     */
    private static function mint(array $bodies, array $existing, callable $publish): array
    {
        $claims = [];
        $published = [];
        foreach ($bodies as $key => $body) {
            $claims[$key] = ['base' => $body['base'], 'identity' => null, 'content' => $key];
            $published[$key] = $publish($body);
        }

        $taken = [];
        foreach ($existing as $name => $body) {
            if (! in_array($body, $published, true)) {
                $taken[] = $name;
            }
        }

        [$names, $contests] = ComponentNames::mint($claims, $taken);

        // Filed in content order, so even the bucket's INSERTION order — which survives `toArray()`
        // and is only sorted away on emit — is a function of the bodies rather than of the order the
        // document walk met them.
        $ordered = $names;
        ksort($ordered);

        $bucket = $existing;
        foreach ($ordered as $key => $name) {
            $bucket[$name] = $published[$key];
        }

        return [$names, $bucket, $contests];
    }

    /**
     * One warning per name a body could not keep. `Error404` is a name a client's generated type is
     * called after, and a second 404 shape retires it and repoints every operation that referenced it —
     * a real change to what the document publishes, and the trade this transformer makes deliberately
     * (naming the common single-shape case `Error404_a1b2c3d4` to spare a few documents a one-time
     * rename would be worse for everyone). What it must not be is silent.
     *
     * Two things send a body up the ladder, and the reader can only act on the one that happened: several
     * bodies wanting one name, or ONE body wanting a name an existing component already holds
     * ({@see mint()}'s `$taken`, which arrives here as a lone claimant). Neither may be reported as a name
     * somebody "claimed" — a base name is `Error<status>` until a producer says otherwise, so the common
     * reading of that word sends an author hunting for a declaration that was never written.
     *
     * @param  array<string, list<string>>  $contests  asked name → the bodies that asked for it
     * @param  array<string, string>  $names  body → the name it was published under
     * @return list<Diagnostic>
     */
    private static function collisions(array $contests, array $names, string $bucket): array
    {
        ksort($contests);

        $out = [];
        foreach ($contests as $asked => $claimants) {
            $published = array_map(static fn (string $key): string => $names[$key] ?? $key, $claimants);
            sort($published);

            // A lone claimant climbed past a name it never contested with another body.
            $incumbent = count($claimants) === 1;

            $out[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'components.name-collision',
                message: $incumbent
                    ? sprintf(
                        'A component in components.%s already holds the name "%s", so the shared error body that would have taken it was published under a name derived from its own content (%s) instead.',
                        $bucket,
                        $asked,
                        implode(', ', $published),
                    )
                    : sprintf(
                        'More than one shared error body would have taken the component name "%s", so each was published under a name derived from its own content (%s) in components.%s.',
                        $asked,
                        implode(', ', $published),
                        $bucket,
                    ),
                help: ($incumbent
                    ? 'The component already holding the name was published before this pass ran and cannot move. Give the error body a name of its own with #[ErrorComponent], or rename the component holding this one, and it publishes under a plain name again.'
                    : 'A shared error body is named after its status unless something names it, and a name belongs to one body only while it holds it alone. Nothing to do if these really are different errors and the derived names read well enough; otherwise have the operations state one body, or name each body with #[ErrorComponent] — one name per body, since a name spread over an exception family, or over a method answering at several statuses, is contested the same way.'
                ).($bucket === 'schemas' ? ' A declared name names the response, so it names the shape under it only where the response states one representation; a response offering several says nothing about which of them is the one it named.' : ''),
            );
        }

        return $out;
    }

    /**
     * Points every shared body at its component, keeping the body's own provenance beside the `$ref` —
     * a per-route fact the hoisted component cannot state.
     *
     * An arm whose prose is not the prose the component publishes keeps its own beside the `$ref`, where
     * a Reference Object's `summary` and `description` override the ones it points at ({@see spoken()}).
     *
     * @param  array<array-key, mixed>  $paths
     * @param  array<string, string>  $names
     * @param  callable(array<array-key, mixed>): list<array{list<array-key>, array<array-key, mixed>, string|null}>  $sites
     * @param  callable(array<array-key, mixed>): array{array<array-key, mixed>, Illustrations, Authored, Prose}  $split
     * @param  array<string, Prose>  $spoken  body → the prose its component publishes
     * @return array<array-key, mixed>
     */
    private static function rewrite(array $paths, array $names, callable $sites, callable $split, string $prefix, array $spoken = []): array
    {
        foreach ($paths as $path => $operations) {
            if (! is_array($operations)) {
                continue;
            }

            foreach ($operations as $method => $operation) {
                if (! is_array($operation) || ! is_array($operation['responses'] ?? null)) {
                    continue;
                }

                $responses = $operation['responses'];
                $rewrote = false;

                foreach ($responses as $status => $response) {
                    if (! is_array($response) || ! self::isShareable($status, $response)) {
                        continue;
                    }

                    foreach ($sites($response) as [$pointer, $body, $claim]) {
                        $scope = self::scope((string) $status, $claim);
                        [$stripped, , , $prose] = $split(self::stripProvenance($body));
                        $key = self::key($scope, $stripped);
                        $name = $names[$key] ?? null;
                        if ($name === null) {
                            continue;
                        }

                        $reference = ['$ref' => $prefix.$name];
                        if ($prose !== ($spoken[$key] ?? [])) {
                            $reference += $prose;
                        }

                        if (array_key_exists(self::PROVENANCE, $body)) {
                            $reference = [self::PROVENANCE => $body[self::PROVENANCE]] + $reference;
                        }

                        $response = self::place($response, $pointer, $reference);
                        $rewrote = true;
                    }

                    $responses[$status] = $response;
                }

                if ($rewrote) {
                    $operation['responses'] = $responses;
                    $operations[$method] = $operation;
                    $paths[$path] = $operations;
                }
            }
        }

        return $paths;
    }

    /**
     * Write `$value` at `$pointer` within `$node`; an empty pointer replaces the node itself.
     *
     * @param  array<array-key, mixed>  $node
     * @param  list<array-key>  $pointer
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function place(array $node, array $pointer, array $value): array
    {
        if ($pointer === []) {
            return $value;
        }

        $head = array_shift($pointer);

        if ($pointer === []) {
            $node[$head] = $value;

            return $node;
        }

        $child = $node[$head] ?? null;
        if (is_array($child)) {
            $node[$head] = self::place($child, $pointer, $value);
        }

        return $node;
    }

    /**
     * An error response with a real body that isn't already a reference. A response stating BOTH a
     * `$ref` and a body is left alone too: a Reference Object defines no `content`, so whatever built it
     * is saying something this transformer cannot safely rewrite.
     *
     * @param  array-key  $status
     * @param  array<array-key, mixed>  $response
     */
    private static function isShareable(int|string $status, array $response): bool
    {
        return ! isset($response['$ref'])
            && is_array($response['content'] ?? null)
            && $response['content'] !== []
            && self::shares((string) $status);
    }

    /**
     * Whether a status is one this pass groups at all — the same question, spelled once, for anything
     * that has to keep out of its way. A producer deciding whether it may publish something this pass
     * would then group on has to read exactly the statuses this reads: a wildcard `4XX` is not one of
     * them, and a guard that thought it was would hold back an example nothing was going to touch.
     */
    public static function shares(string $status): bool
    {
        return ctype_digit($status) && (int) $status >= self::MIN_STATUS;
    }

    /**
     * A body worth hoisting: one that states something, and isn't already pointing somewhere else.
     *
     * @param  array<array-key, mixed>  $body
     */
    private static function isHoistable(array $body): bool
    {
        return $body !== [] && ! isset($body['$ref']);
    }

    /**
     * The dedupe identity of a body: its scope and everything it states, with provenance already
     * removed and keys sorted so two bodies assembled in different orders still collapse together.
     * List order is NOT normalised — `required: [a, b]` and `required: [b, a]` emit different bytes, so
     * treating them as one body would have to pick which bytes to publish.
     *
     * @param  array<array-key, mixed>  $body
     */
    private static function key(string $scope, array $body): string
    {
        return $scope."\0".Json::stable($body);
    }

    /**
     * What distinguishes one publication of a body from another carrying the same bytes: its status and
     * the name claimed for the node being published — which for a shape is the enclosing response's
     * claim only where it speaks for that shape ({@see schemaSites()}). Why both halves: design
     * §"Shared error components".
     */
    private static function scope(string $status, ?string $name): string
    {
        return $name === null ? $status : $status."\0".$name;
    }

    /**
     * The component name a producer declared for this response and this pass will honour: null when
     * none did, and null when the one declared is no legal component key ({@see rejectedClaims()}).
     *
     * @param  array<array-key, mixed>  $response
     */
    private static function claimed(array $response): ?string
    {
        $name = self::declared($response);

        return $name !== null && ComponentNames::isLegal($name) ? $name : null;
    }

    /**
     * The name a producer declared, legal or not. A non-string is read as no declaration at all — an
     * overlay or a hand-written document can put anything anywhere, and this walks past what it cannot
     * read rather than reporting on it.
     *
     * @param  array<array-key, mixed>  $response
     */
    private static function declared(array $response): ?string
    {
        $extension = $response[self::PROVENANCE] ?? null;
        $facts = is_array($extension) ? ($extension['facts'] ?? null) : null;
        $name = is_array($facts) ? ($facts[ResponseDraft::COMPONENT] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * The illegal name a response declared and who declared it, keyed by the pair that identifies the
     * mistake. Empty for a legal declaration, and for no declaration at all.
     *
     * @param  array<array-key, mixed>  $response
     * @return array<string, array{string, string|null}>
     */
    private static function rejected(array $response): array
    {
        $name = self::declared($response);
        if ($name === null || ComponentNames::isLegal($name)) {
            return [];
        }

        $producer = self::declarer($response);

        return [$name."\0".($producer ?? '') => [$name, $producer]];
    }

    /**
     * One warning per producer of a name no `$ref` could carry, so an author error costs the document a
     * better name and never its validity. Deduped by the pair that identifies the mistake — a mapper
     * wrong on one route is wrong on every route it maps — and raised only for bodies that were
     * actually published, which are the only ones "named after its status instead" is true of.
     *
     * @param  array<string, Occurrence>  ...$published
     * @return list<Diagnostic>
     */
    private static function rejectedClaims(array ...$published): array
    {
        $rejected = [];
        foreach ($published as $bodies) {
            foreach ($bodies as $body) {
                $rejected += $body['rejected'];
            }
        }

        ksort($rejected);

        $out = [];
        foreach ($rejected as [$name, $producer]) {
            $out[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'components.name-invalid',
                message: sprintf(
                    '%s declared the component name "%s" for a shared error response, which is not a name an OpenAPI component key can carry, so the body was named after its status instead.',
                    $producer === null ? 'A producer' : sprintf('"%s"', $producer),
                    $name,
                ),
                help: 'A component key is letters, digits, ".", "_" and "-" only. A reason phrase as one word — "NotFound", "TooManyRequests" — is what reads best as a generated client\'s type.',
            );
        }

        return $out;
    }

    /**
     * The producer that declared the name, read off the provenance record owning the field — the one
     * fact in the document that says whose mistake an illegal name is.
     *
     * @param  array<array-key, mixed>  $response
     */
    private static function declarer(array $response): ?string
    {
        $extension = $response[self::PROVENANCE] ?? null;
        $records = is_array($extension) ? ($extension['provenance'] ?? null) : null;
        if (! is_array($records)) {
            return null;
        }

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $fields = $record['fields'] ?? null;
            if (is_array($fields) && in_array(ResponseDraft::COMPONENT, $fields, true)) {
                $producer = $record['producer'] ?? null;

                return is_string($producer) ? $producer : null;
            }
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function stripProvenance(array $value): array
    {
        unset($value[self::PROVENANCE]);

        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::stripProvenance($v);
            }
        }

        return $value;
    }
}
