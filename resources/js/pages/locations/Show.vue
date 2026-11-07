<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import KpiCard from '@/components/rp/KpiCard.vue';
import PageHeader from '@/components/rp/PageHeader.vue';
import Panel from '@/components/rp/Panel.vue';
import VoiceForm, { type VoiceData } from '@/components/rp/VoiceForm.vue';
import { num, pct, timeAgo } from '@/lib/format';

type Stats = {
    reviews: number;
    average_rating: number | null;
    response_rate: number | null;
    median_response_hours: number | null;
};

const props = defineProps<{
    location: {
        id: string;
        name: string;
        city: string | null;
        timezone: string;
        status: string;
        mode: string;
        google: {
            title: string;
            is_verified: boolean;
            sync_state: string;
            last_synced_at: string | null;
            last_error: string | null;
        } | null;
    };
    facts: { id: string; key: string; value: string; source: string }[];
    policy: {
        min_rating: number;
        max_risk_score: number;
        hold_minutes: number;
        daily_cap: number;
        business_hours_only: boolean;
        allowed_languages: string[] | null;
        paused_until: string | null;
    };
    voiceOverride: Partial<VoiceData> | null;
    effectiveVoice: VoiceData;
    stats: { current: Stats; previous: Stats };
    allowedModes: { value: string; allowed: boolean }[];
    canManage: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Locations', href: '/locations' },
            { title: 'Location', href: '#' },
        ],
    },
});

const general = useForm({
    name: props.location.name,
    mode: props.location.mode,
    timezone: props.location.timezone,
});
const policy = useForm({
    ...props.policy,
    paused: !!props.policy.paused_until,
});
const facts = useForm({
    facts: props.facts.map((f) => ({ key: f.key, value: f.value })),
});
const voice = ref<VoiceData>(JSON.parse(JSON.stringify(props.effectiveVoice)));
const showVoice = ref(!!props.voiceOverride);

const modeHelp: Record<string, string> = {
    manual: 'Every reply needs approval.',
    smart: 'Positive, low-risk reviews are answered automatically after a hold window. Everything else waits for you.',
    autopilot:
        'Like Smart, with your own thresholds (3★ allowed). 1–2★ and sensitive reviews always need a person.',
};

const saveVoice = () =>
    router.put(
        `/locations/${props.location.id}/voice`,
        voice.value as unknown as Record<string, never>,
        { preserveScroll: true },
    );
const resetVoice = () =>
    router.put(
        `/locations/${props.location.id}/voice`,
        { reset: true },
        { preserveScroll: true, onSuccess: () => (showVoice.value = false) },
    );
const remove = () => {
    if (
        confirm(
            `Remove ${props.location.name}? Its reviews are deleted after the grace period.`,
        )
    ) {
        router.delete(`/locations/${props.location.id}`);
    }
};
</script>

