<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractChecker;
use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\ContractMessages;

/*
 * The outbound half of the contract: a webhook is a keyed entry of `webhooks`, found by NAME, and the
 * payload an application dispatches for it is held to the body the document publishes.
 */

it('lists every documented webhook, by name then by the canonical method order', function (): void {
    $labels = array_map(static fn ($w): string => $w->label(), contractIndex()->webhooks());

    // `put` before `post` is the canonical order, not the order the document spells them in.
    expect($labels)->toBe([
        'POST webhooks.alert.raised',
        'POST webhooks.digest.sent',
        'POST webhooks.export.ready',
        'POST webhooks.invoice.paid',
        'PUT webhooks.invoice.voided',
        'POST webhooks.invoice.voided',
        'POST webhooks.ping.sent',
        'POST webhooks.receipt.sent',
    ]);
});

it('answers the same list whichever order the document spells the names in', function (): void {
    $reversed = contractIndex(static function (array $document): array {
        $document['webhooks'] = array_reverse($document['webhooks'], preserve_keys: true);

        return $document;
    });

    expect(array_map(static fn ($w): string => $w->label(), $reversed->webhooks()))
        ->toBe(array_map(static fn ($w): string => $w->label(), contractIndex()->webhooks()));
});

it('finds a webhook by name, and carries its id and its pointer segments', function (): void {
    $webhook = contractIndex()->webhooksNamed('invoice.paid')[0];

    expect($webhook->name)->toBe('invoice.paid')
        ->and($webhook->method)->toBe('POST')
        ->and($webhook->id)->toBe('op:v1:aaaaainvoicepaid')
        ->and($webhook->segments)->toBe(['webhooks', 'invoice.paid', 'post']);
});

it('answers nothing for a name the document does not publish', function (): void {
    expect(contractIndex()->webhooksNamed('invoice.refunded'))->toBe([]);
});

it('answers every method a contested name is published under', function (): void {
    expect(array_map(static fn ($w): string => $w->method, contractIndex()->webhooksNamed('invoice.voided')))
        ->toBe(['PUT', 'POST']);
});

it('names the webhooks it publishes once each, sorted', function (): void {
    $names = contractIndex()->webhookNames();

    // The fixture's own list — well under it is a guard against a walk that stopped finding entries.
    expect(count($names))->toBeGreaterThanOrEqual(5)
        ->and($names)->toBe([
            'alert.raised',
            'digest.sent',
            'export.ready',
            'invoice.paid',
            'invoice.voided',
            'ping.sent',
            'receipt.sent',
        ]);
});

it('reads a document with no webhooks, and one whose webhooks member is not a map', function (): void {
    expect(ContractIndex::fromArray([])->webhooks())->toBe([])
        ->and(ContractIndex::fromArray([])->webhookNames())->toBe([])
        ->and(ContractIndex::fromArray(['webhooks' => 'nope'])->webhooks())->toBe([])
        ->and(ContractIndex::fromArray(['webhooks' => ['a' => 'nope']])->webhooks())->toBe([]);
});

it('keeps webhooks out of the inbound lookup entirely', function (): void {
    $index = contractIndex();

    // A webhook name is not a path and a webhook is not an operation: `match()` is inbound by
    // construction, and putting one under `paths` is the only way it could ever answer here.
    expect($index->match('POST', 'invoice.paid'))->toBeNull()
        ->and($index->match('POST', '/invoice.paid'))->toBeNull()
        ->and(array_map(static fn ($o): string => $o->label(), $index->operations()))
        ->not->toContain('POST webhooks.invoice.paid');
});

it('indexes a webhook operation id like any other node', function (): void {
    expect(contractIndex()->identities()['op:v1:aaaaainvoicepaid'])->toBe(['webhooks', 'invoice.paid', 'post']);
});

/*
 * Version reality. `webhooks` is a 3.1/3.2 member; a document downlevelled to 3.0 has none, and that
 * is not the same answer as documenting none.
 */
