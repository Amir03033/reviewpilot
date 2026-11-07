<script setup lang="ts">
defineProps<{ title: string; description?: string }>();
</script>

<template>
    <div
        class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
    >
        <div class="min-w-0">
            <h1 class="text-2xl font-semibold tracking-[-0.015em]">
                {{ $t(title) }}
            </h1>
            <p v-if="description" class="mt-1 text-sm text-muted-foreground">
                {{ $t(description) }}
            </p>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
