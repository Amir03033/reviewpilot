<template>
    <div
        class="flex aspect-square size-8 items-center justify-center rounded-lg bg-foreground text-background shadow-sm"
    >
        <svg
            viewBox="0 0 24 24"
            class="size-4.5"
            fill="none"
            aria-hidden="true"
        >
            <path
                d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v8a2.5 2.5 0 0 1-2.5 2.5H11l-4.5 4v-4h0A2.5 2.5 0 0 1 4 13.5z"
                stroke="currentColor"
                stroke-width="1.6"
                stroke-linejoin="round"
            />
            <path
                d="m8.5 9.5 2.2 2.2 4.8-4.7"
                stroke="currentColor"
                stroke-width="1.6"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </svg>
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="truncate leading-tight font-semibold tracking-tight">{{
            $t('ReviewPilot')
        }}</span>
    </div>
</template>
