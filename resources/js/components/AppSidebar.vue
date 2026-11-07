<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Building2,
    CreditCard,
    Inbox,
    LayoutGrid,
    ListChecks,
    MapPin,
    MessageSquareQuote,
    Plug,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';

const page = usePage();
const tenant = computed(() => page.props.tenant);
const { isCurrentUrl } = useCurrentUrl();

const work = computed(() => [
    { title: 'Overview', href: '/dashboard', icon: LayoutGrid },
    { title: 'Inbox', href: '/reviews', icon: Inbox },
    {
        title: 'Approvals',
        href: '/approvals',
        icon: ListChecks,
        badge: tenant.value?.pendingApprovals || null,
    },
    { title: 'Locations', href: '/locations', icon: MapPin },
]);

const settings = [
    { title: 'Brand voice', href: '/voice', icon: MessageSquareQuote },
    { title: 'Integrations', href: '/integrations', icon: Plug },
    { title: 'Team', href: '/team', icon: Users },
    { title: 'Billing', href: '/billing', icon: CreditCard },
    { title: 'Organisation', href: '/organization', icon: Building2 },
];

function switchOrg(event: Event) {
    const id = (event.target as HTMLSelectElement).value;
    router.post(`/organizations/${id}/switch`);
}
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link href="/dashboard">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <div
                v-if="tenant && tenant.organizations.length > 1"
                class="px-2 group-data-[collapsible=icon]:hidden"
            >
                <select
                    class="w-full rounded-md border border-sidebar-border bg-transparent px-2 py-1.5 text-xs"
                    :value="tenant.organization.id"
                    aria-label="Switch organisation"
                    @change="switchOrg"
                >
                    <option
                        v-for="o in tenant.organizations"
                        :key="o.id"
                        :value="o.id"
                    >
                        {{ o.name }}
                    </option>
                </select>
            </div>
            <p
                v-else-if="tenant"
                class="truncate px-3 text-xs text-muted-foreground group-data-[collapsible=icon]:hidden"
            >
                {{ tenant.organization.name }}
            </p>
        </SidebarHeader>

        <SidebarContent>
            <SidebarGroup class="px-2 py-0">
                <SidebarGroupLabel>Reputation</SidebarGroupLabel>
                <SidebarMenu>
                    <SidebarMenuItem v-for="item in work" :key="item.title">
                        <SidebarMenuButton
                            as-child
                            :is-active="isCurrentUrl(item.href)"
                            :tooltip="item.title"
                        >
                            <Link :href="item.href">
                                <component :is="item.icon" />
                                <span>{{ item.title }}</span>
                            </Link>
                        </SidebarMenuButton>
                        <SidebarMenuBadge
                            v-if="'badge' in item && item.badge"
                            class="text-brass"
                            >{{ item.badge }}</SidebarMenuBadge
                        >
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroup>
            <SidebarGroup class="px-2 py-0">
                <SidebarGroupLabel>Settings</SidebarGroupLabel>
                <SidebarMenu>
                    <SidebarMenuItem v-for="item in settings" :key="item.title">
                        <SidebarMenuButton
                            as-child
                            :is-active="isCurrentUrl(item.href)"
                            :tooltip="item.title"
                        >
                            <Link :href="item.href">
                                <component :is="item.icon" />
                                <span>{{ item.title }}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroup>
        </SidebarContent>

        <SidebarFooter>
            <p
                v-if="tenant?.onTrial"
                class="px-2 text-[11px] text-muted-foreground group-data-[collapsible=icon]:hidden"
            >
                Trial ·
                <Link href="/billing" class="text-brass hover:underline"
                    >choose a plan</Link
                >
            </p>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
