<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, CircleHelp, Copy } from '@lucide/vue';
import { computed, reactive } from 'vue';
import { toast } from 'vue-sonner';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { report } from '@/routes/admin';

type Money = string;

const props = defineProps<{
    filters: { from: string; to: string; view: 'day' | 'user' | 'day_user' };
    totals: {
        generations: number;
        charged: Money;
        provider_cost: Money;
        profit: Money;
        pixelforge_cost: Money | null;
        reconciliation: 'match' | 'mismatch' | 'unavailable';
        mismatched_days: number;
    };
    connection: {
        webhook_url: string;
        api_key_configured: boolean;
        webhook_secret_configured: boolean;
        api_reachable: boolean;
        last_event_at: string | null;
    };
    days: {
        day: string;
        generations: number;
        charged: Money;
        provider_cost: Money;
        profit: Money;
        pixelforge_cost: Money | null;
        pixelforge_generations: number | null;
        matches: boolean | null;
    }[];
    users: {
        user_id: number | null;
        name: string;
        email: string;
        generations: number;
        charged: Money;
        provider_cost: Money;
        profit: Money;
    }[];
    dayUsers: {
        day: string;
        user_id: number | null;
        name: string;
        email: string;
        generations: number;
        charged: Money;
        provider_cost: Money;
        profit: Money;
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Report', href: report() }],
    },
});

const form = reactive({ from: props.filters.from, to: props.filters.to });

const views = [
    { value: 'day', label: 'By day' },
    { value: 'user', label: 'By user' },
    { value: 'day_user', label: 'By day and user' },
] as const;

function load(view: string = props.filters.view) {
    router.get(
        report.url(),
        { from: form.from, to: form.to, view },
        { preserveState: true, preserveScroll: true },
    );
}

async function copyWebhookUrl() {
    try {
        await navigator.clipboard.writeText(props.connection.webhook_url);
        toast.success('Webhook URL copied.');
    } catch {
        toast.error('Could not copy. Select the address and copy it by hand.');
    }
}

