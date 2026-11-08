<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InsightCard from '@/components/rp/InsightCard.vue';
import KpiCard from '@/components/rp/KpiCard.vue';
import type { Insight } from '@/types/reviewpilot';
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
    insight: Insight | null;
    aiQuality: {
        approved: number;
        approved_unchanged: number;
        average_edit: number | null;
        thumbs_up: number;
        thumbs_down: number;
    };
}>();

const insightBusy = ref(false);
const refreshInsight = () =>
    router.post(
        `/locations/${props.location.id}/insights`,
        {},
        {
            preserveScroll: true,
            onStart: () => (insightBusy.value = true),
            onFinish: () => (insightBusy.value = false),
        },
    );

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
            t('Remove :name? Its reviews are deleted after the grace period.', {
                name: props.location.name,
            }),
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
                    >{{
                        $t('Synced :time', {
                            time: timeAgo(location.google.last_synced_at),
                        })
                    }}</span
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
                    >{{ $t('Sync now') }}</Button
                >
            </template>
        </PageHeader>

        <p
            v-if="location.google?.last_error"
            class="rounded-md border border-critical/30 bg-critical/10 px-4 py-2 text-sm text-critical"
        >
            {{ location.google.last_error }}
        </p>

        <div class="rp-stagger grid grid-cols-3 gap-2 sm:gap-3">
            <KpiCard
                :label="$t('Average rating (30d)')"
                :value="
                    stats.current.average_rating
                        ? `${num(stats.current.average_rating, 2)}★`
                        : '—'
                "
                :current="stats.current.average_rating"
                :previous="stats.previous.average_rating"
            />
            <KpiCard
                :label="$t('Reviews (30d)')"
                :value="String(stats.current.reviews)"
                :current="stats.current.reviews"
                :previous="stats.previous.reviews"
            />
            <KpiCard
                :label="$t('Response rate')"
                :value="pct(stats.current.response_rate)"
                :current="stats.current.response_rate"
                :previous="stats.previous.response_rate"
                suffix=" pts"
            />
        </div>

        <div class="grid gap-5 lg:grid-cols-3">
            <div class="space-y-2 lg:col-span-2">
                <InsightCard v-if="insight" :insight="insight" />
                <div
                    v-else
                    class="rounded-xl border border-dashed border-border p-5 text-sm text-muted-foreground"
                >
                    {{
                        $t(
                            'Every Monday ReviewPilot writes concrete advice for this location, based on the last 30 days of reviews.',
                        )
                    }}
                </div>
                <Button
                    v-if="canManage"
                    variant="ghost"
                    size="sm"
                    :disabled="insightBusy"
                    @click="refreshInsight"
                    >{{
                        insightBusy
                            ? $t('Writing advice…')
                            : $t('Write new advice now')
                    }}</Button
                >
            </div>
            <section class="rounded-xl border border-border bg-card p-5">
                <p class="text-xs text-muted-foreground">
                    {{ $t('AI draft quality (90 days)') }}
                </p>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt>{{ $t('Approved without changes') }}</dt>
                        <dd class="tabular font-mono">
                            {{
                                aiQuality.approved
                                    ? pct(
                                          Math.round(
                                              (aiQuality.approved_unchanged /
                                                  aiQuality.approved) *
                                                  100,
                                          ),
                                      )
                                    : '—'
                            }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>{{ $t('Average share edited') }}</dt>
                        <dd class="tabular font-mono">
                            {{
                                aiQuality.average_edit !== null
                                    ? pct(
                                          Math.round(
                                              aiQuality.average_edit * 100,
                                          ),
                                      )
                                    : '—'
                            }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>{{ $t('Ratings') }}</dt>
                        <dd class="tabular font-mono">
                            👍 {{ aiQuality.thumbs_up }} · 👎
                            {{ aiQuality.thumbs_down }}
                        </dd>
                    </div>
                </dl>
                <p class="mt-3 text-xs text-muted-foreground">
                    {{
                        $t(
                            'ReviewPilot learns from your edits and ratings: the more you approve and correct, the closer drafts get to your style.',
                        )
                    }}
                </p>
            </section>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <Panel :title="$t('General & automation mode')">
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
                            <Label>{{ $t('Name') }}</Label>
                            <Input v-model="general.name" />
                            <InputError :message="general.errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label>{{ $t('Mode') }}</Label>
                            <div class="grid gap-2">
                                <label
                                    v-for="m in allowedModes"
                                    :key="m.value"
                                    class="flex cursor-pointer gap-3 rounded-lg border p-3 text-sm"
                                    :class="[
                                        general.mode === m.value
                                            ? 'border-foreground/25 bg-foreground/[0.03]'
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
                                                : $t(m.value)
                                        }}</span>
                                        <span
                                            v-if="!m.allowed"
                                            class="ml-1 text-xs"
                                            >{{ $t('(Pro plan)') }}</span
                                        >
                                        <span
                                            class="block text-xs text-muted-foreground"
                                            >{{ $t(modeHelp[m.value]) }}</span
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
                            >{{ $t('Save') }}</Button
                        >
                    </fieldset>
                </form>
            </Panel>

            <Panel
                :title="$t('Automation safeguards')"
                :description="$t('Applies to Smart and Auto-Pilot.')"
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
                            <Label>{{ $t('Minimum stars') }}</Label>
                            <select
                                v-model.number="policy.min_rating"
                                class="h-9 rounded-md border border-input bg-transparent px-2 text-sm dark:bg-input/30"
                            >
                                <option :value="5">{{ $t('5 only') }}</option>
                                <option :value="4">{{ $t('4 and 5') }}</option>
                                <option :value="3">
                                    {{ $t('3 and up (Auto-Pilot)') }}
                                </option>
                            </select>
                        </div>
                        <div class="grid gap-2">
                            <Label>{{ $t('Max risk score') }}</Label>
                            <Input
                                v-model.number="policy.max_risk_score"
                                type="number"
                                min="0"
                                max="60"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label>{{ $t('Hold window (minutes)') }}</Label>
                            <Input
                                v-model.number="policy.hold_minutes"
                                type="number"
                                min="15"
                            />
                            <InputError :message="policy.errors.hold_minutes" />
                        </div>
                        <div class="grid gap-2">
                            <Label>{{ $t('Daily limit') }}</Label>
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
                            {{
                                $t(
                                    'Only publish between 08:00 and 22:00 (location time)',
                                )
                            }}
                        </label>
                        <label
                            class="flex items-center gap-2 text-sm sm:col-span-2"
                        >
                            <input
                                v-model="policy.paused"
                                type="checkbox"
                                class="accent-[var(--brass)]"
                            />
                            {{ $t('Pause automation for this location') }}
                        </label>
                        <Button
                            v-if="canManage"
                            type="submit"
                            class="w-fit"
                            :disabled="policy.processing"
                            >{{ $t('Save safeguards') }}</Button
                        >
                    </fieldset>
                </form>
            </Panel>

            <Panel
                :title="$t('Facts the AI may use')"
                :description="
                    $t(
                        'Replies never mention anything that is not listed here.',
                    )
                "
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
                        class="grid grid-cols-[1fr_auto] gap-2 rounded-lg border border-border p-2 sm:grid-cols-[10rem_1fr_auto] sm:border-0 sm:p-0"
                    >
                        <Input
                            v-model="f.key"
                            :placeholder="$t('opening_hours')"
                            class="font-mono text-xs sm:text-sm"
                            :disabled="!canManage"
                        />
                        <Input
                            v-model="f.value"
                            :placeholder="$t('Mon–Sun 11:00–22:00')"
                            class="col-span-2 row-start-2 sm:col-span-1 sm:row-start-1"
                            :disabled="!canManage"
                        />
                        <Button
                            v-if="canManage"
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            :aria-label="$t('Remove fact')"
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
                            >{{ $t('Add fact') }}</Button
                        >
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="facts.processing"
                            >{{ $t('Save facts') }}</Button
                        >
                    </div>
                </form>
            </Panel>

            <Panel
                :title="$t('Location voice')"
                :description="
                    $t(
                        'Overrides the organisation voice for this location only.',
                    )
                "
            >
                <div
                    v-if="!showVoice"
                    class="space-y-3 text-sm text-muted-foreground"
                >
                    <p>
                        {{ $t('This location uses the organisation voice.') }}
                    </p>
                    <Button
                        v-if="canManage"
                        variant="outline"
                        size="sm"
                        @click="showVoice = true"
                        >{{ $t('Customise for this location') }}</Button
                    >
                </div>
                <div v-else class="space-y-4">
                    <VoiceForm v-model="voice" :disabled="!canManage" />
                    <div v-if="canManage" class="flex gap-2">
                        <Button size="sm" @click="saveVoice">{{
                            $t('Save location voice')
                        }}</Button>
                        <Button size="sm" variant="ghost" @click="resetVoice">{{
                            $t('Use organisation voice')
                        }}</Button>
                    </div>
                </div>
            </Panel>
        </div>

        <div v-if="canManage" class="border-t border-border pt-5">
            <Button variant="destructive" size="sm" @click="remove">{{
                $t('Remove location')
            }}</Button>
        </div>
    </div>
</template>
