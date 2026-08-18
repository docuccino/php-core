<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

/**
 * The seam an inference-engine package implements so a host adapter can build its {@see TypeEngine}
 * without importing it: the adapter probes for the implementation by class name and hands over plain
 * paths. That keeps the analysis toolchain an optional, dev-only dependency of the adapter.
 *
 * Implementations are total — a failed boot returns a {@see NullTypeEngine} rather than throwing, and
 * names the failure through {@see ReportsBootFailure} so the host can report the degradation instead
 * of publishing a quietly thinner document.
 */
interface TypeEngineBuilder
{
    /**
     * @param  string  $projectRoot  the application root the engine analyses
     * @param  string  $tmpDir  a writable scratch dir for the engine's own caches; the caller creates it
     * @param  string  $vendorPath  the app's vendor dir: readable for types, never descended into
     * @param  list<string>  $primePaths  source roots whose file bodies must stay intact
     * @param  list<string>  $descendPaths  the narrower set interprocedural descent is confined to
     * @param  string|null  $configFile  the application's own analyzer config file, merged into the one
     *                                   the engine writes for itself, so a project's existing analyzer
     *                                   extensions shape what inference recovers; null runs on the
     *                                   engine's own configuration alone, and so does a file that isn't
     *                                   there — the host reports that, the engine never fails over it
     */
    public function build(
        string $projectRoot,
        string $tmpDir,
        string $vendorPath,
        array $primePaths,
        array $descendPaths,
        ?string $configFile = null,
    ): TypeEngine;
}
