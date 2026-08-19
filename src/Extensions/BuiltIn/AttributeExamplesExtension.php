<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Attributes\Example;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Support\ExampleFile;

/**
 * Applies `#[Example]`. An unnamed declaration pins the singular `example`; named ones build the
 * `examples` map, on a response, the request body or a parameter, with the payload written inline or
 * loaded from a JSON/YAML file under the app root.
 *
 * It runs in Finalize so every node a declaration could name already exists: a declaration naming one
 * that doesn't is diagnosed rather than conjuring an empty response to hang an example off. Names are
 * the author's own and the maps are name-sorted, so adding one example never moves another.
 */
final class AttributeExamplesExtension implements OperationExtension
{
    /**
     * Where a `parameter:` name is looked for, most-addressable first. A fixed order rather than the
     * operation's own, so which parameter a name resolves to is a function of the name and nothing else.
     */
    private const array PARAMETER_LOCATIONS = ['path', 'query', 'header', 'cookie'];

    public function __construct(
        private readonly string $basePath,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Finalize;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $declarations = $context->attributes->all(Example::class);
        if ($declarations === []) {
            return;
        }

        /** @var array<string, ExampleTarget> $targets */
        $targets = [];
        /** @var array<string, array<string, array<string, mixed>>> $namedByTarget */
        $namedByTarget = [];
        /** @var array<string, mixed> $singularByTarget */
        $singularByTarget = [];

        foreach ($declarations as $declaration) {
            $target = $this->resolveTarget($operation, $context, $declaration);
            if ($target === null) {
                continue;
            }

            $example = $this->resolveExample($context, $declaration);
            if ($example === null) {
                continue;
            }

            $id = $target->id();
            $targets[$id] = $target;

            if ($declaration->name === null) {
                $singularByTarget[$id] ??= $example['value'] ?? null;

                continue;
            }

            if (isset($namedByTarget[$id][$declaration->name])) {
                $this->duplicateName($context, $target, $declaration->name);

                continue;
            }

            $namedByTarget[$id][$declaration->name] = $example;
        }

        foreach ($targets as $id => $target) {
            $named = $namedByTarget[$id] ?? [];
            $singular = $singularByTarget[$id] ?? null;

            // OAS carries `example` or `examples` on a node, never both. The map wins — an author who
            // named some examples meant a map — but the one that loses says so rather than vanishing.
            if ($named !== [] && $singular !== null) {
                $this->unusable($context, sprintf(
                    'has no `name:` on %s, which also carries named examples — OpenAPI carries `example` or `examples`, never both',
                    $target->label(),
                ));
                $singular = null;
            }

            $this->apply($operation, $context, $target, $named, $singular);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $named
     */
    private function apply(OperationDraft $operation, RouteContext $context, ExampleTarget $target, array $named, mixed $singular): void
    {
        if ($target->kind === ExampleTarget::REQUEST) {
            $operation->declareRequestBodyExamples($target->mediaType, $named, $singular);

            return;
        }

        if ($target->kind === ExampleTarget::PARAMETER) {
            $parameter = $operation->parameter($target->in, $target->key);
            $parameter->declareExamples($named);
            $parameter->set('example', $singular, Contribution::attribute($context->actionSource()));

            return;
        }

        $operation->response($target->key)->declareExamples($target->mediaType, $named, $singular);
    }

    /**
     * The node a declaration illustrates, or null when it names more than one or names one this
     * operation doesn't have. Both are diagnosed here — the declaration is the only thing that knows
     * what was asked for.
     */
    private function resolveTarget(OperationDraft $operation, RouteContext $context, Example $declaration): ?ExampleTarget
    {
        $chosen = (int) ($declaration->status !== null) + (int) $declaration->request + (int) ($declaration->parameter !== null);
        if ($chosen > 1) {
            $this->unusable($context, 'names more than one thing to illustrate — `status:`, `request:` and `parameter:` are alternatives');

            return null;
        }

        if ($declaration->parameter !== null) {
            return $this->parameterTarget($operation, $context, $declaration->parameter);
        }

        if ($declaration->request) {
            return $this->requestTarget($operation, $context, $declaration->mediaType);
        }

        return $this->responseTarget($operation, $context, $declaration);
    }

    private function parameterTarget(OperationDraft $operation, RouteContext $context, string $name): ?ExampleTarget
    {
        foreach (self::PARAMETER_LOCATIONS as $in) {
            if ($operation->hasParameter($in, $name)) {
                return new ExampleTarget(ExampleTarget::PARAMETER, $name, in: $in);
            }
        }

        $this->targetMissing($context, sprintf('no parameter named "%s" is documented on this operation', $name));

        return null;
    }

    private function requestTarget(OperationDraft $operation, RouteContext $context, ?string $requested): ?ExampleTarget
    {
        $body = $operation->resolvedField('requestBody');
        $content = is_array($body) && is_array($body['content'] ?? null) ? $body['content'] : [];

        $mediaType = $requested ?? (string) (array_key_first($content) ?? '');

        if ($mediaType === '' || ! array_key_exists($mediaType, $content)) {
            $this->targetMissing($context, $requested === null
                ? 'this operation documents no request body'
                : sprintf('this operation\'s request body carries no "%s" content', $requested));

            return null;
        }

        return new ExampleTarget(ExampleTarget::REQUEST, '', $mediaType);
    }

    private function responseTarget(OperationDraft $operation, RouteContext $context, Example $declaration): ?ExampleTarget
    {
        $status = $declaration->status === null
            ? $this->successStatus($operation)
            : (string) $declaration->status;

        if ($status === null || ! $operation->hasResponse($status)) {
            $this->targetMissing($context, $declaration->status === null
                ? 'this operation documents no success response'
                : sprintf('this operation documents no %s response', $status));

            return null;
        }

        $response = $operation->response($status);
        $mediaType = $declaration->mediaType ?? $response->primaryMediaType();

        if ($mediaType === '' || ! $response->hasContent($mediaType)) {
            $this->targetMissing($context, sprintf('the %s response carries no %s content', $status, $mediaType === '' ? 'body' : '"'.$mediaType.'"'));

            return null;
        }

        return new ExampleTarget(ExampleTarget::RESPONSE, $status, $mediaType);
    }

    /**
     * The response an unqualified declaration illustrates: the lowest 2xx the operation documents,
     * falling back to the `2XX` range key when that is all it has. The status list is byte-sorted and
     * every digit sorts below `X`, so the first match is the lowest either way.
     */
    private function successStatus(OperationDraft $operation): ?string
    {
        foreach ($operation->responseStatuses() as $status) {
            if (preg_match('/^2(\d\d|XX)$/D', $status) === 1) {
                return $status;
            }
        }

        return null;
    }

    /**
     * The Example Object one declaration describes, or null when it describes none.
     *
     * @return array<string, mixed>|null
     */
    private function resolveExample(RouteContext $context, Example $declaration): ?array
    {
        $sources = (int) ($declaration->value !== null)
            + (int) ($declaration->file !== null)
            + (int) ($declaration->externalValue !== null);

        if ($sources === 0) {
            $this->unusable($context, 'carries no value — give it one of `value:`, `file:` or `externalValue:`');

            return null;
        }

        if ($sources > 1) {
            $this->unusable($context, 'carries more than one value — `value:`, `file:` and `externalValue:` are alternatives');

            return null;
        }

        $namedOnly = $this->namedOnlyArguments($declaration);
        if ($declaration->name === null && $namedOnly !== []) {
            $this->unusable($context, sprintf(
                'carries %s but no `name:` — the singular `example` is a bare value, so only a named one carries those',
                implode(' and ', $namedOnly),
            ));

            return null;
        }

        $example = [];
        if ($declaration->summary !== null) {
            $example['summary'] = $declaration->summary;
        }
        if ($declaration->description !== null) {
            $example['description'] = $declaration->description;
        }

        if ($declaration->externalValue !== null) {
            $example['externalValue'] = $declaration->externalValue;

            return $example;
        }

        if ($declaration->value !== null) {
            // An attribute argument may be `INF` or `NAN`, which no JSON document can hold. The canonical
            // writer is where that used to surface, as an exception naming neither the route nor the
            // declaration and taking the whole build with it.
            $rejected = (new CanonicalJsonSerializer)->rejects($declaration->value);
            if ($rejected !== null) {
                $this->report($context, Severity::Warning, 'attribute.example-unusable', sprintf(
                    'An #[Example] here carries a `value:` no JSON document can hold (%s); it was not documented.',
                    lcfirst($rejected),
                ), 'Examples are published as JSON. `INF`, `-INF` and `NAN` have no JSON form — write the value as a string, or leave the member out.');

                return null;
            }

            $example['value'] = $declaration->value;

            return $example;
        }

        $value = $this->fromFile($context, (string) $declaration->file);
        if ($value === null) {
            return null;
        }

        $example['value'] = $value;

        return $example;
    }

    /**
     * The arguments an Example Object has a slot for and the singular `example` member does not, as a
     * declaration names them.
     *
     * @return list<string>
     */
    private function namedOnlyArguments(Example $declaration): array
    {
        $named = [];

        if ($declaration->externalValue !== null) {
            $named[] = '`externalValue:`';
        }
        if ($declaration->summary !== null) {
            $named[] = '`summary:`';
        }
        if ($declaration->description !== null) {
            $named[] = '`description:`';
        }

        return $named;
    }

    /**
     * The payload a `file:` names, or null when it can't be read as one. The resolved path joins the
     * route's dependencies whether or not the read worked, so creating a file that wasn't there
     * rebuilds this route ({@see RouteContext::dependencies()}).
     */
    private function fromFile(RouteContext $context, string $path): mixed
    {
        $read = ExampleFile::read($this->basePath, $path);

        if ($read->path !== null) {
            $context->dependencies()->addFile($read->path);
        }

        if ($read->ok() && $read->value !== null) {
            return $read->value;
        }

        if ($read->error === ExampleFile::ESCAPED) {
            $this->report($context, Severity::Error, 'example-file.escapes-base-path', sprintf(
                '#[Example] file "%s" escapes the application base path and was rejected.',
                $path,
            ), 'Point `file:` at a path inside the application, written relative to its root.');

            return null;
        }

        if ($read->error === ExampleFile::MISSING) {
            $this->report($context, Severity::Warning, 'example-file.missing', sprintf(
                '#[Example] file "%s" could not be read; the example was not documented.',
                $path,
            ), 'Create the file, or correct the path — it is read relative to the application root.');

            return null;
        }

        $this->report($context, Severity::Warning, 'example-file.invalid', sprintf(
            '#[Example] file "%s" did not read as an example (%s); the example was not documented.',
            $path,
            $read->ok() ? 'it decodes to null' : $read->detail,
        ), 'Examples are read from .json, .yaml and .yml files; the file has to parse, and to hold something.');

        return null;
    }

    private function unusable(RouteContext $context, string $reason): void
    {
        $this->report(
            $context,
            Severity::Warning,
            'attribute.example-unusable',
            sprintf('An #[Example] here %s; it was not documented.', $reason),
            'One value (`value:`, `file:` or `externalValue:`) and at most one target (`status:`, `request:` or `parameter:`) per declaration.',
        );
    }

    private function targetMissing(RouteContext $context, string $reason): void
    {
        $this->report(
            $context,
            Severity::Warning,
            'attribute.example-target-missing',
            sprintf('An #[Example] here was dropped: %s.', $reason),
            'Point the declaration at something the operation documents, or add it with #[Response], #[BodyParameter] or a parameter attribute first.',
        );
    }

    private function duplicateName(RouteContext $context, ExampleTarget $target, string $name): void
    {
        $this->report(
            $context,
            Severity::Warning,
            'attribute.example-duplicate-name',
            sprintf('Two #[Example] declarations here are both named "%s" on %s; the second was dropped.', $name, $target->label()),
            'Example names are the keys of one map — give each declaration on a node its own.',
        );
    }

    private function report(RouteContext $context, Severity $severity, string $code, string $message, string $help): void
    {
        $context->components->addDiagnostic(new Diagnostic(
            severity: $severity,
            code: $code,
            message: $message,
            source: $context->actionSource(),
            routeSignature: $context->route->signature(),
            help: $help,
        ));
    }
}
