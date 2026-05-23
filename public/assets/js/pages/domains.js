/**
 * OwnPay Admin — Domains Page JS
 * Extracted from templates/admin/domains/index.twig
 * Handles: copy-to-clipboard for DNS records.
 */
(function () {
    "use strict";
    document.querySelectorAll(".op-copy-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var el = document.getElementById(this.dataset.copy);
            if (!el) {return;}
            var self = this;
            navigator.clipboard.writeText(el.textContent.trim()).then(function () {
                var orig = self.textContent;
                self.textContent = "✓ Copied!";
                self.classList.add("op-btn-success");
                setTimeout(function () { self.textContent = orig; self.classList.remove("op-btn-success"); }, 1800);
            });
        });
    });
}());
