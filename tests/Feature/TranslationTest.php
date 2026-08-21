<?php

namespace Tests\Feature;

use App\Http\Middleware\SetUserPreferences;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/** Section 2: English, Kurdish Sorani, Arabic and Persian, the last three RTL. */
class TranslationTest extends TestCase
{
    use RefreshDatabase;

    private const TRANSLATED = ['ckb', 'ar', 'fa'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /**
     * Every string the interface uses must exist in every language file. This
     * is the test that stops a new page shipping with English leaking into a
     * Sorani screen.
     */
    public function test_every_language_file_is_complete(): void
    {
        $this->artisan('translations:check')->assertSuccessful();
    }

    public function test_the_interface_actually_renders_in_each_language(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        foreach (self::TRANSLATED as $language) {
            $admin->forceFill(['language' => $language])->save();

            $response = $this->actingAs($admin)->get(route('dashboard'))->assertOk();

            // The layout must switch direction with the text.
            $response->assertSee('lang="'.$language.'"', false);
            $response->assertSee('dir="rtl"', false);

            // And the words themselves must be translated, not passed through.
            $response->assertSee(__('Dashboard', [], $language), false);
            $response->assertDontSee('>Dashboard<', false);
        }
    }

    /** English is the source language, so it is left-to-right and needs no file. */
    public function test_english_stays_left_to_right(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $admin->forceFill(['language' => 'en'])->save();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('Dashboard');
    }

    /**
     * A placeholder dropped in translation would render ":count" or, worse,
     * silently lose the number.
     */
    public function test_placeholders_survive_translation(): void
    {
        $samples = [
            'Not enough stock: :count available.' => [':count'],
            'Only :count left to return on that line.' => [':count'],
            'Stock cache mismatch: the product row says :cached, the batches say :batches, and the movements say :movements. The batches are the truth.' => [':cached', ':batches', ':movements'],
            'Delete :document? Stock will drop by :units units and the refund will be undone.' => [':document', ':units'],
            'Blank uses the shop default of :count.' => [':count'],
            'Apply share: :amount' => [':amount'],
        ];

        foreach (self::TRANSLATED as $language) {
            foreach ($samples as $source => $placeholders) {
                $translated = Lang::get($source, [], $language);

                $this->assertNotSame(
                    $source,
                    $translated,
                    "[{$language}] should translate: {$source}"
                );

                foreach ($placeholders as $placeholder) {
                    $this->assertStringContainsString(
                        $placeholder,
                        $translated,
                        "[{$language}] dropped {$placeholder} from: {$source}"
                    );
                }
            }
        }
    }

    /**
     * A long plural message is normally written as two literals joined across
     * lines. The extractor collected only the first one for a while, so the
     * plural branch never reached the lang files and quietly fell back to
     * English the moment a count reached two.
     */
    public function test_both_branches_of_a_pluralised_message_are_translated(): void
    {
        $sources = [
            '{1}Locked: :count unit from this purchase has already been used.'
            .'|[2,*]Locked: :count units from this purchase have already been used.',

            '{1}Cannot undo this: :count unit that came back has since been sold or written off. Return it to stock first, or correct it with a stock adjustment.'
            .'|[2,*]Cannot undo this: :count units that came back have since been sold or written off. Return them to stock first, or correct it with a stock adjustment.',
        ];

        foreach (self::TRANSLATED as $language) {
            foreach ($sources as $source) {
                foreach ([1, 5] as $count) {
                    $rendered = trans_choice($source, $count, ['count' => $count], $language);

                    $this->assertStringNotContainsString(
                        'Locked:',
                        $rendered,
                        "[{$language}] left an English branch for :count = {$count}"
                    );

                    $this->assertStringNotContainsString('Cannot undo this:', $rendered);
                    $this->assertStringContainsString((string) $count, $rendered);
                }
            }
        }
    }

    /** The extractor must see a Blade template's strings, not just PHP's. */
    public function test_a_string_only_a_blade_template_uses_is_collected(): void
    {
        // Only resources/views/sales/index.blade.php says this.
        $this->assertArrayHasKey(
            'No sales yet. Create your first sale.',
            json_decode(file_get_contents(lang_path('ckb.json')), true),
        );
    }

    /** The framework's own auth messages need files too, or they show as keys. */
    public function test_framework_auth_messages_are_translated(): void
    {
        foreach (array_merge(['en'], self::TRANSLATED) as $language) {
            foreach (['auth.failed', 'auth.password', 'auth.throttle'] as $key) {
                $this->assertNotSame(
                    $key,
                    Lang::get($key, [], $language),
                    "[{$language}] {$key} would display as a raw key"
                );
            }
        }
    }

    /** RTL is a property of the language, and the middleware must know which. */
    public function test_the_rtl_languages_are_the_three_the_doc_names(): void
    {
        $this->assertSame(['ckb', 'ar', 'fa'], SetUserPreferences::RTL_LANGUAGES);
        $this->assertSame(
            ['en', 'ckb', 'ar', 'fa'],
            array_keys(SetUserPreferences::LANGUAGES),
        );
    }
}
