<?php

declare(strict_types=1);

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Contracts\ErrorResponseFinalizer;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Contracts\Finalization;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Tests\Support\StubTypeEngine;

/**
 * What `RouteContext::mapThrow()` sends once the mapper chain has answered and every finalizer has had its
 * say: the rendered draft, a replacement with everything rendering registered withdrawn, or the two side
 * by side. The mapper hoists a component and leaves a note on every render, so whether the rendered body's
 * traces survive is visible on the context.
 */
function finalizationContext(ErrorResponseFinalizer ...$finalizers): RouteContext
{
    $mapper = new class implements ExceptionToResponse
    {
        public int $rendered = 0;

        public function supports(ThrownException $exception, RouteContext $context): bool
        {
            return true;
        }

        public function toResponse(ThrownException $exception, RouteContext $context, ComponentRegistry $components): ResponseDraft
        {
            $this->rendered++;
            $components->registerSchema('RenderedBody', ['type' => 'object']);
            $context->notes()->record('rendered', 'body', 'seen');
            $context->recordDependencyFiles(['renderer.php']);

            $draft = new ResponseDraft('404');
            $draft->content('application/json')->set('type', 'object', Contribution::integration('rendered'));
            $draft->setExample('application/json', ['message' => 'Not Found']);

            return $draft;
        }

        public function producer(): string
        {
            return 'integration:rendered';
        }
    };

    return new RouteContext(
        route: new RouteDescriptor(['GET'], '/api/forms/{form}'),
        actionRef: new ActionRef('app/Http/FormController.php', 'App\\Http\\FormController', 'show'),
        attributes: new AttributeSet,
        engine: new StubTypeEngine,
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(exceptionToResponse: [$mapper], errorResponseFinalizers: array_values($finalizers)),
    );
}

/**
 * @param  list<ResponseDraft>  $responses
 */
function scriptedFinalizer(Finalization $finalization, array $responses = []): ErrorResponseFinalizer
{
    return new class($finalization, $responses) implements ErrorResponseFinalizer
    {
        /**
         * @param  list<ResponseDraft>  $responses
         */
        public function __construct(private readonly Finalization $finalization, private readonly array $responses) {}

        public function finalization(ThrownException $exception, ResponseDraft $rendered, RouteContext $context): Finalization
        {
            return $this->finalization;
        }

        public function responses(ThrownException $exception, ResponseDraft $rendered, RouteContext $context, ComponentRegistry $components): array
        {
            $components->registerSchema('FinalizedBody', ['type' => 'object']);

            return $this->responses;
        }

        public function producer(): string
        {
            return 'integration:finalizer';
        }
    };
}

function finalizationThrow(): ThrownException
{
    return new ThrownException('App\\Exceptions\\Missing', 404, [], ThrowConfidence::Certain, ThrowDisposition::Signal);
}

function problemDraft(string $status = '404', string $mediaType = 'application/problem+json'): ResponseDraft
{
    $draft = new ResponseDraft($status);
    $draft->content($mediaType)->set('type', 'object', Contribution::integration('finalizer'));
    $draft->setExample($mediaType, ['title' => 'Not Found']);

    return $draft;
}

it('sends the rendered response untouched where no finalizer is registered, or one keeps it', function (?Finalization $finalization): void {
    $context = $finalization === null ? finalizationContext() : finalizationContext(scriptedFinalizer($finalization));

    $mapped = $context->mapThrow(finalizationThrow());

    expect($mapped?->producer())->toBe('integration:rendered')
        ->and($mapped?->finalizer)->toBeNull()
        ->and(array_keys($context->components->schemas()))->toBe(['RenderedBody']);
})->with([
    'no finalizer' => [null],
    'a finalizer that keeps it' => [Finalization::Keeps],
]);

