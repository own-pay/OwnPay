/**
 * OwnPay Admin - Gateways Page JS
 * Categorized tab filtering, status dropdown filtering, and real-time local search.
 */
(function () {
    "use strict";

    var activeTab = "all";
    var activeStatus = "all";
    var searchQuery = "";

    var searchInput = document.getElementById("gateway-search");
    var statusSelect = document.getElementById("status-filter");
    var tabs = document.querySelectorAll(".op-tab");
    var cards = document.querySelectorAll(".op-card-item");

    // Compute and display counts on initial load
    var countAllEl = document.getElementById("count-all");
    var countApiEl = document.getElementById("count-api");
    var countManualEl = document.getElementById("count-manual");

    var allCount = cards.length;
    var apiCount = 0;
    var manualCount = 0;

    cards.forEach(function (card) {
        if (card.dataset.type === "api") {
            apiCount++;
        } else if (card.dataset.type === "manual") {
            manualCount++;
        }
    });

    if (countAllEl) {
        countAllEl.textContent = String(allCount);
    }
    if (countApiEl) {
        countApiEl.textContent = String(apiCount);
    }
    if (countManualEl) {
        countManualEl.textContent = String(manualCount);
    }

    function applyFilters() {
        var visibleCount = 0;
        cards.forEach(function (card) {
            var type = card.dataset.type;
            var status = card.dataset.status;
            var name = card.dataset.name || "";
            var slug = card.dataset.slug || "";
            var desc = card.dataset.desc || "";

            // 1. Tab / Type Filter
            var matchesTab = false;
            if (activeTab === "all") {
                matchesTab = true;
            } else {
                matchesTab = (type === activeTab);
            }

            // 2. Status Filter
            var matchesStatus = false;
            if (activeStatus === "all") {
                matchesStatus = true;
            } else if (activeStatus === "active") {
                matchesStatus = (status === "active");
            } else if (activeStatus === "inactive") {
                matchesStatus = (status === "inactive");
            } else if (activeStatus === "uninstalled") {
                matchesStatus = (status === "uninstalled");
            }

            // 3. Search Filter
            var matchesSearch = true;
            if (searchQuery !== "") {
                matchesSearch = name.includes(searchQuery) || slug.includes(searchQuery) || desc.includes(searchQuery);
            }

            // Toggle visibility
            if (matchesTab && matchesStatus && matchesSearch) {
                card.style.display = "";
                visibleCount++;
            } else {
                card.style.display = "none";
            }
        });

        // Toggle Empty State
        var emptyEl = document.getElementById("gateway-empty");
        if (emptyEl) {
            emptyEl.style.display = (visibleCount === 0) ? "" : "none";
        }
    }

    // Tab Event Listeners
    tabs.forEach(function (tab) {
        tab.addEventListener("click", function () {
            tabs.forEach(function (t) { t.classList.remove("active"); });
            tab.classList.add("active");
            activeTab = tab.dataset.tab;

            applyFilters();
        });
    });

    // Status Select Listener
    if (statusSelect) {
        statusSelect.addEventListener("change", function (e) {
            activeStatus = e.target.value;
            applyFilters();
        });
    }

    // Search Input Listener
    if (searchInput) {
        searchInput.addEventListener("input", function (e) {
            searchQuery = e.target.value.toLowerCase().trim();
            applyFilters();
        });
    }

    // Run initial filter check
    applyFilters();
}());
