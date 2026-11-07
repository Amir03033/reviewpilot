<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class LegalTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_legal_pages_are_public_in_both_languages(): void
    {
        config(['reviewpilot.company.legal_name' => 'Voorbeeld B.V.', 'reviewpilot.company.kvk' => '12345678']);

        foreach (['privacy', 'terms', 'dpa'] as $doc) {
            $this->withHeader('Accept-Language', 'nl')->get("/legal/{$doc}")->assertOk()
                ->assertInertia(fn ($page) => $page->component('legal/Show')
                    ->where('html', fn ($html) => str_contains($html, 'Voorbeeld B.V.') && ! str_contains($html, '{{')));
        }

        $this->post('/locale', ['locale' => 'en']);
        $this->get('/legal/privacy')->assertInertia(fn ($page) => $page->where('html', fn ($html) => str_contains($html, 'Limited Use')));
        $this->get('/legal/unknown')->assertNotFound();
        $this->get('/privacy')->assertRedirect('/legal/privacy');
    }

    public function test_onboarding_requires_accepting_the_terms(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/onboarding', ['name' => 'Café Zon', 'country' => 'NL'])
            ->assertSessionHasErrors('accept_terms');

        $this->actingAs($user)->post('/onboarding', ['name' => 'Café Zon', 'country' => 'NL', 'accept_terms' => '1'])
            ->assertRedirect();
        $org = $user->fresh()->currentOrganization;
        $this->assertTrue($org->hasAcceptedCurrentTerms());
        $this->assertSame($user->id, $org->terms_accepted_by);
    }

    public function test_existing_organisation_can_accept_updated_terms(): void
    {
        [$owner, $org] = $this->makeOrganization();
        $this->assertFalse($org->hasAcceptedCurrentTerms());

        $this->actingAs($owner)->get('/dashboard')->assertInertia(fn ($page) => $page->where('tenant.termsAccepted', false));
        $this->actingAs($owner)->post('/organization/accept-terms', ['accept_terms' => true])->assertRedirect();

        $this->assertTrue($org->fresh()->hasAcceptedCurrentTerms());
    }
}
