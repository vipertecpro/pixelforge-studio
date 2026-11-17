<script setup lang="ts">
import type { Component } from 'vue';

export type Choice = {
    value: string;
    label: string;
    hint?: string;
    icon?: Component;
};

defineProps<{
    legend: string;
    name: string;
    options: Choice[];
}>();

const model = defineModel<string>({ required: true });
</script>

<template>
    <fieldset class="grid gap-2">
        <legend class="mb-2 text-sm leading-none font-medium">
            {{ legend }}
        </legend>
        <div class="grid grid-cols-3 gap-2">
            <label
                v-for="option in options"
                :key="option.value"
                class="group relative cursor-pointer"
            >
                <input
                    v-model="model"
                    type="radio"
                    class="peer sr-only"
                    :name="name"
                    :value="option.value"
                />
                <span
                    class="flex h-full flex-col items-start gap-1 rounded-xl border bg-card p-3 text-sm shadow-xs transition duration-200 peer-checked:border-primary peer-checked:bg-accent peer-checked:ring-2 peer-checked:ring-primary/30 peer-focus-visible:ring-2 peer-focus-visible:ring-ring hover:border-primary/50 motion-safe:active:scale-[0.97]"
                >
                    <component
                        :is="option.icon"
                        v-if="option.icon"
                        class="size-5 text-muted-foreground transition-colors group-has-[:checked]:text-primary"
                        aria-hidden="true"
                    />
                    <span class="font-medium">{{ option.label }}</span>
                    <span
                        v-if="option.hint"
                        class="text-xs leading-snug text-muted-foreground"
                    >
                        {{ option.hint }}
                    </span>
                </span>
            </label>
        </div>
    </fieldset>
</template>
