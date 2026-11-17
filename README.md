# Pixelforge Studio

A small Laravel 13 + Vue 3 + Inertia app where signed-in users generate images through the
[Pixelforge](https://pixelforge.promptedit.dev/docs) API, see the price **before** they submit, and are
charged the **exact** cost plus a 25% markup once the image is done.

Built for the PromptEdit Senior Product Engineer take-home. Money is the point of the exercise, so that is
where most of the care went.

- Live app: _added when deployed_
- Pixelforge account used by the live app: _added when submitted_

## What it does

| Brief                                                                                      | Where it lives                                                                                   |
| ------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------ |
| Generate page with prompt, size, quality, reference images, and the cost before submitting | `resources/js/pages/generations/Create.vue`, `GenerationController@estimate`, `app/Pricing/`     |
| Cost plus 25%, estimate up front, actual recorded on completion, failures free             | `GenerationPricer`, `GenerationSettler`                                                          |
| History with live status, estimate, actual cost and image                                  | `resources/js/pages/generations/Index.vue`, `GenerationCard.vue`                                 |
| Webhooks: verified, duplicate-safe, order-safe                                             | `PixelforgeWebhookController`, `WebhookSignature`, `ProcessPixelforgeEvent`, `GenerationSettler` |
| Stretch: admin report that matches `GET /v1/usage`                                         | `Admin/ReportController`, `UsageReport`                                                          |
| Stretch: balances, fake "Buy credits", blocking when you can't afford it                   | `CreditLedger`, `CreditController`, `GenerationController@store`                                 |

Sign-up, sign-in and the settings pages come from Laravel's official Vue starter kit. Everything described
here is in the files named above.

## How the money works

- **No floats, ever.** Every amount is a whole number of nanodollars (1 USD = 1,000,000,000), the same
  precision as Pixelforge's nine-decimal `cost_usd`. Rates stay as the decimal strings the API sends and are
  parsed into integers (`app/Pricing/Rates.php`).
- **The estimate is a range, and it is always true.** Only two things are unknown before an image runs: the
  real prompt token count (within 15% of `ceil(characters / 4)`) and the reasoning tokens (anywhere in a range
  set by quality). Pixelforge's `GET /v1/pricing` supplies every number, so nothing is hard-coded, and the
  range is rounded outwards. The real price can never fall outside it. The page shows "$x to $y" and "you
  will pay at most $y".
- **The actual cost uses the rates the generation was created with**, from the generation object itself, not
  today's price list. Cost = tokens × rate, in integers. The user is charged cost + 25%, rounded half up to a
  whole nanodollar (with Pixelforge's current rates the markup is always exact, so nothing rounds).
- **Failed generations cost nothing**: no charge row is written, and the credits held for them come back.

## How it copes with Pixelforge

- **Sending**: a queued job sends each generation with its own id as the `Idempotency-Key` and as
  `metadata.generation_id`. A timeout is retried with the same key, so it can never create two. 429 and 503
  honour `Retry-After` and back off further each time, capped at 8 attempts. A rejected prompt, a spent daily
  quota and validation errors fail at once. Whatever happens, a user is never left with a spinner forever.
- **Webhooks**: verified against the **raw body** (HMAC-SHA256 of `t.body`, constant-time compare, timestamps
  older than 5 minutes rejected, fails closed with no secret). The route is stateless: no session, no cookie,
  no CSRF. The controller stores the event under a **unique event id** and queues a job, so it answers well
  inside Pixelforge's 10 seconds and a repeat delivery is a no-op.
- **Settling** (`GenerationSettler`) is the only place a generation changes state, whether the news comes from
  a webhook, the create response or the polling fallback. The row is locked; a generation only moves forward
  (queued, processing, final) so a late `processing` can't undo `succeeded`; and the charge is written in the
  same transaction that makes the generation final, with a unique `(generation_id, type)` index behind it.
  A webhook that arrives **before** the create call returns is matched through the metadata we sent.
- **Polling fallback**: `php artisan generations:sync` (scheduled every minute) asks Pixelforge about
  generations that have gone quiet, re-sends any that were never accepted (same idempotency key), and fails
  one Pixelforge says it no longer knows after an hour, which releases its held credits.

## Credits

A user's balance is the sum of a ledger (credits positive, charges negative). Because an image's exact price
is only known when it finishes, credits are **held** (the image's highest possible price) while it runs. A new
image is accepted only if its highest price fits in what is available, checked under a lock on the user, so
two quick submissions can't spend the same credits. New accounts get a $1 welcome credit; "Buy credits" is a
pretend purchase (no payment is taken).

Submissions and top-ups carry an idempotency id, so a double click or a replayed request can only ever make
one image or add one top-up. Deleting an account keeps its images and charges in the books.

## Operators

`/admin/report` (admins only) shows charged, Pixelforge cost and profit per UTC day, per user, and per day and
user, and checks every day against Pixelforge's own `GET /v1/usage` for the same dates. A day is the UTC day
an image **completed**, exactly as Pixelforge bills it. The page also shows the webhook URL to paste into
Pixelforge and whether the key and signing secret are set (never their values).

The admin account is created from the environment, never from the sign-up form:

```bash
ADMIN_EMAIL=you@example.com ADMIN_PASSWORD='a long random password' php artisan pixelforge:admin
```

## Run it locally

Needs PHP 8.3+ (tested on 8.4 and 8.5), Composer, and Node 22.18+, 24.11+ or 26+.

```bash
composer setup                      # install, .env, key, migrate, build
# put your Pixelforge key in .env (PIXELFORGE_API_KEY)
php artisan queue:work              # sends images and processes webhook events
php artisan schedule:work           # the polling fallback, every minute
composer dev                        # or: the app, queue and Vite together
```

Use **one Pixelforge API key per environment**. Each key has its own webhook URL and signing secret, and
`GET /v1/usage` reports each key separately, which is what lets the report match. Webhooks need a public URL:
for local work use a tunnel (Expose, ngrok, Cloudflare Tunnel) and register `https://<tunnel>/webhooks/pixelforge`
on the key. Without one the polling fallback still settles everything within a minute.

Tests and checks (everything in one command):

```bash
composer ci:check                   # frontend lint and format, type checks, Pint, PHPStan, Pest
```

Handy prompt keywords from the Pixelforge docs: `[fail]`, `[blocked]`, `[overloaded]`, `[slow]`,
`[duplicate]`, `[succeed]`.

## Configuration

| Variable                        | Purpose                                            |
| ------------------------------- | -------------------------------------------------- |
| `PIXELFORGE_API_KEY`            | This environment's Pixelforge key                  |
| `PIXELFORGE_WEBHOOK_SECRET`     | That key's webhook signing secret (`whsec_...`)    |
| `PIXELFORGE_BASE_URL`           | Defaults to `https://pixelforge.promptedit.dev/v1` |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | Read only by `pixelforge:admin`                    |
| `TRUSTED_PROXIES`               | `*` behind a host's load balancer                  |

Markup, limits and credit packs are in `config/pixelforge.php`.

## Decisions and trade-offs

- **Sign-ups are verified automatically.** The app sends no email, so a reviewer would otherwise be stuck on
  the verification screen.
- **Images are not copied.** Pixelforge's links expire after 24 hours; the history says so instead of showing
  a broken image. Copying them to storage is the obvious next step for a real product.
- **Dependencies are current, with two deliberate exceptions.** `composer audit` and `npm audit` report
  nothing. TypeScript stays on 6.x because `vue-tsc` can't run on TypeScript 7 yet, and `vite-plus` stays on
  1.6 because 1.7 breaks the Vue compiler's type-import resolution (every shadcn component fails to build).
  Both are one-line bumps once upstream catches up.
- **Fair use**: at most 3 images in progress and 60 a day per user, because Pixelforge's limits (3 at once,
  500 a day) are shared by every user of the account.
- **A price rise mid-flight** could, in theory, make a charge slightly larger than what was held; the balance
  would dip below zero and block new images until topped up. Pixelforge fixes rates per generation, and the
  estimate is rounded outwards, so this needs a rate change between estimate and creation.
- **Ambiguous timeouts** on create are retried with the same idempotency key. If Pixelforge stays unreachable
  for a whole hour the generation is failed, which is the one case where a generation Pixelforge did accept
  could be left uncharged.
- **No payment provider.** In a real integration the top-up row would only be written after a confirmed
  payment, using the same idempotency key the ledger already supports.
- **Refunds for images that came out badly** are not built; an admin credit adjustment is the natural addition.

## Layout

```
app/Pricing/        money: Nano, Rates, TokenUsage, PricingSchedule, GenerationPricer
app/Pixelforge/     API client, API error type, webhook signature check
app/Generations/    GenerationSettler: the one place a generation changes state
app/Credits/        CreditLedger: balance, held credits, charges
app/Jobs/           SubmitGeneration, ProcessPixelforgeEvent
app/Reports/        UsageReport (the operators' numbers)
app/Console/        generations:sync, pixelforge:admin
resources/js/pages/ generations, credits, admin
tests/              Pest: pricing, webhooks, submission, sync, credits, report
```

Built with AI assistance (Claude Code), which the brief allows.
