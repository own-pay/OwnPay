/**
 * OwnPay Admin - Domains Page JS
 * Handles: copy-to-clipboard for DNS records and populating the Edit Settings modal.
 */
(function () {
    "use strict";

    document.querySelectorAll(".op-copy-btn").forEach(function (btn) {
        btn.addEventListener("click", function (e) {
            e.stopPropagation();
            var el = document.getElementById(this.dataset.copy);
            if (!el) { return; }
            var self = this;
            window.opCopyText(el.textContent.trim(), self, function () {
                var orig = self.textContent;
                self.textContent = "✓ Copied!";
                self.classList.add("op-btn-success");
                setTimeout(function () { self.textContent = orig; self.classList.remove("op-btn-success"); }, 1800);
            });
        });
    });

    var toggleGuideBtn = document.getElementById("toggle-dns-guide-btn");
    if (toggleGuideBtn) {
        toggleGuideBtn.addEventListener("click", function () {
            var guide = document.querySelector(".op-dns-guide");
            if (guide) {
                guide.classList.toggle("op-hidden");
            }
        });
    }

    // Populate the Edit Settings modal dynamically
    document.querySelectorAll(".op-edit-domain-btn").forEach(function (btn) {
        btn.addEventListener("click", function (e) {
            e.stopPropagation();
            var id = this.dataset.id;
            var domain = this.dataset.domain;
            var type = this.dataset.type;
            var redirectUrl = this.dataset.redirectUrl;
            var status = this.dataset.status;
            var dnsVerified = this.dataset.dnsVerified;
            var isPrimary = this.dataset.isPrimary;

            var modal = document.getElementById("edit-domain-modal");
            if (modal) {
                var form = modal.querySelector("form");
                if (form) {
                    form.action = "/admin/domains/" + id + "/update";
                }

                var domainDisplay = modal.querySelector('input[name="domain_display"]');
                if (domainDisplay) {
                    domainDisplay.value = domain;
                }

                var typeInput = modal.querySelector('select[name="type"]');
                if (typeInput) {
                    typeInput.value = type;
                }

                var redirectInput = modal.querySelector('input[name="redirect_url"]');
                if (redirectInput) {
                    redirectInput.value = (redirectUrl === "null" || redirectUrl === null || !redirectUrl) ? "" : redirectUrl;
                }

                var statusInput = modal.querySelector('select[name="status"]');
                if (statusInput) {
                    statusInput.value = status;
                }

                var dnsVerifiedInput = modal.querySelector('select[name="dns_verified"]');
                if (dnsVerifiedInput) {
                    dnsVerifiedInput.value = dnsVerified;
                }

                var isPrimaryInput = modal.querySelector('select[name="is_primary"]');
                if (isPrimaryInput) {
                    isPrimaryInput.value = isPrimary;
                }
            }
        });
    });
}());
