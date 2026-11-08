<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import PageHeader from '@/components/rp/PageHeader.vue';
import Panel from '@/components/rp/Panel.vue';
import { date, dateTime, euro } from '@/lib/format';

type Quote = {
    quantity: number;
    unit_cents: number;
    net_cents: number;
    vat_cents: number;
    gross_cents: number;
};

defineProps<{
    configured: boolean;
    plan: string | null;
    onTrial: boolean;
    trialEndsAt: string | null;
    locations: number;
    plans: {
        key: string;
        name: string;
        price_per_location: number;
        min_locations: number;
        ai_reviews_per_location: number;
        modes: string[];
        quote: Quote;
    }[];
    subscription: {
        id: string;
        plan: string;
        status: string;
        quantity: number;
        amount_cents: number;
        current_period_ends_at: string | null;
    } | null;
    payments: {
        id: string;
        status: string;
        amount_cents: number;
        description: string | null;
        paid_at: string | null;
        created_at: string;
    }[];
    invoices: {
        id: string;
        number: string;
        issued_on: string;
        gross_cents: number;
        vat_cents: number;
    }[];
    usage: {
        ai_reviews: number;
        ai_allowance: number;
        ai_cost_microcents: number;
    };
    vatRate: number;
    canBill: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Billing', href: '/billing' }] },
});

const checkout = (plan: string) => router.post('/billing/checkout', { plan });
const cancel = () =>
    confirm(t('Cancel your subscription?')) && router.post('/billing/cancel');
const modeLabel = (m: string) =>
    m === 'autopilot'
        ? 'Auto-Pilot'
        : t(m.charAt(0).toUpperCase() + m.slice(1));
</script>

