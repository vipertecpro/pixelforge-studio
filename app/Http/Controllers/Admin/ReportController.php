<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PixelforgeEvent;
use App\Pixelforge\PixelforgeApiException;
use App\Pixelforge\PixelforgeClient;
use App\Pricing\Nano;
use App\Reports\UsageReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The operators' view: what users were charged, what Pixelforge billed us and
 * the profit, per day and per user. Pixelforge's own GET /v1/usage is fetched
 * for the same dates so every day shows whether our records agree with theirs.
 */
class ReportController extends Controller
{
    private const MAX_DAYS = 92;

    public function __invoke(Request $request, UsageReport $report, PixelforgeClient $client): Response
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'view' => ['nullable', Rule::in(['day', 'user', 'day_user'])],
        ]);

        $to = isset($filters['to'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $filters['to'], 'UTC')->startOfDay()
            : CarbonImmutable::now('UTC')->startOfDay();
        $from = isset($filters['from'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $filters['from'], 'UTC')->startOfDay()
            : $to->subDays(29);

        if ($from->diffInDays($to) > self::MAX_DAYS - 1) {
            throw ValidationException::withMessages(['to' => 'Pick at most '.self::MAX_DAYS.' days.']);
        }

        $view = $filters['view'] ?? 'day';
        $days = $report->byDay($from, $to);
        $reported = $this->pixelforgeUsage($client, $from, $to);

        $dayRows = [];
        $totals = ['generations' => 0, 'charged' => 0, 'cost' => 0];
        $mismatches = 0;

        foreach (array_reverse($days, true) as $day => $row) {
            $theirs = $reported[$day] ?? null;
            $matches = $theirs === null ? null : $theirs['cost'] === $row['cost'] && $theirs['generations'] === $row['generations'];
            $mismatches += $matches === false ? 1 : 0;

            $dayRows[] = [
                'day' => $day,
                'generations' => $row['generations'],
                'charged' => Nano::format($row['charged']),
                'provider_cost' => Nano::format($row['cost']),
                'profit' => Nano::format($row['charged'] - $row['cost']),
                'pixelforge_cost' => $theirs === null ? null : Nano::format($theirs['cost']),
                'pixelforge_generations' => $theirs['generations'] ?? null,
                'matches' => $matches,
            ];

            $totals['generations'] += $row['generations'];
            $totals['charged'] += $row['charged'];
            $totals['cost'] += $row['cost'];
        }

        return Inertia::render('admin/Report', [
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'view' => $view],
            'totals' => [
                'generations' => $totals['generations'],
                'charged' => Nano::format($totals['charged']),
                'provider_cost' => Nano::format($totals['cost']),
                'profit' => Nano::format($totals['charged'] - $totals['cost']),
                'pixelforge_cost' => $reported === null ? null : Nano::format(array_sum(array_column($reported, 'cost'))),
                'reconciliation' => $reported === null ? 'unavailable' : ($mismatches === 0 ? 'match' : 'mismatch'),
                'mismatched_days' => $mismatches,
            ],
            'connection' => [
                'webhook_url' => route('webhooks.pixelforge'),
                'api_key_configured' => filled(config('pixelforge.api_key')),
                'webhook_secret_configured' => filled(config('pixelforge.webhook_secret')),
                'api_reachable' => $reported !== null,
                'last_event_at' => PixelforgeEvent::query()->latest('id')->first()?->created_at?->toIso8601String(),
            ],
            'days' => $dayRows,
            'users' => $view === 'user' ? $this->formatRows($report->byUser($from, $to)) : [],
            'dayUsers' => $view === 'day_user' ? $this->formatRows($report->byDayAndUser($from, $to)) : [],
        ]);
    }

    /**
     * What Pixelforge says it billed this API key per UTC day, in nanodollars,
     * or null when it can't be reached (the report still works without it).
     *
     * @return array<string, array{cost: int, generations: int}>|null
     */
    private function pixelforgeUsage(PixelforgeClient $client, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        try {
            $usage = $client->usage($from->toDateString(), $to->toDateString());
        } catch (PixelforgeApiException) {
            return null;
        }

        $reported = [];

        foreach ((array) ($usage['data'] ?? []) as $row) {
            $reported[(string) $row['date']] = [
                'cost' => Nano::fromUsdString((string) $row['cost_usd']),
                'generations' => (int) $row['generations'],
            ];
        }

        return $reported;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function formatRows(array $rows): array
    {
        return array_map(fn (array $row): array => [
            ...array_diff_key($row, ['charged' => 1, 'cost' => 1]),
            'charged' => Nano::format($row['charged']),
            'provider_cost' => Nano::format($row['cost']),
            'profit' => Nano::format($row['charged'] - $row['cost']),
        ], $rows);
    }
}
