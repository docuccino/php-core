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

it('imports no PHPStan namespace but the standalone phpdoc parser', function (): void {
    // `PHPStan\PhpDocParser\` is the small parsing library core's type grammar is built on — production
    // safe, and shipped by every generator in this space. The analyser itself stays banned.
    expect(importsMatching('core', '/^PHPStan\\\\(?!PhpDocParser\\\\)/'))->toBe([]);
});
