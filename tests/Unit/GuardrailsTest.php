<?php

namespace Tests\Unit;

use App\Services\Reviews\Guardrails;
use Tests\TestCase;

class GuardrailsTest extends TestCase
{
    private function check(string $text, array $overrides = []): array
    {
        return (new Guardrails)->check($text, [
            'expected_language' => 'nl',
            'variant_language' => 'nl',
            'forbidden_phrases' => ['excuses voor het ongemak'],
            'fact_values' => ['klachten@bistro.nl', '030 123 4567'],
            'recent_replies' => [],
            'compensation_policy' => 'invite_contact',
            'max_chars' => 1500,
            ...$overrides,
        ]);
    }

    public function test_a_clean_reply_passes(): void
    {
        $result = $this->check('Dank je wel voor je review! Mail ons gerust via klachten@bistro.nl. Team Bistro');
        $this->assertTrue($result['passed']);
        $this->assertFalse($result['requires_human']);
    }

    public function test_forbidden_phrases_and_invented_contact_details_fail(): void
    {
        $this->assertFalse($this->check('Excuses voor het ongemak, Team Bistro')['checks']['forbidden_phrases']['pass']);
        $this->assertFalse($this->check('Bel ons op 06 98765432 voor een oplossing.')['checks']['contact_details']['pass']);
        $this->assertFalse($this->check('Mail naar info@ergens-anders.nl voor meer info.')['checks']['contact_details']['pass']);
    }

    public function test_compensation_and_liability_are_caught(): void
    {
        $promise = $this->check('We bieden je graag een gratis dessert aan bij je volgende bezoek.');
        $this->assertFalse($promise['passed']);
        $this->assertTrue($promise['requires_human']);

        $allowed = $this->check('We bieden je graag een gratis dessert aan bij je volgende bezoek.', ['compensation_policy' => 'may_offer']);
        $this->assertTrue($allowed['checks']['promises']['pass']);
        $this->assertTrue($allowed['requires_human'], 'compensation always needs a human');

        $this->assertFalse($this->check('Dit is helemaal onze schuld, sorry.')['checks']['liability']['pass']);
    }

    public function test_language_mismatch_and_repetition_fail(): void
    {
        $this->assertFalse($this->check('Thanks a lot for your lovely review!', ['variant_language' => 'en'])['checks']['language']['pass']);

        $text = 'Dank je wel voor je mooie review, we hopen je snel weer te zien bij ons in de zaak!';
        $this->assertFalse($this->check($text, ['recent_replies' => [$text]])['checks']['originality']['pass']);
    }
}