<template>
    <Head :title="location.name" />

    <div class="space-y-6">
        <PageHeader
            :title="location.name"
            :description="
                location.google
                    ? `Google: ${location.google.title}`
                    : 'Not connected to Google'
            "
        >
            <template #actions>
                <span
                    v-if="location.google"
                    class="text-xs text-muted-foreground"
                    >Synced {{ timeAgo(location.google.last_synced_at) }}</span
                >
                <Button
                    v-if="canManage && location.google"
                    variant="outline"
                    size="sm"
                    @click="
                        router.post(
                            `/locations/${location.id}/sync`,
                            {},
                            { preserveScroll: true },
                        )
                    "
                    >Sync now</Button
                >
            </template>
        </PageHeader>

        <p
            v-if="location.google?.last_error"
            class="rounded-md border border-critical/30 bg-critical/10 px-4 py-2 text-sm text-critical"
        >
            {{ location.google.last_error }}
        </p>

        <div class="grid gap-3 sm:grid-cols-3">
            <KpiCard
                label="Average rating (30d)"
                :value="
                    stats.current.average_rating
                        ? `${num(stats.current.average_rating, 2)}★`
                        : '—'
                "
                :current="stats.current.average_rating"
                :previous="stats.previous.average_rating"
            />
            <KpiCard
                label="Reviews (30d)"
                :value="String(stats.current.reviews)"
                :current="stats.current.reviews"
                :previous="stats.previous.reviews"
            />
            <KpiCard
                label="Response rate"
                :value="pct(stats.current.response_rate)"
                :current="stats.current.response_rate"
                :previous="stats.previous.response_rate"
                suffix=" pts"
            />
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <Panel title="General & automation mode">
                <form
                    class="space-y-4"
                    @submit.prevent="
                        general.patch(`/locations/${location.id}`, {
                            preserveScroll: true,
                        })
                    "
                >
                    <fieldset :disabled="!canManage" class="space-y-4">
                        <div class="grid gap-2">
                            <Label>Name</Label>
                            <Input v-model="general.name" />
                            <InputError :message="general.errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label>Mode</Label>
                            <div class="grid gap-2">
                                <label
                                    v-for="m in allowedModes"
                                    :key="m.value"
                                    class="flex cursor-pointer gap-3 rounded-lg border p-3 text-sm"
                                    :class="[
                                        general.mode === m.value
                                            ? 'border-brass/50 bg-brass/5'
                                            : 'border-border',
                                        !m.allowed &&
                                            'cursor-not-allowed opacity-50',
                                    ]"
                                >
                                    <input
                                        v-model="general.mode"
                                        type="radio"
                                        :value="m.value"
                                        :disabled="!m.allowed"
                                        class="mt-0.5 accent-[var(--brass)]"
                                    />
                                    <span>
                                        <span class="font-medium capitalize">{{
                                            m.value === 'autopilot'
                                                ? 'Auto-Pilot'
                                                : m.value
                                        }}</span>
                                        <span
                                            v-if="!m.allowed"
                                            class="ml-1 text-xs"
                                            >(Pro plan)</span
                                        >
                                        <span
                                            class="block text-xs text-muted-foreground"
                                            >{{ modeHelp[m.value] }}</span
                                        >
                                    </span>
                                </label>
                            </div>
                            <InputError :message="general.errors.mode" />
                        </div>
                        <Button
                            v-if="canManage"
                            type="submit"
                            :disabled="general.processing"
                            >Save</Button
                        >
                    </fieldset>
                </form>
            </Panel>

            <Panel
                title="Automation safeguards"
                description="Applies to Smart and Auto-Pilot."
            >
                <form
                    class="space-y-4"
                    @submit.prevent="
                        policy.put(`/locations/${location.id}/policy`, {
                            preserveScroll: true,
                        })
                    "
                >
                    <fieldset
                        :disabled="!canManage"
                        class="grid gap-4 sm:grid-cols-2"
                    >
                        <div class="grid gap-2">
                            <Label>Minimum stars</Label>
                            <select
                                v-model.number="policy.min_rating"
                                class="h-9 rounded-md border border-input bg-transparent px-2 text-sm dark:bg-input/30"
                            >
                                <option :value="5">5 only</option>
                                <option :value="4">4 and 5</option>
                                <option :value="3">
                                    3 and up (Auto-Pilot)
                                </option>
                            </select>
                        </div>
                        <div class="grid gap-2">
                            <Label>Max risk score</Label>
                            <Input
                                v-model.number="policy.max_risk_score"
                                type="number"
                                min="0"
                                max="60"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label>Hold window (minutes)</Label>
                            <Input
                                v-model.number="policy.hold_minutes"
                                type="number"
                                min="15"
                            />
                            <InputError :message="policy.errors.hold_minutes" />
                        </div>
                        <div class="grid gap-2">
                            <Label>Daily limit</Label>
                            <Input
                                v-model.number="policy.daily_cap"
                                type="number"
                                min="1"
                            />
                        </div>
                        <label
                            class="flex items-center gap-2 text-sm sm:col-span-2"
                        >
                            <input
                                v-model="policy.business_hours_only"
                                type="checkbox"
                                class="accent-[var(--brass)]"
                            />
                            Only publish between 08:00 and 22:00 (location time)
                        </label>
                        <label
                            class="flex items-center gap-2 text-sm sm:col-span-2"
                        >
                            <input
                                v-model="policy.paused"
                                type="checkbox"
                                class="accent-[var(--brass)]"
                            />
                            Pause automation for this location
                        </label>
                        <Button
                            v-if="canManage"
                            type="submit"
                            class="w-fit"
                            :disabled="policy.processing"
                            >Save safeguards</Button
                        >
                    </fieldset>
                </form>
            </Panel>

            <Panel
                title="Facts the AI may use"
                description="Replies never mention anything that is not listed here."
            >
                <form
                    class="space-y-3"
                    @submit.prevent="
                        facts.put(`/locations/${location.id}/facts`, {
                            preserveScroll: true,
                        })
                    "
                >
                    <div
                        v-for="(f, i) in facts.facts"
                        :key="i"
                        class="flex gap-2"
                    >
                        <Input
                            v-model="f.key"
                            placeholder="opening_hours"
                            class="w-40"
                            :disabled="!canManage"
                        />
                        <Input
                            v-model="f.value"
                            placeholder="Mon–Sun 11:00–22:00"
                            :disabled="!canManage"
                        />
                        <Button
                            v-if="canManage"
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            aria-label="Remove fact"
                            @click="facts.facts.splice(i, 1)"
                            >×</Button
                        >
                    </div>
                    <InputError :message="Object.values(facts.errors)[0]" />
                    <div v-if="canManage" class="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="facts.facts.push({ key: '', value: '' })"
                            >Add fact</Button
                        >
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="facts.processing"
                            >Save facts</Button
                        >
                    </div>
                </form>
            </Panel>

            <Panel
                title="Location voice"
                description="Overrides the organisation voice for this location only."
            >
                <div
                    v-if="!showVoice"
                    class="space-y-3 text-sm text-muted-foreground"
                >
                    <p>This location uses the organisation voice.</p>
                    <Button
                        v-if="canManage"
                        variant="outline"
                        size="sm"
                        @click="showVoice = true"
                        >Customise for this location</Button
                    >
                </div>
                <div v-else class="space-y-4">
                    <VoiceForm v-model="voice" :disabled="!canManage" />
                    <div v-if="canManage" class="flex gap-2">
                        <Button size="sm" @click="saveVoice"
                            >Save location voice</Button
                        >
                        <Button size="sm" variant="ghost" @click="resetVoice"
                            >Use organisation voice</Button
                        >
                    </div>
                </div>
            </Panel>
        </div>

        <div v-if="canManage" class="border-t border-border pt-5">
            <Button variant="destructive" size="sm" @click="remove"
                >Remove location</Button
            >
        </div>
    </div>
</template>
