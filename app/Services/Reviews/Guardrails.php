<?php

namespace App\Services\Reviews;

/**
 * Deterministic post-generation checks. These run on every AI draft and decide
 * whether a draft may be published automatically. See blueprint §B4.
 */
class Guardrails
{
    private const PROMISE_PATTERN = '/\b(refund|terugbetal\w*|geld terug|gratis|free (meal|drink|dessert|dinner|lunch)|discount|korting|voucher|tegoedbon|cadeaubon|compensat\w*)\b/iu';

    private const LIABILITY_PATTERN = '/\b(our fault|we are liable|we accept (full )?responsibility|onze schuld|aansprakelijk\w*|wij erkennen aansprakelijkheid)\b/iu';

    private const EMAIL_PATTERN = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i';

    private const PHONE_PATTERN = '/(\+?\d[\d\s\-()]{7,}\d)/';

    private const URL_PATTERN = '/\bhttps?:\/\/\S+|\bwww\.\S+/i';

    /**
     * @param  array{
     *     expected_language: ?string,
     *     forbidden_phrases: array<int, string>,
     *     fact_values: array<int, string>,
     *     recent_replies: array<int, string>,
     *     compensation_policy: string,
     *     max_chars: int,
     *     variant_language?: ?string,
     * }  $context
     * @return array{passed: bool, requires_human: bool, checks: array<string, array{pass: bool, message: ?string}>}
     */
    public function check(string $text, array $context): array
    {
        $checks = [];
        $lower = mb_strtolower($text);

        $checks['not_empty'] = $this->result(trim($text) !== '', 'Reply is empty.');

        $checks['length'] = $this->result(
            mb_strlen($text) <= $context['max_chars'] && mb_strlen(trim($text)) >= 10,
            'Reply length is outside the allowed range.',
        );

        $hit = collect($context['forbidden_phrases'])->first(fn ($p) => $p !== '' && str_contains($lower, mb_strtolower($p)));
        $checks['forbidden_phrases'] = $this->result($hit === null, $hit ? "Contains forbidden phrase \"{$hit}\"." : null);

        $expected = $context['expected_language'] ? substr($context['expected_language'], 0, 2) : null;
        $actual = isset($context['variant_language']) && $context['variant_language'] ? substr($context['variant_language'], 0, 2) : null;
        $checks['language'] = $this->result($expected === null || $actual === null || $expected === $actual, "Reply language ({$actual}) differs from review language ({$expected}).");

        $checks['contact_details'] = $this->result($this->contactDetailsAllowed($text, $context['fact_values']), 'Contains contact details that are not in the location facts.');

        $checks['liability'] = $this->result(! preg_match(self::LIABILITY_PATTERN, $text), 'Contains an admission of liability.');

        $mostSimilar = 0.0;
        foreach ($context['recent_replies'] as $previous) {
            $mostSimilar = max($mostSimilar, self::similarity($text, $previous));
        }
        $threshold = (float) config('reviewpilot.ai.similarity_threshold', 0.85);
        $checks['originality'] = $this->result($mostSimilar < $threshold, sprintf('Too similar (%.0f%%) to a recent reply.', $mostSimilar * 100));

        $promises = (bool) preg_match(self::PROMISE_PATTERN, $text);
        $mayOffer = $context['compensation_policy'] === 'may_offer';
        $checks['promises'] = $this->result(! $promises || $mayOffer, 'Offers compensation, which the brand voice does not allow.');

        $passed = collect($checks)->every(fn ($c) => $c['pass']);

        return [
            'passed' => $passed,
            // Even when allowed, compensation offers always need a person.
            'requires_human' => $promises,
            'checks' => $checks,
        ];
    }

    /** Trigram Jaccard similarity between two texts (0..1). */
    public static function similarity(string $a, string $b): float
    {
        $ta = self::trigrams($a);
        $tb = self::trigrams($b);

        if ($ta === [] || $tb === []) {
            return 0.0;
        }

        $intersection = count(array_intersect_key($ta, $tb));
        $union = count($ta + $tb);

        return $union === 0 ? 0.0 : $intersection / $union;
    }

    /** @return array<string, true> */
    private static function trigrams(string $text): array
    {
        $words = preg_split('/\W+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $grams = [];

        for ($i = 0; $i < count($words) - 2; $i++) {
            $grams[$words[$i].' '.$words[$i + 1].' '.$words[$i + 2]] = true;
        }

        return $grams;
    }

    private function contactDetailsAllowed(string $text, array $factValues): bool
    {
        $facts = mb_strtolower(implode(' ', $factValues));
        $normalizedFacts = preg_replace('/\D/', '', $facts);

        foreach ([self::EMAIL_PATTERN, self::URL_PATTERN] as $pattern) {
            preg_match_all($pattern, $text, $matches);
            foreach ($matches[0] as $match) {
                if (! str_contains($facts, mb_strtolower(rtrim($match, '.,)')))) {
                    return false;
                }
            }
        }

        preg_match_all(self::PHONE_PATTERN, $text, $phones);
        foreach ($phones[0] as $phone) {
            $digits = preg_replace('/\D/', '', $phone);
            if (strlen($digits) >= 8 && ! str_contains((string) $normalizedFacts, $digits)) {
                return false;
            }
        }

        return true;
    }

    /** @return array{pass: bool, message: ?string} */
    private function result(bool $pass, ?string $message): array
    {
        return ['pass' => $pass, 'message' => $pass ? null : $message];
    }
}
