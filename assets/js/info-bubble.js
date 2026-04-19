/**
 * AcountPay Pay by Bank - "How it works" info bubble.
 *
 * Toggles the popover when the (i) button is clicked, prevents the surrounding
 * <label> from selecting the radio, and closes on outside click / Escape.
 *
 * Used by the classic WooCommerce checkout. The Blocks checkout has its own
 * React handler in src/block.js.
 */
(function () {
    "use strict";

    function closeAll(except) {
        var open = document.querySelectorAll(".acountpay-info-wrap.is-open");
        for (var i = 0; i < open.length; i++) {
            if (open[i] !== except) {
                open[i].classList.remove("is-open");
                var btn = open[i].querySelector(".acountpay-info-bubble");
                if (btn) {
                    btn.setAttribute("aria-expanded", "false");
                }
            }
        }
    }

    function onBubbleClick(event) {
        var btn = event.target.closest(".acountpay-info-bubble");
        if (!btn) {
            return;
        }
        // Stop the click from bubbling to the surrounding <label>, which would
        // otherwise select the payment method radio.
        event.preventDefault();
        event.stopPropagation();

        var wrap = btn.closest(".acountpay-info-wrap");
        if (!wrap) {
            return;
        }
        var willOpen = !wrap.classList.contains("is-open");
        closeAll(willOpen ? wrap : null);
        wrap.classList.toggle("is-open", willOpen);
        btn.setAttribute("aria-expanded", willOpen ? "true" : "false");
    }

    function onDocClick(event) {
        if (event.target.closest(".acountpay-info-wrap")) {
            return;
        }
        closeAll(null);
    }

    function onKeydown(event) {
        if (event.key === "Escape" || event.key === "Esc") {
            closeAll(null);
        }
    }

    function init() {
        document.addEventListener("click", onBubbleClick, true);
        document.addEventListener("click", onDocClick);
        document.addEventListener("keydown", onKeydown);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
