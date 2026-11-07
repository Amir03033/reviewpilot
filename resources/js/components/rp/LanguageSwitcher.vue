<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { currentLocale } from '@/lib/i18n';

const languages = [
    { code: 'nl', label: 'NL' },
    { code: 'en', label: 'EN' },
];

function choose(code: string) {
    if (code !== currentLocale()) {
        router.post('/locale', { locale: code }, { preserveScroll: true });
    }
}
</script>

<template>
    <div
        class="inline-flex items-center rounded-md border border-border p-0.5 text-xs"
        role="group"
        :aria-label="$t('Language')"
    >
        <button
            v-for="l in languages"
            :key="l.code"
            type="button"
            class="rounded px-2 py-1 font-medium transition-colors"
            :class="
                currentLocale() === l.code
                    ? 'bg-brass/15 text-brass'
                    : 'text-muted-foreground hover:text-foreground'
            "
            :aria-pressed="currentLocale() === l.code"
            @click="choose(l.code)"
        >
            {{ l.label }}
        </button>
    </div>
</template>
