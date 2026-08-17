<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Extensions\Context\RouteNotes;

/**
 * A gated seam that aggregates {@see RouteNotes} across a document's routes, so a
 * {@see DocumentTransformer} can report one finding for a fact many routes found (design §10). The
 * collector holds the aggregate; the transformer reads it and does the reporting.
 *
 * The pipeline feeds this chain from the operation fragment and from nowhere else, on warm hits as well
 * as cold builds — which is what makes a warm build report exactly what a cold one reports. An extension
 * therefore writes its fact to `RouteContext::notes()` and never to the collector directly: a fact
 * written straight into the aggregate is a fact a warm build loses.
 *
 * Resolved per-document like the other gated chains ({@see EnvironmentDigestContributor} et al.), so a
 * disabled integration collects nothing. The aggregate belongs to ONE document: {@see forget()} runs
 * before the first route, so a process exporting several documents never reports one document's facts
 * against another's.
 */
interface RouteNoteCollector
{
    /** The {@see RouteNotes} channel this collector drains. */
    public function channel(): string;

    /** Drop the aggregate. Called once per document, before its first route is built. */
    public function forget(): void;

    /**
     * Fold one route's note for `$key` into the aggregate. Called once per route that recorded one, in
     * document route order, and repeatedly for the same key — deduping is the collector's job.
     *
     * @param  list<string>  $values
     */
    public function collect(string $key, array $values): void;
}
