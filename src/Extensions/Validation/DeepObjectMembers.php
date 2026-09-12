<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Draft\ParameterDraft;
use Docuccino\Core\Draft\SchemaDraft;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;

/**
 * Where a bracketed query name lands on an operation that already publishes the container it names as a
 * deepObject: `filter[min_days]` is then the `min_days` MEMBER of a `filter` object rather than a
 * parameter of its own. With no such container this is inert. Names are read through
 * {@see FieldPath::fromQueryName()}, the declared inverse of the write that produces them.
 *
 * The answer is a function of what the operation publishes WHEN IT IS ASKED — containers are not all
 * written in one phase, so only a {@see OperationPhase::Finalize} reader sees every one. Design:
 * docs/design/uir-and-extensions.md §"deepObject / bracketed attribute parity"; the one-reading rule the
 * additive and subtractive halves share is docs/design/defect-classes.md §"A subtraction leaves no
 * evidence".
 */
final class DeepObjectMembers
{
    /**
     * Per parent: the schema whose `required` list names the members, and what each was stated to be.
     *
     * @var array<string, array{0: SchemaDraft, 1: array<string, bool>}>
     */
    private array $stated = [];

    public function __construct(
        private readonly OperationDraft $operation,
    ) {}

    /**
     * The draft a bracketed name patches, minting the member if the container does not publish it yet.
     * Null where no deepObject container claims the name, so the name is the parameter.
     */
    public function schemaFor(string $name): ?SchemaDraft
    {
        $resolved = $this->resolve($name);

        return $resolved === null ? null : $resolved[0]->property($resolved[1]);
    }

    /** Record one member's stated requiredness. `null` is the ABSENT statement and records nothing. */
    public function stateRequired(string $name, ?bool $required): void
    {
        $resolved = $this->resolve($name);
        if ($resolved === null || $required === null) {
            return;
        }

        [$parent, $member, $key] = $resolved;

        $this->stated[$key] ??= [$parent, []];
        $this->stated[$key][1][$member] = $required;
    }

    /** State what was accumulated, member by member, at the layer of the producer that stated it. */
    public function flush(Contribution $by): void
    {
        foreach ($this->stated as [$parent, $members]) {
            foreach ($members as $member => $required) {
                $parent->stateMemberRequired((string) $member, $required, $by);
            }
        }
    }

    /**
     * Take one bracketed member off the container that publishes it, answering whether it published the
     * name — the caller's only evidence that the declaration reached anything. Mints nothing on the way,
     * which is where it parts from {@see schemaFor()}.
     */
    public function remove(string $name): bool
    {
        $located = $this->locate($name);

        return $located !== null && $located[0]->removeProperty($located[1]);
    }

    /**
     * {@see remove()}'s own answer, asked without removing anything — reachability for both halves, not
     * a spelling match against {@see memberNames()}, which would answer about neither.
     */
    public function publishes(string $name): bool
    {
        $located = $this->locate($name);

        return $located !== null && $located[0]->publishesProperty($located[1]);
    }

    /**
     * Every member every deepObject query container publishes, bracketed as an author writes it — what a
     * report about an unmatched name lists beside the parameters. Byte-sorted, and read at every depth
     * {@see remove()} reaches, so it never depends on producer order.
     *
     * @return list<string>
     */
    public function memberNames(): array
    {
        $names = [];

        foreach ($this->operation->parameterKeys() as $key) {
            [$in, $container] = array_pad(explode(':', $key, 2), 2, '');
            if ($in !== 'query' || $container === '') {
                continue;
            }

            $parameter = $this->operation->parameter('query', $container);
            if (! self::isContainer($parameter)) {
                continue;
            }

            $names = [...$names, ...self::descend($parameter->schema(), [$container])];
        }

        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * The parent schema, the member name, and the parent's bracketed name as the accumulation key — that
     * name rather than the draft's identity, so grouping never depends on allocation order.
     *
     * @return array{0: SchemaDraft, 1: string, 2: string}|null
     */
    private function resolve(string $name): ?array
    {
        $located = $this->container($name);
        if ($located === null) {
            return null;
        }

        [$parameter, $container, $segments] = $located;
        $member = (string) array_pop($segments);

        $parent = $parameter->schema();
        foreach ($segments as $segment) {
            $parent = $parent->property($segment);
        }

        return [$parent, $member, FieldPath::toQueryName([$container, ...$segments])];
    }

    /**
     * The same walk minting nothing. It descends exactly the depths {@see memberNames()} lists, so a name
     * offered as droppable is one this can drop.
     *
     * @return array{0: SchemaDraft, 1: string}|null
     */
    private function locate(string $name): ?array
    {
        $located = $this->container($name);
        if ($located === null) {
            return null;
        }

        [$parameter, , $segments] = $located;
        $member = (string) array_pop($segments);

        $parent = $parameter->schema();
        foreach ($segments as $segment) {
            if (! $parent->hasProperty($segment)) {
                return null;
            }

            $parent = $parent->property($segment);
        }

        return [$parent, $member];
    }

    /**
     * The ONE reading of "does this bracketed name land in a deepObject container". Both walks start
     * here, so a name cannot be a member for one producer and a parameter for another.
     *
     * @return array{0: ParameterDraft, 1: string, 2: list<string>}|null
     */
    private function container(string $name): ?array
    {
        $segments = FieldPath::fromQueryName($name);
        if ($segments === null || count($segments) < 2) {
            return null;
        }

        $container = array_shift($segments);
        if (! $this->operation->hasParameter('query', $container)) {
            return null;
        }

        $parameter = $this->operation->parameter('query', $container);
        if (! self::isContainer($parameter)) {
            return null;
        }

        return [$parameter, $container, $segments];
    }

    /** Whether a parameter publishes its members as the object this class is about. */
    private static function isContainer(ParameterDraft $parameter): bool
    {
        return $parameter->resolvedField('style') === 'deepObject';
    }

    /**
     * One container's members and their own members, each as a bracketed query name.
     *
     * @param  non-empty-list<string>  $prefix
     * @return list<string>
     */
    private static function descend(SchemaDraft $schema, array $prefix): array
    {
        $names = [];

        foreach ($schema->propertyNames() as $member) {
            $path = [...$prefix, $member];
            $names[] = FieldPath::toQueryName($path);

            if ($schema->hasProperty($member)) {
                $names = [...$names, ...self::descend($schema->property($member), $path)];
            }
        }

        return $names;
    }
}
