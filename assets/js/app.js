/**
 * Enterprise CRM ERP — shared front-end behavior.
 * Vanilla JS (no jQuery dependency here) for the auth pages; jQuery/AJAX
 * wiring for DataTables/Chart.js-driven modules will be added as those
 * modules are built.
 */
document.addEventListener('DOMContentLoaded', function () {
    // Auto-dismiss flash alerts after 6 seconds so they don't linger on
    // small screens and crowd out the form below them.
    document.querySelectorAll('.alert').forEach(function (alert) {
        setTimeout(function () {
            alert.style.transition = 'opacity 0.4s ease';
            alert.style.opacity = '0';
            setTimeout(function () { alert.remove(); }, 400);
        }, 6000);
    });

    // Password visibility toggle: any input[type=password] with a sibling
    // element carrying [data-toggle-password] flips between hidden/shown.
    document.querySelectorAll('[data-toggle-password]').forEach(function (toggle) {
        toggle.addEventListener('click', function () {
            var targetId = toggle.getAttribute('data-toggle-password');
            var input = document.getElementById(targetId);
            if (!input) {
                return;
            }
            var isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            toggle.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            toggle.classList.toggle('bi-eye-slash', isHidden);
            toggle.classList.toggle('bi-eye', !isHidden);
        });
    });

    // OTP input: auto-advance focus and submit-ready feel on mobile numeric
    // keypads (digits only, auto-select on focus for easy re-entry).
    var otpInput = document.getElementById('otp');
    if (otpInput) {
        otpInput.addEventListener('input', function () {
            otpInput.value = otpInput.value.replace(/\D/g, '').slice(0, 6);
        });
        otpInput.addEventListener('focus', function () {
            otpInput.select();
        });
    }
});

/**
 * Small helper other modules can reuse for AJAX calls: reads the CSRF
 * token already rendered in the page's hidden field and attaches it as
 * a header, so future fetch()/$.ajax() calls stay CSRF-protected.
 */
function getCsrfToken() {
    var field = document.querySelector('input[name="_csrf_token"]');
    return field ? field.value : null;
}

// Global confirm-before-submit for any "Delete" form across the app —
// row action dropdowns (Clients/Leads/Projects/Billing/Employees/etc.)
// just need class="js-confirm-delete" on the <form>, no per-page script.
document.addEventListener('submit', function (e) {
    if (e.target.matches('.js-confirm-delete')) {
        if (!confirm('Delete this? This cannot be undone.')) {
            e.preventDefault();
        }
    }
});

/**
 * Click-and-drag horizontal scrolling for wide tables (Employees, Leads,
 * etc.). Wide tables sit inside Bootstrap's .table-responsive, which only
 * scrolls via the thin native scrollbar — fiddly on a desktop mouse. This
 * lets people click anywhere on the table and drag it left/right instead,
 * the same way a map or gallery drags.
 *
 * Only tables that actually overflow get the grab cursor / drag behavior;
 * a table that already fits its card is left alone. Re-checked on resize
 * since a table can gain/lose overflow when the viewport changes.
 */
