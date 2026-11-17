<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 32 32"
        fill="currentColor"
        aria-hidden="true"
        :class="className"
        v-bind="$attrs"
    >
        <rect x="6" y="6" width="8" height="8" rx="2" />
        <rect x="18" y="6" width="8" height="8" rx="2" opacity="0.7" />
        <rect x="6" y="18" width="8" height="8" rx="2" opacity="0.45" />
        <path
            d="M22 17.5l1.5 3.5 3.5 1.5-3.5 1.5L22 27.5l-1.5-3.5L17 22.5l3.5-1.5z"
        />
    </svg>
</template>
