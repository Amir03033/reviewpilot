<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';

type Settings = Record<string, boolean>;

defineProps<{
    memberships: {
        id: string;
        organization: string;
        role: string;
        settings: Settings;
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Notification settings', href: '/settings/notifications' },
        ],
    },
});

const options: { key: string; title: string; description: string }[] = [
    {
        key: 'negative_review',
        title: 'Negative and sensitive reviews',
        description:
            'An email as soon as a review needs a person, for locations you can approve.',
    },
    {
        key: 'google_reconnect',
        title: 'Google connection problems',
        description:
            'When Google needs to be reconnected before replies can be published.',
    },
    {
        key: 'weekly_digest',
        title: 'Weekly summary',
        description:
            'Every Monday: new reviews, replies and recurring complaints (owners and admins).',
    },
];

function toggle(membershipId: string, settings: Settings, key: string) {
    router.put(
        '/settings/notifications',
        {
            membership_id: membershipId,
            settings: { ...settings, [key]: !settings[key] },
        },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="$t('Notification settings')" />

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="$t('Notification settings')"
            :description="
                $t('Choose which emails you receive, per organisation.')
            "
        />

        <p v-if="!memberships.length" class="text-sm text-muted-foreground">
            {{ $t('You are not a member of an organisation yet.') }}
        </p>

        <div
            v-for="m in memberships"
            :key="m.id"
            class="rounded-lg border border-border"
        >
            <p
                v-if="memberships.length > 1"
                class="border-b border-border px-4 py-2 text-sm font-medium"
            >
                {{ m.organization }}
            </p>
            <label
                v-for="o in options"
                :key="o.key"
                class="flex cursor-pointer items-start justify-between gap-4 border-b border-border/60 px-4 py-3 last:border-0"
            >
                <span>
                    <span class="block text-sm font-medium">{{
                        $t(o.title)
                    }}</span>
                    <span class="block text-xs text-muted-foreground">{{
                        $t(o.description)
                    }}</span>
                </span>
                <input
                    type="checkbox"
                    class="mt-1 size-4 accent-[var(--brass)]"
                    :checked="m.settings[o.key]"
                    @change="toggle(m.id, m.settings, o.key)"
                />
            </label>
        </div>
    </div>
</template>