(function () {
    function isInteractive(el) {
        return !!el.closest('a, button, input, select, textarea, label, .dropdown-menu, [role="button"]');
    }

    function setupDragScroll(container) {
        var isDown = false;
        var startX = 0;
        var startScrollLeft = 0;
        var didDrag = false;

        function refreshOverflowState() {
            var overflows = container.scrollWidth > container.clientWidth + 1;
            container.classList.toggle('is-drag-scrollable', overflows);
            if (!overflows) {
                container.classList.remove('is-drag-scrolling');
            }
        }

        refreshOverflowState();
        window.addEventListener('resize', refreshOverflowState);

        container.addEventListener('mousedown', function (e) {
            if (!container.classList.contains('is-drag-scrollable') || isInteractive(e.target)) {
                return;
            }
            isDown = true;
            didDrag = false;
            startX = e.pageX;
            startScrollLeft = container.scrollLeft;
            container.classList.add('is-drag-scrolling');
        });

        ['mouseleave', 'mouseup'].forEach(function (evt) {
            container.addEventListener(evt, function () {
                isDown = false;
                container.classList.remove('is-drag-scrolling');
            });
        });

        container.addEventListener('mousemove', function (e) {
            if (!isDown) {
                return;
            }
            var delta = e.pageX - startX;
            if (Math.abs(delta) > 3) {
                didDrag = true;
            }
            container.scrollLeft = startScrollLeft - delta;
        });

        // If the mouse genuinely dragged, swallow the click that follows so
        // a drag-release over a row link/button doesn't also trigger it.
        container.addEventListener('click', function (e) {
            if (didDrag) {
                e.preventDefault();
                e.stopPropagation();
                didDrag = false;
            }
        }, true);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.table-responsive').forEach(setupDragScroll);
    });

    // Action-menu dropdowns (View/Edit/Delete) live inside .table-responsive,
    // which needs overflow-x:auto for horizontal scrolling — that clips the
    // dropdown-menu whenever it would open past the table's edge. Toggling
    // the wrapper's overflow to fix that (an earlier approach) caused a
    // worse bug: on a horizontally-scrolled table it snapped the scroll
    // position back the instant the menu opened, moving the button out
    // from under the click. Instead, while a table-based dropdown is open,
    // detach its menu to <body> and position it with fixed coordinates
    // computed from the toggle button's actual on-screen position — this
    // never touches the table's scroll/overflow at all.
    document.addEventListener('show.bs.dropdown', function (e) {
        var toggle = e.target;
        if (!toggle || !toggle.closest('.table-responsive')) {
            return;
        }
        var menu = toggle.parentElement && toggle.parentElement.querySelector('.dropdown-menu');
        if (!menu) {
            return;
        }

        var originalParent = menu.parentElement;
        var originalNext = menu.nextSibling;
        document.body.appendChild(menu);
        menu.classList.add('dropdown-menu-detached');

        var reposition = function () {
            var rect = toggle.getBoundingClientRect();
            var menuWidth = menu.offsetWidth || 160;
            var menuHeight = menu.offsetHeight || 0;
            var alignEnd = menu.classList.contains('dropdown-menu-end');
            var left = alignEnd ? rect.right - menuWidth : rect.left;
            left = Math.max(8, Math.min(left, document.documentElement.clientWidth - menuWidth - 8));
            var top = rect.bottom + 4;
            if (top + menuHeight > window.innerHeight - 8 && rect.top - menuHeight - 4 > 8) {
                top = rect.top - menuHeight - 4;
            }
            menu.style.position = 'fixed';
            menu.style.top = top + 'px';
            menu.style.left = left + 'px';
            menu.style.right = 'auto';
            menu.style.margin = '0';
            menu.style.zIndex = 1080;
        };
        reposition();
        toggle.__dropdownReposition = reposition;

        toggle.__dropdownCleanup = function () {
            menu.classList.remove('dropdown-menu-detached');
            menu.style.position = '';
            menu.style.top = '';
            menu.style.left = '';
            menu.style.right = '';
            menu.style.margin = '';
            menu.style.zIndex = '';
            if (originalNext) {
                originalParent.insertBefore(menu, originalNext);
            } else {
                originalParent.appendChild(menu);
            }
        };
    });
    // At 'show.bs.dropdown' time Bootstrap hasn't made the menu visible yet
    // (the .show class is added right after this event), so offsetHeight
    // reads 0 and the "flip above if not enough room below" check above
    // never triggers — exactly the case for rows near the bottom of the
    // page, like the last row in a table. Once 'shown.bs.dropdown' fires
    // the menu is actually rendered, so re-run the same positioning logic
    // with its real measured height.
    document.addEventListener('shown.bs.dropdown', function (e) {
        var toggle = e.target;
        if (toggle && toggle.__dropdownReposition) {
            toggle.__dropdownReposition();
        }
    });
    document.addEventListener('hidden.bs.dropdown', function (e) {
        var toggle = e.target;
        if (toggle && toggle.__dropdownCleanup) {
            toggle.__dropdownCleanup();
            delete toggle.__dropdownCleanup;
        }
        if (toggle && toggle.__dropdownReposition) {
            delete toggle.__dropdownReposition;
        }
    });
})();

