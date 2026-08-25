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

    /**
     * The next box a person would fill in, in reading order.
     *
     * Anything hidden, disabled or read-only is skipped, as is anything inside
     * the pad itself. Buttons are not offered: the end of the last field is the
     * end of the run, and saving is a decision, not the next keystroke.
     */
    function nextField(from) {
        const fields = [...document.querySelectorAll(
            'form input:not([type=hidden]):not([type=checkbox]):not([type=radio]), form select, form textarea'
        )].filter((el) =>
            ! el.disabled && ! el.readOnly && ! modal.contains(el) && el.offsetParent !== null
        );

        const at = fields.indexOf(from);

        return at === -1 ? null : (fields[at + 1] ?? null);
    }

    function apply() {
        if (! field) return;

        // Pressing OK on "15000 − 500" means 14500, not 500.
        resolve();

        // Kept, because `field` is cleared before the modal finishes hiding.
        const target = field;

        const minimum = Number(target.dataset.numpadMin ?? 0);
        const value = Math.max(minimum, Number(entry === '' ? 0 : entry));

        target.value = decimals() > 0 ? value.toFixed(decimals()) : String(Math.round(value));

        // The carts listen for 'input', so this is what makes the line total,
        // the below-cost warning and the running total follow along.
        target.dispatchEvent(new Event('input', { bubbles: true }));
        target.dispatchEvent(new Event('change', { bubbles: true }));

        // A price and a quantity are entered one after the other, so the run
        // carries on by itself: the next box takes the caret, and if it is a box
        // the pad belongs on, the pad opens on it. A price, a quantity, the next
        // line's quantity — the whole cart without reaching for the mouse.
        //
        // Bootstrap moves focus about while it hides, so this waits until it has
        // finished; and the pad cannot be reopened until it has fully closed.
        modal.addEventListener('hidden.bs.modal', () => {
            const next = nextField(target);

            if (! next) return;

            if (next.dataset.numpad !== undefined) {
                open(next);

                return;
            }

            next.focus();

            // Selected, not just focused: the next figure replaces the old one
            // rather than being typed onto the end of it.
            if (typeof next.select === 'function') next.select();
        }, { once: true });

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

/**
 * The way back.
 *
 * The button is the browser's own Back with a name on it. Whatever page the
 * reader came from is where it goes: opening a payment from the payments list
 * and then the invoice it settled, the button on that invoice reads "PAY-00008"
 * and returns there, not to the sales history.
 *
 * Which page that is comes from the history itself, not from document.referrer.
 * A referrer is a hint the browser is free to trim or withhold — and when it
 * was missing, the button quietly fell back to the list, which is the one thing
 * it must not do. Instead each history entry is stamped with its depth in
 * history.state, and the URL at each depth is kept in sessionStorage: per tab,
 * because this is where *this* tab has been. The entry one step down is the
 * page behind this one, exactly as the browser counts it, and history.back()
 * goes there — so the history does not grow and the browser puts the scroll
 * back itself.
 *
 * Only when there is no entry behind this one — a bookmark, a typed URL, a link
 * from outside — does the button fall back to what the server wrote: the list
 * this screen belongs to, as the reader last had it, filters and all.
 *
 * That href in the markup is a real route, so the button works with this script
 * disabled and can be opened in a new tab.
 */
(() => {
    const PAGES = 'nav:pages';
    const STACK = 'nav:stack';
    const DEPTH = 'nav:depth';
    const RESTORE = 'nav:restore';
    const SIDEBAR = 'nav:sidebar';
    const LIMIT = 40;

    // Private windows and browsers with site data blocked throw rather than
    // return null. Losing the way back is not worth an error.
    const read = (key) => {
        try {
            return sessionStorage.getItem(key);
        } catch {
            return null;
        }
    };

    const write = (key, value) => {
        try {
            sessionStorage.setItem(key, value);
        } catch {
            /* ignore */
        }
    };

    const forget = (key) => {
        try {
            sessionStorage.removeItem(key);
        } catch {
            /* ignore */
        }
    };

    const parse = (key, fallback) => {
        try {
            return JSON.parse(read(key) ?? '') ?? fallback;
        } catch {
            return fallback;
        }
    };

    const here = () => location.pathname + location.search;

    const segment = (path) => path.split('/').filter(Boolean)[0] ?? '';

    // A list page is a single segment: /sales, but not /sales/2 or /sales/create.
    const isListPage = () => location.pathname.split('/').filter(Boolean).length === 1;

    /** A link's destination, spelled the way here() spells a page. */
    const target = (link) => {
        try {
            const url = new URL(link.href, location.origin);

            return url.origin === location.origin ? url.pathname + url.search : '';
        } catch {
            return '';
        }
    };

    /**
     * Remember something about a page, keeping the most recent LIMIT of them.
     * Unbounded, this would grow for as long as the tab stays open.
     */
    const remember = (url, patch) => {
        const all = parse(PAGES, {});
        const entry = { ...(all[url] ?? {}), ...patch };

        delete all[url];
        all[url] = entry;

        const urls = Object.keys(all);
        urls.slice(0, Math.max(0, urls.length - LIMIT)).forEach((old) => delete all[old]);

        write(PAGES, JSON.stringify(all));
    };

    /**
     * Where this page sits in the tab's history, and what came before it.
     *
     * A fresh navigation is one whose entry carries no depth yet: it is stamped
     * with the next one down, and anything the reader had gone forward to is
     * dropped, because the browser has just dropped it too. Coming back to an
     * entry, its depth is already on it — which is what makes this survive a
     * reload, where a referrer would not.
     */
    const place = (url) => {
        const stamped = history.state?.navDepth;
        let stack = parse(STACK, []);
        let depth;

        if (typeof stamped === 'number') {
            depth = stamped;
        } else {
            depth = Number(read(DEPTH) ?? 0) + 1;

            try {
                history.replaceState({ ...history.state, navDepth: depth }, '');
            } catch {
                /* ignore */
            }

            stack.length = depth;
        }

        stack[depth] = url;
        write(STACK, JSON.stringify(stack));
        write(DEPTH, String(depth));

        if (depth <= 1) {
            // The first page of the tab: nothing of ours behind it.
            return null;
        }

        // A circle: here, away, and back again by a link rather than by going
        // back. Reading an invoice, opening one of its payments, then following
        // that payment's own link to the invoice leaves the entry behind this
        // one pointing at the payment the reader has just left. What they want
        // is where the invoice led from before — the sales history — so the
        // step before the circle is handed back instead.
        //
        // Two steps, and only two. Any page visited earlier in the tab would
        // reach back into an excursion that has nothing to do with this one:
        // arriving at an invoice from a product, having read that invoice half
        // an hour ago, must lead back to the product.
        if (depth > 2 && stack[depth - 2] === url) {
            return { url: stack[depth - 3] ?? null, looped: true };
        }

        return { url: stack[depth - 1] ?? null, looped: false };
    };

    /**
     * Put the page back where it was.
     *
     * Only needed for the fallback, since the browser restores the scroll on its
     * own Back. One attempt is not enough: on the first frame the table is laid
     * out but the web font has not swapped in and the images have no height yet,
     * so the document is shorter than it will be and the browser clamps the
     * scroll to whatever fits — landing tens of pixels above the row the reader
     * left. So the position is re-applied as the page grows, and abandoned the
     * moment the reader scrolls for themselves, because then they have said
     * where they want to be and it is not our business any more.
     */
    const restoreScroll = (y) => {
        let settled = false;
        let observer = null;

        const stop = () => {
            settled = true;
            observer?.disconnect();
        };

        ['wheel', 'touchstart', 'keydown'].forEach(
            (event) => addEventListener(event, stop, { once: true, passive: true })
        );

        const apply = () => {
            if (settled) {
                return;
            }

            window.scrollTo(0, y);

            if (Math.abs(window.scrollY - y) <= 1) {
                stop();
            }
        };

        requestAnimationFrame(apply);

        // Watching the document's height rather than guessing at a delay: it is
        // the growing that clamps the scroll, so re-apply each time it grows and
        // stop the moment the target is reachable.
        if (typeof ResizeObserver === 'function') {
            observer = new ResizeObserver(apply);
            observer.observe(document.documentElement);

            // Nothing should still be settling after this, and an observer left
            // running would fight the reader on every later reflow.
            setTimeout(stop, 2000);
        }
    };

    document.addEventListener('DOMContentLoaded', () => {
        const url = here();
        const behind = place(url);
        const previous = behind?.url ?? null;
        const known = parse(PAGES, {});

        // Arriving from the sidebar is moving sideways, not going into
        // something: the reader chose a section rather than opening a thing, so
        // there is nothing to come back out of and no button for it.
        const fromSidebar = read(SIDEBAR) === url;

        if (fromSidebar) {
            forget(SIDEBAR);
        }

        // Remembered on the way out rather than worked out on the way in, since
        // only the click itself knows it came from the sidebar.
        document.querySelectorAll('.app-sidebar a[href]').forEach((link) => {
            link.addEventListener('click', () => write(SIDEBAR, target(link)));
        });

        // The heading is what this page is called, and is what the button on
        // the next page will say when it offers the way back here.
        remember(url, { title: document.querySelector('main h1')?.textContent.trim() });

        if (isListPage()) {
            // Which URL this list was last seen at — the search, the filters and
            // the page number the reader had open.
            write(`list:${segment(location.pathname)}`, url);
        }

        // pagehide rather than beforeunload: it also fires when the page is
        // frozen into the back/forward cache, which beforeunload does not.
        addEventListener('pagehide', () => remember(url, { y: window.scrollY }));

        // Only when this page was reached by the button below, so a fresh visit
        // to a list still starts at the top.
        if (read(RESTORE) === url) {
            forget(RESTORE);

            const y = known[url]?.y;

            if (Number.isFinite(y) && y > 0) {
                restoreScroll(y);
            }
        }

        // A document's own page cannot be gone back to once it is deleted, so
        // the forms that delete one carry the page behind it and the server
        // sends the reader there instead of guessing at a list.
        document.querySelectorAll('input[data-return-to]').forEach((input) => {
            input.value = previous ?? '';
        });

        document.querySelectorAll('a.back-link').forEach((link) => {
            const label = link.querySelector('span');

            if (fromSidebar) {
                // Nothing to come back out of. Hidden even where the page names
                // a list of its own, because the reader did not come through it.
                link.classList.add('d-none');

                return;
            }

            // An unnamed link needs a page this script has actually seen the
            // reader on. Signing in is not one of those: the dashboard is where
            // the day starts, not somewhere to come back out of.
            if (link.hasAttribute('data-back-auto') && ! known[previous]?.title) {
                return;
            }

            if (previous) {
                // The page behind this one, named after itself. Without a name
                // the button still goes there — where it goes is the promise,
                // and the name is only how it is kept.
                link.setAttribute('href', previous);
                label.textContent = known[previous]?.title || link.dataset.backGeneric || label.textContent;
                // The browser's own Back only lands there when it really is the
                // entry behind this one. After a circle it is not — that entry
                // is the page the reader just came from — so this walks out
                // through the door it came in by instead.
                link.dataset.backHistory = behind.looped ? '' : '1';

                // A link with no destination of its own waits until it has one.
                link.classList.remove('d-none');
            } else if (link.dataset.backTo) {
                // Nothing behind this page, so the list it belongs to, as the
                // reader last had it.
                const remembered = read(`list:${link.dataset.backTo}`);

                if (remembered && remembered.startsWith(`/${link.dataset.backTo}`)) {
                    link.setAttribute('href', remembered);
                }
            }

            link.addEventListener('click', (event) => {
                // Leave every deliberate "open this elsewhere" alone.
                if (event.button !== 0 || event.metaKey || event.ctrlKey
                    || event.shiftKey || event.altKey) {
                    return;
                }

                if (link.dataset.backHistory === '1') {
                    event.preventDefault();
                    history.back();

                    return;
                }

                // A plain navigation, so this script has to put the scroll back.
                write(RESTORE, target(link));
            });
        });
    });
})();

/**
 * The wall clock in the topbar.
 *
 * The machine's own time rather than the server's, because it sits beside a
 * real clock on a real wall and has to agree with it. Twelve hours with am/pm,
 * which is how the shop reads the time, and Latin digits whatever the interface
 * language is, since the rest of the system writes its numbers that way.
 */
document.addEventListener('DOMContentLoaded', () => {
    const time = document.getElementById('app-clock-time');
    const date = document.getElementById('app-clock-date');

    if (! time) {
        return;
    }

    const tick = () => {
        const now = new Date();

        time.textContent = now.toLocaleTimeString('en-US', {
            hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true,
        });

        date.textContent = now.toLocaleDateString('en-GB', {
            weekday: 'short', day: '2-digit', month: 'short', year: 'numeric',
        });
    };

    tick();
    setInterval(tick, 1000);
});

/**
 * The search box in the topbar.
 *
 * The same shape as the cart's product lookup, over the whole shop: type, wait
 * for the typing to stop, ask the server, show what came back grouped by what
 * it is. The server decides what a reader may see, so nothing here filters
 * anything — it draws what it is given.
 *
 * Keyboard first, because the person using it has one hand on a barcode scanner:
 * Ctrl+K from anywhere, arrows to move, Enter to open, Escape to leave.
 */
document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('app-search');
    const panel = document.getElementById('app-search-results');

    if (! input || ! panel) {
        return;
    }

    let items = [];
    let active = -1;
    let pending = null;
    let lastTerm = '';

    const close = () => {
        panel.classList.remove('show');
        input.setAttribute('aria-expanded', 'false');
        active = -1;
    };

    const open = () => {
        panel.classList.add('show');
        input.setAttribute('aria-expanded', 'true');
    };

    const highlight = () => {
        items.forEach((item, index) => item.classList.toggle('active', index === active));
        items[active]?.scrollIntoView({ block: 'nearest' });
    };

    const draw = (groups) => {
        panel.innerHTML = '';
        items = [];
        active = -1;

        if (! groups.length) {
            panel.innerHTML = `<div class="px-3 py-2 text-secondary small">${panel.dataset.empty}</div>`;
            open();

            return;
        }

        groups.forEach((group) => {
            const heading = document.createElement('div');
            heading.className = 'app-search-heading px-3 pt-2 pb-1 small text-secondary';
            heading.textContent = group.label;
            panel.appendChild(heading);

            group.items.forEach((entry) => {
                const row = document.createElement('a');
                row.className = 'dropdown-item d-flex align-items-center gap-2 py-2';
                row.href = entry.url;
                row.setAttribute('role', 'option');
                row.innerHTML = `
                    <i class="bi bi-${entry.icon} text-secondary"></i>
                    <span class="flex-grow-1 min-w-0">
                        <span class="d-block text-truncate">${entry.label}</span>
                        ${entry.note ? `<span class="d-block small text-secondary text-truncate">${entry.note}</span>` : ''}
                    </span>`;
                panel.appendChild(row);
                items.push(row);
            });
        });

        open();
    };

    const search = async (term) => {
        const response = await fetch(
            `${panel.dataset.url}?q=${encodeURIComponent(term)}`,
            { headers: { Accept: 'application/json' } },
        );

        if (! response.ok) {
            return;
        }

        const data = await response.json();

        // A slow answer to an old term must not replace a newer one.
        if (term === lastTerm) {
            draw(data.groups);
        }
    };

    input.addEventListener('input', () => {
        const term = input.value.trim();
        lastTerm = term;
        clearTimeout(pending);

        if (term.length < 2) {
            close();

            return;
        }

        pending = setTimeout(() => search(term), 180);
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            close();
            input.blur();

            return;
        }

        if (! panel.classList.contains('show') || ! items.length) {
            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            active = event.key === 'ArrowDown'
                ? (active + 1) % items.length
                : (active <= 0 ? items.length : active) - 1;
            highlight();
        }

        if (event.key === 'Enter') {
            // Nothing chosen yet: the first result is what the reader meant.
            event.preventDefault();
            (items[active] ?? items[0]).click();
        }
    });

    input.addEventListener('focus', () => {
        if (items.length) {
            open();
        }
    });

    document.addEventListener('click', (event) => {
        if (! input.contains(event.target) && ! panel.contains(event.target)) {
            close();
        }
    });

    // Ctrl+K, or / with nothing else focused — a hand already on the keyboard
    // should not have to find the mouse.
    document.addEventListener('keydown', (event) => {
        const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName ?? '');

        if ((event.key === 'k' && (event.ctrlKey || event.metaKey)) || (event.key === '/' && ! typing)) {
            event.preventDefault();
            input.focus();
            input.select();
        }
    });
});

