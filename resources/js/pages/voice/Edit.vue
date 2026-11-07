<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import PageHeader from '@/components/rp/PageHeader.vue';
import Panel from '@/components/rp/Panel.vue';
import VoiceForm, { type VoiceData } from '@/components/rp/VoiceForm.vue';
import { dateTime } from '@/lib/format';

const props = defineProps<{
    voice: VoiceData;
    version: number | null;
    history: {
        version: number;
        created_at: string | null;
        author: string | null;
    }[];
    canManage: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Brand voice', href: '/voice' }] },
});

const data = ref<VoiceData>(JSON.parse(JSON.stringify(props.voice)));
const saving = ref(false);

const save = () =>
    router.put('/voice', data.value as unknown as Record<string, never>, {
        preserveScroll: true,
        onStart: () => (saving.value = true),
        onFinish: () => (saving.value = false),
    });
</script>

<template>
    <Head :title="$t('Brand voice')" />

    <div class="space-y-6">
        <PageHeader
            :title="$t('Brand voice')"
            :description="
                $t('How every reply should sound. Locations can override this.')
            "
        >
            <template #actions>
                <Button v-if="canManage" :disabled="saving" @click="save">{{
                    $t('Save voice')
                }}</Button>
            </template>
        </PageHeader>

        <div class="grid gap-5 lg:grid-cols-[1fr_260px]">
            <Panel>
                <VoiceForm v-model="data" :disabled="!canManage" />
            </Panel>
            <Panel
                :title="$t('Versions')"
                :description="
                    $t(
                        'Every change is kept, so you can see which voice wrote each reply.',
                    )
                "
            >
                <ol class="space-y-2 text-xs">
                    <li
                        v-for="h in history"
                        :key="h.version"
                        class="flex justify-between gap-2"
                    >
                        <span
                            class="font-mono"
                            :class="h.version === version ? 'text-brass' : ''"
                            >v{{ h.version }}</span
                        >
                        <span class="text-right text-muted-foreground"
                            >{{ h.author ?? $t('System') }}<br />{{
                                dateTime(h.created_at)
                            }}</span
                        >
                    </li>
                </ol>
            </Panel>
        </div>
    </div>
</template>
