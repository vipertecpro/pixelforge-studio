<?php

use App\Enums\GenerationStatus;
use App\Generations\GenerationSettler;
use App\Models\Generation;
use App\Models\PixelforgeEvent;
use App\Pixelforge\WebhookSignature;
use Illuminate\Testing\TestResponse;

const WEBHOOK_SECRET = 'whsec_testing_secret';

beforeEach(function () {
    config(['pixelforge.webhook_secret' => WEBHOOK_SECRET]);
});

/**
 * @param  array<string, mixed>  $event
 */
function deliverWebhook(object $test, array $event, string $secret = WEBHOOK_SECRET, ?int $timestamp = null): TestResponse
{
    $rawBody = json_encode($event, JSON_THROW_ON_ERROR);
    $header = (new WebhookSignature($secret, 300))->header($rawBody, $timestamp ?? time());

    return $test->call('POST', route('webhooks.pixelforge'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => $header,
    ], $rawBody);
}

/**
 * @param  array<string, mixed>  $generation
 * @return array<string, mixed>
 */
function webhookEvent(string $eventId, string $type, array $generation): array
{
    return [
        'id' => $eventId,
        'object' => 'event',
        'type' => $type,
        'created_at' => '2026-11-17T10:00:20Z',
        'data' => ['object' => $generation],
    ];
}

describe('signature', function () {
    it('rejects a request with no signature', function () {
        $this->postJson(route('webhooks.pixelforge'), webhookEvent('evt_1', 'ping', []))->assertStatus(400);

        expect(PixelforgeEvent::count())->toBe(0);
    });

    it('rejects a signature made with another secret', function () {
        deliverWebhook($this, webhookEvent('evt_1', 'ping', []), secret: 'whsec_somebody_else')->assertStatus(400);

        expect(PixelforgeEvent::count())->toBe(0);
    });

    it('rejects a replayed request with an old timestamp', function () {
        deliverWebhook($this, webhookEvent('evt_1', 'ping', []), timestamp: time() - 600)->assertStatus(400);

        expect(PixelforgeEvent::count())->toBe(0);
    });

    it('rejects a body that was changed after signing', function () {
        $header = (new WebhookSignature(WEBHOOK_SECRET, 300))->header('{"id":"evt_1","type":"ping"}', time());

        $this->call('POST', route('webhooks.pixelforge'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => $header,
        ], '{"id":"evt_2","type":"ping"}')->assertStatus(400);

        expect(PixelforgeEvent::count())->toBe(0);
    });

    it('fails closed when no secret is configured', function () {
        config(['pixelforge.webhook_secret' => null]);

        deliverWebhook($this, webhookEvent('evt_1', 'ping', []), secret: '')->assertStatus(400);
    });

    it('needs no CSRF token', function () {
        deliverWebhook($this, webhookEvent('evt_1', 'ping', []))->assertNoContent();
    });
});

describe('settling a generation', function () {
    it('records the real cost and the charge when it succeeds', function () {
        $generation = Generation::factory()->processing()->create();
        $payload = pixelforgePayload($generation->pixelforge_id, 'succeeded', $generation->id);

        deliverWebhook($this, webhookEvent('evt_1', 'generation.succeeded', $payload))->assertNoContent();

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->provider_cost_nano)->toBe(305_510_000)
            ->and($generation->charged_nano)->toBe(381_887_500)
            ->and($generation->image_url)->not->toBeNull()
            ->and($generation->completed_at?->toIso8601String())->toBe('2026-11-17T10:00:20+00:00');
    });

    it('bills at the rates the generation was created with, not today\'s', function () {
        $generation = Generation::factory()->processing()->create();
        $payload = pixelforgePayload($generation->pixelforge_id, 'succeeded', $generation->id, [
            'rates' => ['input_text_tokens' => '10.00', 'input_image_tokens' => '20.00', 'output_tokens' => '80.00', 'reasoning_tokens' => '25.00'],
        ]);

        deliverWebhook($this, webhookEvent('evt_1', 'generation.succeeded', $payload));

        expect($generation->refresh()->provider_cost_nano)->toBe(611_020_000);
    });

    it('moves a queued generation to processing', function () {
        $generation = Generation::factory()->accepted()->create();

        deliverWebhook($this, webhookEvent('evt_1', 'generation.processing', pixelforgePayload($generation->pixelforge_id, 'processing', $generation->id)));

        expect($generation->refresh()->status)->toBe(GenerationStatus::Processing)
            ->and($generation->charged_nano)->toBeNull();
    });

    it('charges nothing when a generation fails', function () {
        $generation = Generation::factory()->processing()->create();

        deliverWebhook($this, webhookEvent('evt_1', 'generation.failed', pixelforgePayload($generation->pixelforge_id, 'failed', $generation->id)));

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->charged_nano)->toBe(0)
            ->and($generation->provider_cost_nano)->toBe(0)
            ->and($generation->error_message)->toBe('The model could not produce an image for this prompt.');
    });
});

