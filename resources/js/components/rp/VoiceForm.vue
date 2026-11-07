<script setup lang="ts">
import { ref, watch } from 'vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Textarea from './Textarea.vue';

export type VoiceData = {
    settings: {
        formality: number;
        warmth: number;
        humor: string;
        emoji: string;
        length: string;
        sign_off: string | null;
        language_mode: string;
        fixed_language: string | null;
        compensation_policy: string;
    };
    preferred_phrases: string[];
    forbidden_phrases: string[];
    guidelines: string | null;
    special_instructions: string | null;
};

const model = defineModel<VoiceData>({ required: true });
defineProps<{ disabled?: boolean }>();

const preferred = ref(model.value.preferred_phrases.join('\n'));
const forbidden = ref(model.value.forbidden_phrases.join('\n'));
const guidelines = ref(model.value.guidelines ?? '');
const instructions = ref(model.value.special_instructions ?? '');

const lines = (v: string) =>
    v
        .split('\n')
        .map((s) => s.trim())
        .filter(Boolean);
watch(preferred, (v) => (model.value.preferred_phrases = lines(v)));
watch(forbidden, (v) => (model.value.forbidden_phrases = lines(v)));
watch(guidelines, (v) => (model.value.guidelines = v || null));
watch(instructions, (v) => (model.value.special_instructions = v || null));

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm dark:bg-input/30';
</script>

<template>
    <fieldset
        :disabled="disabled"
        class="grid gap-5 disabled:opacity-70 md:grid-cols-2"
    >
        <div class="grid gap-2">
            <Label
                >{{ $t('Formality') }}
                <span class="text-muted-foreground"
                    >({{ model.settings.formality }}/5)</span
                ></Label
            >
            <input
                v-model.number="model.settings.formality"
                type="range"
                min="1"
                max="5"
                class="accent-[var(--brass)]"
            />
            <p class="text-xs text-muted-foreground">
                {{ $t('1 = casual (“je”), 5 = formal (“u”).') }}
            </p>
        </div>
        <div class="grid gap-2">
            <Label
                >{{ $t('Warmth') }}
                <span class="text-muted-foreground"
                    >({{ model.settings.warmth }}/5)</span
                ></Label
            >
            <input
                v-model.number="model.settings.warmth"
                type="range"
                min="1"
                max="5"
                class="accent-[var(--brass)]"
            />
        </div>
        <div class="grid gap-2">
            <Label>{{ $t('Humour') }}</Label>
            <select v-model="model.settings.humor" :class="selectClass">
                <option value="none">{{ $t('None') }}</option>
                <option value="light">{{ $t('Light') }}</option>
                <option value="playful">{{ $t('Playful') }}</option>
            </select>
        </div>
        <div class="grid gap-2">
            <Label>{{ $t('Emoji') }}</Label>
            <select v-model="model.settings.emoji" :class="selectClass">
                <option value="never">{{ $t('Never') }}</option>
                <option value="positive_only">
                    {{ $t('Only on positive reviews') }}
                </option>
                <option value="allowed">{{ $t('Allowed') }}</option>
            </select>
        </div>
        <div class="grid gap-2">
            <Label>{{ $t('Reply length') }}</Label>
            <select v-model="model.settings.length" :class="selectClass">
                <option value="short">{{ $t('Short (15–40 words)') }}</option>
                <option value="standard">
                    {{ $t('Standard (40–90 words)') }}
                </option>
                <option value="detailed">
                    {{ $t('Detailed (90–150 words)') }}
                </option>
            </select>
        </div>
        <div class="grid gap-2">
            <Label>{{ $t('Sign-off') }}</Label>
            <Input
                v-model="model.settings.sign_off as string"
                :placeholder="$t('e.g. Team Burgerbar, or Sanne (manager)')"
            />
        </div>
        <div class="grid gap-2">
            <Label>{{ $t('Reply language') }}</Label>
            <select v-model="model.settings.language_mode" :class="selectClass">
                <option value="auto">{{ $t('Same as the review') }}</option>
                <option value="fixed">{{ $t('Always one language') }}</option>
            </select>
            <Input
                v-if="model.settings.language_mode === 'fixed'"
                v-model="model.settings.fixed_language as string"
                :placeholder="$t('nl')"
                maxlength="2"
            />
        </div>
        <div class="grid gap-2">
            <Label>{{ $t('Complaints & compensation') }}</Label>
            <select
                v-model="model.settings.compensation_policy"
                :class="selectClass"
            >
                <option value="none">
                    {{ $t('Never invite contact or offer anything') }}
                </option>
                <option value="invite_contact">
                    {{ $t('Invite the guest to get in touch (recommended)') }}
                </option>
                <option value="may_offer">
                    {{
                        $t(
                            'May suggest making it right (always needs approval)',
                        )
                    }}
                </option>
            </select>
        </div>
        <div class="grid gap-2">
            <Label
                >{{ $t('Preferred phrases') }}
                <span class="text-muted-foreground">{{
                    $t('(one per line)')
                }}</span></Label
            >
            <Textarea v-model="preferred" :rows="4" />
        </div>
        <div class="grid gap-2">
            <Label
                >{{ $t('Forbidden phrases') }}
                <span class="text-muted-foreground">{{
                    $t('(one per line)')
                }}</span></Label
            >
            <Textarea v-model="forbidden" :rows="4" />
        </div>
        <div class="grid gap-2 md:col-span-2">
            <Label>{{ $t('Brand guidelines') }}</Label>
            <Textarea
                v-model="guidelines"
                :rows="3"
                :placeholder="
                    $t('Who you are, how you talk, what makes you different.')
                "
            />
        </div>
        <div class="grid gap-2 md:col-span-2">
            <Label>{{ $t('Special instructions') }}</Label>
            <Textarea
                v-model="instructions"
                :rows="3"
                :placeholder="
                    $t(
                        'e.g. Invite unhappy guests to email klachten@… ; never mention prices.',
                    )
                "
            />
        </div>
    </fieldset>
</template>
