<script setup lang="ts">
const model = defineModel<string>({ default: '' });

defineProps<{ rows?: number; placeholder?: string; id?: string }>();
</script>

<template>
    <textarea
        :id="id"
        v-model="model"
        :rows="rows ?? 4"
        :placeholder="placeholder"
        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
    />
</template>
