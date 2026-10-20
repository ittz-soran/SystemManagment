/**
 * The invoice designer — PROJECT_DOC Section 9. Soran, 2026-10-20: "should
 * layers are changeable and sizes with fonts" and "use best tool to design
 * invoice inside system".
 *
 * The sheet is the invoice's own markup at so many pixels to the millimetre.
 * Dragging and resizing are interact.js; this keeps the layout — millimetres,
 * the same shape the server keeps — and writes it into the form's hidden
 * `layout` field after every change, so holding Save sends what is on screen.
 */
import interact from 'interactjs';
import { fillBlankRows, fitLines, present } from './invoice/layers.js';

const data = JSON.parse(document.getElementById('design-data').textContent);
const limits = data.limits;

const sheet = document.getElementById('design-sheet');
const stage = document.getElementById('design-stage');
const field = document.getElementById('design-layout');
const optionsField = document.getElementById('design-options');
const tag = document.getElementById('design-tag');
const panelTitle = document.getElementById('design-panel-title');
const panelBody = document.getElementById('design-panel-body');
const linesFields = document.getElementById('design-lines-fields');

let layout = JSON.parse(field.value);
let options = JSON.parse(optionsField.value);
// An empty word list comes over as [], which takes no names.
if (Array.isArray(options.units)) options.units = {};
let selected = null;
const history = [];

const clamp = (n, low, high) => Math.min(high, Math.max(low, n));
const tenth = (n) => Math.round(n * 10) / 10;
// Dragging moves in half millimetres; the arrow keys and the boxes go finer.
const half = (n) => Math.round(n * 2) / 2;
const px = () => parseFloat(sheet.style.getPropertyValue('--mm')) || 4;
const layerOf = (key) => sheet.querySelector(`.inv-layer[data-layer="${key}"]`);
const keyOf = (element) => element.closest('.inv-layer')?.dataset.layer;

function remember() {
    history.push(JSON.stringify({ layout, options }));
    if (history.length > 200) history.shift();
}

function undo() {
    const last = history.pop();
    if (last) {
        ({ layout, options } = JSON.parse(last));
        showOptions();
        render();
    }
}

/** Every box inside the sheet and no smaller than the smallest. */
function keepInside(layer) {
    layer.w = clamp(layer.w, limits.smallest, limits.width);
    layer.h = clamp(layer.h, limits.smallest, limits.height);
    layer.x = clamp(layer.x, 0, limits.width - layer.w);
    layer.y = clamp(layer.y, 0, limits.height - layer.h);
}

/** The column lines in order, each column at least the narrowest wide. */
function keepColumns(lines) {
    const gap = limits.columnMin;
    lines.w = Math.max(lines.w, gap * 4);
    const c = lines.columns;
    c[0] = clamp(c[0], gap, lines.w - gap * 3);
    c[1] = clamp(c[1], c[0] + gap, lines.w - gap * 2);
    c[2] = clamp(c[2], c[1] + gap, lines.w - gap);
}

/** One layer's numbers on to its box. */
function draw(key) {
    const layer = layout[key];
    const element = layerOf(key);
    ['x', 'y', 'w', 'h', 'size', 'color'].forEach((f) => element.style.setProperty(`--${f}`, layer[f]));
    element.classList.toggle('is-bold', !!layer.bold);
    element.classList.toggle('is-off', !layer.show);

    if (key === 'lines') {
        element.style.setProperty('--row', layer.row);
        layer.columns.forEach((at, i) => {
            element.style.setProperty(`--c${i + 1}`, at);
            element.querySelector(`.design-divider[data-column="${i}"]`)?.style.setProperty('--at', at);
        });
    }

    document.querySelector(`[data-summary="${key}"]`).textContent = layer.show ? `${layer.size} pt` : '—';
}

/** While dragging: the box, the field and the panel; the rest at the end. */
function quick(key) {
    draw(key);
    field.value = JSON.stringify(layout);
    showPanel();
    placeTag();
}

/** The sample's words, the invoice options applied — as the print writes them. */
function write() {
    const shown = present(data.fill, options);

    Object.keys(layout).forEach((key) => {
        const value = layerOf(key).querySelector(':scope > .inv-value');
        if (value) value.textContent = shown[key] ?? '';
    });

    layerOf('lines').querySelectorAll('[data-item]').forEach((row, i) => {
        row.querySelectorAll('[data-cell]').forEach((cell) => {
            cell.textContent = shown.rows[i]?.[cell.dataset.cell] ?? '';
        });
    });

    optionsField.value = JSON.stringify(options);
}

function render() {
    Object.keys(layout).forEach(draw);
    write();
    field.value = JSON.stringify(layout);
    fillBlankRows(layerOf('lines'));
    fitLines(sheet);
    showPanel();
    placeTag();
}

