import * as bootstrap from 'bootstrap';

window.bootstrap = bootstrap;

/**
 * Section 9b: toasts for success — brief, top-right (top-left in RTL),
 * auto-dismiss. Validation errors are rendered inline instead, because a toast
 * vanishes before the field can be fixed.
 */
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.toast').forEach((el) => {
        bootstrap.Toast.getOrCreateInstance(el, { delay: 4000 }).show();
    });

    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
        bootstrap.Tooltip.getOrCreateInstance(el);
    });

    /**
     * Section 9b: "Disable the save button while submitting. Double-clicking
     * Save on a sale is the classic way to create a duplicate document."
     */
    document.querySelectorAll('form[data-guard-submit]').forEach((form) => {
        form.addEventListener('submit', () => {
            form.querySelectorAll('button[type="submit"]').forEach((button) => {
                button.disabled = true;
                button.dataset.originalText ??= button.innerHTML;
                button.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-2"></span>' +
                    (button.dataset.submittingText ?? button.dataset.originalText);
            });
        });
    });
});

/**
 * The number keypad (Section 9b).
 *
 * Any input carrying data-numpad opens it when tapped. The counter is a
 * touchscreen as often as a keyboard, and a price is the number most often
 * typed by hand — a small number input is the wrong target for a finger.
 *
 * It does arithmetic too, because the sums done at a price field are real ones:
 * taking a discount off (15000 − 500), or working back from a total a customer
 * quotes (36000 ÷ 3).
 *
 * Both ways in stay open: the pad's own buttons, and the physical keyboard
 * while it is showing. Enter applies, Escape cancels.
 */
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('number-pad');

    if (! modal) return;

    const display = document.getElementById('number-pad-display');
    const expression = document.getElementById('number-pad-expression');
    const title = document.getElementById('number-pad-title');
    const dot = document.getElementById('number-pad-dot');
    const instance = bootstrap.Modal.getOrCreateInstance(modal);

    const SIGNS = { '+': '+', '-': '−', '*': '×', '/': '÷' };

    let field = null;
    let entry = '';
    // A calculator replaces the old figure on the first key rather than
    // appending to it, which is what anyone expects from a till.
    let fresh = true;
    // The half-finished sum: 15000 and '−', waiting for the second number.
    let left = null;
    let operator = null;

    const decimals = () => Number(field?.dataset.numpadDecimals ?? 0);

    const tidy = (value) => {
        // A division rarely lands on a whole number, and 5142.857142857143 is
        // not a price anyone wants to read.
        const rounded = Math.round(value * 1000) / 1000;

        return Number.isFinite(rounded) ? String(rounded) : '0';
    };

    function show() {
        display.textContent = entry === '' ? '0' : entry;
        expression.textContent = operator === null ? ' ' : `${left} ${SIGNS[operator]}`;
    }

    function open(target) {
        field = target;
        entry = target.value ?? '';
        fresh = true;
        left = null;
        operator = null;

        title.textContent = target.dataset.numpad || '';
        dot.classList.toggle('invisible', decimals() === 0);

        show();
        instance.show();
    }

    /** Fold the pending operation into a single number. */
    function resolve() {
        if (operator === null) return;

        const right = Number(entry === '' ? 0 : entry);
        const a = Number(left);

        const result = {
            '+': a + right,
            '-': a - right,
            '*': a * right,
            // Dividing by nothing has no answer; leaving the left side alone is
            // less surprising than showing Infinity.
            '/': right === 0 ? a : a / right,
        }[operator];

        entry = tidy(result);
        left = null;
        operator = null;
        fresh = true;
    }

    function press(key) {
        if (key === 'clear') {
            entry = '';
            left = null;
            operator = null;
            fresh = false;
        } else if (key === 'back') {
            entry = fresh ? '' : entry.slice(0, -1);
            fresh = false;
        } else if (key === '=') {
            resolve();
        } else if (SIGNS[key]) {
            // Two operators in a row just changes the operator, rather than
            // folding a number that was never typed.
            if (! fresh || operator === null) resolve();

            left = entry === '' ? '0' : entry;
            operator = key;
            fresh = true;
        } else if (key === '.') {
            if (decimals() > 0 && ! entry.includes('.')) {
                entry = (fresh || entry === '' ? '0' : entry) + '.';
                fresh = false;
            }
        } else {
            if (fresh) {
                entry = '';
                fresh = false;
            }

            // A decimal field stops at the places it allows; a whole-number one
            // never grows a fractional part by typing.
            const [, fraction = ''] = entry.split('.');

            if (entry.includes('.') && fraction.length >= decimals()) return;

            entry = entry === '0' ? key : entry + key;
        }

        show();
    }

    function apply() {
        if (! field) return;

        // Pressing OK on "15000 − 500" means 14500, not 500.
        resolve();

        const minimum = Number(field.dataset.numpadMin ?? 0);
        const value = Math.max(minimum, Number(entry === '' ? 0 : entry));

        field.value = decimals() > 0 ? value.toFixed(decimals()) : String(Math.round(value));

        // The carts listen for 'input', so this is what makes the line total,
        // the below-cost warning and the running total follow along.
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));

        instance.hide();
    }

    // Delegated, because cart rows are built after this runs.
    document.addEventListener('click', (event) => {
        const target = event.target.closest('input[data-numpad]');

        if (target && ! target.disabled && ! target.readOnly) {
            // Stop the field taking focus behind the modal, which would leave a
            // caret blinking on a page nobody can reach.
            event.preventDefault();
            target.blur();
            open(target);
        }
    });

    modal.addEventListener('click', (event) => {
        const button = event.target.closest('[data-pad]');

        if (button) press(button.dataset.pad);
    });

    document.getElementById('number-pad-ok').addEventListener('click', apply);

    // On the document rather than the modal: Bootstrap moves focus around while
    // showing, and a listener that only fires when focus happens to be inside
    // the dialog misses keystrokes. The open check is what scopes it.
    document.addEventListener('keydown', (event) => {
        if (! modal.classList.contains('show')) return;

        if (event.key >= '0' && event.key <= '9') {
            press(event.key);
        } else if (event.key === '.' || event.key === ',') {
            press('.');
        } else if (SIGNS[event.key]) {
            press(event.key);
        } else if (event.key === '=') {
            press('=');
        } else if (event.key === 'Backspace') {
            press('back');
        } else if (event.key === 'Delete') {
            press('clear');
        } else if (event.key === 'Enter') {
            apply();
        } else {
            return;
        }

        // Otherwise a digit would also "press" whichever pad button has focus.
        event.preventDefault();
    });

    modal.addEventListener('shown.bs.modal', () => document.getElementById('number-pad-ok').focus());

    // Back to the scanner, so the next barcode lands where it should.
    modal.addEventListener('hidden.bs.modal', () => {
        field = null;
        document.getElementById('product-search')?.focus();
    });
});