it('knows whether the artifact format can carry webhooks at all', function (string $version, bool $supported): void {
    $index = contractIndex(static function (array $document) use ($version): array {
        if ($version === '') {
            unset($document['openapi']);

            return $document;
        }

        $document['openapi'] = $version;

        return $document;
    });

    expect($index->supportsWebhooks())->toBe($supported)
        ->and($index->openApiVersion())->toBe($version);
})->with([
    'the version the product emits' => ['3.2.0', true],
    '3.1' => ['3.1.0', true],
    '3.0, which defines no webhooks member' => ['3.0.4', false],
    '3.0.0' => ['3.0.0', false],
    // A document that declares no version is not a 3.0 one; it is a document with nothing to say.
    'no openapi member' => ['', true],
]);

/*
 * Checking a delivery. It goes through the same SchemaCheck the response half does, so a failure names
 * the schema node and the producer that wrote it.
 */
it('passes a payload that satisfies the body its webhook documents', function (): void {
    $outcome = checkDelivery('invoice.paid', '{"reference":"INV-1","total":12.5,"lines":[]}');

    expect($outcome->ok())->toBeTrue()
        ->and($outcome->note)->toBeNull();
});

it('fails a payload that disagrees, naming the schema node and the producer behind it', function (): void {
    $outcome = checkDelivery('invoice.paid', '{"reference":"INV-1","total":"12.50"}');

    expect($outcome->ok())->toBeFalse()
        ->and($outcome->violations[0]->location)->toBe('the delivered payload')
        ->and($outcome->violations[0]->pointer)->toBe('/total')
        ->and($outcome->violations[0]->message)->toContain('must match the type: number')
        ->and($outcome->violations[0]->schemaPointer)->toBe('/components/schemas/Invoice/properties/total')
        ->and($outcome->violations[0]->provenance->lines())
        ->toBe(['integration:eloquent (integration) — app/Models/Invoice.php:31 in App\Models\Invoice::$total']);
});

it('names the webhook body itself when the schema is written there rather than referenced', function (): void {
    $outcome = checkDelivery('invoice.paid', '{"id":"nope"}', static function (array $document): array {
        $document['webhooks']['invoice.paid']['post']['requestBody']['content']['application/json']['schema'] = [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
        ];

        return $document;
    });

    expect($outcome->violations[0]->schemaPointer)
        ->toBe('/webhooks/invoice.paid/post/requestBody/content/application~1json/schema/properties/id');
});

it('follows a $ref from the webhook body to the shared request body it names', function (): void {
    $outcome = checkDelivery('invoice.paid', '{"reference":"INV-1","total":"12.50"}', static function (array $document): array {
        $document['components']['requestBodies']['InvoiceDelivery'] = $document['webhooks']['invoice.paid']['post']['requestBody'];
        $document['webhooks']['invoice.paid']['post']['requestBody'] = ['$ref' => '#/components/requestBodies/InvoiceDelivery'];

        return $document;
    });

    expect($outcome->ok())->toBeFalse()
        ->and($outcome->violations[0]->schemaPointer)->toBe('/components/schemas/Invoice/properties/total');
});

it('fails a payload that is not JSON at all, and one that is not there', function (string $payload, string $message): void {
    $outcome = checkDelivery('invoice.paid', $payload);

    expect($outcome->ok())->toBeFalse()
        ->and($outcome->violations[0]->location)->toBe('the delivery')
        ->and($outcome->violations[0]->message)->toContain($message);
})->with([
    'malformed' => ['{"reference":', 'the delivered payload is not valid JSON'],
    'empty' => ['', 'the delivered payload is empty, but the contract documents a application/json body'],
    'whitespace' => ["  \n", 'the delivered payload is empty'],
]);

/*
 * Two shapes here FAIL rather than noting, and both are the document rather than the check. A webhook
 * the document publishes no body for is the document publishing nothing at all, which is what an
 * undocumented status already is on the inbound half; a body behind a `$ref` that lands nowhere is the
 * document being broken. The degradation rows further down are the other thing entirely — the check
 * saying it cannot read what the document did publish.
 */
it('fails a delivery the contract publishes no body for at all', function (): void {
    $outcome = checkDelivery('ping.sent', '{"anything":true}');

    expect($outcome->ok())->toBeFalse()
        ->and($outcome->note)->toBeNull()
        ->and($outcome->violations[0]->location)->toBe('POST webhooks.ping.sent')
        ->and($outcome->violations[0]->message)
        ->toBe('documents no delivered body, so there is nothing here for a payload to be held to');
});

