<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import PageHeader from '@/components/rp/PageHeader.vue';
import Panel from '@/components/rp/Panel.vue';
import { dateTime, euro } from '@/lib/format';

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
    confirm('Cancel your subscription?') && router.post('/billing/cancel');
const modeLabel = (m: string) =>
    m === 'autopilot' ? 'Auto-Pilot' : m.charAt(0).toUpperCase() + m.slice(1);
</script>

<template>
    <Head title="Billing" />

    <div class="space-y-6">
        <PageHeader
            title="Billing"
            description="Priced per location per month, excl. VAT. Pay with iDEAL, SEPA Direct Debit or card via Mollie."
        />

        <div
            v-if="!configured"
            class="rounded-xl border border-brass/30 bg-brass/5 p-4 text-sm text-muted-foreground"
        >
            Payments are not configured yet: add <code>MOLLIE_KEY</code> to
            <code>.env</code>.
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">Current plan</p>
                <p class="mt-2 text-xl font-medium capitalize">
                    {{ plan ?? 'None' }}
                </p>
                <p v-if="onTrial" class="mt-1 text-xs text-brass">
                    Trial until {{ dateTime(trialEndsAt) }}
                </p>
                <p
                    v-else-if="subscription"
                    class="mt-1 text-xs text-muted-foreground capitalize"
                >
                    {{ subscription.status }} ·
                    {{ subscription.quantity }} location(s)
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">Billable locations</p>
                <p class="tabular mt-2 font-mono text-xl">{{ locations }}</p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">
                    AI reviews this month
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
                        ? 'border-brass/60'
                        : 'border-border'
                "
            >
                <p class="font-medium">{{ p.name }}</p>
                <p class="mt-3">
                    <span class="tabular font-mono text-2xl">{{
                        euro(p.price_per_location)
                    }}</span>
                    <span class="text-xs text-muted-foreground"
                        >/ location / month</span
                    >
                </p>
                <ul
                    class="mt-4 flex-1 space-y-1.5 text-sm text-muted-foreground"
                >
                    <li>
                        {{ p.ai_reviews_per_location }} AI reviews per location
                    </li>
                    <li>Modes: {{ p.modes.map(modeLabel).join(', ') }}</li>
                    <li v-if="p.min_locations > 1">
                        Minimum {{ p.min_locations }} locations, volume discount
                    </li>
                </ul>
                <p class="mt-4 text-xs text-muted-foreground">
                    For you: {{ p.quote.quantity }} ×
                    {{ euro(p.quote.unit_cents) }} =
                    {{ euro(p.quote.net_cents) }} +
                    {{ Math.round(vatRate * 100) }}% VAT =
                    <span class="text-foreground">{{
                        euro(p.quote.gross_cents)
                    }}</span
                    >/month
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
                            ? 'Current plan'
                            : 'Choose ' + p.name
                    }}
                </Button>
            </div>
        </div>

        <Panel v-if="payments.length" title="Payments" :padded="false">
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
                            {{ pay.status }}
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
            <Button variant="ghost" size="sm" @click="cancel"
                >Cancel subscription</Button
            >
        </div>
    </div>
</template>
