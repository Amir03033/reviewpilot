<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PageHeader from '@/components/rp/PageHeader.vue';
import Panel from '@/components/rp/Panel.vue';

const props = defineProps<{
    organization: {
        name: string;
        country: string;
        vat_number: string | null;
        default_locale: string;
        timezone: string;
        automation_kill_switch: boolean;
        billing_email: string | null;
        billing_name: string | null;
        billing_address: string | null;
        billing_postal_code: string | null;
        billing_city: string | null;
        data_retention_days: number | null;
    };
    canManage: boolean;
    isOwner: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Organisation', href: '/organization' }] },
});

const form = useForm({
    name: props.organization.name,
    country: props.organization.country,
    vat_number: props.organization.vat_number ?? '',
    default_locale: props.organization.default_locale,
    timezone: props.organization.timezone,
    billing_email: props.organization.billing_email ?? '',
    billing_name: props.organization.billing_name ?? '',
    billing_address: props.organization.billing_address ?? '',
    billing_postal_code: props.organization.billing_postal_code ?? '',
    billing_city: props.organization.billing_city ?? '',
    data_retention_days: props.organization.data_retention_days ?? undefined,
});
const confirmName = ref('');

const toggleKill = () =>
    router.put(
        '/organization/kill-switch',
        { enabled: !props.organization.automation_kill_switch },
        { preserveScroll: true },
    );
const destroy = () =>
    router.delete('/organization', { data: { confirm: confirmName.value } });
</script>

<template>
    <Head :title="$t('Organisation')" />

    <div class="space-y-6">
        <PageHeader :title="$t('Organisation')">
            <template #actions>
                <Button v-if="canManage" variant="outline" as-child
                    ><Link href="/organization/audit">{{
                        $t('Audit log')
                    }}</Link></Button
                >
            </template>
        </PageHeader>

        <Panel
            :title="$t('Automatic publishing')"
            :description="
                $t(
                    'Emergency stop for every location. Scheduled replies go back to the approval queue.',
                )
            "
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p
                    class="text-sm"
                    :class="
                        organization.automation_kill_switch
                            ? 'text-critical'
                            : 'text-positive'
                    "
                >
                    {{
                        organization.automation_kill_switch
                            ? 'Paused: nothing is published without approval.'
                            : 'Active: Smart and Auto-Pilot locations publish eligible replies.'
                    }}
                </p>
                <Button
                    v-if="canManage"
                    :variant="
                        organization.automation_kill_switch
                            ? 'default'
                            : 'destructive'
                    "
                    @click="toggleKill"
                >
                    {{
                        organization.automation_kill_switch
                            ? 'Resume automation'
                            : 'Pause all automation'
                    }}
                </Button>
            </div>
        </Panel>

        <Panel :title="$t('Details')">
            <form
                class="grid gap-4 md:grid-cols-2"
                @submit.prevent="
                    form.put('/organization', { preserveScroll: true })
                "
            >
                <fieldset :disabled="!canManage" class="contents">
                    <div class="grid gap-2">
                        <Label>{{ $t('Name') }}</Label
                        ><Input v-model="form.name" /><InputError
                            :message="form.errors.name"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>{{ $t('Country (ISO)') }}</Label
                        ><Input
                            v-model="form.country"
                            maxlength="2"
                        /><InputError :message="form.errors.country" />
                    </div>
                    <div class="grid gap-2">
                        <Label>{{ $t('VAT number') }}</Label
                        ><Input
                            v-model="form.vat_number"
                            :placeholder="$t('NL123456789B01')"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>{{ $t('Billing email') }}</Label
                        ><Input
                            v-model="form.billing_email"
                            type="email"
                        /><InputError :message="form.errors.billing_email" />
                    </div>
                    <div class="grid gap-2">
                        <Label>{{ $t('Company name on invoices') }}</Label
                        ><Input
                            v-model="form.billing_name"
                            :placeholder="organization.name"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>{{ $t('Invoice address') }}</Label
                        ><Input
                            v-model="form.billing_address"
                            :placeholder="$t('Street and number')"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>{{ $t('Postal code') }}</Label
                        ><Input v-model="form.billing_postal_code" />
                    </div>
                    <div class="grid gap-2">
                        <Label>{{ $t('City') }}</Label
                        ><Input v-model="form.billing_city" />
                    </div>
                    <div class="grid gap-2">
                        <Label>{{ $t('Default language') }}</Label>
                        <select
                            v-model="form.default_locale"
                            class="h-9 rounded-md border border-input bg-transparent px-2 text-sm dark:bg-input/30"
                        >
                            <option value="nl">{{ $t('Nederlands') }}</option>
                            <option value="en">{{ $t('English') }}</option>
                            <option value="de">{{ $t('Deutsch') }}</option>
                            <option value="fr">{{ $t('Français') }}</option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label>{{ $t('Time zone') }}</Label
                        ><Input v-model="form.timezone" /><InputError
                            :message="form.errors.timezone"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>{{ $t('Keep review data (days)') }}</Label>
                        <Input
                            v-model.number="form.data_retention_days"
                            type="number"
                            min="30"
                            :placeholder="$t('Plan default')"
                        />
                        <InputError
                            :message="form.errors.data_retention_days"
                        />
                    </div>
                    <div class="md:col-span-2">
                        <Button
                            v-if="canManage"
                            type="submit"
                            :disabled="form.processing"
                            >{{ $t('Save') }}</Button
                        >
                    </div>
                </fieldset>
            </form>
        </Panel>

        <Panel
            v-if="isOwner"
            :title="$t('Delete organisation')"
            :description="$t('Data is permanently removed after 30 days.')"
        >
            <div class="flex flex-wrap items-end gap-2">
                <div class="grid gap-2">
                    <Label>{{
                        $t('Type “:name” to confirm', {
                            name: organization.name,
                        })
                    }}</Label>
                    <Input v-model="confirmName" class="w-72" />
                </div>
                <Button
                    variant="destructive"
                    :disabled="confirmName !== organization.name"
                    @click="destroy"
                    >{{ $t('Delete organisation') }}</Button
                >
            </div>
        </Panel>
    </div>
</template>