function select(key) {
    selected = key;
    sheet.querySelectorAll('.inv-layer').forEach((el) => el.classList.toggle('is-selected', el.dataset.layer === key));
    document.querySelectorAll('[data-select]').forEach((el) => el.classList.toggle('active', el.dataset.select === key));
    showPanel();
    placeTag();
}

function placeTag() {
    if (!selected) {
        tag.classList.add('d-none');
        return;
    }
    const layer = layout[selected];
    tag.textContent = data.names[selected];
    tag.style.left = `calc(${layer.x} * var(--mm))`;
    tag.style.top = `calc(${layer.y} * var(--mm))`;
    tag.classList.remove('d-none');
}

function showPanel() {
    if (!selected) {
        panelTitle.textContent = data.words.choose;
        panelBody.classList.add('d-none');
        return;
    }

    const layer = layout[selected];
    panelTitle.textContent = data.names[selected];
    panelBody.classList.remove('d-none');

    panelBody.querySelectorAll('[data-field]').forEach((input) => {
        if (document.activeElement === input) return;
        const value = layer[input.dataset.field];
        if (input.type === 'checkbox') input.checked = !!value;
        else input.value = value ?? '';
    });

    const show = panelBody.querySelector('[data-field="show"]');
    show.disabled = data.alwaysShown.includes(selected);

    linesFields.classList.toggle('d-none', selected !== 'lines');
    if (selected === 'lines') {
        linesFields.querySelectorAll('[data-column]').forEach((input) => {
            if (document.activeElement !== input) input.value = layer.columns[input.dataset.column];
        });
    }
}

// ---- dragging and resizing ------------------------------------------------

const moving = new WeakMap();

interact('#design-sheet .inv-layer')
    .draggable({
        listeners: {
            start(event) {
                const key = keyOf(event.target);
                remember();
                select(key);
                moving.set(event.target, { x: layout[key].x, y: layout[key].y });
            },
            move(event) {
                const key = keyOf(event.target);
                const raw = moving.get(event.target);
                raw.x += event.dx / px();
                raw.y += event.dy / px();
                const layer = layout[key];
                layer.x = clamp(half(raw.x), 0, limits.width - layer.w);
                layer.y = clamp(half(raw.y), 0, limits.height - layer.h);
                quick(key);
            },
            end: render,
        },
    })
    .resizable({
        edges: { left: true, right: true, top: true, bottom: true },
        margin: 8,
        listeners: {
            start(event) {
                const key = keyOf(event.target);
                remember();
                select(key);
                const layer = layout[key];
                moving.set(event.target, { left: layer.x, top: layer.y, right: layer.x + layer.w, bottom: layer.y + layer.h });
            },
            move(event) {
                const key = keyOf(event.target);
                const raw = moving.get(event.target);
                const d = event.deltaRect;
                raw.left += d.left / px();
                raw.top += d.top / px();
                raw.right += d.right / px();
                raw.bottom += d.bottom / px();

                const layer = layout[key];
                const least = key === 'lines' ? Math.max(limits.smallest, limits.columnMin * 4) : limits.smallest;

                // The edges not being dragged stay exactly where they were.
                const right = event.edges.right ? clamp(half(raw.right), layer.x + least, limits.width) : layer.x + layer.w;
                const bottom = event.edges.bottom ? clamp(half(raw.bottom), layer.y + limits.smallest, limits.height) : layer.y + layer.h;
                const left = event.edges.left ? clamp(half(raw.left), 0, right - least) : layer.x;
                const top = event.edges.top ? clamp(half(raw.top), 0, bottom - limits.smallest) : layer.y;

                layer.x = left;
                layer.y = top;
                layer.w = tenth(right - left);
                layer.h = tenth(bottom - top);
                keepInside(layer);
                if (key === 'lines') keepColumns(layer);
                quick(key);
            },
            end: render,
        },
    });

// The column lines of the item rows, dragged inside their box.
const lines = layerOf('lines');
[0, 1, 2].forEach((i) => {
    const divider = document.createElement('div');
    divider.className = 'design-divider';
    divider.dataset.column = i;
    lines.append(divider);
});

interact('#design-sheet .design-divider').draggable({
    listeners: {
        start(event) {
            remember();
            select('lines');
            moving.set(event.target, layout.lines.columns[event.target.dataset.column]);
        },
        move(event) {
            const i = Number(event.target.dataset.column);
            const raw = moving.get(event.target) + event.dx / px();
            moving.set(event.target, raw);
            const c = layout.lines.columns;
            const low = (i === 0 ? 0 : c[i - 1]) + limits.columnMin;
            const high = (i === 2 ? layout.lines.w : c[i + 1]) - limits.columnMin;
            c[i] = clamp(half(raw), low, high);
            quick('lines');
        },
        end: render,
    },
});

// ---- choosing, typing, keys -----------------------------------------------

