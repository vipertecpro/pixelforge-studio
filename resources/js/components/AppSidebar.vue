<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { BookOpen, ChartColumn, History, Sparkles, Wallet } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { report } from '@/routes/admin';
import { index as creditsIndex } from '@/routes/credits';
import { create, index } from '@/routes/generations';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Generate',
        href: create(),
        icon: Sparkles,
    },
    {
        title: 'History',
        href: index(),
        icon: History,
    },
    {
        title: 'Credits',
        href: creditsIndex(),
        icon: Wallet,
    },
    ...(page.props.auth.user.is_admin
        ? [{ title: 'Report', href: report(), icon: ChartColumn }]
        : []),
]);

const footerNavItems: NavItem[] = [
    {
        title: 'Pixelforge API docs',
        href: 'https://pixelforge.promptedit.dev/docs',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="create()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <Link
                v-if="page.props.credits"
                :href="creditsIndex()"
                class="mx-2 rounded-xl border bg-sidebar-accent/60 p-3 text-sm transition-colors group-data-[collapsible=icon]:hidden hover:bg-sidebar-accent"
            >
                <span class="block text-xs text-muted-foreground">
                    Available credits
                </span>
                <span class="block text-lg font-semibold tabular-nums">
                    {{ page.props.credits.available }}
                </span>
            </Link>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