it('fails a delivery documented behind a reference the contract does not define', function (array $body, string $pointer): void {
    // A `$ref` that lands nowhere degrades to the `$ref` node itself, which has no `content` — so
    // without this the delivery falls through to the "no media types" NOTE and passes. One typo in a
    // reference would erase the body it names and report the erasure as outbound coverage.
    $outcome = checkDelivery('invoice.paid', '{"anything":true}', static function (array $document) use ($body): array {
        $document['components']['requestBodies']['Loop'] = ['$ref' => '#/components/requestBodies/Knot'];
        $document['components']['requestBodies']['Knot'] = ['$ref' => '#/components/requestBodies/Loop'];
        $document['webhooks']['invoice.paid']['post']['requestBody'] = $body;

        return $document;
    });

    expect($outcome->ok())->toBeFalse()
        ->and($outcome->note)->toBeNull()
        ->and($outcome->violations[0]->location)->toBe('the delivered body')
        ->and($outcome->violations[0]->message)
        ->toBe('is documented at '.$pointer.', which the contract does not define');
})->with([
    // The message names the reference that went nowhere, which for a name nothing defines is the one
    // written on the webhook and for a loop is the hop the chain gave up on — where to go and look.
    'a reference at a name nothing defines' => [
        ['$ref' => '#/components/requestBodies/InvoiceDelivery'],
        '#/components/requestBodies/InvoiceDelivery',
    ],
    'a reference chain that never lands' => [
        ['$ref' => '#/components/requestBodies/Loop'],
        '#/components/requestBodies/Loop',
    ],
]);

/*
 * The degradation contract: where the document cannot be checked rather than being wrong, the outcome
 * PASSES with a note. One row per way that happens — a pass that says nothing is how a suite comes to
 * believe it has outbound coverage it does not have.
 */
it('passes with a note where there is nothing a payload can be checked against', function (string $name, string $note): void {
    $outcome = checkDelivery($name, '{"anything":true}');

    expect($outcome->ok())->toBeTrue()
        ->and($outcome->note)->toBe($note);
})->with([
    'a body with no media types' => [
        'digest.sent',
        'the contract documents a delivered body with no media types for POST webhooks.digest.sent',
    ],
    'a media type JSON Schema cannot check' => [
        'export.ready',
        'the delivered payload is text/csv, which JSON Schema cannot check',
    ],
    'a media type with no schema' => [
        'alert.raised',
        'the contract documents no schema for the delivered payload (application/json)',
    ],
    'several media types, with nothing saying which was sent' => [
        'receipt.sent',
        'the contract documents POST webhooks.receipt.sent under several media types '.
        '(application/json, application/xml), so there is nothing here one payload answers to',
    ],
]);

/*
 * The messages. Every one of them is the product: a failure is worth having only if it says what to
 * change next.
 */
it('says what a failing delivery got wrong, and which webhook promised otherwise', function (): void {
    $index = contractIndex();
    $webhook = $index->webhooksNamed('invoice.paid')[0];
    $outcome = (new ContractChecker($index))->delivery($webhook, '{"reference":"INV-1","total":"12.50"}');

    expect(ContractMessages::delivery($webhook, $outcome))
        ->toContain('The payload dispatched for POST webhooks.invoice.paid does not match the documented contract.')
        ->toContain('webhook    POST webhooks.invoice.paid  op:v1:aaaaainvoicepaid')
        ->toContain('the delivered payload at /total')
        ->toContain('must match the type: number')
        ->toContain('schema   /components/schemas/Invoice/properties/total')
        ->toContain('from     integration:eloquent (integration) — app/Models/Invoice.php:31');
});

it('says a delivery it could not read the body of passed having proved less than it looks like', function (): void {
    $index = contractIndex();
    $webhook = $index->webhooksNamed('export.ready')[0];
    $outcome = (new ContractChecker($index))->delivery($webhook, '{"anything":true}');

    // One line, and the finding is in it: this goes to a run's warning channel, where a runner shows the
    // first line and truncates. A note nobody is told is a pass that proved nothing and said nothing.
    expect(ContractMessages::uncheckedDelivery($webhook, $outcome))
        ->toBe('POST webhooks.export.ready passed, but part of the contract was not checked: the delivered payload is text/csv, which JSON Schema cannot check.');
});

