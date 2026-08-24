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
use Docuccino\Core\Draft\DeprecationNote;
use Docuccino\Core\Draft\DescriptionAppender;
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
 * `#[Group]` → tags, `#[DeprecatedOperation]` (flag, plus its reason as a description paragraph),
 * `#[Internal]` → `x-internal`, and `#[Summary]` / `#[Description]` over whatever the docblock said.
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
        // Either spelling can carry a reason, and the attribute outranks the docblock exactly as it does
        // for the flag. A reason is prose for the consumer, so it joins the description rather than
        // being written on its own — see applyDeprecationReason() for why it travels with each write.
        $attributeDeprecation = $context->attributes->first(DeprecatedOperation::class);
        $attributeReason = DeprecationNote::paragraph($attributeDeprecation?->reason);
        $docblockReason = DeprecationNote::paragraph($context->deprecationReason);

        // Docblock layer.
        $operation->setSummary($context->summary, Contribution::docblock());
        $operation->setDescription(
            $attributeReason === null && $docblockReason !== null
                ? DescriptionAppender::joined($context->description, $docblockReason)
                : $context->description,
            Contribution::docblock(),
        );
        if ($context->deprecated) {
            $operation->setDeprecated(true, Contribution::docblock());
        }

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

        if ($attributeDeprecation !== null) {
            $operation->setDeprecated(true, $attribute);
        }

        if ($context->attributes->has(Internal::class)) {
            $operation->set('x-internal', true, $attribute);
        }

        $summary = $context->attributes->first(Summary::class);
        if ($summary !== null) {
            $operation->setSummary($summary->text, $attribute);
        }

        $described = $context->attributes->first(Description::class);
        $description = $described === null ? null : $this->describedText($context, $described);

        $this->applyDescriptionAndReason($operation, $description, $attributeReason ?? $docblockReason, $attributeReason, $attribute);
    }

    /**
     * The attribute-layer description write, with the deprecation reason folded in.
     *
     * A patch is shadowed by an earlier one at its own layer, so a reason cannot be appended after
     * `#[Description]` has written — it travels WITH that write. Where there is no `#[Description]`, an
     * attribute-borne reason still writes here, over the docblock layer's description; a docblock-borne
     * one already joined that write and needs nothing more.
     */
    private function applyDescriptionAndReason(OperationDraft $operation, ?string $description, ?string $reason, ?string $attributeReason, Contribution $attribute): void
    {
        if ($description !== null) {
            $operation->setDescription($reason === null ? $description : DescriptionAppender::joined($description, $reason), $attribute);

            return;
        }

        if ($attributeReason !== null) {
            DescriptionAppender::append($operation, $attributeReason, $attribute);
        }
    }

    /**
     * The prose `#[Description]` states: inline `text:`, or the markdown file `file:` names. Exactly one
     * of them says something, so a declaration carrying both or neither is diagnosed and states nothing
     * — picking one would document prose the author never chose.
     */
    private function describedText(RouteContext $context, Description $description): ?string
    {
        if (($description->text === null) === ($description->file === null)) {
            $this->report($context, Severity::Warning, 'attribute.description-unusable', sprintf(
                'A #[Description] here carries %s; the description was not documented.',
                $description->text === null ? 'neither `text:` nor `file:`' : 'both `text:` and `file:`',
            ), 'One of `text:` (inline prose) or `file:` (a markdown file under the application root) per declaration.');

            return null;
        }

        return $description->file !== null
            ? $this->describedFile($context, $description->file)
            : $description->text;
    }

    /**
     * Load the markdown file `#[Description(file: …)]` names, confined to the app base path: a path
     * escaping the base is rejected with an error diagnostic and never read. The resolved path joins
     * the route's dependencies whether or not the read worked, so a file that isn't there yet still
     * rebuilds this route when it appears — an absent file hashes to nothing, and gaining contents is
     * a change like any other.
     */
    private function describedFile(RouteContext $context, string $path): ?string
    {
        $resolved = ConfinedPath::resolve($this->basePath, $path);
        if ($resolved === null) {
            $this->report($context, Severity::Error, 'description-file.escapes-base-path', sprintf(
                '#[Description] file "%s" escapes the application base path and was rejected.',
                $path,
            ), 'Point `file:` at a path inside the application, written relative to its root.');

            return null;
        }

        $context->dependencies()->addFile($resolved);

        $contents = @file_get_contents($resolved);
        if ($contents === false) {
            $this->report($context, Severity::Warning, 'description-file.missing', sprintf(
                '#[Description] file "%s" could not be read; the description was not documented.',
                $path,
            ), 'Create the file, or correct the path — it is read relative to the application root.');

            return null;
        }

        return rtrim(LineEndings::normalize($contents), "\n");
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
