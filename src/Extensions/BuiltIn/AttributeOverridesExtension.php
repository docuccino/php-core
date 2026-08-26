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
use Docuccino\Core\Support\PlainText;

/**
 * The overrides layer: docblock summary/description (docblock precedence), then the operation
 * attributes (attribute precedence) — `#[OperationId]` over the default route-name strategy,
 * `#[Group]` → tags, `#[DeprecatedOperation]` (flag, plus its reason as a description paragraph),
 * `#[Internal]` → `x-internal`, and `#[Summary]` / `#[Description]` over whatever the docblock said.
 *
 * The last pair exist because one docblock serves two readers. Prose written for whoever maintains
 * the action is not prose an API consumer can use, and until these there was no way to say the second
 * thing without deleting the first.
 *
 * `#[Description(request: true)]` is the same attribute pointed at the request body instead, which is a
 * fact about this operation's use of the body rather than about the type behind it.
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

        [$describedOperation, $describedRequest] = $this->partitionDescriptions($context);

        $description = $describedOperation === null ? null : $this->describedText($context, $describedOperation);

        $this->applyDescriptionAndReason($operation, $description, $attributeReason ?? $docblockReason, $attributeReason, $attribute);

        if ($describedRequest !== null) {
            $this->applyRequestDescription($operation, $context, $describedRequest);
        }
    }

    /**
     * The `#[Description]` declarations that describe the operation and the request body, most specific
     * of each first — the attribute set already orders method-level ahead of class-level, so the head of
     * each partition is the one that wins.
     *
     * @return array{0: Description|null, 1: Description|null}
     */
    private function partitionDescriptions(RouteContext $context): array
    {
        $operation = null;
        $request = null;

        foreach ($context->attributes->all(Description::class) as $described) {
            if ($described->request) {
                $request ??= $described;

                continue;
            }

            $operation ??= $described;
        }

        return [$operation, $request];
    }

    /**
     * The `requestBody.description` write: prose about how to fill THIS operation's body in, which is a
     * different fact from the schema's own description, which a class-level declaration writes — that
     * one says what the type IS, and every operation sharing the component reads it.
     *
     * A declaration written on the ACTION with no body to describe is reported rather than dropped: it
     * is the same mistake as a `#[Description]` that says nothing certain, and lands in the same channel.
     */
    private function applyRequestDescription(OperationDraft $operation, RouteContext $context, Description $described): void
    {
        $text = $this->describedText($context, $described);
        if ($text === null) {
            return;
        }

        // Every requestBody producer runs in OperationPhase::Request, ahead of this extension's
        // Overrides phase, so by now the field is settled whatever DefaultExtensions::all() lists first.
        if (is_array($operation->resolvedField('requestBody'))) {
            $operation->declareRequestBodyDescription($text);

            return;
        }

        // Reported only where the author is standing: one declaration on a CONTROLLER covers every
        // action under it, so a report per bodyless action fires where there is nothing to do.
        if (! in_array($described, $context->attributes->direct(Description::class), true)) {
            return;
        }

        $this->report(
            $context,
            Severity::Warning,
            'attribute.description-unusable',
            'A #[Description(request: true)] here describes a request body, and this operation documents none; the description was not documented.',
            'Document the body first — validation rules, a request DTO or #[BodyParameter] — or drop `request:` to describe the operation itself.',
        );
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
     * The prose one `#[Description]` states: inline `text:`, or the markdown file `file:` names. Exactly
     * one of them says something, so a declaration carrying both or neither is diagnosed and states
     * nothing — picking one would document prose the author never chose. The report names which slot
     * the declaration was aimed at, since an action may carry one of each.
     */
    private function describedText(RouteContext $context, Description $description): ?string
    {
        if (($description->text === null) === ($description->file === null)) {
            $this->report($context, Severity::Warning, 'attribute.description-unusable', sprintf(
                'A %s here carries %s; the description was not documented.',
                $description->request ? '#[Description(request: true)]' : '#[Description]',
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
        // The path is the author's own text on its way into a published message, so it is escaped
        // before it is quoted — a NUL byte is exactly what gets one refused below.
        $quoted = PlainText::of($path);
        $resolved = ConfinedPath::resolve($this->basePath, $path);
        if ($resolved === null) {
            $this->report($context, Severity::Error, 'description-file.escapes-base-path', sprintf(
                '#[Description] file "%s" does not name a path inside the application and was rejected.',
                $quoted,
            ), ConfinedPath::FILE_ESCAPED_HELP);

            return null;
        }

        $context->dependencies()->addFile($resolved);

        $contents = @file_get_contents($resolved);
        if ($contents === false) {
            $this->report($context, Severity::Warning, 'description-file.missing', sprintf(
                '#[Description] file "%s" could not be read; the description was not documented.',
                $quoted,
            ), ConfinedPath::FILE_MISSING_HELP);

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
