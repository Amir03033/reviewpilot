<script setup lang="ts">
withDefaults(
    defineProps<{ title?: string; description?: string; padded?: boolean }>(),
    { title: undefined, description: undefined, padded: true },
);
</script>

<template>
    <section class="rounded-xl border border-border bg-card">
        <header
            v-if="title || $slots.actions"
            class="flex items-start justify-between gap-3 border-b border-border px-5 py-4"
        >
            <div>
                <h2 class="text-sm font-medium">{{ $t(title ?? '') }}</h2>
                <p
                    v-if="description"
                    class="mt-0.5 text-xs text-muted-foreground"
                >
                    {{ $t(description ?? '') }}
                </p>
            </div>
            <slot name="actions" />
        </header>
        <div :class="padded ? 'p-5' : ''"><slot /></div>
    </section>
</template>
