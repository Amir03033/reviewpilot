<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { humanize } from '@/lib/format';
import type { Draft } from '@/types';
import Textarea from './Textarea.vue';

const props = defineProps<{
    reviewId: string;
    drafts: Draft[];
    canApprove: boolean;
    canDraft: boolean;
    compact?: boolean;
}>();

const order = ['standard', 'short', 'warm', 'manual'];
const sorted = computed(() =>
    [...props.drafts].sort(
        (a, b) => order.indexOf(a.variant) - order.indexOf(b.variant),
    ),
);

const activeId = ref<string | null>(sorted.value[0]?.id ?? null);
const active = computed(
    () => sorted.value.find((d) => d.id === activeId.value) ?? null,
);
const text = ref(active.value?.text ?? '');
const hint = ref('');
const busy = ref(false);
const writingManually = ref(props.drafts.length === 0);

watch(active, (d) => {
    text.value = d?.text ?? '';
});
watch(
    () => props.drafts,
    () => {
        activeId.value = sorted.value[0]?.id ?? null;
        text.value = sorted.value[0]?.text ?? '';
        writingManually.value = props.drafts.length === 0;
    },
);

const edited = computed(
    () =>
        active.value !== null && text.value.trim() !== active.value.text.trim(),
);

const done = () => (busy.value = false);

function approve() {
    if (writingManually.value || !active.value) {
        router.post(
            `/reviews/${props.reviewId}/reply`,
            { text: text.value },
            {
                preserveScroll: true,
                onStart: () => (busy.value = true),
                onFinish: done,
            },
        );

        return;
    }

    router.post(
        `/drafts/${active.value.id}/approve`,
        { text: edited.value ? text.value : null },
        {
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onFinish: done,
        },
    );
}

function regenerate(preset?: string) {
    router.post(
        `/reviews/${props.reviewId}/regenerate`,
        { hint: preset ?? hint.value },
        {
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onFinish: done,
        },
    );
    hint.value = '';
}

function reject() {
    router.post(
        `/reviews/${props.reviewId}/reject`,
        {},
        {
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onFinish: done,
        },
    );
}

const quickHints = [
    'Shorter',
    'More formal',
    'Warmer',
    'Apologise more clearly',
];
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center gap-1.5">
            <button
                v-for="d in sorted"
                :key="d.id"
                type="button"
                class="rounded-md border px-2.5 py-1 text-xs capitalize transition-colors"
                :class="
                    d.id === activeId && !writingManually
                        ? 'border-brass/50 bg-brass/10 text-brass'
                        : 'border-border text-muted-foreground hover:bg-accent'
                "
                @click="
                    activeId = d.id;
                    writingManually = false;
                "
            >
                {{ d.variant }}
                <span v-if="!d.guardrails_passed" class="text-critical">•</span>
            </button>
            <button
                v-if="canApprove"
                type="button"
                class="rounded-md border px-2.5 py-1 text-xs transition-colors"
                :class="
                    writingManually
                        ? 'border-brass/50 bg-brass/10 text-brass'
                        : 'border-border text-muted-foreground hover:bg-accent'
                "
                @click="
                    writingManually = true;
                    text = '';
                "
            >
                Write my own
            </button>
            <span
                v-if="active?.language && !writingManually"
                class="ml-auto font-mono text-[11px] text-muted-foreground uppercase"
                >{{ active.language }}</span
            >
        </div>

        <Textarea
            v-model="text"
            :rows="compact ? 5 : 7"
            :placeholder="writingManually ? 'Write your reply…' : 'Draft reply'"
        />

        <div
            class="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground"
        >
            <span class="tabular">{{ text.length }} characters</span>
            <span v-if="edited" class="text-brass">Edited</span>
        </div>

        <div
            v-if="active && !writingManually && active.failed_checks.length"
            class="rounded-md border border-critical/30 bg-critical/5 p-3 text-xs"
        >
            <p class="font-medium text-critical">
                Guardrails flagged this draft
            </p>
            <ul class="mt-1 list-disc space-y-0.5 pl-4 text-muted-foreground">
                <li v-for="c in active.failed_checks" :key="c.name">
                    <span class="text-foreground">{{ humanize(c.name) }}:</span>
                    {{ c.message }}
                </li>
            </ul>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <Button
                v-if="canApprove"
                :disabled="busy || text.trim().length < 2"
                @click="approve"
            >
                {{
                    edited || writingManually
                        ? 'Publish edited reply'
                        : 'Approve & publish'
                }}
            </Button>
            <Button
                v-if="canApprove"
                variant="ghost"
                :disabled="busy"
                @click="reject"
                >No reply</Button
            >
            <span v-if="!canApprove" class="text-xs text-muted-foreground"
                >You can view this review but not approve replies.</span
            >
        </div>

        <div v-if="canDraft" class="space-y-2 border-t border-border pt-3">
            <p class="text-xs text-muted-foreground">
                Regenerate with guidance
            </p>
            <div class="flex flex-wrap gap-1.5">
                <button
                    v-for="h in quickHints"
                    :key="h"
                    type="button"
                    class="rounded-full border border-border px-2.5 py-0.5 text-xs text-muted-foreground hover:bg-accent disabled:opacity-50"
                    :disabled="busy"
                    @click="regenerate(h)"
                >
                    {{ h }}
                </button>
            </div>
            <form class="flex gap-2" @submit.prevent="regenerate()">
                <Input
                    v-model="hint"
                    placeholder="e.g. mention we fixed the air conditioning"
                    class="h-8 text-sm"
                />
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="busy"
                    type="submit"
                    >Regenerate</Button
                >
            </form>
        </div>
    </div>
</template>
