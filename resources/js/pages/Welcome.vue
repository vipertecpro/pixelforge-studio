<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Receipt, ShieldCheck, Sparkles } from '@lucide/vue';
import BrandMark from '@/components/BrandMark.vue';
import { Button } from '@/components/ui/button';
import { login, register } from '@/routes';
import { create } from '@/routes/generations';

const points = [
    {
        icon: Receipt,
        title: 'See the price first',
        text: 'Every image shows an exact price range before you submit.',
    },
    {
        icon: ShieldCheck,
        title: 'Fair, exact billing',
        text: 'You pay Pixelforge’s cost plus 25%, worked out from real usage. A failed image costs nothing.',
    },
    {
        icon: Sparkles,
        title: 'Live progress',
        text: 'Watch each image move from queued to done, with its real charge.',
    },
];
</script>

<template>
    <Head title="Welcome" />

    <div
        class="relative flex min-h-svh flex-col overflow-hidden bg-background text-foreground"
    >
        <div
            class="pointer-events-none absolute -top-40 left-1/2 size-[42rem] -translate-x-1/2 rounded-full bg-gradient-to-br from-indigo-500/20 via-violet-500/15 to-rose-400/20 blur-3xl"
            aria-hidden="true"
        />

        <header
            class="relative mx-auto flex w-full max-w-5xl items-center justify-between p-4 sm:p-6"
        >
            <span class="flex items-center gap-2 font-semibold">
                <BrandMark class="size-8" />
                Pixelforge Studio
            </span>
            <nav class="flex items-center gap-2">
                <Button v-if="$page.props.auth.user" as-child>
                    <Link :href="create()">Open the studio</Link>
                </Button>
                <template v-else>
                    <Button as-child variant="ghost">
                        <Link :href="login()">Log in</Link>
                    </Button>
                    <Button as-child>
                        <Link :href="register()">Register</Link>
                    </Button>
                </template>
            </nav>
        </header>

        <main
            class="relative mx-auto flex w-full max-w-5xl flex-1 flex-col justify-center gap-10 p-4 pb-20 sm:p-6 sm:pb-24"
        >
            <div class="max-w-2xl space-y-5 motion-safe:animate-rise">
                <h1
                    class="text-4xl font-semibold tracking-tight text-balance sm:text-6xl"
                >
                    Generate images. Know what each one
                    <span
                        class="bg-gradient-to-r from-indigo-500 via-violet-500 to-rose-400 bg-clip-text text-transparent"
                        >costs.</span
                    >
                </h1>
                <p class="text-lg text-balance text-muted-foreground">
                    A small studio on top of the Pixelforge image API. Prompts
                    become images, and every cent is accounted for.
                </p>
                <div>
                    <Button v-if="$page.props.auth.user" as-child size="lg">
                        <Link :href="create()">
                            Generate an image <ArrowRight />
                        </Link>
                    </Button>
                    <Button v-else as-child size="lg">
                        <Link :href="register()">
                            Create an account <ArrowRight />
                        </Link>
                    </Button>
                </div>
            </div>

            <ul class="grid gap-4 sm:grid-cols-3">
                <li
                    v-for="(point, index) in points"
                    :key="point.title"
                    class="rounded-2xl border bg-card/80 p-5 shadow-xs backdrop-blur motion-safe:animate-rise"
                    :style="{ animationDelay: 120 + index * 90 + 'ms' }"
                >
                    <span
                        class="mb-3 flex size-9 items-center justify-center rounded-xl bg-primary/10 text-primary"
                    >
                        <component
                            :is="point.icon"
                            class="size-5"
                            aria-hidden="true"
                        />
                    </span>
                    <h2 class="font-medium">{{ point.title }}</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ point.text }}
                    </p>
                </li>
            </ul>
        </main>
    </div>
</template>
