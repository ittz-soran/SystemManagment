/**
 * What the A5 invoice's layers need from the browser, for the print and the
 * designer alike — PROJECT_DOC Section 9, the invoice designer.
 *
 * Only the browser knows how wide a Kurdish line is in the shop's font, so
 * these run after the font has loaded.
 */

/** Points, and the smallest a line is made to fit its box. */
const SMALLEST = 6;

/**
 * A single line too long for its box is made smaller, half a point at a
 * time, until it fits or reaches 6 pt — rather than cut off at the edge.
 */
export function fitLines(root) {
    root.querySelectorAll('.inv-one').forEach((layer) => {
        const value = layer.querySelector('.inv-value');
        if (!value) return;

        let size = parseFloat(getComputedStyle(layer).getPropertyValue('--size')) || 10;
        layer.style.removeProperty('font-size');

        while (value.getBoundingClientRect().width > layer.clientWidth + 0.5 && size > SMALLEST) {
            size = Math.max(SMALLEST, size - 0.5);
            layer.style.fontSize = `calc(${size} * 0.3528 * var(--mm))`;
        }
    });
}

/** An empty row, the shape of an item's. */
export function blankRow() {
    const row = document.createElement('div');
    row.className = 'inv-row';
    row.dataset.blank = '';
    for (let i = 0; i < 4; i++) row.append(document.createElement('div'));
    return row;
}

const overflows = (box) => box.scrollHeight > box.clientHeight + 1;

/**
 * Empty rows under the last item to the bottom of the box, so a short invoice
 * still looks like the book. Takes away the ones it put there before.
 */
export function fillBlankRows(lines) {
    lines.querySelectorAll('[data-blank]').forEach((row) => row.remove());

    // A guard rather than a trust: a row of no height would never fill the box.
    for (let i = 0; i < 200; i++) {
        const row = blankRow();
        lines.append(row);
        if (overflows(lines)) {
            row.remove();
            return;
        }
    }
}

/**
 * Items that do not fit go on to another sheet of the paper — the customer,
 * date and number on each, the total and its words on the last.
 *
 * The server sends every row on the first sheet; this moves them along. A
 * single item taller than the whole box stays on a sheet of its own.
 */
export function paginate(first) {
    const lines = first.querySelector('.inv-lines');
    if (!lines) return [first];

    const rows = [...lines.querySelectorAll('[data-item]')];
    rows.forEach((row) => row.remove());

    const empty = first.cloneNode(true);
    const sheets = [first];
    let box = lines;

    rows.forEach((row) => {
        box.append(row);
        if (overflows(box) && box.children.length > 1) {
            row.remove();
            const next = empty.cloneNode(true);
            sheets.at(-1).after(next);
            sheets.push(next);
            box = next.querySelector('.inv-lines');
            box.append(row);
        }
    });

    sheets.slice(0, -1).forEach((sheet) => sheet.querySelectorAll('[data-last-only]').forEach((el) => el.remove()));
    sheets.forEach((sheet) => fillBlankRows(sheet.querySelector('.inv-lines')));

    return sheets;
}
