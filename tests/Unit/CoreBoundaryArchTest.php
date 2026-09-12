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
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Tests\Fixtures\Boundary\DocblockLeakProbe;
use Docuccino\Core\TypeGrammar\ImportContext;
use Docuccino\Core\TypeGrammar\PhpDocParserStack;
use PHPStan\PhpDocParser\Ast\AbstractNodeVisitor;
use PHPStan\PhpDocParser\Ast\Node as PhpDocParserNode;
use PHPStan\PhpDocParser\Ast\NodeTraverser as PhpDocNodeTraverser;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;

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
/**
 * The types one docblock names, resolved the way PHP would resolve them in $file. Parsed through the
 * product's own phpdoc stack rather than matched: a reflection sweep sees `array`, and `list<Draft>` in
 * the docblock beside it is the type a caller actually receives.
 *
 * @return list<string>
 */
function coreDocBlockTypes(?string $docComment, ?string $file): array
{
    $parsed = (new PhpDocParserStack)->parseDocBlock($docComment);
    if ($parsed === null) {
        return [];
    }

    $visitor = new class extends AbstractNodeVisitor
    {
        /** @var list<string> */
        public array $names = [];

        public function enterNode(PhpDocParserNode $node)
        {
            if ($node instanceof IdentifierTypeNode) {
                $this->names[] = $node->name;
            }

            return null;
        }
    };

    (new PhpDocNodeTraverser([$visitor]))->traverse([$parsed]);

    $imports = ImportContext::forFile($file);
    $names = [];
    foreach ($visitor->names as $name) {
        $names[] = $imports->resolve($name);
    }

    sort($names);

    return array_values(array_unique($names));
}

/**
 * Every public promise the given classes make in an `@internal` type, as a readable sentence each.
 *
 * A function rather than an inline loop so the state it must REFUSE can be run through it: a guard that
 * only ever sees a clean surface passes just as well written `return []`.
 *
 * @param  list<class-string>  $classes
 * @return list<string>
 */
function coreInternalLeaks(array $classes): array
{
    $internal = static function (string $name): bool {
        if (! class_exists($name) && ! interface_exists($name) && ! enum_exists($name)) {
            return false;
        }

        return str_contains((string) (new ReflectionClass($name))->getDocComment(), '@internal');
    };

    $native = static function (?ReflectionType $type, ReflectionClass $owner) use ($internal): array {
        $flat = match (true) {
            $type instanceof ReflectionUnionType, $type instanceof ReflectionIntersectionType => $type->getTypes(),
            $type instanceof ReflectionNamedType => [$type],
            default => [],
        };

        $names = [];
        foreach ($flat as $one) {
            if (! $one instanceof ReflectionNamedType || $one->isBuiltin()) {
                continue;
            }

            // `self` and `static` name the class itself, `parent` its base — all three name a type a
            // reader has to look up, and `parent` can name one that was never meant to be public.
            $name = match (strtolower($one->getName())) {
                'self', 'static' => $owner->getName(),
                'parent' => ($owner->getParentClass() ?: null)?->getName() ?? '',
                default => $one->getName(),
            };

            if ($name !== '' && $internal($name)) {
                $names[] = $name;
            }
        }

        return $names;
    };

    $documented = static function (?string $doc, ?string $file) use ($internal): array {
        return array_values(array_filter(coreDocBlockTypes($doc === false || $doc === null ? null : $doc, $file), $internal));
    };

    $leaks = [];
    foreach ($classes as $class) {
        $reflection = new ReflectionClass($class);
        $file = $reflection->getFileName() === false ? null : $reflection->getFileName();

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // A method that says it's internal is honest about it; the rule is about the silent ones.
            if ($method->getDeclaringClass()->getName() !== $class || str_contains((string) $method->getDocComment(), '@internal')) {
                continue;
            }

            $doc = $method->getDocComment() === false ? null : $method->getDocComment();

            foreach ($native($method->getReturnType(), $reflection) as $name) {
                $leaks[] = "{$class}::{$method->getName()}() returns {$name}";
            }

            foreach ($documented($doc, $file) as $name) {
                $leaks[] = "{$class}::{$method->getName()}() documents {$name}";
            }

            foreach ($method->getParameters() as $parameter) {
                foreach ($native($parameter->getType(), $reflection) as $name) {
                    $leaks[] = "{$class}::{$method->getName()}(\${$parameter->getName()}) takes {$name}";
                }
            }
        }

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            foreach ($native($property->getType(), $reflection) as $name) {
                $leaks[] = "{$class}::\${$property->getName()} is {$name}";
            }

            $doc = $property->getDocComment() === false ? null : $property->getDocComment();
            foreach ($documented($doc, $file) as $name) {
                $leaks[] = "{$class}::\${$property->getName()} documents {$name}";
            }
        }
    }

    sort($leaks);

    return array_values(array_unique($leaks));
}

