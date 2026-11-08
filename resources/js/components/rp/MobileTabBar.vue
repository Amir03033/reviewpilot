<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Inbox, LayoutGrid, ListChecks, MapPin, Menu } from '@lucide/vue';
import { computed } from 'vue';
import { useSidebar } from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';

const page = usePage();
const { setOpenMobile } = useSidebar();
const { isCurrentOrParentUrl } = useCurrentUrl();
const pending = computed(() => page.props.tenant?.pendingApprovals ?? 0);

const tabs = [
    { title: 'Overview', href: '/dashboard', icon: LayoutGrid },
    { title: 'Inbox', href: '/reviews', icon: Inbox },
    { title: 'Approvals', href: '/approvals', icon: ListChecks, badge: true },
    { title: 'Locations', href: '/locations', icon: MapPin },
];
</script>

<template>
    <nav
        class="fixed inset-x-0 bottom-0 z-40 border-t border-border bg-background/90 pb-[env(safe-area-inset-bottom)] backdrop-blur-xl md:hidden"
        :aria-label="$t('Main navigation')"
    >
        <ul class="grid grid-cols-5">
            <li v-for="t in tabs" :key="t.href">
                <Link
                    :href="t.href"
                    class="relative flex flex-col items-center gap-1 pt-2.5 pb-2 text-[10.5px] font-medium transition-colors"
                    :class="
                        isCurrentOrParentUrl(t.href)
                            ? 'text-foreground'
                            : 'text-muted-foreground'
                    "
                >
                    <span
                        class="absolute top-0 h-0.5 w-8 rounded-full bg-foreground transition-opacity duration-300"
                        :class="
                            isCurrentOrParentUrl(t.href)
                                ? 'opacity-100'
                                : 'opacity-0'
                        "
                    />
                    <span class="relative">
                        <component :is="t.icon" class="size-5" />
                        <span
                            v-if="t.badge && pending"
                            class="tabular absolute -top-1.5 -right-2.5 min-w-4 rounded-full bg-foreground px-1 text-center text-[9.5px] leading-4 text-background"
                            >{{ pending > 99 ? '99+' : pending }}</span
                        >
                    </span>
                    {{ $t(t.title) }}
                </Link>
            </li>
            <li>
                <button
                    type="button"
                    class="flex w-full flex-col items-center gap-1 pt-2.5 pb-2 text-[10.5px] font-medium text-muted-foreground"
                    @click="setOpenMobile(true)"
                >
                    <Menu class="size-5" />
                    {{ $t('More') }}
                </button>
            </li>
        </ul>
    </nav>
</template>
