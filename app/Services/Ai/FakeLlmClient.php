<?php

namespace App\Services\Ai;

/**
 * Deterministic, offline stand-in for a real LLM.
 *
 * Used when AI_PROVIDER=fake (local development, demos, CI) so the whole review
 * pipeline works before an OpenAI key is configured. Output follows the same
 * JSON schemas as the real provider. Quality is intentionally basic.
 */
class FakeLlmClient implements LlmClient
{
    private const TOPIC_KEYWORDS = [
        'food.taste' => ['lekker', 'smaak', 'tasty', 'delicious', 'flavour', 'flavor', 'bland', 'smakeloos', 'lecker'],
        'food.temperature' => ['koud', 'lauw', 'cold', 'lukewarm', 'kalt'],
        'food.portion' => ['portie', 'portion', 'klein', 'small portion'],
        'food.quality' => ['vers', 'fresh', 'quality', 'kwaliteit', 'oud brood'],
        'service.friendliness' => ['vriendelijk', 'friendly', 'rude', 'onbeleefd', 'unfriendly', 'aardig', 'freundlich'],
        'service.speed' => ['wachten', 'lang', 'slow', 'traag', 'wait', 'waited', 'snel', 'quick', 'fast'],
        'service.accuracy' => ['verkeerd', 'wrong order', 'vergeten', 'forgot', 'missing'],
        'venue.cleanliness' => ['vies', 'schoon', 'dirty', 'clean', 'smerig', 'hygiene', 'hygiëne'],
        'venue.ambience' => ['sfeer', 'ambience', 'atmosphere', 'gezellig', 'cozy', 'noisy', 'lawaai'],
        'venue.toilets' => ['toilet', 'wc', 'restroom', 'bathroom'],
        'value.price' => ['duur', 'prijs', 'expensive', 'price', 'pricey', 'goedkoop', 'cheap', 'teuer'],
        'value.value_for_money' => ['value for money', 'prijs-kwaliteit', 'waar voor je geld', 'worth'],
        'operations.reservations' => ['reservering', 'reservation', 'booking', 'gereserveerd'],
        'operations.delivery' => ['bezorg', 'delivery', 'thuisbezorgd', 'takeaway', 'afhalen'],
    ];

    private const RISK_KEYWORDS = [
        'food_safety' => ['ziek', 'vergiftig', 'food poisoning', 'sick', 'allergi', 'allergy', 'haar in', 'hair in', 'braken', 'vomit'],
        'hygiene' => ['kakkerlak', 'cockroach', 'muis', 'mouse', 'rat ', 'vies', 'dirty', 'smerig'],
        'discrimination' => ['racis', 'discrimin'],
        'legal_threat' => ['advocaat', 'lawyer', 'rechtszaak', 'sue ', 'lawsuit'],
        'health_authority' => ['nvwa', 'ggd', 'health inspector', 'gezondheidsdienst'],
        'refund_request' => ['geld terug', 'refund', 'terugbetal'],
    ];

    public function __construct(private GenerationRecorder $recorder) {}

    public function structured(LlmRequest $request): LlmResult
    {
        $data = match ($request->purpose) {
            'analysis' => $this->analysis($request->context),
            default => $this->drafts($request->context),
        };

        $tokens = (int) (strlen($request->system.$request->user) / 4);
        $generation = $this->recorder->record($request, 'fake', 'fake-model', 'ok', $tokens, (int) (strlen(json_encode($data)) / 4), 1, $data);

        return new LlmResult($data, $generation);
    }

