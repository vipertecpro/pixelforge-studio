<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import BrandMark from '@/components/BrandMark.vue';

const name = usePage().props.name;
</script>

<template>
    <BrandMark class="size-8" />
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate leading-tight font-semibold">{{
            name
        }}</span>
    </div>
</template>
