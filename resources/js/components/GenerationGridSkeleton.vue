<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';

withDefaults(defineProps<{ count?: number }>(), { count: 6 });
</script>

<template>
    <div
        class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
        role="status"
        aria-label="Loading your images"
    >
        <div
            v-for="n in count"
            :key="n"
            class="flex flex-col overflow-hidden rounded-2xl border bg-card"
        >
            <Skeleton class="aspect-square w-full rounded-none" />
            <div class="grid gap-3 p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="grid flex-1 gap-2">
                        <Skeleton class="h-4 w-full" />
                        <Skeleton class="h-4 w-2/3" />
                    </div>
                    <Skeleton class="h-5 w-16 rounded-full" />
                </div>
                <Skeleton class="h-4 w-full" />
                <Skeleton class="h-3 w-1/2" />
            </div>
        </div>
    </div>
</template>
