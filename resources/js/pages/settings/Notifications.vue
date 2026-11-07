<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';

type Settings = Record<string, boolean>;

const props = defineProps<{
    memberships: {
        id: string;
        organization: string;
        role: string;
        settings: Settings;
    }[];
    push: { publicKey: string | null; devices: number };
}>();

// --- Push notifications on this device ---
const supported =
    typeof window !== 'undefined' &&
    'serviceWorker' in navigator &&
    'PushManager' in window &&
    'Notification' in window;
const subscribed = ref(false);
const pushBusy = ref(false);
const pushMessage = ref('');

function xsrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return m ? decodeURIComponent(m[1]) : '';
}

async function api(method: string, url: string, body?: unknown) {
    const res = await fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrf(),
        },
        body: body ? JSON.stringify(body) : undefined,
    });

    if (!res.ok) {
        throw new Error(String(res.status));
    }

    return res.json();
}

function keyToBytes(base64: string): Uint8Array {
    const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4))
        .replace(/-/g, '+')
        .replace(/_/g, '/');
    const raw = atob(padded);

    return Uint8Array.from(raw, (c) => c.charCodeAt(0));
}

onMounted(async () => {
    if (!supported) {
        return;
    }

    const reg = await navigator.serviceWorker.getRegistration('/sw.js');
    subscribed.value = Boolean(await reg?.pushManager.getSubscription());
});

async function enablePush() {
    if (!props.push.publicKey) {
        return;
    }

    pushBusy.value = true;
    pushMessage.value = '';

    try {
        const permission = await Notification.requestPermission();

        if (permission !== 'granted') {
            pushMessage.value = t(
                'Notifications are blocked. Allow them in your browser settings.',
            );

            return;
        }

        const reg = await navigator.serviceWorker.register('/sw.js');
        await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: keyToBytes(props.push.publicKey)
                .buffer as ArrayBuffer,
        });
        await api('POST', '/settings/push-subscriptions', sub.toJSON());
        subscribed.value = true;
        await api('POST', '/settings/push-subscriptions/test');
        pushMessage.value = t('Done. You should see a test notification now.');
    } catch {
        pushMessage.value = t(
            'Could not turn on notifications on this device.',
        );
    } finally {
        pushBusy.value = false;
    }
}

async function disablePush() {
    pushBusy.value = true;

    try {
        const reg = await navigator.serviceWorker.getRegistration('/sw.js');
        const sub = await reg?.pushManager.getSubscription();

        if (sub) {
            await api('DELETE', '/settings/push-subscriptions', {
                endpoint: sub.endpoint,
            });
            await sub.unsubscribe();
        }

        subscribed.value = false;
    } finally {
        pushBusy.value = false;
    }
}

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
        key: 'push_alerts',
        title: 'Push notification for bad reviews',
        description:
            'Instantly on your phone, with the suggested reply ready to approve. Turn it on for each device below.',
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

        <div
            v-if="push.publicKey"
            class="space-y-2 rounded-lg border border-border p-4"
        >
            <p class="text-sm font-medium">
                {{ $t('Push notifications on this device') }}
            </p>
            <p class="text-xs text-muted-foreground">
                {{
                    $t(
                        'Get a notification on your phone as soon as a bad review comes in. On iPhone, first add ReviewPilot to your home screen (Share → Add to Home Screen) and open it from there.',
                    )
                }}
            </p>
            <div class="flex flex-wrap items-center gap-2">
                <Button
                    v-if="!subscribed"
                    size="sm"
                    :disabled="!supported || pushBusy"
                    @click="enablePush"
                    >{{ $t('Turn on for this device') }}</Button
                >
                <Button
                    v-else
                    size="sm"
                    variant="outline"
                    :disabled="pushBusy"
                    @click="disablePush"
                    >{{ $t('Turn off for this device') }}</Button
                >
                <span class="text-xs text-muted-foreground">{{
                    $t(':count device(s) connected', { count: push.devices })
                }}</span>
            </div>
            <p v-if="!supported" class="text-xs text-muted-foreground">
                {{ $t('This browser does not support push notifications.') }}
            </p>
            <p v-if="pushMessage" class="text-xs text-brass">
                {{ pushMessage }}
            </p>
        </div>

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
