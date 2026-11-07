<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    document: string;
    title: string;
    html: string;
    documents: { key: string; title: string }[];
}>();
</script>

<template>
    <Head :title="title" />

    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 sm:py-14">
        <nav class="mb-8 flex flex-wrap gap-2 text-sm">
            <Link
                v-for="d in documents"
                :key="d.key"
                :href="`/legal/${d.key}`"
                class="rounded-full border px-3 py-1 transition-colors"
                :class="
                    d.key === document
                        ? 'border-brass/40 bg-brass/10 text-brass'
                        : 'border-border text-muted-foreground hover:text-foreground'
                "
                >{{ d.title }}</Link
            >
        </nav>
        <!-- Rendered server-side from our own markdown files; raw HTML input is escaped. -->
        <article class="legal-prose" v-html="html" />
    </div>
</template>

<style scoped>
.legal-prose :deep(h1) {
    font-size: 1.875rem;
    font-weight: 600;
    letter-spacing: -0.02em;
    margin-bottom: 0.5rem;
}
.legal-prose :deep(h2) {
    font-size: 1.15rem;
    font-weight: 600;
    margin-top: 2.25rem;
    margin-bottom: 0.75rem;
}
.legal-prose :deep(p),
.legal-prose :deep(li) {
    color: var(--muted-foreground);
    line-height: 1.7;
    font-size: 0.95rem;
}
.legal-prose :deep(p) {
    margin-bottom: 0.9rem;
}
.legal-prose :deep(strong) {
    color: var(--foreground);
}
.legal-prose :deep(ul),
.legal-prose :deep(ol) {
    margin: 0 0 1rem 1.25rem;
}
.legal-prose :deep(ul) {
    list-style: disc;
}
.legal-prose :deep(ol) {
    list-style: decimal;
}
.legal-prose :deep(a) {
    color: var(--brass);
    text-decoration: underline;
    text-underline-offset: 3px;
}
.legal-prose :deep(code) {
    font-family: var(--font-mono, monospace);
    font-size: 0.85em;
}
.legal-prose :deep(table) {
    display: block;
    overflow-x: auto;
    width: 100%;
    border-collapse: collapse;
    margin: 1rem 0 1.5rem;
    font-size: 0.85rem;
}
.legal-prose :deep(th),
.legal-prose :deep(td) {
    border: 1px solid var(--border);
    padding: 0.5rem 0.65rem;
    text-align: left;
    vertical-align: top;
    color: var(--muted-foreground);
}
.legal-prose :deep(th) {
    color: var(--foreground);
    font-weight: 600;
    background: color-mix(in oklab, var(--muted) 50%, transparent);
}
</style>
