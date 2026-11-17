<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    Gauge,
    Info,
    LoaderCircle,
    RectangleHorizontal,
    RectangleVertical,
    ShieldCheck,
    Sparkles,
    Square,
    Zap,
} from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import ChoiceGroup from '@/components/ChoiceGroup.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import ReferenceImagesField from '@/components/ReferenceImagesField.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { useEstimate } from '@/composables/useEstimate';
import { create, store } from '@/routes/generations';

const props = defineProps<{
    sizes: string[];
    qualities: string[];
    limits: { prompt: number; reference_images: number };
    markupPercent: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generate', href: create() }],
    },
});

type Described = { label: string; hint: string; icon: Component };

const sizeInfo: Record<string, Described> = {
    '1024x1024': { label: 'Square', hint: '1024 × 1024', icon: Square },
    '1536x1024': {
        label: 'Landscape',
        hint: '1536 × 1024',
        icon: RectangleHorizontal,
    },
    '1024x1536': {
        label: 'Portrait',
        hint: '1024 × 1536',
        icon: RectangleVertical,
    },
};

const qualityInfo: Record<string, Described> = {
    low: { label: 'Low', hint: 'Fast and cheap', icon: Zap },
    medium: { label: 'Medium', hint: 'Balanced', icon: Gauge },
    high: { label: 'High', hint: 'Most detail', icon: Sparkles },
};

const sizeOptions = props.sizes.map((value) => ({
    value,
    label: sizeInfo[value]?.label ?? value,
    hint: sizeInfo[value]?.hint,
    icon: sizeInfo[value]?.icon,
}));

const qualityOptions = props.qualities.map((value) => ({
    value,
    label: qualityInfo[value]?.label ?? value,
    hint: qualityInfo[value]?.hint,
    icon: qualityInfo[value]?.icon,
}));

const form = useForm({
    prompt: '',
    size: props.sizes[0] ?? '1024x1024',
    quality: props.qualities.includes('medium')
        ? 'medium'
        : (props.qualities[0] ?? 'medium'),
    reference_images: [] as string[],
});

const filledReferenceImages = computed(() =>
    form.reference_images.map((link) => link.trim()).filter(Boolean),
);

const { estimate, loading, error } = useEstimate(() => ({
    prompt: form.prompt.trim(),
    size: form.size,
    quality: form.quality,
    reference_images: filledReferenceImages.value,
}));

const formError = computed(
    () => (form.errors as Record<string, string | undefined>).form,
);

const canSubmit = computed(
    () =>
        form.prompt.trim() !== '' &&
        !form.processing &&
        estimate.value !== null,
);

function submit() {
    form.post(store.url());
}
</script>

<template>
    <Head title="Generate" />

    <div class="mx-auto w-full max-w-5xl p-4 md:p-6">
        <Heading
            title="Generate an image"
            description="Describe it, pick a size and quality, and see what it will cost before you submit."
        />

        <form
            class="grid items-start gap-6 motion-safe:animate-rise lg:grid-cols-[minmax(0,1fr)_20rem]"
            @submit.prevent="submit"
        >
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <div class="flex items-baseline justify-between">
                        <Label for="prompt">Prompt</Label>
                        <span
                            class="text-xs text-muted-foreground tabular-nums"
                            :class="{
                                'text-destructive':
                                    form.prompt.length > limits.prompt,
                            }"
                        >
                            {{ form.prompt.length }} / {{ limits.prompt }}
                        </span>
                    </div>
                    <Textarea
                        id="prompt"
                        v-model="form.prompt"
                        class="min-h-36 rounded-xl"
                        placeholder="A lighthouse on a cliff at dawn, oil painting"
                        :maxlength="limits.prompt"
                        required
                        :aria-invalid="form.errors.prompt ? true : undefined"
                    />
                    <InputError :message="form.errors.prompt" />
                </div>

                <div class="grid gap-6 sm:grid-cols-2">
                    <div>
                        <ChoiceGroup
                            v-model="form.size"
                            legend="Size"
                            name="size"
                            :options="sizeOptions"
                        />
                        <InputError class="mt-2" :message="form.errors.size" />
                    </div>
                    <div>
                        <ChoiceGroup
                            v-model="form.quality"
                            legend="Quality"
                            name="quality"
                            :options="qualityOptions"
                        />
                        <InputError
                            class="mt-2"
                            :message="form.errors.quality"
                        />
                    </div>
                </div>

                <ReferenceImagesField
                    v-model="form.reference_images"
                    :max="limits.reference_images"
                    :errors="form.errors"
                />
            </div>

            <aside
                class="grid gap-4 rounded-2xl border bg-card p-5 text-card-foreground shadow-sm lg:sticky lg:top-6"
                aria-live="polite"
            >
                <div>
                    <h2
                        class="flex items-center gap-2 text-sm font-medium text-muted-foreground"
                    >
                        <ShieldCheck class="size-4" aria-hidden="true" />
                        Estimated price
                    </h2>

                    <template v-if="estimate">
                        <p
                            class="mt-3 flex flex-wrap items-baseline gap-x-2 text-2xl font-semibold tracking-tight tabular-nums transition-opacity duration-200"
                            :class="{ 'opacity-40': loading }"
                        >
                            <span>{{ estimate.min }}</span>
                            <span
                                class="text-base font-normal text-muted-foreground"
                                >to</span
                            >
                            <span>{{ estimate.max }}</span>
                        </p>
                        <p class="mt-3 text-sm text-muted-foreground">
                            You will pay
                            <strong class="font-semibold text-foreground">{{
                                estimate.max
                            }}</strong>
                            at most. The exact price is set when your image is
                            finished. If it fails, it costs nothing.
                        </p>
                    </template>
                    <div v-else-if="loading" class="mt-3 grid gap-2">
                        <Skeleton class="h-8 w-52" />
                        <Skeleton class="h-4 w-full" />
                        <Skeleton class="h-4 w-2/3" />
                    </div>
                    <p
                        v-else
                        class="mt-3 flex items-start gap-2 text-sm text-destructive"
                    >
                        <Info
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        {{ error }}
                    </p>
                </div>

                <details v-if="estimate" class="group text-sm">
                    <summary
                        class="cursor-pointer text-muted-foreground transition-colors hover:text-foreground"
                    >
                        How this is worked out
                    </summary>
                    <dl class="mt-3 grid gap-1.5 text-muted-foreground">
                        <div class="flex justify-between gap-4">
                            <dt>Pixelforge cost</dt>
                            <dd class="text-right tabular-nums">
                                {{ estimate.provider_min }} –
                                {{ estimate.provider_max }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt>Markup</dt>
                            <dd>+{{ markupPercent }}%</dd>
                        </div>
                        <div class="mt-1 border-t pt-2 leading-relaxed">
                            Tokens: prompt
                            {{ estimate.tokens.text[0] }}–{{
                                estimate.tokens.text[1]
                            }}, reference images {{ estimate.tokens.image }},
                            picture {{ estimate.tokens.output }}, thinking
                            {{ estimate.tokens.reasoning[0] }}–{{
                                estimate.tokens.reasoning[1]
                            }}. The prompt and thinking counts are only known
                            once the image is done, which is why this is a
                            range.
                        </div>
                    </dl>
                </details>

                <InputError :message="formError" />

                <Button type="submit" size="lg" :disabled="!canSubmit">
                    <LoaderCircle
                        v-if="form.processing"
                        class="motion-safe:animate-spin"
                    />
                    <Sparkles v-else />
                    {{ form.processing ? 'Submitting…' : 'Generate' }}
                </Button>
            </aside>
        </form>
    </div>
</template>
