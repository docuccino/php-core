<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Attributes\DeprecatedOperation;
use Docuccino\Attributes\DescriptionFromFile;
use Docuccino\Attributes\Group;
use Docuccino\Attributes\Internal;
use Docuccino\Attributes\OperationId;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Support\ConfinedPath;
use Docuccino\Core\Support\Fqcn;

/**
 * The overrides layer: docblock summary/description (docblock precedence), then the operation
 * attributes (attribute precedence) — `#[OperationId]` over the default route-name strategy,
 * `#[Group]` → tags, `#[DeprecatedOperation]`, `#[Internal]` → `x-internal`, and
 * `#[DescriptionFromFile]` loading a markdown file into the description.
 */
final class AttributeOverridesExtension implements OperationExtension
{
    public function __construct(
        private readonly string $basePath,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Overrides;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        // Docblock layer.
        $operation->setSummary($context->summary, Contribution::docblock());
        $operation->setDescription($context->description, Contribution::docblock());

        // Default operationId from the representation policy, at fallback precedence so
        // #[OperationId] still wins.
        $operation->setOperationId($this->defaultOperationId($context), Contribution::fallback());

        $attribute = Contribution::attribute($context->actionSource());

        $operationId = $context->attributes->first(OperationId::class);
        if ($operationId !== null) {
            $operation->setOperationId($operationId->id, $attribute);
        }

        $tags = $this->tags($context);
        if ($tags !== []) {
            $operation->setTags($tags, $attribute);
        }

        if ($context->attributes->has(DeprecatedOperation::class)) {
            $operation->setDeprecated(true, $attribute);
        }

        if ($context->attributes->has(Internal::class)) {
            $operation->set('x-internal', true, $attribute);
        }

        $fromFile = $context->attributes->first(DescriptionFromFile::class);
        if ($fromFile !== null) {
            $this->applyDescriptionFromFile($operation, $context, $fromFile->path, $attribute);
        }
    }

    /**
     * Load a `#[DescriptionFromFile]` markdown file into the description, confined to the app base
     * path: a path escaping the base is rejected with an error diagnostic and never read. A file that
     * is read joins the route's dependencies so editing it invalidates the cached operation.
     */
    private function applyDescriptionFromFile(OperationDraft $operation, RouteContext $context, string $path, Contribution $attribute): void
    {
        $resolved = ConfinedPath::resolve($this->basePath, $path);
        if ($resolved === null) {
            $context->components->addDiagnostic(new Diagnostic(
                severity: Severity::Error,
                code: 'description-file.escapes-base-path',
                message: sprintf('#[DescriptionFromFile] path "%s" escapes the application base path and was rejected.', $path),
                source: $context->actionSource(),
            ));

            return;
        }

        $contents = @file_get_contents($resolved);
        if ($contents === false) {
            return;
        }

        $context->dependencies()->addFile($resolved);
        $operation->setDescription(rtrim($contents, "\n"), $attribute);
    }

    /**
     * The operationId the representation policy dictates: `route-name` uses the route's name,
     * `controller-method` builds `{ShortController}@{method}` — falling back to the route name for a
     * closure route with no class.
     */
    private function defaultOperationId(RouteContext $context): ?string
    {
        if ($context->representation()->operationId !== 'controller-method') {
            return $context->route->name;
        }

        $class = $context->actionRef->class;
        if ($class === null) {
            return $context->route->name;
        }

        return Fqcn::short($class).'@'.$context->actionRef->method;
    }

    /**
     * @return list<string>
     */
    private function tags(RouteContext $context): array
    {
        $tags = [];
        foreach ($context->attributes->all(Group::class) as $group) {
            $mapped = $context->document->mapTag($group->name);
            if (! in_array($mapped, $tags, true)) {
                $tags[] = $mapped;
            }
        }

        if ($tags !== []) {
            return $tags;
        }

        // No #[Group]: the document's default strategy decides, and the assembler re-derives the same
        // answer to spot two controllers landing on one tag — so the rule lives there, once.
        $default = $context->document->defaultTag($context->actionRef->class);

        return $default === null ? [] : [$default];
    }
}
