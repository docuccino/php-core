<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

/**
 * The seam an inference-engine package implements so a host adapter can build its {@see TypeEngine}
 * without importing it: the adapter probes for the implementation by class name and hands over plain
 * paths. That keeps the analysis toolchain an optional, dev-only dependency of the adapter.
 *
 * Implementations are total — a failed boot returns a {@see NullTypeEngine} rather than throwing.
 */
interface TypeEngineBuilder
{
    /**
     * @param  string  $projectRoot  the application root the engine analyses
     * @param  string  $tmpDir  a writable scratch dir for the engine's own caches; the caller creates it
     * @param  string  $vendorPath  the app's vendor dir: readable for types, never descended into
     * @param  list<string>  $primePaths  source roots whose file bodies must stay intact
     * @param  list<string>  $descendPaths  the narrower set interprocedural descent is confined to
     */
    public function build(
        string $projectRoot,
        string $tmpDir,
        string $vendorPath,
        array $primePaths,
        array $descendPaths,
    ): TypeEngine;
}
