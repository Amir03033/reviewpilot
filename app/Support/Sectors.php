<?php

namespace App\Support;

/**
 * The kinds of businesses ReviewPilot supports. A sector decides which topics the AI
 * can assign, how the business is described to the AI, extra rules for replies, and
 * whether replies may ever be published without a person.
 */
final class Sectors
{
    public const DEFAULT = 'hospitality';

    /**
     * @var array<string, array{
     *   label_en: string, label_nl: string, examples_en: string, examples_nl: string,
     *   business: string, topic_groups: list<string>, automation: bool, reply_rules: list<string>
     * }>
     */
    public const SECTORS = [
        'hospitality' => [
            'label_en' => 'Restaurant, café or bar', 'label_nl' => 'Restaurant, café of bar',
            'examples_en' => 'restaurants, cafés, bars, lunchrooms, takeaway', 'examples_nl' => 'restaurants, cafés, bars, lunchrooms, afhaal',
            'business' => 'a restaurant, café or bar',
            'topic_groups' => ['shared', 'food', 'hospitality'],
            'automation' => true,
            'reply_rules' => [],
        ],
        'hotel' => [
            'label_en' => 'Hotel or B&B', 'label_nl' => 'Hotel of B&B',
            'examples_en' => 'hotels, B&Bs, holiday parks, hostels', 'examples_nl' => 'hotels, B&B\'s, vakantieparken, hostels',
            'business' => 'a hotel, B&B or other place to stay',
            'topic_groups' => ['shared', 'hotel', 'food'],
            'automation' => true,
            'reply_rules' => ['Never confirm room numbers, dates of stay or other guests\' details.'],
        ],
        'beauty' => [
            'label_en' => 'Hair, beauty or wellness', 'label_nl' => 'Kapper, beauty of wellness',
            'examples_en' => 'hairdressers, barbers, beauty and nail salons, spas', 'examples_nl' => 'kappers, barbiers, schoonheids- en nagelsalons, sauna\'s',
            'business' => 'a hair, beauty or wellness salon',
            'topic_groups' => ['shared', 'beauty'],
            'automation' => true,
            'reply_rules' => ['Never discuss skin, health or allergy details of the client in public; invite them to get in touch privately.'],
        ],
        'automotive' => [
            'label_en' => 'Garage or car services', 'label_nl' => 'Garage of autobedrijf',
            'examples_en' => 'garages, car dealers, tyre centres, bicycle repair', 'examples_nl' => 'garages, autodealers, bandencentra, fietsenmakers',
            'business' => 'a garage or vehicle service business',
            'topic_groups' => ['shared', 'automotive'],
            'automation' => true,
            'reply_rules' => ['Never make technical or safety claims about a specific vehicle or repair; invite the customer to get in touch.'],
        ],
        'retail' => [
            'label_en' => 'Shop', 'label_nl' => 'Winkel',
            'examples_en' => 'shops, boutiques, bakeries, garden centres, webshops with a store', 'examples_nl' => 'winkels, boetieks, bakkers, tuincentra, webshops met een winkel',
            'business' => 'a shop',
            'topic_groups' => ['shared', 'retail'],
            'automation' => true,
            'reply_rules' => ['Never promise a refund, exchange or discount; refer to the returns policy only if it is in the facts.'],
        ],
        'health' => [
            'label_en' => 'Healthcare practice', 'label_nl' => 'Zorgpraktijk',
            'examples_en' => 'dentists, physiotherapists, GPs, clinics, vets', 'examples_nl' => 'tandartsen, fysiotherapeuten, huisartsen, klinieken, dierenartsen',
            'business' => 'a healthcare practice',
            'topic_groups' => ['shared', 'health'],
            // Medical confidentiality: a reply can reveal that someone is a patient. Always a person.
            'automation' => false,
            'reply_rules' => [
                'Never confirm or suggest that the reviewer is or was a patient or client, and never mention any treatment, diagnosis or visit details.',
                'Thank them in general terms and invite them to contact the practice privately.',
            ],
        ],
        'services' => [
            'label_en' => 'Other local business', 'label_nl' => 'Andere lokale dienstverlener',
            'examples_en' => 'installers, cleaners, gyms, estate agents, driving schools', 'examples_nl' => 'installateurs, schoonmaak, sportscholen, makelaars, rijscholen',
            'business' => 'a local service business',
            'topic_groups' => ['shared', 'services'],
            'automation' => true,
            'reply_rules' => [],
        ],
    ];

    /** @return array<string, mixed> */
    public static function get(?string $sector): array
    {
        return self::SECTORS[$sector] ?? self::SECTORS[self::DEFAULT];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::SECTORS);
    }

    /** @return list<array{key: string, label: string, examples: string}> for forms */
    public static function options(string $locale): array
    {
        $nl = $locale === 'nl';

        return array_map(fn (string $key) => [
            'key' => $key,
            'label' => self::SECTORS[$key][$nl ? 'label_nl' : 'label_en'],
            'examples' => self::SECTORS[$key][$nl ? 'examples_nl' : 'examples_en'],
        ], self::keys());
    }
}
