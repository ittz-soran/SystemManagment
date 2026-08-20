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
            'Locked: :count units from this purchase have already been used.' => [':count'],
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