it('withdraws what rendering registered before a replacement is built, and keeps what it read', function (): void {
    $context = finalizationContext(scriptedFinalizer(Finalization::Replaces, [problemDraft()]));

    $mapped = $context->mapThrow(finalizationThrow());
    $frozen = $mapped?->draft->freeze()->content;

    expect(array_keys($frozen ?? []))->toBe(['application/problem+json'])
        ->and($mapped?->producer())->toBe('integration:finalizer')
        // The replaced body's component and note are gone; the finalizer's own writes are not.
        ->and(array_keys($context->components->schemas()))->toBe(['FinalizedBody'])
        ->and($context->notes()->all())->not->toHaveKey('rendered')
        // What the decision was a function of still keys the fragment.
        ->and($context->dependencyFiles())->toContain('renderer.php');
});

it('publishes an extension beside the rendered response, rendered body first', function (): void {
    $context = finalizationContext(scriptedFinalizer(Finalization::Extends, [problemDraft()]));

    $frozen = $context->mapThrow(finalizationThrow())?->draft->freeze()->content ?? [];

    expect(array_keys($frozen))->toBe(['application/json', 'application/problem+json'])
        ->and($frozen['application/json']['example'] ?? null)->toBe(['message' => 'Not Found'])
        ->and(array_keys($context->components->schemas()))->toBe(['RenderedBody', 'FinalizedBody']);
});

it('leaves everything as it stood where a replacement builds nothing to send', function (): void {
    $context = finalizationContext(scriptedFinalizer(Finalization::Replaces));

    $mapped = $context->mapThrow(finalizationThrow());

    // Nothing to send instead is not nothing sent: the rendered response stands, whole.
    expect(array_keys($mapped?->draft->freeze()->content ?? []))->toBe(['application/json'])
        ->and(array_keys($context->components->schemas()))->toBe(['RenderedBody'])
        ->and($context->notes()->all())->toHaveKey('rendered');
});

it('keeps an alternative only at the status the rendered response is still sent at', function (): void {
    $context = finalizationContext(scriptedFinalizer(Finalization::Extends, [problemDraft('410')]));

    $mapped = $context->mapThrow(finalizationThrow());

    expect($mapped?->draft->status)->toBe('404')
        ->and(array_keys($mapped?->draft->freeze()->content ?? []))->toBe(['application/json']);
});

it('lets a replacement name its own status', function (): void {
    $context = finalizationContext(scriptedFinalizer(Finalization::Replaces, [problemDraft('410'), problemDraft('409', 'application/json')]));

    $mapped = $context->mapThrow(finalizationThrow());

    // The first replacement's status, and only what shares it: two statuses are two responses.
    expect($mapped?->draft->status)->toBe('410')
        ->and(array_keys($mapped?->draft->freeze()->content ?? []))->toBe(['application/problem+json']);
});

it('applies finalizers in order, each over what the one before it sent', function (): void {
    $context = finalizationContext(
        scriptedFinalizer(Finalization::Replaces, [problemDraft()]),
        scriptedFinalizer(Finalization::Extends, [problemDraft(mediaType: 'application/xml')]),
    );

    $frozen = $context->mapThrow(finalizationThrow())?->draft->freeze()->content ?? [];

    expect(array_keys($frozen))->toBe(['application/problem+json', 'application/xml']);
});

it('publishes a media type several alternatives send under an empty schema and no example', function (): void {
    $either = ResponseDraft::eitherOf(problemDraft(), problemDraft(), problemDraft(mediaType: 'application/xml'));
    $frozen = $either->freeze()->content ?? [];

    expect(array_keys($frozen))->toBe(['application/problem+json', 'application/xml'])
        ->and($frozen['application/problem+json'])->toBe(['schema' => []])
        ->and($frozen['application/xml']['example'] ?? null)->toBe(['title' => 'Not Found']);
});

it('returns a single alternative as it is', function (): void {
    $only = problemDraft();

    expect(ResponseDraft::eitherOf($only))->toBe($only);
});
