<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const usage = computed(() => page.props.tenant?.aiUsage ?? null);
const pct = computed(() => {
    if (!usage.value) {
        return 0;
    }

    const { used, allowance } = usage.value;

    return allowance > 0
        ? Math.min(100, Math.round((used / allowance) * 100))
        : used > 0
          ? 100
          : 0;
});
const tone = computed(() =>
    pct.value >= 100
        ? 'bg-critical'
        : pct.value >= 80
          ? 'bg-warning'
          : 'bg-brass',
);
</script>

<template>
    <Link
        v-if="usage"
        href="/billing"
        class="block rounded-md px-2 py-1.5 transition-colors group-data-[collapsible=icon]:hidden hover:bg-sidebar-accent"
        :title="$t('AI replies this month')"
    >
        <div class="flex items-baseline justify-between text-[11px]">
            <span class="text-muted-foreground">{{
                $t('AI replies this month')
            }}</span>
            <span class="tabular font-mono"
                >{{ usage.used
                }}<span class="text-muted-foreground"
                    >/{{ usage.allowance }}</span
                ></span
            >
        </div>
        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-muted">
            <div
                class="h-full rounded-full transition-all"
                :class="tone"
                :style="{ width: `${pct}%` }"
            />
        </div>
        <p v-if="pct >= 100" class="mt-1 text-[11px] text-critical">
            {{
                $t(
                    'Limit reached: new reviews get no AI draft, you can still reply yourself.',
                )
            }}
        </p>
        <p v-else-if="pct >= 80" class="mt-1 text-[11px] text-muted-foreground">
            {{ $t('Almost at your monthly limit.') }}
        </p>
    </Link>
</template>
