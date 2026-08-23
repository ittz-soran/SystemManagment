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