/**
 * Hold to save (Section 9b).
 *
 * A barcode scanner types the code and then presses Enter. Scanning the same
 * item twice used to submit the product form on the second Enter, and so did
 * Enter pressed on the reorder level or any other box — a half-finished product
 * saved by a keystroke nobody meant as an instruction.
 *
 * So the Save button has to be held down for three seconds. Let go early and it
 * counts for nothing; the fill runs back and the count starts again next time.
 *
 * The release is listened for on the window rather than on the button, and that
 * is the whole trick. A release only reaches the button if the pointer is still
 * over it and the element under it still exists — and neither is safe to assume,
 * since the label changes while the hold runs and a finger or mouse drifts. A
 * missed release leaves the count running after the shopkeeper has let go, and
 * the form saves itself three seconds later. The window sees every release.
 *
 * The markup ships as an ordinary submit button and is demoted here. If this
 * script never arrives — a stale build, a blocked file — the shopkeeper gets a
 * Save button that works rather than a form that cannot be saved at all.
 */
document.addEventListener('DOMContentLoaded', () => {
    const HOLD_MS = 3000;

    document.querySelectorAll('[data-hold-submit]').forEach((button) => {
        const form = button.closest('form');

        if (! form) return;

        button.type = 'button';

        // Only this element's text changes while the hold runs. The icon and
        // every wrapper stay put, so whatever the pointer is resting on is
        // still there when it is lifted.
        const text = button.querySelector('.btn-hold-text');
        const fill = button.querySelector('.btn-hold-fill');
        const resting = text?.textContent ?? '';
        const holdingWord = button.dataset.holdHolding ?? 'Keep holding…';

        let frame = null;
        let startedAt = 0;
        let holding = false;
        let saving = false;

        const say = (words) => { if (text) text.textContent = words; };

        const paint = (fraction) => {
            if (fill) fill.style.width = `${Math.round(fraction * 100)}%`;
        };

        const stop = () => {
            if (! holding) return;

            holding = false;

            if (frame) cancelAnimationFrame(frame);

            frame = null;
            startedAt = 0;
            paint(0);
            button.classList.remove('is-holding');
            say(resting);
        };

        const finish = () => {
            holding = false;
            frame = null;

            // Validation first, and asked rather than assumed: a required box
            // still empty must leave the button usable, not stranded saying
            // "Saving…" over a form that never went anywhere.
            if (typeof form.reportValidity === 'function' && ! form.reportValidity()) {
                paint(0);
                button.classList.remove('is-holding');
                say(resting);

                // Held for three seconds and nothing happened reads as a broken
                // button, not as a missing field — so the field that stopped it
                // is brought on screen and asked to say why.
                const missing = form.querySelector('input:invalid, select:invalid, textarea:invalid');

                if (missing) {
                    missing.scrollIntoView({ block: 'center', behavior: 'smooth' });
                    missing.focus();
                    form.reportValidity();
                }

                return;
            }

            saving = true;
            button.disabled = true;
            paint(1);
            say(button.dataset.holdDone ?? 'Saving…');

            if (typeof form.requestSubmit === 'function') form.requestSubmit();
            else form.submit();
        };

        const tick = () => {
            // Checked every frame as well as on release: if a release is ever
            // missed the count still cannot outlive the press by more than one
            // frame, because nothing else sets this back to true.
            if (! holding) return;

            const elapsed = Date.now() - startedAt;

            if (elapsed >= HOLD_MS) return finish();

            paint(elapsed / HOLD_MS);

            // Counted down out loud, so three seconds reads as three seconds
            // rather than as a button that has not responded yet.
            say(`${holdingWord} ${Math.ceil((HOLD_MS - elapsed) / 1000)}`);

            frame = requestAnimationFrame(tick);
        };

        const begin = (event) => {
            if (saving || holding) return;

            // Left button only: a right-click opens a menu, and the release
            // that closes it is not a release of this button.
            if (event.button !== undefined && event.button !== 0) return;

            // Keeps the pointer stream on this button even if the finger or the
            // mouse drifts off it while pressed.
            if (event.pointerId !== undefined && button.setPointerCapture) {
                try { button.setPointerCapture(event.pointerId); } catch { /* not fatal */ }
            }

            holding = true;
            startedAt = Date.now();
            button.classList.add('is-holding');
            frame = requestAnimationFrame(tick);
        };

        button.addEventListener('pointerdown', begin);

        // On the window, and in the capture phase, so nothing can swallow it
        // before it arrives. Every way a press can end is listened for.
        ['pointerup', 'pointercancel', 'mouseup', 'touchend', 'touchcancel', 'dragstart', 'contextmenu']
            .forEach((name) => window.addEventListener(name, stop, true));

        // Alt-tabbing away, or the tab going to the background, is letting go.
        window.addEventListener('blur', stop);
        document.addEventListener('visibilitychange', () => { if (document.hidden) stop(); });

        // The keyboard holds it too: Space or Enter while the button has focus.
        // A scanner's Enter is a tap, so it dies on the keyup milliseconds later.
        button.addEventListener('keydown', (event) => {
            // Not event.repeat: a held key repeats, and each repeat would
            // otherwise restart a hold that is already running.
            if ((event.key === ' ' || event.key === 'Enter') && ! event.repeat) {
                event.preventDefault();
                begin(event);
            }
        });

        window.addEventListener('keyup', (event) => {
            if (event.key === ' ' || event.key === 'Enter') stop();
        }, true);

        /**
         * The same protection for the keyboard, stated rather than relied upon.
         *
         * A form whose only button is a hold button has no submit control, so
         * most browsers will not submit it on Enter anyway. This says so out
         * loud, and covers the one-field case where a browser still would.
         */
        if (! form.dataset.holdGuarded) {
            form.dataset.holdGuarded = '1';

            form.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter') return;

                // A textarea is somewhere Enter means a new line, and a button
                // is somewhere it means press me. Elsewhere it means nothing.
                const tag = event.target.tagName;

                if (tag === 'TEXTAREA' || tag === 'BUTTON' || tag === 'A') return;

                event.preventDefault();

                // A scanner ends every read with Enter. Selecting the box it
                // just filled means a second read of the same label replaces
                // the code rather than being typed onto the end of it, which
                // is how one scan too many used to become a 26-digit barcode.
                if (event.target.dataset?.rescan !== undefined
                    && typeof event.target.select === 'function') {
                    event.target.select();
                }
            });
        }
    });
});
