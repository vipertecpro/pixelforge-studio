<?php

namespace App\Http\Controllers;

use App\Credits\CreditLedger;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Http\Requests\EstimateGenerationRequest;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Jobs\SubmitGeneration;
use App\Models\User;
use App\Pixelforge\PixelforgeApiException;
use App\Pixelforge\PixelforgeClient;
use App\Pricing\Estimate;
use App\Pricing\GenerationPricer;
use App\Pricing\Nano;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('generations/Index', [
            // The list streams in after the page, with skeletons holding its place; the
            // query only runs when the data is asked for (including each live refresh).
            'generations' => Inertia::defer(fn () => GenerationResource::collection(
                $request->user()->generations()->latest()->paginate(12)
            )),
        ]);
    }

    public function create(Request $request): Response
    {
        // "Try again" and "Make another like this" open the form filled in from
        // one of the user's own images (never anybody else's).
        $source = $request->filled('from')
            ? $request->user()->generations()->find($request->string('from')->toString())
            : null;

        return Inertia::render('generations/Create', [
            'sizes' => array_column(ImageSize::cases(), 'value'),
            'qualities' => array_column(ImageQuality::cases(), 'value'),
            'limits' => [
                'prompt' => StoreGenerationRequest::MAX_PROMPT_LENGTH,
                'reference_images' => StoreGenerationRequest::MAX_REFERENCE_IMAGES,
            ],
            'markupPercent' => (int) config('pixelforge.markup_percent'),
            'prefill' => $source === null ? null : [
                'prompt' => $source->prompt,
                'size' => $source->size,
                'quality' => $source->quality,
                'reference_images' => $source->reference_images,
            ],
        ]);
    }

    /**
     * The price shown while the user fills in the form, before they submit.
     */
    public function estimate(EstimateGenerationRequest $request, PixelforgeClient $client, GenerationPricer $pricer, CreditLedger $ledger): JsonResponse
    {
        try {
            $estimate = $this->estimateFor($request, $client, $pricer);
        } catch (PixelforgeApiException) {
            return response()->json(['message' => 'Prices are unavailable right now. Please try again in a moment.'], 503);
        }

        $available = $ledger->summary($request->user())['available'];

        return response()->json([
            'min' => Nano::format($estimate->userMinNano),
            'max' => Nano::format($estimate->userMaxNano),
            'provider_min' => Nano::format($estimate->providerMinNano),
            'provider_max' => Nano::format($estimate->providerMaxNano),
            'markup_percent' => (int) config('pixelforge.markup_percent'),
            'available' => Nano::format($available, 2),
            'affordable' => $estimate->userMaxNano <= $available,
            'tokens' => [
                'text' => [$estimate->textTokensMin, $estimate->textTokensMax],
                'image' => $estimate->imageTokens,
                'output' => $estimate->outputTokens,
                'reasoning' => [$estimate->reasoningTokensMin, $estimate->reasoningTokensMax],
            ],
        ]);
    }

    public function store(StoreGenerationRequest $request, PixelforgeClient $client, GenerationPricer $pricer, CreditLedger $ledger): RedirectResponse
    {
        try {
            $estimate = $this->estimateFor($request, $client, $pricer);
        } catch (PixelforgeApiException) {
            throw ValidationException::withMessages([
                'form' => 'Prices are unavailable right now, so this was not submitted. Please try again in a moment.',
            ]);
        }

        // Everything that decides whether this image may be made happens under one
        // lock on the user, so two quick submissions can't spend the same credits
        // or beat the limits.
        [$generation, $created] = DB::transaction(function () use ($request, $ledger, $estimate): array {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $requestId = $request->string('request_id')->toString();

            // The same submission arriving again (double click, back button) is
            // answered with the image it already made.
            $existing = $user->generations()->where('request_id', $requestId)->first();

            if ($existing !== null) {
                return [$existing, false];
            }

            $this->ensureWithinFairUse($user);
            $available = $ledger->availableNano($user);

            if ($estimate->userMaxNano > $available) {
                throw ValidationException::withMessages([
                    'form' => 'Not enough credits. This image could cost up to '.Nano::format($estimate->userMaxNano, 2)
                        .' and you have '.Nano::format(max(0, $available), 2).' available. Add credits to continue.',
                ]);
            }

            return [$user->generations()->create([
                'request_id' => $requestId,
                'status' => GenerationStatus::Queued,
                'prompt' => $request->string('prompt')->toString(),
                'size' => $request->string('size')->toString(),
                'quality' => $request->string('quality')->toString(),
                'reference_images' => $request->referenceImages(),
                'estimated_min_nano' => $estimate->userMinNano,
                'estimated_max_nano' => $estimate->userMaxNano,
            ]), true];
        });

        if ($created) {
            // Sending to Pixelforge happens in the background, with retries.
            SubmitGeneration::dispatch($generation->id);
        }

        return to_route('generations.index');
    }

    /**
     * Pixelforge's limits are shared by every user, so each user gets a fair slice.
     */
    private function ensureWithinFairUse(User $user): void
    {
        $maxActive = (int) config('pixelforge.limits.active_per_user');
        $maxPerDay = (int) config('pixelforge.limits.per_user_per_day');

        if ($user->generations()->whereIn('status', GenerationStatus::active())->count() >= $maxActive) {
            throw ValidationException::withMessages([
                'form' => "You already have {$maxActive} images in progress. Wait for one to finish, then try again.",
            ]);
        }

        if ($user->generations()->where('created_at', '>=', now()->subDay())->count() >= $maxPerDay) {
            throw ValidationException::withMessages([
                'form' => "You have reached today's limit of {$maxPerDay} images. Please try again tomorrow.",
            ]);
        }
    }

    private function estimateFor(StoreGenerationRequest $request, PixelforgeClient $client, GenerationPricer $pricer): Estimate
    {
        return $pricer->estimate(
            $client->pricing(),
            $request->string('prompt')->toString(),
            $request->enum('size', ImageSize::class) ?? ImageSize::Square,
            $request->enum('quality', ImageQuality::class) ?? ImageQuality::Medium,
            count($request->input('reference_images', [])),
        );
    }
}
