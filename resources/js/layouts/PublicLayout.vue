<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import LanguageSwitcher from '@/components/rp/LanguageSwitcher.vue';
import { Button } from '@/components/ui/button';

const page = usePage();
const user = computed(() => page.props.auth.user);
const company = computed(() => page.props.company);
const year = new Date().getFullYear();
</script>

<template>
    <div class="flex min-h-svh flex-col bg-background text-foreground">
        <header
            class="sticky top-0 z-30 border-b border-border/60 bg-background/85 backdrop-blur"
        >
            <div
                class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3 sm:px-6"
            >
                <Link href="/" class="flex items-center gap-2"
                    ><AppLogo
                /></Link>
                <nav class="flex items-center gap-1 sm:gap-2">
                    <a
                        href="/#pricing"
                        class="hidden px-3 text-sm text-muted-foreground hover:text-foreground sm:inline"
                        >{{ $t('Pricing') }}</a
                    >
                    <a
                        href="/#faq"
                        class="hidden px-3 text-sm text-muted-foreground hover:text-foreground sm:inline"
                        >{{ $t('FAQ') }}</a
                    >
                    <LanguageSwitcher class="mr-1" />
                    <Button v-if="user" as-child size="sm"
                        ><Link href="/dashboard">{{
                            $t('Open ReviewPilot')
                        }}</Link></Button
                    >
                    <template v-else>
                        <Button as-child variant="ghost" size="sm"
                            ><Link href="/login">{{
                                $t('Log in')
                            }}</Link></Button
                        >
                        <Button as-child size="sm" class="hidden sm:inline-flex"
                            ><Link href="/register">{{
                                $t('Start free trial')
                            }}</Link></Button
                        >
                    </template>
                </nav>
            </div>
        </header>

        <main class="flex-1">
            <slot />
        </main>

        <footer class="border-t border-border/60">
            <div
                class="mx-auto grid max-w-6xl gap-8 px-4 py-10 text-sm sm:grid-cols-3 sm:px-6"
            >
                <div class="space-y-2">
                    <AppLogo />
                    <p class="text-muted-foreground">
                        {{ $t('AI review replies for local businesses.') }}
                    </p>
                </div>
                <div class="space-y-2">
                    <p class="font-medium">{{ $t('Legal') }}</p>
                    <ul class="space-y-1 text-muted-foreground">
                        <li>
                            <Link
                                href="/legal/privacy"
                                class="hover:text-foreground"
                                >{{ $t('Privacy policy') }}</Link
                            >
                        </li>
                        <li>
                            <Link
                                href="/legal/terms"
                                class="hover:text-foreground"
                                >{{ $t('Terms of service') }}</Link
                            >
                        </li>
                        <li>
                            <Link
                                href="/legal/dpa"
                                class="hover:text-foreground"
                                >{{ $t('Data processing agreement') }}</Link
                            >
                        </li>
                    </ul>
                </div>
                <div class="space-y-2">
                    <p class="font-medium">{{ $t('Contact') }}</p>
                    <ul class="space-y-1 text-muted-foreground">
                        <li>
                            <a
                                :href="`mailto:${company.email}`"
                                class="hover:text-foreground"
                                >{{ company.email }}</a
                            >
                        </li>
                        <li v-if="company.kvk">
                            {{ $t('Chamber of Commerce (KvK)') }}:
                            {{ company.kvk }}
                        </li>
                        <li v-if="company.vat_number">
                            {{ $t('VAT number') }}: {{ company.vat_number }}
                        </li>
                    </ul>
                </div>
            </div>
            <p
                class="mx-auto max-w-6xl px-4 pb-8 text-xs text-muted-foreground sm:px-6"
            >
                © {{ year }} {{ company.legal_name }}
            </p>
        </footer>
    </div>
</template>
