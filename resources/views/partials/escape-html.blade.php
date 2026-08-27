    {{--
        Every screen that draws rows from JSON builds them as HTML strings, and
        the values in them are names somebody typed: a product, a customer, a
        supplier. Written straight into innerHTML, a name is script — and it
        runs in the browser of whoever searches, which on this system includes
        the admin. So nothing typed goes into markup without passing through
        here first.

        A classic script in <head>, not a module and not app.js, because the
        inline scripts on those screens must be able to call it whenever they
        run. One definition; app.js uses this one too.
    --}}
    <script>
        window.escapeHtml = (value) => String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#39;');
    </script>