describe('duplicate and out-of-order events', function () {
    it('stores an event delivered twice once, and charges once', function () {
        $generation = Generation::factory()->processing()->create();
        $event = webhookEvent('evt_1', 'generation.succeeded', pixelforgePayload($generation->pixelforge_id, 'succeeded', $generation->id));

        deliverWebhook($this, $event)->assertNoContent();
        $chargeAfterFirst = $generation->refresh()->charged_nano;

        deliverWebhook($this, $event)->assertNoContent();

        expect(PixelforgeEvent::count())->toBe(1)
            ->and(PixelforgeEvent::first()->processed_at)->not->toBeNull()
            ->and($generation->refresh()->charged_nano)->toBe($chargeAfterFirst)
            ->and($chargeAfterFirst)->toBe(381_887_500);
    });

    it('does not let a late processing event undo success', function () {
        $generation = Generation::factory()->processing()->create();

        deliverWebhook($this, webhookEvent('evt_2', 'generation.succeeded', pixelforgePayload($generation->pixelforge_id, 'succeeded', $generation->id)));
        deliverWebhook($this, webhookEvent('evt_1', 'generation.processing', pixelforgePayload($generation->pixelforge_id, 'processing', $generation->id)));

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->charged_nano)->toBe(381_887_500)
            ->and(PixelforgeEvent::count())->toBe(2);
    });

    it('treats a final state as final', function () {
        $generation = Generation::factory()->processing()->create();

        deliverWebhook($this, webhookEvent('evt_1', 'generation.failed', pixelforgePayload($generation->pixelforge_id, 'failed', $generation->id)));
        deliverWebhook($this, webhookEvent('evt_2', 'generation.succeeded', pixelforgePayload($generation->pixelforge_id, 'succeeded', $generation->id)));

        expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
            ->and($generation->charged_nano)->toBe(0);
    });

    it('settles a generation whose webhook arrives before the create call returns', function () {
        // Our row exists but does not know its Pixelforge id yet.
        $generation = Generation::factory()->create();

        deliverWebhook($this, webhookEvent('evt_1', 'generation.succeeded', pixelforgePayload('gen_EARLY', 'succeeded', $generation->id)));

        $generation->refresh();
        expect($generation->pixelforge_id)->toBe('gen_EARLY')
            ->and($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->charged_nano)->toBe(381_887_500);

        // ...and the create response finally arriving changes nothing.
        app(GenerationSettler::class)->apply(pixelforgePayload('gen_EARLY', 'queued', $generation->id));

        expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->charged_nano)->toBe(381_887_500);
    });

    it('answers a ping and an unknown generation without error', function () {
        deliverWebhook($this, webhookEvent('evt_1', 'ping', []))->assertNoContent();
        deliverWebhook($this, webhookEvent('evt_2', 'generation.succeeded', pixelforgePayload('gen_NOBODY', 'succeeded')))->assertNoContent();

        expect(PixelforgeEvent::whereNull('processed_at')->count())->toBe(0);
    });

    it('ignores an event that names our generation but carries another Pixelforge id', function () {
        $generation = Generation::factory()->accepted('gen_REAL')->create();

        deliverWebhook($this, webhookEvent('evt_1', 'generation.succeeded', pixelforgePayload('gen_OTHER', 'succeeded', $generation->id)));

        expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
    });
});
