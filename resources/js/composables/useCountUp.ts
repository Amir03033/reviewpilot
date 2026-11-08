import { onMounted, ref, watch } from 'vue';
import type { Ref } from 'vue';

/**
 * Animates the leading number of a formatted value ("3.60★", "45", "12%") from 0 to its
 * value. Non-numeric values ("—") are shown as they are. Respects reduced motion.
 */
export function useCountUp(source: Ref<string>, duration = 650): Ref<string> {
    const display = ref(source.value);

    function run(target: string) {
        const match = target.match(/^(-?)(\d+(?:[.,]\d+)?)(.*)$/s);
        const reduced =
            typeof window === 'undefined' ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        if (!match || reduced) {
            display.value = target;

            return;
        }

        const [, sign, numberText, rest] = match;
        const separator = numberText.includes(',') ? ',' : '.';
        const decimals = numberText.split(/[.,]/)[1]?.length ?? 0;
        const end = parseFloat(numberText.replace(',', '.'));
        const start = performance.now();

        const frame = (now: number) => {
            const t = Math.min(1, (now - start) / duration);
            const eased = 1 - Math.pow(1 - t, 3);
            const value = (end * eased)
                .toFixed(decimals)
                .replace('.', separator);
            display.value = `${sign}${value}${rest}`;

            if (t < 1) {
                requestAnimationFrame(frame);
            } else {
                display.value = target;
            }
        };

        requestAnimationFrame(frame);
    }

    onMounted(() => run(source.value));
    watch(source, (v) => run(v));

    return display;
}