const lastEvent = computed(() =>
    props.connection.last_event_at
        ? new Date(props.connection.last_event_at).toLocaleString(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : 'None yet',
);

const stats = computed(() => [
    { label: 'Charged to users', value: props.totals.charged },
    { label: 'Pixelforge cost', value: props.totals.provider_cost },
    { label: 'Profit', value: props.totals.profit },
    { label: 'Images', value: String(props.totals.generations) },
]);
</script>

<template>
    <Head title="Report" />

    <div class="mx-auto w-full max-w-6xl p-4 md:p-6">
        <Heading
            title="Report"
            description="What users were charged, what Pixelforge billed, and the profit. A day is the UTC day an image finished."
        />

        <form
            class="mb-6 flex flex-wrap items-end gap-3"
            @submit.prevent="load()"
        >
            <div class="grid gap-1.5">
                <Label for="from">From</Label>
                <Input id="from" v-model="form.from" type="date" required />
            </div>
            <div class="grid gap-1.5">
                <Label for="to">To</Label>
                <Input id="to" v-model="form.to" type="date" required />
            </div>
            <Button type="submit" variant="secondary">Update</Button>
            <InputError
                :message="($page.props.errors as Record<string, string>)?.to"
            />
            <InputError
                :message="($page.props.errors as Record<string, string>)?.from"
            />
        </form>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div
                v-for="stat in stats"
                :key="stat.label"
                class="rounded-2xl border bg-card p-4"
            >
                <p class="text-xs text-muted-foreground">{{ stat.label }}</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">
                    {{ stat.value }}
                </p>
            </div>
        </div>

        <p
            class="mt-4 flex items-start gap-2 rounded-xl border p-3 text-sm"
            :class="{
                'border-emerald-500/30 bg-emerald-500/10':
                    totals.reconciliation === 'match',
                'border-amber-500/40 bg-amber-500/10':
                    totals.reconciliation === 'mismatch',
                'bg-muted': totals.reconciliation === 'unavailable',
            }"
            role="status"
        >
            <CircleCheck
                v-if="totals.reconciliation === 'match'"
                class="mt-0.5 size-4 shrink-0 text-emerald-600"
                aria-hidden="true"
            />
            <CircleAlert
                v-else-if="totals.reconciliation === 'mismatch'"
                class="mt-0.5 size-4 shrink-0 text-amber-600"
                aria-hidden="true"
            />
            <CircleHelp
                v-else
                class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
            <span v-if="totals.reconciliation === 'match'">
                Every day matches Pixelforge's own usage report (<span
                    class="tabular-nums"
                    >{{ totals.pixelforge_cost }}</span
                >
                billed in total).
            </span>
            <span v-else-if="totals.reconciliation === 'mismatch'">
                {{ totals.mismatched_days }}
                {{
                    totals.mismatched_days === 1 ? 'day differs' : 'days differ'
                }}
                from Pixelforge's usage report. An image that has finished at
                Pixelforge but not yet reached us can cause this briefly.
            </span>
            <span v-else>
                Pixelforge's usage report could not be reached, so these numbers
                are not checked against it.
            </span>
        </p>

        <div
            class="mt-6 inline-flex rounded-xl border bg-muted p-1 text-sm"
            role="tablist"
        >
            <button
                v-for="view in views"
                :key="view.value"
                type="button"
                role="tab"
                :aria-selected="filters.view === view.value"
                class="rounded-lg px-3 py-1.5 font-medium transition-colors"
                :class="
                    filters.view === view.value
                        ? 'bg-background shadow-sm'
                        : 'text-muted-foreground hover:text-foreground'
                "
                @click="load(view.value)"
            >
                {{ view.label }}
            </button>
        </div>

        <div class="mt-4 overflow-x-auto rounded-2xl border bg-card">
            <table class="w-full min-w-[40rem] text-sm tabular-nums">
                <thead class="border-b text-left text-xs text-muted-foreground">
                    <tr>
                        <th
                            v-if="filters.view !== 'user'"
                            class="p-3 font-medium"
                        >
                            Day (UTC)
                        </th>
                        <th
                            v-if="filters.view !== 'day'"
                            class="p-3 font-medium"
                        >
                            User
                        </th>
                        <th class="p-3 text-right font-medium">Images</th>
                        <th class="p-3 text-right font-medium">Charged</th>
                        <th class="p-3 text-right font-medium">
                            Pixelforge cost
                        </th>
                        <th class="p-3 text-right font-medium">Profit</th>
                        <template v-if="filters.view === 'day'">
                            <th class="p-3 text-right font-medium">
                                Pixelforge says
                            </th>
                            <th class="p-3 text-center font-medium">Check</th>
                        </template>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <template v-if="filters.view === 'day'">
                        <tr v-for="row in days" :key="row.day">
                            <td class="p-3">{{ row.day }}</td>
                            <td class="p-3 text-right">
                                {{ row.generations }}
                            </td>
                            <td class="p-3 text-right">{{ row.charged }}</td>
                            <td class="p-3 text-right">
                                {{ row.provider_cost }}
                            </td>
                            <td class="p-3 text-right">{{ row.profit }}</td>
                            <td class="p-3 text-right text-muted-foreground">
                                {{ row.pixelforge_cost ?? '–' }}
                            </td>
                            <td class="p-3 text-center">
                                <CircleCheck
                                    v-if="row.matches === true"
                                    class="mx-auto size-4 text-emerald-600"
                                    aria-label="Matches"
                                />
                                <CircleAlert
                                    v-else-if="row.matches === false"
                                    class="mx-auto size-4 text-amber-600"
                                    aria-label="Differs"
                                />
                                <span v-else class="text-muted-foreground"
                                    >–</span
                                >
                            </td>
                        </tr>
                    </template>
                    <template v-else-if="filters.view === 'user'">
                        <tr
                            v-for="row in users"
                            :key="row.user_id ?? 'deleted'"
                        >
                            <td class="p-3">
                                <div class="font-medium">{{ row.name }}</div>
                                <div class="text-xs text-muted-foreground">
                                    {{ row.email }}
                                </div>
                            </td>
                            <td class="p-3 text-right">
                                {{ row.generations }}
                            </td>
                            <td class="p-3 text-right">{{ row.charged }}</td>
                            <td class="p-3 text-right">
                                {{ row.provider_cost }}
                            </td>
                            <td class="p-3 text-right">{{ row.profit }}</td>
                        </tr>
                        <tr v-if="!users.length">
                            <td
                                colspan="5"
                                class="p-6 text-center text-muted-foreground"
                            >
                                No finished images in these dates.
                            </td>
                        </tr>
                    </template>
                    <template v-else>
                        <tr
                            v-for="row in dayUsers"
                            :key="row.day + (row.user_id ?? 'deleted')"
                        >
                            <td class="p-3">{{ row.day }}</td>
                            <td class="p-3">
                                <div class="font-medium">{{ row.name }}</div>
                                <div class="text-xs text-muted-foreground">
                                    {{ row.email }}
                                </div>
                            </td>
                            <td class="p-3 text-right">
                                {{ row.generations }}
                            </td>
                            <td class="p-3 text-right">{{ row.charged }}</td>
                            <td class="p-3 text-right">
                                {{ row.provider_cost }}
                            </td>
                            <td class="p-3 text-right">{{ row.profit }}</td>
                        </tr>
                        <tr v-if="!dayUsers.length">
                            <td
                                colspan="6"
                                class="p-6 text-center text-muted-foreground"
                            >
                                No finished images in these dates.
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <section
            class="mt-10 rounded-2xl border bg-card p-5"
            aria-labelledby="connection-heading"
        >
            <h2 id="connection-heading" class="text-base font-medium">
                Pixelforge connection
            </h2>
            <p class="mt-1 text-sm text-muted-foreground">
                The API key and signing secret are set in the server's
                environment (<code class="rounded bg-muted px-1"
                    >PIXELFORGE_API_KEY</code
                >
                and
                <code class="rounded bg-muted px-1"
                    >PIXELFORGE_WEBHOOK_SECRET</code
                >), never in the database or the browser. In the Pixelforge
                dashboard, give the key this webhook address and copy its
                signing secret.
            </p>

            <div class="mt-4 flex items-center gap-2">
                <Input
                    :model-value="connection.webhook_url"
                    readonly
                    aria-label="Webhook URL"
                    class="font-mono text-xs"
                    @focus="($event.target as HTMLInputElement).select()"
                />
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    aria-label="Copy the webhook URL"
                    @click="copyWebhookUrl"
                >
                    <Copy />
                </Button>
            </div>

            <dl class="mt-4 grid gap-2 text-sm sm:grid-cols-2">
                <div
                    class="flex items-center justify-between gap-4 rounded-xl bg-muted/60 px-3 py-2"
                >
                    <dt>API key</dt>
                    <dd
                        :class="
                            connection.api_key_configured
                                ? 'text-emerald-700 dark:text-emerald-400'
                                : 'text-destructive'
                        "
                    >
                        {{ connection.api_key_configured ? 'Set' : 'Missing' }}
                    </dd>
                </div>
                <div
                    class="flex items-center justify-between gap-4 rounded-xl bg-muted/60 px-3 py-2"
                >
                    <dt>Webhook signing secret</dt>
                    <dd
                        :class="
                            connection.webhook_secret_configured
                                ? 'text-emerald-700 dark:text-emerald-400'
                                : 'text-destructive'
                        "
                    >
                        {{
                            connection.webhook_secret_configured
                                ? 'Set'
                                : 'Missing'
                        }}
                    </dd>
                </div>
                <div
                    class="flex items-center justify-between gap-4 rounded-xl bg-muted/60 px-3 py-2"
                >
                    <dt>Can reach Pixelforge</dt>
                    <dd
                        :class="
                            connection.api_reachable
                                ? 'text-emerald-700 dark:text-emerald-400'
                                : 'text-destructive'
                        "
                    >
                        {{ connection.api_reachable ? 'Yes' : 'No' }}
                    </dd>
                </div>
                <div
                    class="flex items-center justify-between gap-4 rounded-xl bg-muted/60 px-3 py-2"
                >
                    <dt>Last webhook received</dt>
                    <dd class="tabular-nums">{{ lastEvent }}</dd>
                </div>
            </dl>
        </section>
    </div>
</template>
