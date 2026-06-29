/**
 * OwnPay Admin - Plugins Page JS
 * Categorized tab filtering, status dropdown filtering, and real-time local search.
 */
(function () {
    "use strict";

    var activeTab = "all";
    var activeStatus = "all";
    var searchQuery = "";

    var searchInput = document.getElementById("pluginSearch");
    var statusSelect = document.getElementById("statusFilter");
    var tabs = document.querySelectorAll(".op-tab");
    var cards = document.querySelectorAll(".plugin-row");

    function applyFilters() {
        cards.forEach(function (card) {
            var status = card.dataset.status;
            var type = card.dataset.type;

            var titleEl = card.querySelector(".op-plugin-card-title");
            var slugEl = card.querySelector(".op-plugin-card-slug");
            var descEl = card.querySelector(".op-plugin-card-desc");

            var name = titleEl ? titleEl.textContent.toLowerCase() : "";
            var slug = slugEl ? slugEl.textContent.toLowerCase() : "";
            var desc = descEl ? descEl.textContent.toLowerCase() : "";

            // 1. Tab / Type Filter
            var matchesTab = false;
            if (activeTab === "all") {
                matchesTab = (status !== "trashed");
            } else if (activeTab === "trash") {
                matchesTab = (status === "trashed");
            } else {
                // Type matches the tab name (gateway, addon/plugin, theme)
                if (activeTab === "addon") {
                    matchesTab = ((type === "addon" || type === "plugin") && status !== "trashed");
                } else {
                    matchesTab = (type === activeTab && status !== "trashed");
                }
            }

            // 2. Status Filter (Only applicable if not viewing Trash tab)
            var matchesStatus = false;
            if (activeTab === "trash") {
                matchesStatus = true; // Always show all items in trash regardless of status filter
            } else if (activeStatus === "all") {
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
            } else {
                card.style.display = "none";
            }
        });
    }

    // Tab Event Listeners
    tabs.forEach(function (tab) {
        tab.addEventListener("click", function () {
            tabs.forEach(function (t) { t.classList.remove("active"); });
            tab.classList.add("active");
            activeTab = tab.dataset.tab;

            // If selecting Trash, we hide the status dropdown to avoid confusion
            if (activeTab === "trash") {
                if (statusSelect) {
                    statusSelect.style.display = "none";
                }
            } else {
                if (statusSelect) {
                    statusSelect.style.display = "";
                }
            }

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
