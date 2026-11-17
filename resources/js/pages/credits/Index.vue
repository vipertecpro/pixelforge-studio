<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowDownLeft,
    Coins,
    Gift,
    Hourglass,
    Sparkles,
    Wallet,
} from '@lucide/vue';
import { ref } from 'vue';
import type { Component } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { index, store } from '@/routes/credits';

defineProps<{
    balance: string;
    reserved: string;
    available: string;
    packs: { usd: number; label: string }[];
    transactions: {
        data: {
            id: number;
            type: 'signup_bonus' | 'top_up' | 'charge';
            amount: string;
            is_charge: boolean;
            description: string | null;
            created_at: string | null;
        }[];
        links: { prev: string | null; next: string | null };
        meta: { current_page: number; last_page: number };
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Credits', href: index() }],
    },
});

const buying = ref<number | null>(null);

function buy(pack: number) {
    buying.value = pack;
    router.post(
        store.url(),
        // A fresh id for each click, so a retry of the same click can't add twice.
        { pack, request_id: crypto.randomUUID() },
        {
            preserveScroll: true,
            onFinish: () => (buying.value = null),
        },
    );
}

const typeIcon: Record<string, Component> = {
    signup_bonus: Gift,
    top_up: ArrowDownLeft,
    charge: Sparkles,
};

const typeLabel: Record<string, string> = {
    signup_bonus: 'Welcome credit',
    top_up: 'Credits added',
    charge: 'Image',
};

function formatDate(iso: string | null): string {
    return iso
        ? new Date(iso).toLocaleString(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : '';
}
</script>

<template>
    <Head title="Credits" />

    <div class="mx-auto w-full max-w-4xl p-4 md:p-6">
        <Heading
            title="Credits"
            description="Credits are US dollars, spent as your images finish. A failed image costs nothing."
        />

        <div class="grid gap-4 motion-safe:animate-rise sm:grid-cols-3">
            <div
                class="rounded-2xl border bg-gradient-to-br from-indigo-500/10 via-violet-500/10 to-rose-400/10 p-5 sm:col-span-1"
            >
                <p
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Wallet class="size-4" aria-hidden="true" /> Balance
                </p>
                <p
                    class="mt-2 text-3xl font-semibold tracking-tight tabular-nums"
                >
                    {{ balance }}
                </p>
            </div>
            <div class="rounded-2xl border bg-card p-5">
                <p
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Hourglass class="size-4" aria-hidden="true" /> Held for
                    running images
                </p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">
                    {{ reserved }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    The most they could cost. You only pay the exact price when
                    each one finishes.
                </p>
            </div>
            <div class="rounded-2xl border bg-card p-5">
                <p
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Coins class="size-4" aria-hidden="true" /> Available
                </p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">
                    {{ available }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    What you can spend on new images right now.
                </p>
            </div>
        </div>

        <section class="mt-10" aria-labelledby="buy-heading">
            <h2 id="buy-heading" class="text-base font-medium">Add credits</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                This is a test purchase: credits are added straight away and
                nothing is charged.
            </p>
            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                <Button
                    v-for="pack in packs"
                    :key="pack.usd"
                    type="button"
                    variant="outline"
                    size="lg"
                    class="h-auto justify-between rounded-xl px-5 py-4 text-lg font-semibold motion-safe:active:scale-[0.98]"
                    :disabled="buying !== null"
                    @click="buy(pack.usd)"
                >
                    <span>{{ pack.label }}</span>
                    <span class="text-sm font-normal text-muted-foreground">
                        {{ buying === pack.usd ? 'Adding…' : 'Buy credits' }}
                    </span>
                </Button>
            </div>
        </section>

        <section class="mt-10" aria-labelledby="history-heading">
            <h2 id="history-heading" class="text-base font-medium">Activity</h2>

            <ul
                v-if="transactions.data.length"
                class="mt-4 divide-y rounded-2xl border bg-card"
            >
                <li
                    v-for="transaction in transactions.data"
                    :key="transaction.id"
                    class="flex items-center gap-4 p-4"
                >
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-xl"
                        :class="
                            transaction.is_charge
                                ? 'bg-primary/10 text-primary'
                                : 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400'
                        "
                    >
                        <component
                            :is="typeIcon[transaction.type]"
                            class="size-4"
                            aria-hidden="true"
                        />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">
                            {{ typeLabel[transaction.type] }}
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ transaction.description }} ·
                            {{ formatDate(transaction.created_at) }}
                        </p>
                    </div>
                    <p
                        class="text-sm font-semibold tabular-nums"
                        :class="
                            transaction.is_charge
                                ? ''
                                : 'text-emerald-700 dark:text-emerald-400'
                        "
                    >
                        {{ transaction.amount }}
                    </p>
                </li>
            </ul>
            <p v-else class="mt-4 text-sm text-muted-foreground">
                Nothing yet.
            </p>

            <nav
                v-if="transactions.meta.last_page > 1"
                class="mt-4 flex items-center justify-between text-sm"
                aria-label="Pagination"
            >
                <Button
                    v-if="transactions.links.prev"
                    as-child
                    variant="outline"
                    size="sm"
                >
                    <Link :href="transactions.links.prev" preserve-scroll>
                        Newer
                    </Link>
                </Button>
                <span v-else />
                <span class="text-muted-foreground">
                    Page {{ transactions.meta.current_page }} of
                    {{ transactions.meta.last_page }}
                </span>
                <Button
                    v-if="transactions.links.next"
                    as-child
                    variant="outline"
                    size="sm"
                >
                    <Link :href="transactions.links.next" preserve-scroll>
                        Older
                    </Link>
                </Button>
                <span v-else />
            </nav>
        </section>
    </div>
</template>
