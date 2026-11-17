<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pixelforge API
    |--------------------------------------------------------------------------
    |
    | Use one API key per environment. Each key has its own webhook URL and
    | signing secret, so the secret below must belong to the key above.
    |
    */

    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),

    'api_key' => env('PIXELFORGE_API_KEY'),

    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

    /*
    | Webhook signatures older than this many seconds are rejected (replays).
    */
    'webhook_tolerance_seconds' => 300,

    /*
    |--------------------------------------------------------------------------
    | Pricing
    |--------------------------------------------------------------------------
    */

    /*
    | What users pay on top of Pixelforge's cost, as a whole percentage.
    */
    'markup_percent' => 25,

    /*
    | How long the public /v1/pricing table is cached. A stale copy is kept as a
    | fallback when Pixelforge is unreachable.
    */
    'pricing_cache_seconds' => 60,

    /*
    |--------------------------------------------------------------------------
    | Fair use
    |--------------------------------------------------------------------------
    |
    | Pixelforge allows 3 generations at once and 500 a day for the whole account,
    | so one user must not be able to use them all up.
    |
    */

    'limits' => [
        'active_per_user' => 3,
        'per_user_per_day' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    |
    | generations:sync polls Pixelforge for generations that have not changed for
    | this many seconds, in case a webhook never arrived.
    |
    */

    'sync_after_seconds' => (int) env('PIXELFORGE_SYNC_AFTER_SECONDS', 90),

    /*
    | A generation that Pixelforge never accepted is re-sent (safely, with the
    | same idempotency key) after this many seconds.
    */
    'resubmit_after_seconds' => 600,

];
