<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Input } from '@/components/ui/input';
import PageHeader from '@/components/rp/PageHeader.vue';
import Pagination from '@/components/rp/Pagination.vue';
import { dateTime } from '@/lib/format';
import type { Paginated } from '@/types';

const props = defineProps<{
    logs: Paginated<{
        id: number;
        at: string;
        action: string;
        actor: string;
        subject: string | null;
        changes: unknown;
        ip: string | null;
    }>;
    filters: { action: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Organisation', href: '/organization' },
            { title: 'Audit log', href: '/organization/audit' },
        ],
    },
});

const action = ref(props.filters.action);
const apply = () =>
    router.get(
        '/organization/audit',
        action.value ? { action: action.value } : {},
        { preserveState: true },
    );
</script>

<template>
    <Head title="Audit log" />

    <div class="space-y-5">
        <PageHeader
            title="Audit log"
            description="Every change, approval and automated action. Records cannot be edited or deleted."
        />
        <form class="max-w-sm" @submit.prevent="apply">
            <Input
                v-model="action"
                placeholder="Filter by action, e.g. reply."
            />
        </form>
        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-muted-foreground">
                    <tr class="border-b border-border">
                        <th class="px-5 py-2.5 font-normal">When</th>
                        <th class="px-3 py-2.5 font-normal">Action</th>
                        <th class="px-3 py-2.5 font-normal">Actor</th>
                        <th class="px-3 py-2.5 font-normal">Subject</th>
                        <th class="px-5 py-2.5 font-normal">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="l in logs.data"
                        :key="l.id"
                        class="border-b border-border/60 align-top last:border-0"
                    >
                        <td
                            class="px-5 py-2.5 text-xs whitespace-nowrap text-muted-foreground"
                        >
                            {{ dateTime(l.at) }}
                        </td>
                        <td class="px-3 py-2.5 font-mono text-xs">
                            {{ l.action }}
                        </td>
                        <td class="px-3 py-2.5 text-xs">{{ l.actor }}</td>
                        <td
                            class="px-3 py-2.5 font-mono text-xs text-muted-foreground"
                        >
                            {{ l.subject ?? '—' }}
                        </td>
                        <td
                            class="max-w-md truncate px-5 py-2.5 font-mono text-[11px] text-muted-foreground"
                        >
                            {{ l.changes ? JSON.stringify(l.changes) : '' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :links="logs.links" />
    </div>
</template>
