<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PageHeader from '@/components/rp/PageHeader.vue';
import Panel from '@/components/rp/Panel.vue';
import { timeAgo } from '@/lib/format';
import type { Option } from '@/types';

type Member = {
    id: string;
    name: string | null;
    email: string | null;
    role: string;
    two_factor: boolean;
    locations: { id: string; role: string }[];
    is_self: boolean;
};

const props = defineProps<{
    members: Member[];
    invitations: {
        id: string;
        email: string;
        role: string;
        expires_at: string;
    }[];
    locations: Option[];
    roles: string[];
    locationRoles: string[];
    canManage: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Team', href: '/team' }] },
});

const roleHelp: Record<string, string> = {
    owner: 'Everything, including billing and deleting the organisation.',
    admin: 'All locations and settings, except billing.',
    analyst: 'Read-only access to all locations.',
    billing: 'Billing only.',
    member: 'Only the locations you assign below.',
};

const invite = useForm({
    email: '',
    role: 'member',
    location_ids: [] as string[],
    location_role: 'manager',
});
const editing = ref<string | null>(null);
const edit = ref<{ role: string; locations: { id: string; role: string }[] }>({
    role: 'member',
    locations: [],
});

function startEdit(m: Member) {
    editing.value = m.id;
    edit.value = {
        role: m.role,
        locations: m.locations.map((l) => ({ ...l })),
    };
}

function toggleLocation(id: string) {
    const i = edit.value.locations.findIndex((l) => l.id === id);
    if (i >= 0) {
        edit.value.locations.splice(i, 1);
    } else {
        edit.value.locations.push({ id, role: 'manager' });
    }
}