/**
 * The frozen public surface: the extension-author contracts plus the two context objects an extension is
 * passed, the DType hierarchy those contracts take and hand back, and the contract-testing surface an
 * adapter's assertions are built on.
 *
 * @return list<class-string>
 */
function corePublicSurface(): array
{
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

    /** @var list<class-string> $surface */
    return $surface;
}

it('never hands a public API consumer a type marked @internal', function (): void {
    $surface = corePublicSurface();

    // A glob that stops matching would turn this into a test of nothing, and one that stopped honouring
    // `@internal` would turn it into a test of everything.
    expect($surface)->toContain(
        'Docuccino\Core\Contract\ContractIndex',
        'Docuccino\Core\Contract\Coverage\CoverageReport',
        'Docuccino\Core\Inference\ArgumentSlots',
        'Docuccino\Core\Inference\DType\DType',
    );

    // One expectation per name, because `not->toContain(a, b)` passes the moment ONE of them is
    // absent — a list there is a guard that stops guarding as soon as its first entry is right.
    //
    // The two ParameterSchema types are here deliberately: they are how the CHECKER reads a
    // parameter's declaration, nothing outside core names them, and a type that joins the frozen
    // surface with no user freezes a shape nobody has had to live with yet.
    foreach ([
        'Docuccino\Core\Contract\SchemaCheck',
        'Docuccino\Core\Contract\ParameterSchema',
        'Docuccino\Core\Contract\ParameterSchemaKind',
        'Docuccino\Core\Inference\LocalWrites',
    ] as $marked) {
        expect($surface)->not->toContain($marked);
    }

    expect(coreInternalLeaks($surface))->toBe([]);
});

it('reads the type a member DOCUMENTS as well as the one it declares', function (): void {
    // The reflection sweep answers for native signatures only, and 9 of the surface's public members
    // declare `array` or `iterable` natively with the real type in the docblock beside it — so an
    // `@internal` class promised as `list<LocalWrites>` behind a native `array` was a v1 promise nothing
    // was reading. Written out rather than assumed: the probe makes both promises, and the sweep must
    // name both.
    $leaks = coreInternalLeaks([DocblockLeakProbe::class]);

    expect($leaks)->toBe([
        DocblockLeakProbe::class.'::natively() returns Docuccino\Core\Inference\LocalWrites',
        DocblockLeakProbe::class.'::onlyInTheDocblock() documents Docuccino\Core\Inference\LocalWrites',
    ]);

    // …and a member promising nothing internal is not reported, so the rows above are about what the
    // sweep found and not about one that reports everything.
    expect(coreInternalLeaks([ContractIndex::class]))->toBe([]);
});

it('reads a docblock type through the imports of the file that wrote it', function (string $doc, string $named): void {
    // The imported short name, the ALIASED one and the fully-qualified spelling all have to answer with
    // the same class — an alias is the spelling a name scan cannot follow, and the surface freezes at v1,
    // so a promise made through one is as binding as a promise made through any other.
    $file = __DIR__.'/../Fixtures/Boundary/DocblockLeakProbe.php';

    expect(coreDocBlockTypes($doc, $file))->toContain($named);
})->with([
    'an imported short name' => ['/** @return list<LocalWrites> */', 'Docuccino\Core\Inference\LocalWrites'],
    'an aliased import' => ['/** @return array<string, Concealed> */', 'Docuccino\Core\Inference\LocalWrites'],
    'a fully-qualified name' => ['/** @return \\Docuccino\\Core\\Inference\\LocalWrites|null */', 'Docuccino\Core\Inference\LocalWrites'],
    'a nullable short name' => ['/** @return ?LocalWrites */', 'Docuccino\Core\Inference\LocalWrites'],
    'a union member' => ['/** @return LocalWrites|false */', 'Docuccino\Core\Inference\LocalWrites'],
    'a parameter rather than a return' => ['/** @param  list<LocalWrites>  $writes */', 'Docuccino\Core\Inference\LocalWrites'],
]);

