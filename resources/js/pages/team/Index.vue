<script setup lang="ts">
import { Pencil, Trash2 } from '@lucide/vue';
import { t } from '@/lib/i18n';
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
    confirm(t('Remove :name?', { name: m.name ?? '' })) &&
    router.delete(`/team/${m.id}`, { preserveScroll: true });
const locationName = (id: string) =>
    props.locations.find((l) => l.id === id)?.name ?? 'Unknown';
</script>

<template>
    <Head :title="$t('Team')" />

    <div class="space-y-6">
        <PageHeader
            :title="$t('Team')"
            :description="$t('Who can see and approve replies, per location.')"
        />

        <Panel :title="$t('Members')" :padded="false">
            <ul class="divide-y divide-border">
                <li v-for="m in members" :key="m.id" class="px-5 py-4">
                    <div class="flex items-start gap-3">
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium"
                            >{{
                                (m.name ?? m.email ?? '?')
                                    .split(' ')
                                    .map((w) => w[0])
                                    .slice(0, 2)
                                    .join('')
                                    .toUpperCase()
                            }}</span
                        >
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-medium">
                                    {{ m.name }}
                                    <span
                                        v-if="m.is_self"
                                        class="text-xs font-normal text-muted-foreground"
                                        >{{ $t('(you)') }}</span
                                    >
                                </p>
                                <span
                                    class="rounded-full border border-border px-2 py-0.5 text-[11px] capitalize"
                                    >{{ $t(m.role) }}</span
                                >
                            </div>
                            <p class="truncate text-xs text-muted-foreground">
                                {{ m.email }}
                                <span v-if="!m.two_factor">{{
                                    $t('· no 2FA')
                                }}</span>
                            </p>
                            <p
                                v-if="m.role === 'member'"
                                class="mt-0.5 text-xs text-muted-foreground"
                            >
                                {{
                                    m.locations
                                        .map((l) => locationName(l.id))
                                        .join(', ') || $t('No locations')
                                }}
                            </p>
                        </div>
                        <div v-if="canManage" class="flex shrink-0 gap-1">
                            <Button
                                size="sm"
                                variant="ghost"
                                :aria-label="$t('Edit')"
                                @click="startEdit(m)"
                                ><Pencil /><span class="hidden sm:inline">{{
                                    $t('Edit')
                                }}</span></Button
                            >
                            <Button
                                v-if="!m.is_self"
                                size="sm"
                                variant="ghost"
                                class="text-critical hover:text-critical"
                                :aria-label="$t('Remove')"
                                @click="remove(m)"
                                ><Trash2 /><span class="hidden sm:inline">{{
                                    $t('Remove')
                                }}</span></Button
                            >
                        </div>
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
                                {{ $t(r) }}
                            </option>
                        </select>
                        <p class="text-xs text-muted-foreground">
                            {{ $t(roleHelp[edit.role]) }}
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
                                        {{ $t(r) }}
                                    </option>
                                </select>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <Button size="sm" @click="saveEdit(m.id)">{{
                                $t('Save')
                            }}</Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                @click="editing = null"
                                >{{ $t('Cancel') }}</Button
                            >
                        </div>
                    </div>
                </li>
            </ul>
        </Panel>

        <Panel v-if="canManage" :title="$t('Invite someone')">
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
                    <Label>{{ $t('Email') }}</Label>
                    <Input v-model="invite.email" type="email" required />
                    <InputError :message="invite.errors.email" />
                </div>
                <div class="grid gap-2">
                    <Label>{{ $t('Role') }}</Label>
                    <select
                        v-model="invite.role"
                        class="h-9 rounded-md border border-input bg-transparent px-2 text-sm capitalize dark:bg-input/30"
                    >
                        <option
                            v-for="r in roles.filter((r) => r !== 'owner')"
                            :key="r"
                            :value="r"
                        >
                            {{ $t(r) }}
                        </option>
                    </select>
                    <p class="text-xs text-muted-foreground">
                        {{ $t(roleHelp[invite.role]) }}
                    </p>
                </div>
                <div v-if="invite.role === 'member'" class="grid gap-2">
                    <Label>{{ $t('Locations (as manager)') }}</Label>
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
                    <Button type="submit" :disabled="invite.processing">{{
                        $t('Send invitation')
                    }}</Button>
                </div>
            </form>
        </Panel>

        <Panel
            v-if="invitations.length"
            :title="$t('Pending invitations')"
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
                            >· {{ $t(i.role) }}</span
                        ></span
                    >
                    <span class="text-xs text-muted-foreground">{{
                        $t('expires :time', { time: timeAgo(i.expires_at) })
                    }}</span>
                    <Button
                        v-if="canManage"
                        size="sm"
                        variant="ghost"
                        @click="
                            router.delete(`/team/invitations/${i.id}`, {
                                preserveScroll: true,
                            })
                        "
                        >{{ $t('Revoke') }}</Button
                    >
                </li>
            </ul>
        </Panel>
    </div>
</template>