<template>
    <Head :title="$t('Billing')" />

    <div class="space-y-6">
        <PageHeader
            :title="$t('Billing')"
            :description="
                $t(
                    'Priced per location per month, excl. VAT. Pay with iDEAL, SEPA Direct Debit or card via Mollie.',
                )
            "
        />

        <div
            v-if="!configured"
            class="rounded-xl border border-border bg-muted/50 p-4 text-sm text-muted-foreground"
        >
            {{ $t('Payments are not configured yet: add') }}
            <code>{{ $t('MOLLIE_KEY') }}</code> {{ $t('to') }}
            <code>{{ $t('.env') }}</code
            >.
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">
                    {{ $t('Current plan') }}
                </p>
                <p class="mt-2 text-xl font-medium capitalize">
                    {{ plan ? $t(plan) : $t('None') }}
                </p>
                <p v-if="onTrial" class="mt-1 text-xs text-brass">
                    {{
                        $t('Trial until :date', { date: dateTime(trialEndsAt) })
                    }}
                </p>
                <p
                    v-else-if="subscription"
                    class="mt-1 text-xs text-muted-foreground capitalize"
                >
                    {{ $t(subscription.status) }} ·
                    {{
                        $t(':count location(s)', {
                            count: subscription.quantity,
                        })
                    }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">
                    {{ $t('Billable locations') }}
                </p>
                <p class="tabular mt-2 font-mono text-xl">{{ locations }}</p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">
                    {{ $t('AI reviews this month') }}
                </p>
                <p class="tabular mt-2 font-mono text-xl">
                    {{ usage.ai_reviews }}
                    <span class="text-sm text-muted-foreground"
                        >/ {{ usage.ai_allowance }}</span
                    >
                </p>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-muted">
                    <div
                        class="h-full bg-brass"
                        :style="{
                            width: `${Math.min(100, (usage.ai_reviews / Math.max(1, usage.ai_allowance)) * 100)}%`,
                        }"
                    />
                </div>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            <div
                v-for="p in plans"
                :key="p.key"
                class="flex flex-col rounded-xl border bg-card p-5"
                :class="
                    plan === p.key && !onTrial
                        ? 'border-foreground/30'
                        : 'border-border'
                "
            >
                <p class="font-medium">{{ p.name }}</p>
                <p class="mt-3">
                    <span class="tabular font-mono text-2xl">{{
                        euro(p.price_per_location)
                    }}</span>
                    <span class="text-xs text-muted-foreground">{{
                        $t('/ location / month')
                    }}</span>
                </p>
                <ul
                    class="mt-4 flex-1 space-y-1.5 text-sm text-muted-foreground"
                >
                    <li>
                        {{
                            $t(':count AI reviews per location', {
                                count: p.ai_reviews_per_location,
                            })
                        }}
                    </li>
                    <li>
                        {{
                            $t('Modes: :modes', {
                                modes: p.modes.map(modeLabel).join(', '),
                            })
                        }}
                    </li>
                    <li v-if="p.min_locations > 1">
                        {{
                            $t('Minimum :count locations, volume discount', {
                                count: p.min_locations,
                            })
                        }}
                    </li>
                </ul>
                <p class="mt-4 text-xs text-muted-foreground">
                    {{
                        $t('For you: :quantity × :unit = :net + :vat% VAT =', {
                            quantity: p.quote.quantity,
                            unit: euro(p.quote.unit_cents),
                            net: euro(p.quote.net_cents),
                            vat: Math.round(vatRate * 100),
                        })
                    }}
                    <span class="text-foreground">{{
                        euro(p.quote.gross_cents)
                    }}</span
                    >{{ $t('/month') }}
                </p>
                <Button
                    v-if="canBill"
                    class="mt-4"
                    :variant="
                        plan === p.key && !onTrial ? 'outline' : 'default'
                    "
                    :disabled="!configured || (plan === p.key && !onTrial)"
                    @click="checkout(p.key)"
                >
                    {{
                        plan === p.key && !onTrial
                            ? $t('Current plan')
                            : $t('Choose :plan', { plan: p.name })
                    }}
                </Button>
            </div>
        </div>

        <Panel v-if="invoices.length" :title="$t('Invoices')" :padded="false">
            <table class="w-full text-sm">
                <tbody>
                    <tr
                        v-for="inv in invoices"
                        :key="inv.id"
                        class="border-b border-border/60 last:border-0"
                    >
                        <td class="px-5 py-3 font-mono text-xs">
                            {{ inv.number }}
                        </td>
                        <td class="px-3 py-3 text-xs text-muted-foreground">
                            {{ date(inv.issued_on) }}
                        </td>
                        <td class="tabular px-3 py-3 text-right font-mono">
                            {{ euro(inv.gross_cents) }}
                            <span class="text-xs text-muted-foreground">{{
                                $t('incl. VAT')
                            }}</span>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <a
                                :href="`/billing/invoices/${inv.id}`"
                                class="text-xs font-medium text-brass hover:underline"
                                >{{ $t('Download PDF') }}</a
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </Panel>

        <Panel v-if="payments.length" :title="$t('Payments')" :padded="false">
            <table class="w-full text-sm">
                <tbody>
                    <tr
                        v-for="pay in payments"
                        :key="pay.id"
                        class="border-b border-border/60 last:border-0"
                    >
                        <td class="px-5 py-3">
                            {{ pay.description ?? 'Subscription payment' }}
                        </td>
                        <td class="px-3 py-3 text-xs text-muted-foreground">
                            {{ dateTime(pay.paid_at ?? pay.created_at) }}
                        </td>
                        <td
                            class="px-3 py-3 text-xs capitalize"
                            :class="
                                pay.status === 'paid'
                                    ? 'text-positive'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{ $t(pay.status) }}
                        </td>
                        <td class="tabular px-5 py-3 text-right font-mono">
                            {{ euro(pay.amount_cents) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </Panel>

        <div
            v-if="canBill && subscription && subscription.status !== 'pending'"
        >
            <Button variant="ghost" size="sm" @click="cancel">{{
                $t('Cancel subscription')
            }}</Button>
        </div>
    </div>
</template>
