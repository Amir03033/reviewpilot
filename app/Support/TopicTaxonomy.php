<?php

namespace App\Support;

use App\Models\Topic;

/**
 * Fixed hospitality topic taxonomy (v1). See docs/blueprint/04-feature-specification.md §E1.
 * A fixed taxonomy makes trends and location comparisons possible.
 */
final class TopicTaxonomy
{
    public const VERSION = 1;

    /** @var array<string, array{0: string, 1: string}> key => [label_en, label_nl] */
    public const TOPICS = [
        'food' => ['Food', 'Eten'],
        'food.taste' => ['Taste', 'Smaak'],
        'food.temperature' => ['Temperature', 'Temperatuur'],
        'food.portion' => ['Portion size', 'Portiegrootte'],
        'food.quality' => ['Freshness & quality', 'Versheid & kwaliteit'],
        'food.variety' => ['Menu variety', 'Menukeuze'],
        'food.dietary' => ['Dietary & allergies', 'Dieet & allergieën'],
        'food.presentation' => ['Presentation', 'Presentatie'],
        'service' => ['Service', 'Service'],
        'service.friendliness' => ['Friendliness', 'Vriendelijkheid'],
        'service.speed' => ['Speed & waiting time', 'Snelheid & wachttijd'],
        'service.attentiveness' => ['Attentiveness', 'Attentheid'],
        'service.accuracy' => ['Order accuracy', 'Juistheid bestelling'],
        'service.knowledge' => ['Staff knowledge', 'Productkennis'],
        'venue' => ['Venue', 'Locatie'],
        'venue.cleanliness' => ['Cleanliness & hygiene', 'Netheid & hygiëne'],
        'venue.toilets' => ['Toilets', 'Toiletten'],
        'venue.ambience' => ['Ambience & noise', 'Sfeer & geluid'],
        'venue.seating' => ['Seating & comfort', 'Zitplaatsen & comfort'],
        'venue.accessibility' => ['Accessibility', 'Toegankelijkheid'],
        'venue.parking' => ['Parking', 'Parkeren'],
        'value' => ['Value', 'Prijs'],
        'value.price' => ['Price level', 'Prijsniveau'],
        'value.value_for_money' => ['Value for money', 'Prijs-kwaliteit'],
        'value.billing' => ['Billing errors', 'Fouten op de rekening'],
        'operations' => ['Operations', 'Operatie'],
        'operations.reservations' => ['Reservations', 'Reserveringen'],
        'operations.opening_hours' => ['Opening hours', 'Openingstijden'],
        'operations.delivery' => ['Takeaway & delivery', 'Afhalen & bezorgen'],
        'operations.payment' => ['Payment options', 'Betaalmogelijkheden'],
        'other' => ['Other', 'Overig'],
        'other.management_response' => ['Management response', 'Reactie management'],
        'other.events' => ['Events & groups', 'Evenementen & groepen'],
    ];

    /** Leaf keys the AI may assign. @return array<int, string> */
    public static function leafKeys(): array
    {
        return array_values(array_filter(array_keys(self::TOPICS), fn ($k) => str_contains($k, '.')));
    }

    public static function seed(): void
    {
        foreach (self::TOPICS as $key => [$en, $nl]) {
            Topic::updateOrCreate(['key' => $key], [
                'parent_key' => str_contains($key, '.') ? explode('.', $key)[0] : null,
                'vertical' => 'hospitality',
                'label_en' => $en,
                'label_nl' => $nl,
                'taxonomy_version' => self::VERSION,
            ]);
        }
    }

    /** @return array<string, string> key => id */
    public static function idMap(): array
    {
        $map = Topic::query()->pluck('id', 'key')->all();

        if ($map === []) {
            self::seed();
            $map = Topic::query()->pluck('id', 'key')->all();
        }

        return $map;
    }
}
