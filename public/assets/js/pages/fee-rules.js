/**
 * OwnPay Admin - Fee Rules JS
 * Handles: type toggle (simple vs tiered), dynamic tiered rows add/remove.
 */
(function () {
    "use strict";

    function run() {
        var typeSelect = document.getElementById("type");
        var simpleValueGroup = document.getElementById("simple-value-group");
        var tieredSetupCard = document.getElementById("tiered-setup-card");
        var tiersContainer = document.getElementById("tiers-container");
        var tierTemplate = document.getElementById("tier-row-template");
        var addTierBtn = document.getElementById("add-tier-btn");

        // Keep track of index for tier row names
        var tierIdx = tiersContainer ? tiersContainer.querySelectorAll(".op-tier-row").length : 0;

        // --- Type Toggle Function ----------------------------------------------
        function toggleType(type) {
            if (type === "tiered") {
                if (simpleValueGroup) {
                    simpleValueGroup.style.display = "none";
                }
                if (tieredSetupCard) {
                    tieredSetupCard.style.display = "";
                }

                // Add a default first row if empty
                if (tiersContainer && tiersContainer.children.length === 0) {
                    addTierRow();
                }
            } else {
                if (simpleValueGroup) {
                    simpleValueGroup.style.display = "";
                }
                if (tieredSetupCard) {
                    tieredSetupCard.style.display = "none";
                }
            }
        }

        // --- Add Tier Row Function ---------------------------------------------
        function addTierRow() {
            if (!tiersContainer || !tierTemplate) {
                return;
            }
            var html = tierTemplate.innerHTML.replace(/__IDX__/g, tierIdx++);
            var div = document.createElement("div");
            div.innerHTML = html;
            tiersContainer.appendChild(div.firstElementChild);
        }

        // Initialize display state on page load
        if (typeSelect) {
            toggleType(typeSelect.value);

            typeSelect.addEventListener("change", function () {
                toggleType(this.value);
            });
        }

        // Add Tier button click
        if (addTierBtn) {
            addTierBtn.addEventListener("click", function () {
                addTierRow();
            });
        }

        // Remove Tier delegated click handler
        if (tiersContainer) {
            tiersContainer.addEventListener("click", function (e) {
                if (e.target.classList.contains("remove-tier-btn")) {
                    var row = e.target.closest(".op-tier-row");
                    if (row) {
                        row.remove();
                    }
                }
            });
        }
    }

    if (document.readyState !== "loading") {
        run();
    } else {
        document.addEventListener("DOMContentLoaded", run);
    }

}());
