/**
 * OwnPay Admin — Settings Page JS
 * Extracted from templates/admin/settings/index.twig
 * Handles: tab switching, URL hash navigation, maintenance mode toggle,
 *          FAQ dynamic add/remove, Feature card dynamic add/remove.
 */
(function () {
    "use strict";

    // ─── Tab Switching ────────────────────────────────────────────────────────
    document.querySelectorAll(".op-tab").forEach(function (t) {
        t.addEventListener("click", function () {
            document.querySelectorAll(".op-tab, .op-tab-panel").forEach(function (e) {
                e.classList.remove("active");
            });
            this.classList.add("active");
            var panel = document.getElementById("tab-" + this.dataset.tab);
            if (panel) {panel.classList.add("active");}
            var tabInput = document.getElementById("active-tab-input");
            if (tabInput) {tabInput.value = this.dataset.tab;}
            if (window.location.hash !== "#tab-" + this.dataset.tab) {
                history.replaceState(null, null, "#tab-" + this.dataset.tab);
            }
        });
    });

    // ─── Hash-based or POST-redirect tab activation ───────────────────────────
    var activeTabInput = document.getElementById("active-tab-input");
    var defaultTab = activeTabInput ? activeTabInput.value : "general";
    var targetTab = null;
    if (window.location.hash) {
        targetTab = window.location.hash.replace("#tab-", "");
    } else if (defaultTab && defaultTab !== "general") {
        targetTab = defaultTab;
    }
    if (targetTab) {
        var tab = document.querySelector('.op-tab[data-tab="' + targetTab + '"]');
        if (tab) {tab.click();}
    }

    // ─── Maintenance Mode Warning Toggle ─────────────────────────────────────
    var maintToggle = document.getElementById("maintenance-toggle");
    var maintWarn   = document.getElementById("maintenance-warning");
    if (maintToggle && maintWarn) {
        maintToggle.addEventListener("change", function () {
            maintWarn.style.display = this.checked ? "block" : "none";
            if (this.checked && !confirm("Enable maintenance mode? Public users will see a 503 page.")) {
                this.checked = false;
                maintWarn.style.display = "none";
            }
        });
    }

    // ─── FAQ Dynamic Add ─────────────────────────────────────────────────────
    var addFaqBtn = document.getElementById("add-faq");
    if (addFaqBtn) {
        var faqContainer = document.getElementById("faq-container");
        var faqIdx = faqContainer ? faqContainer.querySelectorAll(".op-faq-row").length : 0;
        addFaqBtn.addEventListener("click", function () {
            faqContainer.insertAdjacentHTML("beforeend",
                '<div class="op-faq-row op-card op-card-bordered op-mb-2 op-p-3">' +
                '<div class="op-form-group"><label>Question</label><input type="text" name="faqs[' + faqIdx + '][question]" class="op-input"></div>' +
                '<div class="op-form-group"><label>Answer</label><textarea name="faqs[' + faqIdx + '][answer]" rows="2" class="op-input"></textarea></div>' +
                '<button type="button" class="op-btn op-btn-sm op-btn-danger op-faq-remove">Remove</button>' +
                "</div>"
            );
            faqIdx++;
        });
        faqContainer.addEventListener("click", function (e) {
            if (e.target.classList.contains("op-faq-remove")) {
                e.target.closest(".op-faq-row").remove();
            }
        });
    }

    // ─── Feature Card Dynamic Add ─────────────────────────────────────────────
    var addFeatureBtn = document.getElementById("add-feature");
    if (addFeatureBtn) {
        var featContainer = document.getElementById("features-container");
        var featureIdx = featContainer ? featContainer.querySelectorAll(".op-faq-row").length : 0;
        addFeatureBtn.addEventListener("click", function () {
            featContainer.insertAdjacentHTML("beforeend",
                '<div class="op-faq-row op-card op-card-bordered op-mb-2 op-p-3">' +
                '<div class="op-form-row">' +
                '<div class="op-form-group op-col-6"><label>Title</label><input type="text" name="features[' + featureIdx + '][title]" class="op-input"></div>' +
                '<div class="op-form-group op-col-6"><label>Description</label><input type="text" name="features[' + featureIdx + '][description]" class="op-input"></div>' +
                "</div>" +
                '<button type="button" class="op-btn op-btn-sm op-btn-danger op-faq-remove">Remove</button>' +
                "</div>"
            );
            featureIdx++;
        });
        featContainer.addEventListener("click", function (e) {
            if (e.target.classList.contains("op-faq-remove")) {
                e.target.closest(".op-faq-row").remove();
            }
        });
    }

}());
