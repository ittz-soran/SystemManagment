<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A button that posts somewhere other than the form it is drawn inside.
 *
 * **Soran, 2026-09-20:** *"i want delete an room but not deleted however show
 * delete room success"*. He was right twice over — the room was not deleted,
 * and the page said it had been.
 *
 * A form cannot be nested inside a form, so a button that must post elsewhere
 * names its form by id — `form="room-5-delete"` — while still sitting in the
 * footer of the modal it belongs to. HTML honours that attribute. `app.js` did
 * not: it bound the hold-to-save guard by walking `form.querySelectorAll` from
 * each guarded form, which finds the button by DOM descent and hands it the
 * wrong form. The guard then demotes it with `button.type = 'button'`, severing
 * the attribute the browser would have used to put it right, and submits the
 * form it was given.
 *
 * So Remove submitted the modal's PUT instead of its own DELETE. The room was
 * *saved*, the controller flashed "Stock room saved", and both the shop and the
 * shopkeeper believed it was gone.
 *
 * ⚠️ AND IT WAS NOT ONLY ROOMS. `Back up now` in Settings is the same shape —
 * `form="backup-now"` inside the settings form — and was doing the same thing:
 * saving the settings and taking no backup at all, while reporting success.
 * That is the worse of the two, because the answer to "is my shop backed up"
 * was yes and the answer was wrong.
 *
 * Neither had a test, because both work perfectly in the markup. The fault was
 * only ever in which form the script picked up.
 */
class FormOwnerTest extends TestCase
{
    /**
     * Every `<button … form="x">` in the shop, as [view => list of form ids].
     *
     * @return array<string, list<string>>
     */
    private function buttonsNamingAForm(): array
    {
        $found = [];

        foreach (glob(resource_path('views/*/*.blade.php')) as $path) {
            $text = (string) file_get_contents($path);

            preg_match_all('/<button\b(?:\{\{.*?\}\}|[^>])*>/s', $text, $buttons);

            foreach ($buttons[0] as $button) {
                if (preg_match('/\bform="([^"]+)"/', $button, $owner)) {
                    $found[$path][] = $owner[1];
                }
            }
        }

        return $found;
    }

    /**
     * The one that would have caught it.
     *
     * Against the COMPILED bundle rather than the source, for the reason
     * AssetBuildTest exists: the build is committed and travels to shops that
     * have no Node, so a correct `app.js` beside a stale `public/build` is a
     * shop that still has the bug.
     *
     * `button.form` is the form owner the HTML spec defines — the attribute
     * where there is one, the ancestor where there is not — so reading it is
     * the whole fix. The names around it are minified; `.form` is a DOM
     * property and is not.
     */
    public function test_the_hold_guard_submits_the_form_each_button_belongs_to(): void
    {
        $bundles = glob(public_path('build/assets/app-*.js'));

        $this->assertNotSame([], $bundles, 'No compiled bundle. Run `npm run build` first.');

        foreach ($bundles as $path) {
            $js = (string) file_get_contents($path);

            $this->assertStringContainsString('button:not([type])', $js,
                'The hold-to-save binding is not in this build at all.');

            // From where the buttons are collected to where they are bound.
            $this->assertMatchesRegularExpression(
                '/button:not\(\[type\]\).{0,120}?\.form\s*\?\?/s',
                $js,
                basename($path).": the hold guard binds each button to the form it SITS INSIDE.\n"
                ."A button carrying form=\"…\" posts somewhere else, and binding it by DOM\n"
                ."descent submits the wrong form — Remove saved the room it was meant to\n"
                ."delete, and Back up now saved the settings instead of taking a backup.\n"
                .'Pass `button.form ?? form` to the hold binding, and rebuild.',
            );
        }
    }

    /**
     * ⚠️ And the id it names has to exist in the same view.
     *
     * `form="room-5-delete"` pointing at nothing is a button that silently does
     * nothing at all — no error, no request. The ids here are Blade
     * expressions, so they are compared as the literal text of the attribute,
     * which is exactly how the browser will match them once rendered.
     */
    public function test_a_button_that_names_its_own_form_names_one_that_is_there(): void
    {
        $dangling = [];

        foreach ($this->buttonsNamingAForm() as $path => $ids) {
            $text = (string) file_get_contents($path);

            foreach ($ids as $id) {
                if (! str_contains($text, 'id="'.$id.'"')) {
                    $dangling[] = basename(dirname($path)).'/'.basename($path).'  form="'.$id.'"';
                }
            }
        }

        sort($dangling);

        $this->assertSame([], $dangling, implode("\n", [
            'These buttons name a form that is not in the same view, so pressing',
            'them does nothing whatsoever — no request, no error, no clue.',
            '',
            ...$dangling,
            '',
        ]));
    }

    /** The two that are known to rely on it, named so a rename cannot quietly drop one. */
    public function test_the_buttons_that_depend_on_this_are_still_wired_that_way(): void
    {
        $all = [];

        foreach ($this->buttonsNamingAForm() as $path => $ids) {
            foreach ($ids as $id) {
                $all[] = basename(dirname($path)).'/'.basename($path).'  form="'.$id.'"';
            }
        }

        sort($all);

        $this->assertSame([
            'settings/edit.blade.php  form="backup-now"',
            'stock-rooms/_form.blade.php  form="{{ $id }}-delete"',
        ], $all, implode("\n", [
            'The set of buttons posting to a form other than the one they sit in has',
            'changed. That is fine — but each one depends on the hold guard reading',
            '`button.form`, so check the new one actually posts where it says, in a',
            'browser, and then update this list.',
            '',
        ]));
    }
}