it('reads a docblock on the frozen surface itself, not just on a probe', function (): void {
    // The denominator the docblock half owes: a parser that stopped parsing, or an import context that
    // stopped resolving, would report a clean surface over an empty set of docblocks and pass forever.
    // The surface really does describe its collections in prose — MEASURE it rather than assume.
    $classes = 0;
    foreach (corePublicSurface() as $class) {
        $reflection = new ReflectionClass($class);
        $file = $reflection->getFileName() === false ? null : $reflection->getFileName();

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach (coreDocBlockTypes($method->getDocComment() === false ? null : $method->getDocComment(), $file) as $name) {
                if (class_exists($name) || interface_exists($name) || enum_exists($name)) {
                    $classes++;
                }
            }
        }
    }

    expect($classes)->toBeGreaterThanOrEqual(20);
});

it('names no class for a docblock that promises none', function (): void {
    // The other direction, so the rows above are about what the reader found. A keyword is qualified
    // against the file's namespace the way PHP qualifies an unimported single segment, which resolves to
    // nothing and so promises nothing — what the caller asks of each name is whether it is an `@internal`
    // CLASS, and none of these is a class at all.
    $file = __DIR__.'/../Fixtures/Boundary/DocblockLeakProbe.php';

    foreach (coreDocBlockTypes('/** @return list<int>|array<string, bool> */', $file) as $name) {
        expect(class_exists($name) || interface_exists($name) || enum_exists($name))->toBeFalse();
    }

    expect(coreDocBlockTypes(null, $file))->toBe([])
        ->and(coreDocBlockTypes('/** just prose */', $file))->toBe([]);
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

/**
 * The same freeze over the one façade a THIRD-PARTY rule transformer is handed. Every method here is a
 * v1 promise the moment it ships, and the cost of one that should not have been made is paid by an
 * extension author, not by us: `type()` answered a field's type with a single word and null where it
 * carried several, so three separate readers published a claim about a union they could not see.
 *
 * The list is the DECISION and reflection is the source of truth, so a method added without a line
 * here fails rather than shipping as a promise — and one removed has to be removed here too, which is
 * where a breaking change stops being silent.
 */
it('freezes the rule-transformer field façade at the methods it means to promise', function (): void {
    $public = [];
    foreach ((new ReflectionClass(ValidationField::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getName() !== '__construct' && ! str_contains((string) $method->getDocComment(), '@internal')) {
            $public[] = $method->getName();
        }
    }

    sort($public);

    expect($public)->toBe([
        'get',
        'has',
        'isRequired',
        'markMultipart',
        'markNullable',
        'markOptional',
        'markRequired',
        'markSometimes',
        'mayClaim',
        'path',
        'proposeExample',
        'remove',
        'set',
        // The counterpart of the converter's own `reference()`, which is already public: without it a
        // third-party transformer can mint a component and then have no way to publish a field AS one,
        // which is a capability we would be keeping for ourselves.
        'setReference',
        'setType',
        'setTypes',
        'sibling',
        'types',
    ]);
});

/**
 * The configuration reader and writer are build machinery, not something an extension author is
 * promised. They sit outside every directory the surface above is globbed from, so nothing there would
 * notice one losing its marker — and the surface freezes at v1, which makes a marker dropped by
 * accident a promise made by accident.
 */
it('keeps the configuration reader off the frozen public surface', function (): void {
    $files = (array) glob(__DIR__.'/../../src/Config/*.php');

    // A glob that stopped matching would make this a scan of nothing.
    expect($files)->toHaveCount(3);

    foreach ($files as $file) {
        $class = 'Docuccino\Core\Config\\'.basename((string) $file, '.php');

        expect((new ReflectionClass($class))->getDocComment())->toContain('@internal');
    }
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
