<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { euro } from '@/lib/format';

const props = defineProps<{
    trialDays: number;
    sectors: { key: string; label: string; examples: string }[];
    plans: {
        key: string;
        name: string;
        price_per_location: number;
        min_locations: number;
        ai_reviews_per_location: number;
        max_users: number | null;
        modes: string[];
        features: string[];
    }[];
}>();

const page = usePage();
const company = computed(() => page.props.company);

const steps = [
    [
        'Connect Google',
        'Log in with the Google account that manages your Business Profile. Your locations and reviews are imported automatically.',
    ],
    [
        'Set your voice',
        'Tell us how you speak to guests: formal or casual, warm or brief, how you sign off. Add facts like opening hours so replies stay correct.',
    ],
    [
        'Approve or automate',
        'Approve suggested replies with one tap, or let positive reviews be answered automatically. Complaints always come to you first.',
    ],
];

const features = [
    [
        'Replies in your own voice',
        'Three suggestions per review, in the language of the guest, based only on facts you provided.',
    ],
    [
        'Safety first',
        'Complaints about health, safety, hygiene, refunds or legal threats, and low ratings, are never published without a person.',
    ],
    [
        'All locations in one place',
        'One inbox for every branch, with roles per location for managers and staff.',
    ],
    [
        'See what keeps coming back',
        'Topics and sentiment per location, so recurring problems are spotted early.',
    ],
    [
        'Full audit trail',
        'Who approved what and when, for every reply that goes live.',
    ],
    [
        'Emergency stop',
        'Pause all automatic replies for your whole organisation with one switch.',
    ],
];

const faqs = [
    [
        'Which businesses is ReviewPilot for?',
        'For every business with a Google Business Profile: restaurants and cafés, hotels, salons, garages, shops, healthcare practices and other local service providers. You choose your type of business when you sign up, so topics and replies fit your sector.',
    ],
    [
        'Will guests notice the replies are written with AI?',
        'Replies follow your tone, use the guest’s first name and vary their wording, so they read like a person from your team wrote them. You can edit every reply before it goes live.',
    ],
    [
        'Can ReviewPilot publish something I do not want?',
        'Only if you allow it. In Manual mode nothing is published without your approval. In Smart Approval only positive, low-risk reviews are answered automatically, after a waiting period in which you can still stop them. Sensitive reviews always need a person.',
    ],
    [
        'Which review platforms are supported?',
        'Google reviews via your Google Business Profile. Other platforms are on the roadmap.',
    ],
    [
        'What does it cost?',
        'You pay per location per month, excluding VAT. Cancel monthly. The first :days days are free and no payment details are needed.',
    ],
    [
        'Where is my data stored and is it GDPR compliant?',
        'Your data is stored on servers in the EU. The AI processing runs via Anthropic under EU-approved safeguards. Every customer gets a data processing agreement, and your reviews are never used to train AI models.',
    ],
    [
        'Do I need to install anything?',
        'No. ReviewPilot works in your browser on your computer and phone.',
    ],
];

const modeLabel = (m: string) =>
    ({ manual: 'Manual', smart: 'Smart Approval', autopilot: 'Auto-Pilot' })[
        m
    ] ?? m;

const featureLabel = (f: string) =>
    ({
        insights_basic: 'Insights',
        insights_advanced: 'Advanced insights',
        voice_overrides: 'Brand voice per location',
        two_step_approval: 'Two-step approval',
    })[f] ?? f;

const popular = computed(
    () => props.plans.find((p) => p.key === 'pro')?.key ?? null,
);
</script>

