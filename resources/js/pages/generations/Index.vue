<script setup lang="ts">
import { Deferred, Head, Link, usePoll } from '@inertiajs/vue3';
import { Images, Sparkles } from '@lucide/vue';
import { computed, watch } from 'vue';
import GenerationCard from '@/components/GenerationCard.vue';
import GenerationGridSkeleton from '@/components/GenerationGridSkeleton.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { create, index } from '@/routes/generations';
import type { Generation } from '@/types';

const props = defineProps<{
    generations?: {
        data: Generation[];
        links: { prev: string | null; next: string | null };
        meta: { current_page: number; last_page: number };
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'History', href: index() }],
    },
});

const hasActive = computed(() =>
    (props.generations?.data ?? []).some(
        (generation) =>
            generation.status === 'queued' ||
            generation.status === 'processing',
    ),
);

// Statuses are live: while anything is still running, refresh just the list.
const { start, stop } = usePoll(
    2000,
    { only: ['generations', 'credits'] },
    { autoStart: false },
);

watch(hasActive, (active) => (active ? start() : stop()), {
    immediate: true,
});
</script>

<template>
    <Head title="History" />

    <div class="mx-auto w-full max-w-6xl p-4 md:p-6">
        <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
            <Heading
                class="!mb-0"
                title="History"
                description="Your images, what each one was estimated at, and what you were actually charged."
            />
            <Button as-child>
                <Link :href="create()"><Sparkles /> Generate an image</Link>
            </Button>
        </div>

        <Deferred data="generations">
            <template #fallback>
                <GenerationGridSkeleton />
            </template>

            <template v-if="generations">
                <div
                    v-if="generations.data.length === 0"
                    class="flex flex-col items-center gap-3 rounded-xl border border-dashed p-12 text-center"
                >
                    <span
                        class="flex size-12 items-center justify-center rounded-2xl bg-primary/10 text-primary"
                    >
                        <Images class="size-6" aria-hidden="true" />
                    </span>
                    <p class="font-medium">No images yet</p>
                    <p class="max-w-sm text-sm text-muted-foreground">
                        Write a prompt and the first one will show up here, with
                        its price.
                    </p>
                    <Button as-child>
                        <Link :href="create()"
                            ><Sparkles /> Generate your first image</Link
                        >
                    </Button>
                </div>

                <TransitionGroup
                    v-else
                    name="card"
                    tag="div"
                    class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
                    aria-live="polite"
                >
                    <GenerationCard
                        v-for="generation in generations.data"
                        :key="generation.id"
                        :generation="generation"
                    />
                </TransitionGroup>

                <nav
                    v-if="generations.meta.last_page > 1"
                    class="mt-8 flex items-center justify-between text-sm"
                    aria-label="Pagination"
                >
                    <Button
                        v-if="generations.links.prev"
                        as-child
                        variant="outline"
                        size="sm"
                    >
                        <Link :href="generations.links.prev" preserve-scroll>
                            Newer
                        </Link>
                    </Button>
                    <span v-else />
                    <span class="text-muted-foreground">
                        Page {{ generations.meta.current_page }} of
                        {{ generations.meta.last_page }}
                    </span>
                    <Button
                        v-if="generations.links.next"
                        as-child
                        variant="outline"
                        size="sm"
                    >
                        <Link :href="generations.links.next" preserve-scroll>
                            Older
                        </Link>
                    </Button>
                    <span v-else />
                </nav>
            </template>
        </Deferred>
    </div>
</template>