    private function analysis(array $ctx): array
    {
        $text = mb_strtolower((string) ($ctx['comment'] ?? ''));
        $rating = (int) ($ctx['rating'] ?? 3);
        $polarity = $rating >= 4 ? 'positive' : ($rating <= 2 ? 'negative' : 'neutral');

        $topics = [];
        foreach (self::TOPIC_KEYWORDS as $key => $words) {
            foreach ($words as $word) {
                if ($word !== '' && str_contains($text, $word)) {
                    $topics[] = ['key' => $key, 'polarity' => $polarity, 'evidence' => $word];
                    break;
                }
            }
        }

        $flags = [];
        foreach (self::RISK_KEYWORDS as $flag => $words) {
            foreach ($words as $word) {
                if (str_contains($text.' ', $word)) {
                    $flags[] = $flag;
                    break;
                }
            }
        }

        $risk = min(100, count($flags) * 35 + ($rating <= 2 ? 30 : ($rating === 3 ? 10 : 0)));

        return [
            'language' => self::detectLanguage($text),
            'language_confidence' => 0.9,
            'sentiment_score' => round(($rating - 3) / 2, 2),
            'sentiment_label' => $polarity,
            'topics' => $topics,
            'entities' => ['dishes' => [], 'staff_mentions' => [], 'visit_context' => 'unknown'],
            'risk_flags' => $flags,
            'risk_score' => $risk,
            'requires_human' => $flags !== [] || $rating <= 2,
            'requires_human_reasons' => $flags,
            'confidence' => 0.85,
            'summary' => mb_substr(trim((string) ($ctx['comment'] ?? '')), 0, 120) ?: "{$rating}-star rating without text",
            'keywords' => array_column($topics, 'evidence'),
        ];
    }

    private function drafts(array $ctx): array
    {
        $lang = $ctx['language'] ?? 'nl';
        $name = $ctx['first_name'] ?? null;
        $rating = (int) ($ctx['rating'] ?? 5);
        $signOff = $ctx['sign_off'] ?? 'Het team';
        $nl = $lang === 'nl';

        $greeting = $nl ? ('Hoi'.($name ? " {$name}" : '').',') : ('Hi'.($name ? " {$name}" : '').',');

        if ($rating >= 4) {
            $bodies = $nl
                ? ['Dank je wel voor je mooie review!', 'Wat leuk om te lezen dat je zo genoten hebt. Dank je wel dat je de tijd nam om dit te delen.', 'Wat een fijne woorden, daar worden we echt blij van. We hopen je snel weer te zien!']
                : ['Thank you so much for your lovely review!', 'We are really happy to read that you enjoyed your visit. Thanks for taking the time to share it.', 'What kind words, they made our day. We hope to welcome you back soon!'];
        } else {
            $bodies = $nl
                ? ['Dank je voor je eerlijke feedback. Dit is niet de ervaring die we je willen geven.', 'Bedankt dat je dit met ons deelt. Het spijt ons dat je bezoek niet was zoals je had gehoopt; we nemen je punten mee in het team.', 'Dank je voor je review. We willen graag begrijpen wat er misging. Neem gerust contact met ons op, dan kijken we samen hoe we het goed kunnen maken.']
                : ['Thank you for your honest feedback. This is not the experience we want to offer.', 'Thanks for sharing this with us. We are sorry your visit was not what you hoped for; we will discuss your points with the team.', 'Thank you for your review. We would like to understand what went wrong. Please get in touch so we can look into it together.'];
        }

        $variants = [];
        foreach (['short', 'standard', 'warm'] as $i => $key) {
            $variants[] = [
                'key' => $key,
                'text' => "{$greeting}\n\n{$bodies[$i]}\n\n{$signOff}",
                'language' => $lang,
                'fact_keys_used' => [],
            ];
        }

        return ['variants' => $variants];
    }

    public static function detectLanguage(string $text): string
    {
        $text = ' '.mb_strtolower($text).' ';
        $scores = [
            'nl' => preg_match_all('/\s(de|het|een|en|niet|was|lekker|erg|heel|wij|we|zeer|goed|eten|bediening)\s/u', $text),
            'de' => preg_match_all('/\s(der|die|das|und|nicht|sehr|lecker|essen|war|wir|gut)\s/u', $text),
            'en' => preg_match_all('/\s(the|and|was|very|food|great|good|not|we|service|staff)\s/u', $text),
            'fr' => preg_match_all('/\s(le|la|les|et|très|bon|nous|était|pas|service)\s/u', $text),
        ];
        arsort($scores);
        $best = array_key_first($scores);

        return $scores[$best] > 0 ? $best : 'nl';
    }
}
