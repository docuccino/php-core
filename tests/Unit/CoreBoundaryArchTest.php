<?php

declare(strict_types=1);

/**
 * The core package is framework-agnostic (design §6): its only runtime dependencies are
 * psr/container, opis/json-schema, symfony/yaml, nikic/php-parser, phpstan/phpdoc-parser and the
 * dependency-free docuccino/attributes (core reads Docuccino attributes off reflected classes/enums —
 * SchemaIdentity, EnumReflection, the attribute-overrides extension — so the tiny, lockstep-versioned
 * attribute package is a runtime dependency, deliberately NOT the framework or the analysis engine).
 * These rules freeze that boundary so an accidental `use Illuminate\…` or `use PHPStan\…` in core —
 * which would couple the vocabulary-free core to a host framework or to the static analyser — fails
 * the build.
 */
use Docuccino\Core\Contract\ContractIndex;

arch('core never depends on the Laravel framework')
    ->expect('Docuccino\Core')
    ->not->toUse('Illuminate');

arch('core never depends on the inference engine')
    ->expect('Docuccino\Core')
    ->not->toUse('Docuccino\Inference\PhpStan');

arch('core never depends on the Laravel adapter')
    ->expect('Docuccino\Core')
    ->not->toUse('Docuccino\Laravel');

/**
 * The other half of the `@internal` boundary, which an import scan cannot see: a public method may
 * HAND BACK an internal type, and every caller of `$context->converter()->toSchema(…)` then depends on
 * an internal class's methods while importing nothing. So a public surface — the extension-author
 * contracts plus the two context objects an extension is passed, the DType hierarchy those contracts
 * take and hand back, and the contract-testing surface an adapter's assertions are built on — may only
 * name types that are public themselves. Annotate the method `@internal` if it really is pipeline-only
 * (Draft::guard()), or promote the return type to a contract (TypeSchemaConverter).
 */
it('never hands a public API consumer a type marked @internal', function (): void {
    $internal = static function (?ReflectionType $type): array {
        $named = match (true) {
            $type instanceof ReflectionUnionType, $type instanceof ReflectionIntersectionType => $type->getTypes(),
            $type instanceof ReflectionNamedType => [$type],
            default => [],
        };

        return array_values(array_filter(array_map(
            static fn (ReflectionType $one): string => $one instanceof ReflectionNamedType && ! $one->isBuiltin() ? $one->getName() : '',
            $named,
        ), static function (string $name): bool {
            if ($name === '' || (! class_exists($name) && ! interface_exists($name))) {
                return false;
            }

            return str_contains((string) (new ReflectionClass($name))->getDocComment(), '@internal');
        }));
    };

    $surface = ['Docuccino\Core\Extensions\Context\RouteContext', 'Docuccino\Core\Extensions\Context\DocumentContext'];
    foreach ((array) glob(__DIR__.'/../../src/Extensions/Contracts/*.php') as $file) {
        $surface[] = 'Docuccino\Core\Extensions\Contracts\\'.basename((string) $file, '.php');
    }

    // Everything under Contract/ that is not itself `@internal`: the index, the checker, the values a
    // caller reads off a result, the coverage and example reports. And everything under Inference/ on
    // the same terms — the DType hierarchy is what a TypeToSchema mapper is handed and matches on, and
    // ArgumentSlots is where a reader finds a call's arguments, so both freeze at v1 as the contracts do.
    foreach (['Contract', 'Inference'] as $root) {
        foreach ((array) glob(__DIR__.'/../../src/'.$root.'/{,*/}*.php', GLOB_BRACE) as $file) {
            $directory = basename(dirname((string) $file));
            $class = 'Docuccino\Core\\'.$root.'\\'
                .($directory === $root ? '' : $directory.'\\')
                .basename((string) $file, '.php');

            if (! str_contains((string) (new ReflectionClass($class))->getDocComment(), '@internal')) {
                $surface[] = $class;
            }
        }
    }

    // A glob that stops matching would turn this into a test of nothing, and one that stopped honouring
    // `@internal` would turn it into a test of everything.
    expect($surface)->toContain(
        'Docuccino\Core\Contract\ContractIndex',
        'Docuccino\Core\Contract\Coverage\CoverageReport',
        'Docuccino\Core\Inference\ArgumentSlots',
        'Docuccino\Core\Inference\DType\DType',
    )->and($surface)->not->toContain('Docuccino\Core\Contract\SchemaCheck', 'Docuccino\Core\Inference\LocalWrites');

    $leaks = [];
    foreach ($surface as $class) {
        $reflection = new ReflectionClass($class);
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // A method that says it's internal is honest about it; the rule is about the silent ones.
            if ($method->getDeclaringClass()->getName() !== $class || str_contains((string) $method->getDocComment(), '@internal')) {
                continue;
            }

            foreach ($internal($method->getReturnType()) as $name) {
                $leaks[] = "{$class}::{$method->getName()}() returns {$name}";
            }

            foreach ($method->getParameters() as $parameter) {
                foreach ($internal($parameter->getType()) as $name) {
                    $leaks[] = "{$class}::{$method->getName()}(\${$parameter->getName()}) takes {$name}";
                }
            }
        }

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            foreach ($internal($property->getType()) as $name) {
                $leaks[] = "{$class}::\${$property->getName()} is {$name}";
            }
        }
    }

    expect($leaks)->toBe([]);
});

/**
 * The reflection rule above catches a public method that HANDS BACK something internal. This one catches
 * the other way a promise gets made by accident: a public method that is fine to return but was never
 * meant to be called from outside the package at all.
 *
 * {@see ContractIndex} is where that bites, because it is the one class on the contract-testing surface
 * whose split is invisible from the outside — an adapter's assertions call a handful of these and core's
 * own checker and messages call the rest. The list is the DECISION; reflection is the source of truth,
 * so a public method added without one fails here rather than shipping as a v1 promise.
 */
it('freezes the contract index at the methods outside core actually call', function (): void {
    $public = [];
    foreach ((new ReflectionClass(ContractIndex::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (! str_contains((string) $method->getDocComment(), '@internal')) {
            $public[] = $method->getName();
        }
    }

    sort($public);

    expect($public)->toBe([
        'document',
        'fromArray',
        'fromJson',
        'identities',
        'isUir',
        'match',
        'operation',
        'operations',
        'provenanceOf',
        'supportsWebhooks',
        'webhooksNamed',
    ]);
});

it('names the Laravel adapter nowhere in its source, prose and diagnostics included', function (): void {
    // The arch rules above read `use` statements, so an adapter class named inside a STRING is invisible
    // to them — which is how a core diagnostic came to tell its reader to call one. Core states the
    // action; the framework it is running under is what names the class that performs it.
    $files = (array) glob(__DIR__.'/../../src/{,*/,*/*/,*/*/*/}*.php', GLOB_BRACE);
    $named = [];

    foreach ($files as $file) {
        if (preg_match('/Docuccino\\\\{1,2}Laravel/', (string) file_get_contents((string) $file)) === 1) {
            $named[] = basename((string) $file);
        }
    }

    // A glob that stopped matching would make this a scan of nothing, and pass forever.
    expect(count($files))->toBeGreaterThanOrEqual(200)
        ->and($named)->toBe([]);
});

it('imports no PHPStan namespace but the standalone phpdoc parser', function (): void {
    // `PHPStan\PhpDocParser\` is the small parsing library core's type grammar is built on — production
    // safe, and shipped by every generator in this space. The analyser itself stays banned.
    expect(importsMatching('core', '/^PHPStan\\\\(?!PhpDocParser\\\\)/'))->toBe([]);
});
