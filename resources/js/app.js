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
