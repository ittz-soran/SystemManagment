{{--
    The layers of the A5 government invoice, for the print and for the
    designer alike — PROJECT_DOC Section 9, the invoice designer.

    Every length is a number of millimetres times var(--mm): a real millimetre
    on the printed sheet, so many pixels in the designer. One stylesheet for
    both is what makes the designer show what will print.
--}}
<style>
    .inv-sheet {
        position: relative;
        width: calc(148 * var(--mm));
        height: calc(210 * var(--mm));
        overflow: hidden;
        background: #fff;
        color: #111;
        font-family: var(--invoice-font);
    }
    .inv-paper { position: absolute; inset: 0; width: 100%; height: 100%; }

    .inv-layer {
        position: absolute;
        left: calc(var(--x) * var(--mm));
        top: calc(var(--y) * var(--mm));
        width: calc(var(--w) * var(--mm));
        height: calc(var(--h) * var(--mm));
        /* A point is 0.3528 mm. */
        font-size: calc(var(--size) * 0.3528 * var(--mm));
        line-height: 1.25;
        color: var(--color, #111111);
    }
    .inv-layer.is-bold { font-weight: 700; }

    /* One line of text, sitting on the box's bottom edge — or its middle, in the total band. */
    .inv-one { display: flex; align-items: flex-end; white-space: nowrap; overflow: hidden; }
    .inv-one.is-middle { align-items: center; }
    .inv-one.is-centred { justify-content: center; }
    .inv-one.is-ltr { direction: ltr; }
    .inv-one .inv-value { flex: none; }

    /*
     * The item lines. A row is at least the row height and as tall as its name
     * needs; the line comes after it. The figures sit on the name's first line.
     */
    .inv-lines { display: flex; flex-direction: column; overflow: hidden; }
    .inv-row {
        flex: none;
        display: grid;
        grid-template-columns:
            calc(var(--c1) * var(--mm))
            calc((var(--c2) - var(--c1)) * var(--mm))
            calc((var(--c3) - var(--c2)) * var(--mm))
            1fr;
        min-height: calc(var(--row) * var(--mm));
        border-bottom: calc(0.1 * var(--mm)) solid #6b6b6b;
    }
    /*
     * ⚠️ Less room under the text than over it. A Kurdish or Arabic line in a
     * Latin font draws its letters from a second font, and the line comes out
     * a little taller than its line-height; with equal room above and below,
     * every such row grew past the row height and the book's eleven lines held
     * ten. With less below, a one-line row is exactly the row height.
     */
    .inv-row > div {
        padding:
            calc((var(--row) * var(--mm) - 1lh) / 2)
            calc(1.2 * var(--mm))
            calc((var(--row) * var(--mm) - 1lh) / 4);
        overflow-wrap: anywhere;
        min-width: 0;
    }
    .inv-row .inv-num { text-align: center; direction: ltr; white-space: nowrap; }
    .inv-row .inv-qty { text-align: center; white-space: nowrap; }
    .inv-row .inv-name { text-align: start; }
</style>
