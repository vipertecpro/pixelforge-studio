<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';

withDefaults(defineProps<{ rows?: number }>(), { rows: 4 });
</script>

<template>
    <ul
        class="mt-4 divide-y rounded-2xl border bg-card"
        role="status"
        aria-label="Loading your activity"
    >
        <li v-for="n in rows" :key="n" class="flex items-center gap-4 p-4">
            <Skeleton class="size-9 shrink-0 rounded-xl" />
            <div class="grid flex-1 gap-2">
                <Skeleton class="h-4 w-32" />
                <Skeleton class="h-3 w-56 max-w-full" />
            </div>
            <Skeleton class="h-4 w-16" />
        </li>
    </ul>
</template>
