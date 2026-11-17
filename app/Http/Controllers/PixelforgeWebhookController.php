<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessPixelforgeEvent;
use App\Models\PixelforgeEvent;
use App\Pixelforge\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives Pixelforge webhooks. The work is deliberately tiny: check the
 * signature against the raw body, remember the event once, and hand it to a
 * queued job, so we answer well inside Pixelforge's 10-second limit.
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, WebhookSignature $signature): Response
    {
        $rawBody = $request->getContent();

        if (! $signature->isValid($rawBody, $request->header('Pixelforge-Signature'))) {
            abort(400, 'Invalid Pixelforge signature.');
        }

        $event = json_decode($rawBody, true);

        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            abort(400, 'Malformed Pixelforge event.');
        }

        // The unique event_id makes a second delivery of the same event a no-op.
        $generation = $event['data']['object'] ?? null;

        PixelforgeEvent::query()->insertOrIgnore([
            'event_id' => $event['id'],
            'type' => $event['type'],
            'pixelforge_generation_id' => is_array($generation) && is_string($generation['id'] ?? null) ? $generation['id'] : null,
            'payload' => $rawBody,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stored = PixelforgeEvent::query()->where('event_id', $event['id'])->firstOrFail();

        if ($stored->processed_at === null) {
            ProcessPixelforgeEvent::dispatch($stored->id);
        }

        return response()->noContent();
    }
}
