<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Renders the legal documents in resources/legal/{locale}/{document}.md, filling in
 * company details from config('reviewpilot.company') so nothing is hard-coded.
 */
class LegalDocuments
{
    public const DOCUMENTS = ['privacy', 'terms', 'dpa'];

    public static function render(string $document, string $locale): string
    {
        $locale = in_array($locale, ['nl', 'en'], true) ? $locale : 'nl';
        $markdown = file_get_contents(resource_path("legal/{$locale}/{$document}.md"));

        $html = Str::markdown(strtr($markdown, self::variables($locale)), [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        // Open external links in a new tab.
        return preg_replace('/<a href="(https?:[^"]+)"/', '<a href="$1" target="_blank" rel="noopener"', $html);
    }

    /** @return array<string, string> */
    private static function variables(string $locale): array
    {
        $c = config('reviewpilot.company');
        $nl = $locale === 'nl';

        $block = collect([
            '**'.e($c['legal_name']).'**',
            trim(($c['address'] ?? '').', '.trim(($c['postal_code'] ?? '').' '.($c['city'] ?? '')), ', '),
            $c['country'],
            $c['kvk'] ? ($nl ? 'KvK-nummer: ' : 'Chamber of Commerce (KvK): ').$c['kvk'] : null,
            $c['vat_number'] ? ($nl ? 'Btw-nummer: ' : 'VAT number: ').$c['vat_number'] : null,
            'E-mail: '.$c['email'],
        ])->filter()->implode("  \n");

        $rows = collect(config('reviewpilot.legal.subprocessors'))
            ->map(fn ($s) => '| '.e($s['name']).' | '.e(__($s['purpose'], [], $locale)).' | '.e($s['location']).' |')
            ->implode("\n");
        $table = ($nl ? "| Partij | Doel | Locatie |\n" : "| Party | Purpose | Location |\n")."|---|---|---|\n".$rows;

        return [
            '{{company_legal_name}}' => e($c['legal_name']),
            '{{company_block}}' => $block,
            '{{kvk_suffix}}' => $c['kvk'] ? ($nl ? ', ingeschreven bij de KvK onder nummer ' : ', registered with the Dutch Chamber of Commerce under number ').$c['kvk'] : '',
            '{{email}}' => $c['email'],
            '{{privacy_email}}' => $c['privacy_email'],
            '{{last_updated}}' => config('reviewpilot.legal.last_updated'),
            '{{terms_version}}' => config('reviewpilot.legal.terms_version'),
            '{{subprocessors_table}}' => $table,
            '{{trial_days}}' => (string) config('reviewpilot.trial_days'),
            '{{grace_days}}' => (string) config('reviewpilot.billing.past_due_grace_days'),
            '{{ai_payload_days}}' => (string) config('reviewpilot.retention.ai_payload_days'),
            '{{disconnect_grace_days}}' => (string) config('reviewpilot.retention.disconnect_grace_days'),
        ];
    }
}
