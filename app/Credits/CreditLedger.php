<?php

namespace App\Credits;

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A user's credits, in nanodollars. Three numbers matter:
 *
 *  - balance: everything credited minus everything charged so far;
 *  - reserved: the most that images still being made could yet cost, because
 *    their exact price is only known when they finish;
 *  - available: balance minus reserved. A new image is only accepted if its
 *    highest possible price fits in what is available, so a user can never end
 *    up owing money, however many images finish at once.
 */
final class CreditLedger
{
    public function balanceNano(User $user): int
    {
        return (int) $user->creditTransactions()->sum('amount_nano');
    }

    public function reservedNano(User $user): int
    {
        return (int) $user->generations()
            ->whereIn('status', GenerationStatus::active())
            ->sum('estimated_max_nano');
    }

    public function availableNano(User $user): int
    {
        return $this->balanceNano($user) - $this->reservedNano($user);
    }

    /**
     * All three numbers in two queries, for pages that show them together.
     *
     * @return array{balance: int, reserved: int, available: int}
     */
    public function summary(User $user): array
    {
        $balance = $this->balanceNano($user);
        $reserved = $this->reservedNano($user);

        return ['balance' => $balance, 'reserved' => $reserved, 'available' => $balance - $reserved];
    }

    /**
     * Add a credit or debit. With an idempotency key, asking again with the same
     * key returns the first row instead of crediting twice (double clicks,
     * retries after a lost response).
     */
    public function add(
        User $user,
        CreditTransactionType $type,
        int $amountNano,
        ?string $description = null,
        ?string $idempotencyKey = null,
    ): CreditTransaction {
        if ($idempotencyKey !== null && ($existing = $this->withKey($user, $idempotencyKey)) !== null) {
            return $existing;
        }

        try {
            return $user->creditTransactions()->create([
                'type' => $type,
                'amount_nano' => $amountNano,
                'description' => $description,
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            // Two identical requests raced; the other one won.
            return $this->withKey($user, (string) $idempotencyKey) ?? throw $exception;
        }
    }

    private function withKey(User $user, string $idempotencyKey): ?CreditTransaction
    {
        return $user->creditTransactions()->where('idempotency_key', $idempotencyKey)->first();
    }

    /**
     * Take what a finished generation cost. The unique (generation_id, type)
     * index makes a second call for the same generation fail loudly rather
     * than charge twice.
     */
    public function charge(Generation $generation): ?CreditTransaction
    {
        if ($generation->charged_nano === null || $generation->charged_nano === 0) {
            return null;
        }

        return CreditTransaction::query()->create([
            'user_id' => $generation->user_id,
            'type' => CreditTransactionType::Charge,
            'amount_nano' => -$generation->charged_nano,
            'generation_id' => $generation->id,
            'description' => 'Image generation',
        ]);
    }
}
