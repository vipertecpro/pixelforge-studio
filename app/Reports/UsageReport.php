<?php

namespace App\Reports;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Money by day and by user, from our own records. Only succeeded generations
 * count, and a generation belongs to the UTC day it completed on, which is
 * exactly how Pixelforge's GET /v1/usage reports its billing.
 */
final class UsageReport
{
    /**
     * @return array<string, array{generations: int, charged: int, cost: int}> keyed by Y-m-d
     */
    public function byDay(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->succeeded($from, $to)
            ->selectRaw('DATE(completed_at) as day, COUNT(*) as generations, SUM(charged_nano) as charged, SUM(provider_cost_nano) as cost')
            ->groupBy('day')
            ->get();

        $days = [];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $days[$day->toDateString()] = ['generations' => 0, 'charged' => 0, 'cost' => 0];
        }

        foreach ($rows as $row) {
            $days[(string) $row->getAttribute('day')] = $this->totals($row);
        }

        return $days;
    }

    /**
     * @return array<int, array{user_id: int|null, name: string, email: string, generations: int, charged: int, cost: int}>
     */
    public function byUser(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->succeeded($from, $to)
            ->selectRaw('user_id, COUNT(*) as generations, SUM(charged_nano) as charged, SUM(provider_cost_nano) as cost')
            ->groupBy('user_id')
            ->get();

        $users = User::query()->whereIn('id', $rows->pluck('user_id')->filter())->get()->keyBy('id');

        return $rows
            ->map(fn (Generation $row): array => [
                'user_id' => $row->user_id,
                'name' => $this->owner($users, $row)->name ?? 'Deleted account',
                'email' => $this->owner($users, $row)->email ?? '',
                ...$this->totals($row),
            ])
            ->sortByDesc('charged')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{day: string, user_id: int|null, name: string, email: string, generations: int, charged: int, cost: int}>
     */
    public function byDayAndUser(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->succeeded($from, $to)
            ->selectRaw('DATE(completed_at) as day, user_id, COUNT(*) as generations, SUM(charged_nano) as charged, SUM(provider_cost_nano) as cost')
            ->groupBy('day', 'user_id')
            ->get();

        $users = User::query()->whereIn('id', $rows->pluck('user_id')->filter())->get()->keyBy('id');

        return $rows
            ->map(fn (Generation $row): array => [
                'day' => (string) $row->getAttribute('day'),
                'user_id' => $row->user_id,
                'name' => $this->owner($users, $row)->name ?? 'Deleted account',
                'email' => $this->owner($users, $row)->email ?? '',
                ...$this->totals($row),
            ])
            ->sortBy([['day', 'desc'], ['charged', 'desc']])
            ->values()
            ->all();
    }

    /**
     * @return Builder<Generation>
     */
    private function succeeded(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return Generation::query()
            ->where('status', GenerationStatus::Succeeded)
            ->where('completed_at', '>=', $from->startOfDay())
            ->where('completed_at', '<=', $to->endOfDay());
    }

    /**
     * The row's user, or null when the account was deleted (its images stay in
     * the books, so the report keeps agreeing with Pixelforge).
     *
     * @param  Collection<int, User>  $users
     */
    private function owner(Collection $users, Generation $row): ?User
    {
        return $row->user_id === null ? null : $users->get($row->user_id);
    }

    /**
     * @return array{generations: int, charged: int, cost: int}
     */
    private function totals(Generation $row): array
    {
        return [
            'generations' => (int) $row->getAttribute('generations'),
            'charged' => (int) $row->getAttribute('charged'),
            'cost' => (int) $row->getAttribute('cost'),
        ];
    }
}
