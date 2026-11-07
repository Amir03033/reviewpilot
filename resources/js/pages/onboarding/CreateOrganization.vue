<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import AppLogo from '@/components/AppLogo.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const form = useForm({ name: '', country: 'NL' });
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
                            'Your restaurant, café or group. You can add locations and invite your team next.',
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
                        :placeholder="$t('e.g. Burgerbar Groep')"
                        required
                        autofocus
                    />
                    <InputError :message="form.errors.name" />
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
