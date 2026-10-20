/**
 * The A5 government invoice on the shop's paper — PROJECT_DOC Section 9.
 *
 * Waits for the shop's font, because how far a name wraps depends on it, then
 * moves items that do not fit on to more sheets, fills the empty rows and
 * shrinks any single line too long for its box. `data-ready` on the body says
 * it has finished, and the Print button waits for it.
 */
import { fitLines, paginate } from './invoice/layers.js';

const laid = (async () => {
    await document.fonts.ready;

    const first = document.querySelector('.inv-sheet');
    if (first) {
        paginate(first);
        fitLines(document);
    }

    document.body.dataset.ready = 'true';
})();

document.querySelectorAll('[data-print]').forEach((button) => {
    button.addEventListener('click', async () => {
        await laid;
        window.print();
    });
});