const saveEdit = (id: string) =>
    router.put(`/team/${id}`, edit.value, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
const remove = (m: Member) =>
    confirm(`Remove ${m.name}?`) &&
    router.delete(`/team/${m.id}`, { preserveScroll: true });
const locationName = (id: string) =>
    props.locations.find((l) => l.id === id)?.name ?? 'Unknown';
</script>

<template>
    <Head title="Team" />

    <div class="space-y-6">
        <PageHeader
            title="Team"
            description="Who can see and approve replies, per location."
        />

        <Panel title="Members" :padded="false">
            <ul class="divide-y divide-border">
                <li v-for="m in members" :key="m.id" class="px-5 py-4">
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium">
                                {{ m.name }}
                                <span
                                    v-if="m.is_self"
                                    class="text-xs text-muted-foreground"
                                    >(you)</span
                                >
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ m.email }}
                                <span v-if="!m.two_factor">· no 2FA</span>
                            </p>
                        </div>
                        <span
                            class="rounded-full border border-border px-2 py-0.5 text-xs capitalize"
                            >{{ m.role }}</span
                        >
                        <span
                            v-if="m.role === 'member'"
                            class="text-xs text-muted-foreground"
                            >{{
                                m.locations
                                    .map((l) => locationName(l.id))
                                    .join(', ') || 'No locations'
                            }}</span
                        >
                        <template v-if="canManage">
                            <Button
                                size="sm"
                                variant="ghost"
                                @click="startEdit(m)"
                                >Edit</Button
                            >
                            <Button
                                v-if="!m.is_self"
                                size="sm"
                                variant="ghost"
                                @click="remove(m)"
                                >Remove</Button
                            >
                        </template>
                    </div>
                    <div
                        v-if="editing === m.id"
                        class="mt-4 space-y-3 rounded-lg border border-border p-4"
                    >
                        <select
                            v-model="edit.role"
                            class="h-9 rounded-md border border-input bg-transparent px-2 text-sm capitalize dark:bg-input/30"
                        >
                            <option v-for="r in roles" :key="r" :value="r">
                                {{ r }}
                            </option>
                        </select>
                        <p class="text-xs text-muted-foreground">
                            {{ roleHelp[edit.role] }}
                        </p>
                        <div
                            v-if="edit.role === 'member'"
                            class="grid gap-2 sm:grid-cols-2"
                        >
                            <div
                                v-for="l in locations"
                                :key="l.id"
                                class="flex items-center gap-2 text-sm"
                            >
                                <input
                                    type="checkbox"
                                    :checked="
                                        edit.locations.some(
                                            (x) => x.id === l.id,
                                        )
                                    "
                                    class="accent-[var(--brass)]"
                                    @change="toggleLocation(l.id)"
                                />
                                <span class="flex-1">{{ l.name }}</span>
                                <select
                                    v-if="
                                        edit.locations.find(
                                            (x) => x.id === l.id,
                                        )
                                    "
                                    v-model="
                                        edit.locations.find(
                                            (x) => x.id === l.id,
                                        )!.role
                                    "
                                    class="h-7 rounded border border-input bg-transparent px-1 text-xs dark:bg-input/30"
                                >
                                    <option
                                        v-for="r in locationRoles"
                                        :key="r"
                                        :value="r"
                                    >
                                        {{ r }}
                                    </option>
                                </select>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <Button size="sm" @click="saveEdit(m.id)"
                                >Save</Button
                            >
                            <Button
                                size="sm"
                                variant="ghost"
                                @click="editing = null"
                                >Cancel</Button
                            >
                        </div>
                    </div>
                </li>
            </ul>
        </Panel>

        <Panel v-if="canManage" title="Invite someone">
            <form
                class="grid gap-4 md:grid-cols-3"
                @submit.prevent="
                    invite.post('/team/invitations', {
                        preserveScroll: true,
                        onSuccess: () => invite.reset(),
                    })
                "
            >
                <div class="grid gap-2">
                    <Label>Email</Label>
                    <Input v-model="invite.email" type="email" required />
                    <InputError :message="invite.errors.email" />
                </div>
                <div class="grid gap-2">
                    <Label>Role</Label>
                    <select
                        v-model="invite.role"
                        class="h-9 rounded-md border border-input bg-transparent px-2 text-sm capitalize dark:bg-input/30"
                    >
                        <option
                            v-for="r in roles.filter((r) => r !== 'owner')"
                            :key="r"
                            :value="r"
                        >
                            {{ r }}
                        </option>
                    </select>
                    <p class="text-xs text-muted-foreground">
                        {{ roleHelp[invite.role] }}
                    </p>
                </div>
                <div v-if="invite.role === 'member'" class="grid gap-2">
                    <Label>Locations (as manager)</Label>
                    <select
                        v-model="invite.location_ids"
                        multiple
                        class="min-h-20 rounded-md border border-input bg-transparent px-2 py-1 text-sm dark:bg-input/30"
                    >
                        <option
                            v-for="l in locations"
                            :key="l.id"
                            :value="l.id"
                        >
                            {{ l.name }}
                        </option>
                    </select>
                </div>
                <div class="md:col-span-3">
                    <Button type="submit" :disabled="invite.processing"
                        >Send invitation</Button
                    >
                </div>
            </form>
        </Panel>

        <Panel
            v-if="invitations.length"
            title="Pending invitations"
            :padded="false"
        >
            <ul class="divide-y divide-border">
                <li
                    v-for="i in invitations"
                    :key="i.id"
                    class="flex items-center gap-3 px-5 py-3 text-sm"
                >
                    <span class="flex-1"
                        >{{ i.email }}
                        <span class="text-xs text-muted-foreground capitalize"
                            >· {{ i.role }}</span
                        ></span
                    >
                    <span class="text-xs text-muted-foreground"
                        >expires {{ timeAgo(i.expires_at) }}</span
                    >
                    <Button
                        v-if="canManage"
                        size="sm"
                        variant="ghost"
                        @click="
                            router.delete(`/team/invitations/${i.id}`, {
                                preserveScroll: true,
                            })
                        "
                        >Revoke</Button
                    >
                </li>
            </ul>
        </Panel>
    </div>
</template>
