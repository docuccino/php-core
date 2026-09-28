<?php

declare(strict_types=1);

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Contracts\ErrorResponseFinalizer;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Contracts\ExceptionTranslator;
use Docuccino\Core\Extensions\Contracts\Finalization;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\Frame;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Tests\Support\StubTypeEngine;

/**
 * `RouteContext::mapThrow()` with translators in front of the chain: the exception a translator answers
 * with is the one every mapper renders and every finalizer is handed, since a framework that swaps the
 * exception before rendering sends the swap's response. The mapper and the finalizer record what they
 * were asked about, and the mapper files its draft under the status of what it was handed.
 */
final class TranslationProbe
{
    /** @var list<string> */
    public array $rendered = [];

    /** @var list<string> */
    public array $finalized = [];
}

function translationContext(TranslationProbe $probe, ExceptionTranslator ...$translators): RouteContext
{
    $mapper = new class($probe) implements ExceptionToResponse
    {
        public function __construct(private readonly TranslationProbe $probe) {}

        public function supports(ThrownException $exception, RouteContext $context): bool
        {
            return true;
        }

        public function toResponse(ThrownException $exception, RouteContext $context, ComponentRegistry $components): ResponseDraft
        {
            $this->probe->rendered[] = $exception->exceptionFqcn;

            return new ResponseDraft((string) $exception->httpStatusHint);
        }

        public function producer(): string
        {
            return 'integration:rendered';
        }
    };

    $finalizer = new class($probe) implements ErrorResponseFinalizer
    {
        public function __construct(private readonly TranslationProbe $probe) {}

        public function finalization(ThrownException $exception, ResponseDraft $rendered, RouteContext $context): Finalization
        {
            $this->probe->finalized[] = $exception->exceptionFqcn;

            return Finalization::Keeps;
        }

        public function responses(ThrownException $exception, ResponseDraft $rendered, RouteContext $context, ComponentRegistry $components): array
        {
            return [];
        }

        public function producer(): string
        {
            return 'integration:finalizer';
        }
    };

    return new RouteContext(
        route: new RouteDescriptor(['GET'], '/api/forms/{form}'),
        actionRef: new ActionRef('app/Http/FormController.php', 'App\\Http\\FormController', 'show'),
        attributes: new AttributeSet,
        engine: new StubTypeEngine,
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(
            exceptionToResponse: [$mapper],
            errorResponseFinalizers: [$finalizer],
            exceptionTranslators: array_values($translators),
        ),
    );
}

/** A translator answering `$to` (at `$status`) for every throw, or null where `$to` is null. */
function scriptedTranslator(?string $to, int $status = 402): ExceptionTranslator
{
    return new class($to, $status) implements ExceptionTranslator
    {
        public function __construct(private readonly ?string $to, private readonly int $status) {}

        public function translate(ThrownException $exception, RouteContext $context): ?ThrownException
        {
            return $this->to === null
                ? null
                : $exception->as($this->to, $this->status);
        }
    };
}

function translatedThrow(): ThrownException
{
    return new ThrownException('App\\Exceptions\\Missing', 404, [], ThrowConfidence::Certain, ThrowDisposition::Signal);
}

it('renders and finalizes the translation, and never the exception thrown', function (): void {
    $probe = new TranslationProbe;
    $mapped = translationContext($probe, scriptedTranslator('App\\Exceptions\\PaymentRequired'))->mapThrow(translatedThrow());

    expect($probe->rendered)->toBe(['App\\Exceptions\\PaymentRequired'])
        ->and($probe->finalized)->toBe(['App\\Exceptions\\PaymentRequired'])
        ->and($mapped?->draft->status)->toBe('402')
        ->and($mapped?->translated?->exceptionFqcn)->toBe('App\\Exceptions\\PaymentRequired');
});

it('leaves the throw as it is where no translator answers', function (ExceptionTranslator ...$translators): void {
    $probe = new TranslationProbe;
    $mapped = translationContext($probe, ...$translators)->mapThrow(translatedThrow());

    expect($probe->rendered)->toBe(['App\\Exceptions\\Missing'])
        ->and($probe->finalized)->toBe(['App\\Exceptions\\Missing'])
        ->and($mapped?->draft->status)->toBe('404')
        ->and($mapped?->translated)->toBeNull();
})->with([
    'no translators' => [],
    'one declining' => [scriptedTranslator(null)],
]);

it('keeps where a throw was raised when it is rendered as another class', function (): void {
    $frame = new Frame('FormController::show', new SourceLocation('app/Http/FormController.php', 12));
    $thrown = new ThrownException('App\\Exceptions\\Missing', 404, [$frame], ThrowConfidence::Declared, ThrowDisposition::Internal);

    $rendered = $thrown->as('App\\Exceptions\\PaymentRequired', 402);

    expect([$rendered->exceptionFqcn, $rendered->httpStatusHint])->toBe(['App\\Exceptions\\PaymentRequired', 402])
        ->and([$rendered->callChain, $rendered->confidence, $rendered->disposition])->toBe([[$frame], ThrowConfidence::Declared, ThrowDisposition::Internal]);
});

it('takes the first answer, and nothing translates it again', function (): void {
    $probe = new TranslationProbe;
    translationContext(
        $probe,
        scriptedTranslator(null),
        scriptedTranslator('App\\Exceptions\\First'),
        scriptedTranslator('App\\Exceptions\\Second'),
    )->mapThrow(translatedThrow());

    expect($probe->rendered)->toBe(['App\\Exceptions\\First']);
});
