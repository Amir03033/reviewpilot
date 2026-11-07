<?php

namespace Tests\Feature\I18n;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class TranslationTest extends TestCase
{
    use RefreshDatabase;

    /** Every literal string passed to $t()/t() in Vue/TS and __()/toast() in PHP has a Dutch translation. */
    public function test_every_source_string_has_a_dutch_translation(): void
    {
        $nl = json_decode(file_get_contents(lang_path('nl.json')), true, flags: JSON_THROW_ON_ERROR);
        $keys = [];
        $unescape = fn (string $s) => stripslashes($s);

        $js = Finder::create()->files()->in(resource_path('js'))->name(['*.vue', '*.ts'])
            ->exclude(['components/ui', 'routes', 'actions', 'wayfinder']);
        foreach ($js as $file) {
            $src = $file->getContents();
            preg_match_all("/(?<![A-Za-z_.])\\\$?t\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $src, $m);
            array_push($keys, ...array_map($unescape, $m[1]));
            preg_match_all("/(?:title|description|buttonText):\\s*'([^']+)'/", $src, $m);
            array_push($keys, ...$m[1]);
        }

        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            $src = $file->getContents();
            preg_match_all("/(?:__|toast|backWith)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $src, $m);
            array_push($keys, ...array_map($unescape, $m[1]));
        }

        $missing = collect($keys)->unique()
            ->filter(fn ($k) => preg_match('/[A-Za-z]/', $k))
            ->reject(fn ($k) => array_key_exists($k, $nl))
            ->values()->all();

        $this->assertSame([], $missing, 'Missing Dutch translations in lang/nl.json');
    }

    public function test_dutch_translations_keep_their_placeholders(): void
    {
        $nl = json_decode(file_get_contents(lang_path('nl.json')), true);
        $broken = [];
        foreach ($nl as $en => $text) {
            preg_match_all('/:([a-z_]+)/i', $en, $a);
            foreach ($a[1] as $placeholder) {
                if (! str_contains($text, ':'.$placeholder)) {
                    $broken[] = $en;
                }
            }
        }
        $this->assertSame([], array_values(array_unique($broken)));
    }

    public function test_guests_get_dutch_by_default_and_can_switch(): void
    {
        // Symfony's test request sends "en-us" by default; a Dutch browser sends nl-NL.
        $this->withHeader('Accept-Language', 'nl-NL,nl;q=0.9')
            ->get('/login')->assertInertia(fn ($page) => $page->where('locale', 'nl')
            ->where('translations.Log in', fn ($v) => $v !== 'Log in'));

        $this->post('/locale', ['locale' => 'en'])->assertRedirect();
        $this->get('/login')->assertInertia(fn ($page) => $page->where('locale', 'en'));
    }

    public function test_browser_language_is_respected_for_guests(): void
    {
        $this->get('/login', ['Accept-Language' => 'en-GB,en;q=0.9'])
            ->assertInertia(fn ($page) => $page->where('locale', 'en'));
    }

    public function test_switching_language_is_saved_on_the_user(): void
    {
        $user = User::factory()->create(['locale' => 'en']);

        $this->actingAs($user)->post('/locale', ['locale' => 'nl'])->assertRedirect();

        $this->assertSame('nl', $user->fresh()->locale);
        $this->assertSame('nl', $user->fresh()->preferredLocale());
    }

    public function test_unsupported_locale_is_rejected(): void
    {
        $this->post('/locale', ['locale' => 'fr'])->assertSessionHasErrors('locale');
    }
}