// ⚠️ A box made smaller leaves the pointer over the paper, and the browser's
// own picture-dragging then starts and cancels the resize half-way — found by
// driving it: a 6 mm pull resized 1 mm.
sheet.addEventListener('dragstart', (event) => event.preventDefault());

sheet.addEventListener('pointerdown', (event) => {
    const key = keyOf(event.target);
    select(key ?? null);
});

document.querySelectorAll('[data-select]').forEach((button) => {
    button.addEventListener('click', () => select(button.dataset.select));
});

panelBody.addEventListener('change', (event) => {
    const input = event.target;
    if (!selected) return;
    const layer = layout[selected];
    remember();

    if (input.dataset.column !== undefined) {
        layer.columns[input.dataset.column] = tenth(Number(input.value) || 0);
        keepColumns(layer);
    } else if (input.type === 'color') {
        layer.color = input.value.toLowerCase();
    } else if (input.type === 'checkbox') {
        layer[input.dataset.field] = input.checked;
    } else {
        const value = tenth(Number(input.value) || 0);
        if (input.dataset.field === 'size') layer.size = clamp(Math.round(value * 2) / 2, limits.sizeMin, limits.sizeMax);
        else if (input.dataset.field === 'row') layer.row = clamp(value, limits.rowMin, limits.rowMax);
        else layer[input.dataset.field] = value;
        keepInside(layer);
        if (selected === 'lines') keepColumns(layer);
    }

    render();
});

document.addEventListener('keydown', (event) => {
    // A box being typed in keeps its own undo; a tick box or a colour has
    // none, and Ctrl+Z after ticking one is meant for the design — found by
    // driving it, when undoing "unit after the quantity" did nothing.
    const typing = event.target.closest('textarea, input:not([type="checkbox"]):not([type="radio"]):not([type="color"])');

    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z' && !typing) {
        event.preventDefault();
        undo();
        return;
    }

    // A list moves its choice with the arrows, so they stay the list's.
    const step = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[event.key];
    if (!step || !selected || typing || event.target.closest('select')) return;

    event.preventDefault();
    remember();
    const by = event.shiftKey ? 0.1 : 0.5;
    const layer = layout[selected];
    layer.x = tenth(layer.x + step[0] * by);
    layer.y = tenth(layer.y + step[1] * by);
    keepInside(layer);
    render();
});

// ---- the invoice options --------------------------------------------------

function showOptions() {
    document.querySelectorAll('[data-option]').forEach((control) => {
        const value = options[control.dataset.option];
        if (control.type === 'checkbox') control.checked = !!value;
        else control.value = value;
    });
    document.querySelectorAll('[data-unit]').forEach((input) => {
        input.value = options.units[input.dataset.unit] ?? '';
    });
}

document.getElementById('design-options-card').addEventListener('change', (event) => {
    const control = event.target;
    remember();

    if (control.dataset.option) {
        options[control.dataset.option] = control.type === 'checkbox' ? control.checked : control.value;
    } else if (control.dataset.unit !== undefined) {
        const word = control.value.trim();
        if (word === '') delete options.units[control.dataset.unit];
        else options.units[control.dataset.unit] = word;
    }

    render();
});

showOptions();

document.getElementById('design-undo').addEventListener('click', undo);

document.getElementById('design-reset').addEventListener('click', () => {
    remember();
    layout = structuredClone(data.defaults);
    render();
});

// ---- the paper and the font, shown before they are saved -------------------

document.getElementById('invoice_a5_paper').addEventListener('change', (event) => {
    const file = event.target.files[0];
    if (!file) return;

    // A data: URL, which the page's security policy allows for pictures.
    const reader = new FileReader();
    reader.onload = () => {
        const paper = document.getElementById('design-paper');
        paper.src = reader.result;
        paper.classList.remove('d-none');
        document.getElementById('design-nopaper')?.remove();
    };
    reader.readAsDataURL(file);
});

document.getElementById('invoice_font').addEventListener('change', async (event) => {
    const file = event.target.files[0];
    const note = document.getElementById('design-font-note');
    if (!file) return;

    try {
        // Read from the file's bytes, so nothing is fetched from anywhere.
        const face = new FontFace('Invoice Preview', await file.arrayBuffer());
        await face.load();
        document.fonts.add(face);
        sheet.style.setProperty('--invoice-font', '"Invoice Preview", var(--bs-body-font-family)');
        note.textContent = data.words.fontReady;
        note.classList.remove('text-danger');
        render();
    } catch {
        note.textContent = data.words.fontBad;
        note.classList.add('text-danger');
    }
});

// ---- the sheet fills its column ------------------------------------------

function scale() {
    const room = stage.clientWidth - 32;
    sheet.style.setProperty('--mm', `${clamp(room / limits.width, 2, 7)}px`);
    fitLines(sheet);
}

new ResizeObserver(scale).observe(stage);
scale();

document.fonts.ready.then(render);
render();
