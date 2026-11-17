<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';
</script>

<template>
    <div role="status" aria-label="Loading the report">
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div v-for="n in 4" :key="n" class="rounded-2xl border bg-card p-4">
                <Skeleton class="h-3 w-24" />
                <Skeleton class="mt-3 h-6 w-28" />
            </div>
        </div>
        <Skeleton class="mt-4 h-12 w-full rounded-xl" />
        <Skeleton class="mt-6 h-10 w-72 max-w-full rounded-xl" />
        <div class="mt-4 rounded-2xl border bg-card">
            <div
                v-for="n in 6"
                :key="n"
                class="flex gap-4 border-b p-3 last:border-0"
            >
                <Skeleton class="h-4 w-24" />
                <Skeleton class="ml-auto h-4 w-12" />
                <Skeleton class="h-4 w-20" />
                <Skeleton class="h-4 w-20" />
                <Skeleton class="hidden h-4 w-20 sm:block" />
            </div>
        </div>
    </div>
</template>