<template>
    <Head :title="$t('AI review replies for local businesses')" />

    <!-- Hero -->
    <section class="mx-auto max-w-6xl px-4 pt-16 pb-20 sm:px-6 sm:pt-24">
        <p class="text-sm font-medium text-brass">
            {{ $t('For restaurants, salons, garages, shops and more') }}
        </p>
        <h1
            class="mt-4 max-w-3xl text-4xl leading-[1.05] font-semibold tracking-[-0.03em] sm:text-6xl"
        >
            {{ $t('Answer every Google review. Without the evening work.') }}
        </h1>
        <p class="mt-6 max-w-2xl text-lg text-muted-foreground">
            {{
                $t(
                    'ReviewPilot writes replies to your Google reviews in your own tone of voice. You approve with one tap, or let positive reviews be answered automatically. Complaints always come to you first.',
                )
            }}
        </p>
        <div class="mt-10 flex flex-wrap items-center gap-3">
            <Button as-child size="lg"
                ><Link href="/register">{{
                    $t('Try :days days free', { days: trialDays })
                }}</Link></Button
            >
            <Button as-child size="lg" variant="outline"
                ><a href="#how">{{ $t('How it works') }}</a></Button
            >
        </div>
        <p class="mt-4 text-sm text-muted-foreground">
            {{
                $t(
                    'No payment details needed · Cancel monthly · Data stored in the EU',
                )
            }}
        </p>
    </section>

    <!-- How it works -->
    <section id="how" class="border-t border-border/60 bg-card/40">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-20">
            <h2 class="text-2xl font-semibold tracking-tight sm:text-3xl">
                {{ $t('How it works') }}
            </h2>
            <ol class="mt-10 grid gap-6 md:grid-cols-3">
                <li
                    v-for="(s, i) in steps"
                    :key="s[0]"
                    class="rounded-xl border border-border bg-background p-6"
                >
                    <span
                        class="flex size-8 items-center justify-center rounded-full bg-brass/15 font-mono text-sm text-brass"
                        >{{ i + 1 }}</span
                    >
                    <p class="mt-4 font-medium">{{ $t(s[0]) }}</p>
                    <p class="mt-2 text-sm text-muted-foreground">
                        {{ $t(s[1]) }}
                    </p>
                </li>
            </ol>
        </div>
    </section>

    <!-- Features -->
    <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-20">
        <h2 class="text-2xl font-semibold tracking-tight sm:text-3xl">
            {{ $t('Built for local businesses') }}
        </h2>
        <div class="mt-10 grid gap-x-8 gap-y-10 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="f in features" :key="f[0]">
                <p class="font-medium">{{ $t(f[0]) }}</p>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{ $t(f[1]) }}
                </p>
            </div>
        </div>
    </section>

    <!-- Sectors -->
    <section class="mx-auto max-w-6xl px-4 pb-16 sm:px-6">
        <h2 class="text-xl font-semibold tracking-tight">
            {{ $t('Made for your type of business') }}
        </h2>
        <p class="mt-2 max-w-2xl text-sm text-muted-foreground">
            {{
                $t(
                    'ReviewPilot knows what matters in your sector: it tracks the right topics and writes replies that fit.',
                )
            }}
        </p>
        <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div
                v-for="s in sectors"
                :key="s.key"
                class="rounded-xl border border-border bg-card p-4"
            >
                <p class="text-sm font-medium">{{ s.label }}</p>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ s.examples }}
                </p>
            </div>
        </div>
    </section>

    <!-- Pricing -->
    <section id="pricing" class="border-t border-border/60 bg-card/40">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-20">
            <h2 class="text-2xl font-semibold tracking-tight sm:text-3xl">
                {{ $t('Pricing') }}
            </h2>
            <p class="mt-3 text-muted-foreground">
                {{
                    $t(
                        'Per location per month, excl. VAT. Every plan starts with a free :days-day trial.',
                        { days: trialDays },
                    )
                }}
            </p>
            <div class="mt-10 grid gap-6 md:grid-cols-3">
                <div
                    v-for="p in plans"
                    :key="p.key"
                    class="flex flex-col rounded-xl border bg-background p-6"
                    :class="
                        p.key === popular
                            ? 'border-brass/50 ring-1 ring-brass/30'
                            : 'border-border'
                    "
                >
                    <div class="flex items-center justify-between">
                        <p class="font-medium">{{ p.name }}</p>
                        <span
                            v-if="p.key === popular"
                            class="rounded-full bg-brass/15 px-2 py-0.5 text-xs text-brass"
                            >{{ $t('Most chosen') }}</span
                        >
                    </div>
                    <p class="mt-4">
                        <span class="text-3xl font-semibold tracking-tight">{{
                            euro(p.price_per_location)
                        }}</span>
                        <span class="text-sm text-muted-foreground">
                            / {{ $t('location / month') }}</span
                        >
                    </p>
                    <ul class="mt-6 flex-1 space-y-2 text-sm">
                        <li v-if="p.min_locations > 1">
                            {{
                                $t('From :count locations', {
                                    count: p.min_locations,
                                })
                            }}
                        </li>
                        <li>
                            {{
                                $t(':count AI replies per location / month', {
                                    count: p.ai_reviews_per_location,
                                })
                            }}
                        </li>
                        <li>
                            {{
                                p.max_users
                                    ? $t('Up to :count users', {
                                          count: p.max_users,
                                      })
                                    : $t('Unlimited users')
                            }}
                        </li>
                        <li>
                            {{
                                p.modes.map((m) => $t(modeLabel(m))).join(' · ')
                            }}
                        </li>
                        <li v-for="f in p.features" :key="f">
                            {{ $t(featureLabel(f)) }}
                        </li>
                    </ul>
                    <Button
                        as-child
                        class="mt-6"
                        :variant="p.key === popular ? 'default' : 'outline'"
                        ><Link href="/register">{{
                            $t('Start free trial')
                        }}</Link></Button
                    >
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ -->
    <section id="faq" class="mx-auto max-w-3xl px-4 py-16 sm:px-6 sm:py-20">
        <h2 class="text-2xl font-semibold tracking-tight sm:text-3xl">
            {{ $t('Frequently asked questions') }}
        </h2>
        <div class="mt-8 divide-y divide-border border-y border-border">
            <details v-for="q in faqs" :key="q[0]" class="group py-4">
                <summary
                    class="flex cursor-pointer list-none items-center justify-between gap-4 font-medium"
                >
                    {{ $t(q[0]) }}
                    <span
                        class="text-muted-foreground transition-transform group-open:rotate-45"
                        >+</span
                    >
                </summary>
                <p class="mt-3 text-sm text-muted-foreground">
                    {{ $t(q[1], { days: trialDays }) }}
                </p>
            </details>
        </div>
    </section>

    <!-- Contact -->
    <section id="contact" class="border-t border-border/60 bg-card/40">
        <div
            class="mx-auto flex max-w-6xl flex-col items-start gap-6 px-4 py-16 sm:px-6 md:flex-row md:items-center md:justify-between"
        >
            <div>
                <h2 class="text-2xl font-semibold tracking-tight">
                    {{ $t('Questions or a demo?') }}
                </h2>
                <p class="mt-2 text-muted-foreground">
                    {{
                        $t(
                            'Send us an email. We usually reply within one working day.',
                        )
                    }}
                </p>
            </div>
            <div class="flex flex-wrap gap-3">
                <Button as-child variant="outline"
                    ><a :href="`mailto:${company.email}`">{{
                        company.email
                    }}</a></Button
                >
                <Button as-child
                    ><Link href="/register">{{
                        $t('Start free trial')
                    }}</Link></Button
                >
            </div>
        </div>
    </section>
</template>
