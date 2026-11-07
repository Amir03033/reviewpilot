<script setup lang="ts">
defineProps<{ title: string; description?: string }>();
</script>

<template>
    <div
        class="flex flex-col items-center justify-center rounded-xl border border-dashed border-border px-6 py-14 text-center"
    >
        <p class="font-medium">{{ $t(title) }}</p>
        <p
            v-if="description"
            class="mt-1 max-w-md text-sm text-muted-foreground"
        >
            {{ $t(description ?? '') }}
        </p>
        <div class="mt-4"><slot /></div>
    </div>
</template>
