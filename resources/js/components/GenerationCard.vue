<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    CircleCheck,
    CircleX,
    Clock,
    ImageOff,
    LoaderCircle,
    RotateCcw,
    Send,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { create } from '@/routes/generations';
import type { Generation } from '@/types';

const props = defineProps<{ generation: Generation }>();

const imageBroken = ref(false);
const imageLoaded = ref(false);

const aspect = computed(
    () =>
        ({
            '1024x1024': 'aspect-square',
            '1536x1024': 'aspect-[3/2]',
            '1024x1536': 'aspect-[2/3]',
        })[props.generation.size] ?? 'aspect-square',
);

const isActive = computed(
    () =>
        props.generation.status === 'queued' ||
        props.generation.status === 'processing',
);

// Where an in-flight image is: handed to Pixelforge, waiting its turn, or being drawn.
const steps = ['Sent', 'Queued', 'Generating'];
const stepIndex = computed(() => {
    if (props.generation.status === 'processing') {
        return 2;
    }

    return props.generation.sending ? 0 : 1;
});

const status = computed(() => {
    switch (props.generation.status) {
        case 'queued':
            return {
                label: props.generation.sending ? 'Sending' : 'Queued',
                icon: props.generation.sending ? Send : Clock,
                class: 'bg-secondary text-secondary-foreground',
                spin: false,
            };
        case 'processing':
            return {
                label: 'Generating',
                icon: LoaderCircle,
                class: 'bg-primary/15 text-primary',
                spin: true,
            };
        case 'succeeded':
            return {
                label: 'Done',
                icon: CircleCheck,
                class: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
                spin: false,
            };
        default:
            return {
                label: 'Failed',
                icon: CircleX,
                class: 'bg-destructive/10 text-destructive dark:text-red-400',
                spin: false,
            };
    }
});

const createdAt = computed(() =>
    props.generation.created_at
        ? new Date(props.generation.created_at).toLocaleString(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : '',
);

const sizeText = computed(() => props.generation.size.replace('x', ' × '));
</script>

<template>
    <article
        class="flex flex-col overflow-hidden rounded-2xl border bg-card text-card-foreground shadow-xs transition-shadow duration-300 hover:shadow-md"
        :class="{ 'ring-1 ring-primary/25': isActive }"
    >
        <div
            class="relative flex w-full items-center justify-center overflow-hidden bg-muted"
            :class="aspect"
        >
            <div
                v-if="generation.image_url && !imageBroken && !imageLoaded"
                class="absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-primary/10 to-transparent motion-safe:animate-shimmer"
                aria-hidden="true"
            />
            <img
                v-if="generation.image_url && !imageBroken"
                :src="generation.image_url"
                :alt="generation.prompt"
                class="size-full object-cover transition duration-700 ease-out"
                :class="
                    imageLoaded
                        ? 'blur-0 scale-100 opacity-100'
                        : 'scale-105 opacity-0 blur-md'
                "
                loading="lazy"
                @load="imageLoaded = true"
                @error="imageBroken = true"
            />

            <div
                v-else-if="isActive"
                class="relative flex size-full flex-col items-center justify-center gap-4 p-4 text-center"
            >
                <div
                    class="absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-primary/10 to-transparent motion-safe:animate-shimmer"
                    aria-hidden="true"
                />
                <LoaderCircle
                    class="relative size-7 text-primary motion-safe:animate-spin"
                    aria-hidden="true"
                />
                <ol
                    class="relative flex w-full max-w-48 items-center gap-1.5"
                    :aria-label="'Progress: ' + steps[stepIndex]"
                >
                    <li
                        v-for="(step, index) in steps"
                        :key="step"
                        class="flex flex-1 flex-col items-center gap-1.5 text-[11px] font-medium"
                        :class="
                            index <= stepIndex
                                ? 'text-foreground'
                                : 'text-muted-foreground/70'
                        "
                    >
                        <span
                            class="h-1 w-full rounded-full transition-colors duration-500"
                            :class="[
                                index <= stepIndex
                                    ? 'bg-primary'
                                    : 'bg-foreground/10',
                                index === stepIndex
                                    ? 'motion-safe:animate-pulse'
                                    : '',
                            ]"
                        />
                        {{ step }}
                    </li>
                </ol>
            </div>

            <div
                v-else-if="generation.status === 'failed'"
                class="flex flex-col items-center gap-2 p-6 text-center text-sm motion-safe:animate-pop"
            >
                <span
                    class="flex size-11 items-center justify-center rounded-full bg-destructive/10"
                >
                    <TriangleAlert
                        class="size-5 text-destructive dark:text-red-400"
                        aria-hidden="true"
                    />
                </span>
                <p class="text-balance">
                    {{
                        generation.error ?? 'This image could not be generated.'
                    }}
                </p>
            </div>

            <div
                v-else
                class="flex flex-col items-center gap-2 p-6 text-center text-sm text-muted-foreground"
            >
                <ImageOff class="size-6" aria-hidden="true" />
                <p class="text-balance">
                    This image link has expired. Pixelforge keeps images for 24
                    hours.
                </p>
            </div>
        </div>

        <div class="flex flex-1 flex-col gap-3 p-4">
            <div class="flex items-start justify-between gap-3">
                <p class="line-clamp-3 text-sm" :title="generation.prompt">
                    {{ generation.prompt }}
                </p>
                <Badge
                    :class="status.class"
                    class="shrink-0 gap-1 border-transparent"
                >
                    <component
                        :is="status.icon"
                        :class="{ 'motion-safe:animate-spin': status.spin }"
                        aria-hidden="true"
                    />
                    {{ status.label }}
                </Badge>
            </div>

            <dl class="mt-auto grid gap-1 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Estimated</dt>
                    <dd class="text-right tabular-nums">
                        {{ generation.estimated_min }} –
                        {{ generation.estimated_max }}
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Charged</dt>
                    <dd
                        v-if="generation.status === 'succeeded'"
                        class="font-semibold tabular-nums motion-safe:animate-pop"
                    >
                        {{ generation.charged }}
                    </dd>
                    <dd v-else-if="generation.status === 'failed'">
                        Nothing: failed images are free
                    </dd>
                    <dd v-else class="text-muted-foreground">Not yet</dd>
                </div>
            </dl>

            <p class="text-xs text-muted-foreground">
                <time :datetime="generation.created_at ?? undefined">{{
                    createdAt
                }}</time>
                · {{ sizeText }} · {{ generation.quality }}
                <template v-if="generation.reference_images.length">
                    · {{ generation.reference_images.length }} reference{{
                        generation.reference_images.length === 1 ? '' : 's'
                    }}
                </template>
            </p>

            <Link
                v-if="
                    generation.status === 'failed' ||
                    generation.status === 'succeeded'
                "
                :href="create({ query: { from: generation.id } })"
                class="inline-flex w-fit items-center gap-1.5 text-xs font-medium text-primary underline-offset-4 hover:underline"
            >
                <RotateCcw class="size-3.5" aria-hidden="true" />
                {{
                    generation.status === 'failed'
                        ? 'Try again'
                        : 'Make another like this'
                }}
            </Link>
        </div>
    </article>
</template>
