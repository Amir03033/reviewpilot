<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    steps: {
        key: string;
        title: string;
        description: string;
        href: string;
        done: boolean;
    }[];
}>();

const doneCount = computed(() => props.steps.filter((s) => s.done).length);
const next = computed(() => props.steps.find((s) => !s.done)?.key);

const dismiss = () =>
    router.post('/dashboard/checklist/dismiss', {}, { preserveScroll: true });
</script>

<template>
    <section class="rounded-xl border border-brass/30 bg-card">
        <div
            class="flex flex-wrap items-center justify-between gap-3 border-b border-border/60 px-5 py-4"
        >
            <div>
                <p class="font-medium">{{ $t('Getting started') }}</p>
                <p class="text-xs text-muted-foreground">
                    {{
                        $t(':done of :total steps done', {
                            done: doneCount,
                            total: steps.length,
                        })
                    }}
                </p>
            </div>
            <div class="flex items-center gap-4">
                <div class="h-1.5 w-32 overflow-hidden rounded-full bg-muted">
                    <div
                        class="h-full rounded-full bg-brass transition-all"
                        :style="{
                            width: `${(doneCount / steps.length) * 100}%`,
                        }"
                    />
                </div>
                <button
                    type="button"
                    class="text-xs text-muted-foreground hover:text-foreground"
                    @click="dismiss"
                >
                    {{ $t('Hide') }}
                </button>
            </div>
        </div>
        <ol class="divide-y divide-border/60">
            <li v-for="s in steps" :key="s.key">
                <Link
                    :href="s.href"
                    class="flex items-start gap-3 px-5 py-3 transition-colors hover:bg-muted/40"
                >
                    <span
                        class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border text-[11px]"
                        :class="
                            s.done
                                ? 'border-positive/40 bg-positive/15 text-positive'
                                : s.key === next
                                  ? 'border-brass text-brass'
                                  : 'border-border text-muted-foreground'
                        "
                        >{{ s.done ? '✓' : '' }}</span
                    >
                    <span class="min-w-0">
                        <span
                            class="block text-sm"
                            :class="
                                s.done
                                    ? 'text-muted-foreground line-through'
                                    : 'font-medium'
                            "
                            >{{ $t(s.title) }}</span
                        >
                        <span
                            v-if="!s.done"
                            class="block text-xs text-muted-foreground"
                            >{{ $t(s.description) }}</span
                        >
                    </span>
                </Link>
            </li>
        </ol>
    </section>
</template>
