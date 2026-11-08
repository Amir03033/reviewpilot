<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import AppLogo from '@/components/AppLogo.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineProps<{
    hasOrganization: boolean;
    sectors: { key: string; label: string; examples: string }[];
}>();

const form = useForm({
    name: '',
    country: 'NL',
    sector: 'hospitality',
    accept_terms: false,
});
</script>

<template>
    <Head :title="$t('Create your organisation')" />

    <div class="flex min-h-svh items-center justify-center bg-background p-6">
        <div class="w-full max-w-md space-y-8">
            <div class="flex items-center gap-2"><AppLogo /></div>
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    {{ $t('Set up your organisation') }}
                </h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{
                        $t(
                            'Your business or group of businesses. You can add locations and invite your team next.',
                        )
                    }}
                </p>
            </div>
            <form class="space-y-5" @submit.prevent="form.post('/onboarding')">
                <div class="grid gap-2">
                    <Label for="name">{{ $t('Organisation name') }}</Label>
                    <Input
                        id="name"
                        v-model="form.name"
                        :placeholder="$t('e.g. Burgerbar Groep or Salon Lisa')"
                        required
                        autofocus
                    />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="sector">{{ $t('Type of business') }}</Label>
                    <select
                        id="sector"
                        v-model="form.sector"
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm dark:bg-input/30"
                    >
                        <option
                            v-for="s in sectors"
                            :key="s.key"
                            :value="s.key"
                        >
                            {{ s.label }}
                        </option>
                    </select>
                    <p class="text-xs text-muted-foreground">
                        {{
                            sectors.find((s) => s.key === form.sector)
                                ?.examples
                        }}.
                        {{
                            $t(
                                'This decides which topics we track and how replies are written.',
                            )
                        }}
                    </p>
                    <InputError :message="form.errors.sector" />
                </div>
                <div class="grid gap-2">
                    <Label for="country">{{ $t('Country') }}</Label>
                    <select
                        id="country"
                        v-model="form.country"
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm dark:bg-input/30"
                    >
                        <option value="NL">{{ $t('Netherlands') }}</option>
                        <option value="BE">{{ $t('Belgium') }}</option>
                        <option value="DE">{{ $t('Germany') }}</option>
                        <option value="GB">{{ $t('United Kingdom') }}</option>
                        <option value="FR">{{ $t('France') }}</option>
                    </select>
                    <InputError :message="form.errors.country" />
                </div>
                <div class="grid gap-2">
                    <label class="flex items-start gap-2 text-sm">
                        <input
                            v-model="form.accept_terms"
                            type="checkbox"
                            class="mt-0.5 size-4 accent-[var(--brass)]"
                            required
                        />
                        <span class="text-muted-foreground">
                            {{ $t('I agree to the') }}
                            <a
                                href="/legal/terms"
                                target="_blank"
                                class="text-foreground underline"
                                >{{ $t('terms of service') }}</a
                            >
                            {{ $t('and the') }}
                            <a
                                href="/legal/dpa"
                                target="_blank"
                                class="text-foreground underline"
                                >{{ $t('data processing agreement') }}</a
                            >, {{ $t('on behalf of this organisation.') }}
                        </span>
                    </label>
                    <InputError :message="form.errors.accept_terms" />
                </div>
                <Button
                    type="submit"
                    class="w-full"
                    :disabled="form.processing"
                >
                    {{ $t('Create organisation') }}
                </Button>
                <p class="text-center text-xs text-muted-foreground">
                    {{
                        $t(
                            'Includes a 14-day trial. No payment details needed.',
                        )
                    }}
                </p>
            </form>
        </div>
    </div>
</template>
