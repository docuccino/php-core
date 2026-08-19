<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Attributes\DeprecatedOperation;
use Docuccino\Attributes\Description;
use Docuccino\Attributes\Group;
use Docuccino\Attributes\Internal;
use Docuccino\Attributes\OperationId;
use Docuccino\Attributes\Summary;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Support\ConfinedPath;
use Docuccino\Core\Support\Fqcn;
use Docuccino\Core\Support\LineEndings;

/**
 * The overrides layer: docblock summary/description (docblock precedence), then the operation
 * attributes (attribute precedence) — `#[OperationId]` over the default route-name strategy,
 * `#[Group]` → tags, `#[DeprecatedOperation]`, `#[Internal]` → `x-internal`, and
 * `#[Summary]` / `#[Description]` over whatever the docblock said.
 *
 * The last pair exist because one docblock serves two readers. Prose written for whoever maintains
 * the action is not prose an API consumer can use, and until these there was no way to say the second
 * thing without deleting the first.
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

        $summary = $context->attributes->first(Summary::class);
        if ($summary !== null) {
            $operation->setSummary($summary->text, $attribute);
        }

        $description = $context->attributes->first(Description::class);
        if ($description !== null) {
            $this->applyDescription($operation, $context, $description, $attribute);
        }
    }

    /**
     * Apply `#[Description]`: inline `text:`, or the markdown file `file:` names. Exactly one of them
     * says something, so a declaration carrying both or neither is diagnosed and writes nothing —
     * picking one would document prose the author never chose.
     */
    private function applyDescription(OperationDraft $operation, RouteContext $context, Description $description, Contribution $attribute): void
    {
        if (($description->text === null) === ($description->file === null)) {
            $this->report($context, Severity::Warning, 'attribute.description-unusable', sprintf(
                'A #[Description] here carries %s; the description was not documented.',
                $description->text === null ? 'neither `text:` nor `file:`' : 'both `text:` and `file:`',
            ), 'One of `text:` (inline prose) or `file:` (a markdown file under the application root) per declaration.');

            return;
        }

        if ($description->file !== null) {
            $this->applyDescriptionFile($operation, $context, $description->file, $attribute);

            return;
        }

        $operation->setDescription($description->text, $attribute);
    }

    /**
     * Load the markdown file `#[Description(file: …)]` names, confined to the app base path: a path
     * escaping the base is rejected with an error diagnostic and never read. The resolved path joins
     * the route's dependencies whether or not the read worked, so a file that isn't there yet still
     * rebuilds this route when it appears — an absent file hashes to nothing, and gaining contents is
     * a change like any other.
     */
    private function applyDescriptionFile(OperationDraft $operation, RouteContext $context, string $path, Contribution $attribute): void
    {
        $resolved = ConfinedPath::resolve($this->basePath, $path);
        if ($resolved === null) {
            $this->report($context, Severity::Error, 'description-file.escapes-base-path', sprintf(
                '#[Description] file "%s" escapes the application base path and was rejected.',
                $path,
            ), 'Point `file:` at a path inside the application, written relative to its root.');

            return;
        }

        $context->dependencies()->addFile($resolved);

        $contents = @file_get_contents($resolved);
        if ($contents === false) {
            $this->report($context, Severity::Warning, 'description-file.missing', sprintf(
                '#[Description] file "%s" could not be read; the description was not documented.',
                $path,
            ), 'Create the file, or correct the path — it is read relative to the application root.');

            return;
        }

        $operation->setDescription(rtrim(LineEndings::normalize($contents), "\n"), $attribute);
    }

    private function report(RouteContext $context, Severity $severity, string $code, string $message, string $help): void
    {
        $context->components->addDiagnostic(new Diagnostic(
            severity: $severity,
            code: $code,
            message: $message,
            source: $context->actionSource(),
            help: $help,
        ));
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
