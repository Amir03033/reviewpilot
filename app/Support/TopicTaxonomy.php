<?php

namespace App\Support;

use App\Models\Topic;

/**
 * Fixed topic taxonomy per sector. A fixed list makes trends and location comparisons
 * possible. Topics are grouped; each sector uses the shared groups plus its own
 * (see Sectors::TOPIC_GROUPS). Keys are global and never renamed: stored analyses use them.
 */
final class TopicTaxonomy
{
    public const VERSION = 2;

    /** @var array<string, array<string, array{0: string, 1: string}>> group => key => [label_en, label_nl] */
    public const GROUPS = [
        // Used by every sector.
        'shared' => [
            'service' => ['Service', 'Service'],
            'service.friendliness' => ['Friendliness', 'Vriendelijkheid'],
            'service.speed' => ['Speed & waiting time', 'Snelheid & wachttijd'],
            'service.attentiveness' => ['Attentiveness', 'Attentheid'],
            'service.knowledge' => ['Staff knowledge', 'Vakkennis'],
            'venue' => ['Venue', 'Locatie'],
            'venue.cleanliness' => ['Cleanliness & hygiene', 'Netheid & hygiëne'],
            'venue.ambience' => ['Ambience & noise', 'Sfeer & geluid'],
            'venue.accessibility' => ['Accessibility', 'Toegankelijkheid'],
            'venue.parking' => ['Parking', 'Parkeren'],
            'value' => ['Value', 'Prijs'],
            'value.price' => ['Price level', 'Prijsniveau'],
            'value.value_for_money' => ['Value for money', 'Prijs-kwaliteit'],
            'value.billing' => ['Billing errors', 'Fouten op de rekening'],
            'operations' => ['Operations', 'Operatie'],
            'operations.reservations' => ['Reservations & appointments', 'Reserveren & afspraken'],
            'operations.opening_hours' => ['Opening hours', 'Openingstijden'],
            'operations.payment' => ['Payment options', 'Betaalmogelijkheden'],
            'operations.communication' => ['Communication & follow-up', 'Communicatie & opvolging'],
            'other' => ['Other', 'Overig'],
            'other.management_response' => ['Management response', 'Reactie management'],
        ],
        'food' => [
            'food' => ['Food', 'Eten'],
            'food.taste' => ['Taste', 'Smaak'],
            'food.temperature' => ['Temperature', 'Temperatuur'],
            'food.portion' => ['Portion size', 'Portiegrootte'],
            'food.quality' => ['Freshness & quality', 'Versheid & kwaliteit'],
            'food.variety' => ['Menu variety', 'Menukeuze'],
            'food.dietary' => ['Dietary & allergies', 'Dieet & allergieën'],
            'food.presentation' => ['Presentation', 'Presentatie'],
        ],
        'hospitality' => [
            'service.accuracy' => ['Order accuracy', 'Juistheid bestelling'],
            'venue.toilets' => ['Toilets', 'Toiletten'],
            'venue.seating' => ['Seating & comfort', 'Zitplaatsen & comfort'],
            'operations.delivery' => ['Takeaway & delivery', 'Afhalen & bezorgen'],
            'other.events' => ['Events & groups', 'Evenementen & groepen'],
        ],
        'hotel' => [
            'stay' => ['Stay', 'Verblijf'],
            'stay.room' => ['Room', 'Kamer'],
            'stay.bed' => ['Bed & sleep', 'Bed & slapen'],
            'stay.bathroom' => ['Bathroom', 'Badkamer'],
            'stay.checkin' => ['Check-in & check-out', 'In- en uitchecken'],
            'stay.breakfast' => ['Breakfast', 'Ontbijt'],
            'stay.facilities' => ['Facilities & wifi', 'Faciliteiten & wifi'],
            'stay.location' => ['Location & surroundings', 'Ligging & omgeving'],
        ],
        'beauty' => [
            'treatment' => ['Treatment', 'Behandeling'],
            'treatment.result' => ['Result', 'Resultaat'],
            'treatment.consultation' => ['Advice & consultation', 'Advies & intake'],
            'treatment.punctuality' => ['Punctuality', 'Op tijd beginnen'],
            'treatment.products' => ['Products used', 'Gebruikte producten'],
            'treatment.comfort' => ['Comfort & care', 'Comfort & zorg'],
        ],
        'automotive' => [
            'repair' => ['Repair', 'Reparatie'],
            'repair.quality' => ['Quality of the repair', 'Kwaliteit reparatie'],
            'repair.diagnosis' => ['Diagnosis', 'Diagnose'],
            'repair.quote' => ['Quote vs. final price', 'Offerte vs. eindprijs'],
            'repair.turnaround' => ['Turnaround time', 'Doorlooptijd'],
            'repair.parts' => ['Parts', 'Onderdelen'],
            'repair.vehicle_care' => ['Care for the vehicle', 'Zorg voor het voertuig'],
        ],
        'retail' => [
            'shop' => ['Shop', 'Winkel'],
            'shop.product_quality' => ['Product quality', 'Productkwaliteit'],
            'shop.assortment' => ['Assortment', 'Assortiment'],
            'shop.stock' => ['Availability & stock', 'Voorraad'],
            'shop.returns' => ['Returns & warranty', 'Retour & garantie'],
            'shop.advice' => ['Product advice', 'Productadvies'],
            'shop.online_order' => ['Online orders & delivery', 'Online bestellen & levering'],
        ],
        'health' => [
            'care' => ['Care', 'Zorg'],
            'care.explanation' => ['Explanation & information', 'Uitleg & informatie'],
            'care.empathy' => ['Empathy & attention', 'Empathie & aandacht'],
            'care.waiting_list' => ['Waiting list & availability', 'Wachtlijst & bereikbaarheid'],
            'care.experience' => ['Overall care experience', 'Ervaring met de zorg'],
        ],
        'services' => [
            'work' => ['Work', 'Werk'],
            'work.quality' => ['Quality of the work', 'Kwaliteit van het werk'],
            'work.punctuality' => ['Punctuality & agreements', 'Afspraken nakomen'],
            'work.quote' => ['Quote vs. final price', 'Offerte vs. eindprijs'],
            'work.tidiness' => ['Tidiness', 'Netjes werken'],
            'work.aftercare' => ['Aftercare', 'Nazorg'],
        ],
    ];

