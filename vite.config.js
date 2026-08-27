import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

// `command` decides the base URL Vite stamps into the built CSS.
//
// By default laravel-vite-plugin builds with base `/build/`, so a @font-face
// inside app.css comes out as url(/build/assets/…). That is only correct when
// public/ is the document root. The shop's host serves the app from a
// subdirectory — https://soranstore.com/sys/public/ — where /build/assets/…
// resolves to the wrong place and every font 404s. The text fonts fail
// silently (the browser falls back to a system face) but the icon font cannot:
// every glyph draws as an empty box.
//
// './' makes Vite write the font URLs relative to the stylesheet that asks for
// them (they sit in the same assets/ folder), so they resolve wherever the app
// is deployed — document root, subdirectory, or moved later. The <link> and
// <script> tags themselves are unaffected: Blade builds those from APP_URL.
//
// Only for `build`. The dev server needs the plugin's own empty base.
export default defineConfig(({ command }) => ({
    base: command === 'build' ? './' : '',
    plugins: [
        laravel({
            input: ['resources/scss/app.scss', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
}));