it('says nothing at all about a delivery it checked in full', function (): void {
    $index = contractIndex();
    $webhook = $index->webhooksNamed('invoice.paid')[0];
    $outcome = (new ContractChecker($index))->delivery($webhook, '{"reference":"INV-1","total":12.5,"lines":[]}');

    expect(ContractMessages::uncheckedDelivery($webhook, $outcome))->toBeNull();
});

it('escapes an artifact string on its way into a note, the way every other message does', function (): void {
    // A media type key nobody re-read, in the one message that goes somewhere a terminal renders.
    $index = contractIndex(static function (array $document): array {
        $content = $document['webhooks']['export.ready']['post']['requestBody']['content'];
        $document['webhooks']['export.ready']['post']['requestBody']['content'] = [
            "text/\x1b[32mcsv" => reset($content),
        ];

        return $document;
    });
    $webhook = $index->webhooksNamed('export.ready')[0];

    expect(ContractMessages::uncheckedDelivery($webhook, (new ContractChecker($index))->delivery($webhook, '{}')))
        ->toContain('text/\x1B[32mcsv')
        ->not->toContain("\x1b");
});

it('says a payload no encoder could read was dispatched for this webhook, in the encoder’s own escaped words', function (): void {
    $webhook = contractIndex()->webhooksNamed('invoice.paid')[0];

    expect(ContractMessages::unreadableDelivery($webhook, "Type is \x1b[32mnot supported.", 'Pass what you deliver.'))
        ->toContain('Docuccino cannot read the payload dispatched for POST webhooks.invoice.paid as JSON: Type is \x1B[32mnot supported.')
        ->toContain('Pass what you deliver.')
        ->not->toContain("\x1b");
});

it('tells a reader which webhooks the contract does publish', function (): void {
    expect(ContractMessages::undocumentedWebhook('invoice.refunded', null, contractIndex(), 'Rebuild it.'))
        ->toContain('webhooks.invoice.refunded is not documented.')
        ->toContain('The contract documents these webhooks:')
        ->toContain('    invoice.paid')
        ->toContain('The artifact predates this webhook, or the webhook is excluded from the document.')
        ->toContain('Rebuild it.');
});

it('stops listing webhook names before the list stops being read', function (): void {
    $many = contractIndex(static function (array $document): array {
        foreach (range(1, 12) as $n) {
            $document['webhooks']['thing.'.$n] = ['post' => ['responses' => []]];
        }

        return $document;
    });

    expect(ContractMessages::undocumentedWebhook('nope', null, $many))->toContain('… and 11 more.');
});

it('says which methods a published name is documented for, when the one asked for is not among them', function (): void {
    expect(ContractMessages::undocumentedWebhook('invoice.paid', 'delete', contractIndex()))
        ->toContain('DELETE webhooks.invoice.paid is not documented.')
        ->toContain('The contract publishes that webhook, for these methods:')
        ->toContain('    POST webhooks.invoice.paid');
});

it('says so plainly when the contract publishes no webhook at all', function (): void {
    expect(ContractMessages::undocumentedWebhook('invoice.paid', null, ContractIndex::fromArray([])))
        ->toContain('The contract documents no webhook at all.');
});

it('refuses to guess which of a name is meant, and names the choice', function (): void {
    $message = ContractMessages::ambiguousWebhook(
        'invoice.voided',
        contractIndex()->webhooksNamed('invoice.voided'),
        'Name the one you send.',
    );

    expect($message)
        ->toContain('webhooks.invoice.voided is documented for more than one method')
        ->toContain('PUT webhooks.invoice.voided  op:v1:aaaaaaavoidedput')
        ->toContain('POST webhooks.invoice.voided  op:v1:aaaaaavoidedpost')
        ->toContain('Name the one you send.');
});

it('says an artifact its format cannot carry webhooks in has none, rather than that the webhook is undocumented', function (): void {
    $downlevelled = contractIndex(static function (array $document): array {
        $document['openapi'] = '3.0.4';
        unset($document['webhooks']);

        return $document;
    });

    expect(ContractMessages::webhooksUnsupported($downlevelled, 'Export it as UIR.'))
        ->toContain('The contract is OpenAPI 3.0.4, which defines no `webhooks` member.')
        ->toContain('Every webhook the document had was dropped on the way down to 3.0')
        ->toContain('Assert against the UIR artifact, or a 3.1 or 3.2 export.')
        ->toContain('Export it as UIR.');
});