    /** Every topic of every sector. @return array<string, array{0: string, 1: string}> */
    public static function all(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    /**
     * Leaf keys the AI may assign. Without a sector: all of them.
     *
     * @return array<int, string>
     */
    public static function leafKeys(?string $sector = null): array
    {
        $topics = $sector === null
            ? self::all()
            : array_merge(...array_map(fn ($g) => self::GROUPS[$g], Sectors::get($sector)['topic_groups']));

        return array_values(array_filter(array_keys($topics), fn ($k) => str_contains($k, '.')));
    }

    public static function seed(): void
    {
        foreach (self::GROUPS as $group => $topics) {
            foreach ($topics as $key => [$en, $nl]) {
                Topic::updateOrCreate(['key' => $key], [
                    'parent_key' => str_contains($key, '.') ? explode('.', $key)[0] : null,
                    'vertical' => $group,
                    'label_en' => $en,
                    'label_nl' => $nl,
                    'taxonomy_version' => self::VERSION,
                ]);
            }
        }
    }

    /** @return array<string, string> key => id */
    public static function idMap(): array
    {
        $map = Topic::query()->pluck('id', 'key')->all();

        // Empty, or a newer taxonomy than the database holds: (re)seed. Existing keys are kept.
        if (count($map) < count(self::all())) {
            self::seed();
            $map = Topic::query()->pluck('id', 'key')->all();
        }

        return $map;
    }
}
