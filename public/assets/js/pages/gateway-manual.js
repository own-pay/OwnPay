/**
 * OwnPay Admin - Manual Gateway Create / Edit Page JS
 * Handles: dynamic input field add/remove, auto-slug from name.
 */
(function () {
    "use strict";

    function run() {
        var container = document.getElementById("input-fields-container");
        var template = document.getElementById("field-template");

        // Initial index = number of existing rendered field rows
        var fieldIdx = container ? container.querySelectorAll(".op-field-row").length : 0;

        // --- Add Field --------------------------------------------------------
        var addBtn = document.getElementById("add-field-btn");
        if (addBtn && container && template) {
            addBtn.addEventListener("click", function () {
                var html = template.innerHTML.replace(/__IDX__/g, fieldIdx++);
                var div = document.createElement("div");
                div.innerHTML = html;
                container.appendChild(div.firstElementChild);
            });
        }

        // --- Remove Field (delegated) -----------------------------------------
        if (container) {
            container.addEventListener("click", function (e) {
                if (e.target.classList.contains("remove-field-btn")) {
                    var row = e.target.closest(".op-field-row");
                    if (row) { row.remove(); }
                }
            });
        }

        // --- Auto-generate slug from name (create form only) ------------------
        var nameInput = document.getElementById("name");
        var slugInput = document.getElementById("slug");
        if (nameInput && slugInput) {
            nameInput.addEventListener("input", function () {
                slugInput.value = this.value
                    .toLowerCase()
                    .replace(/[^a-z0-9]+/g, "-")
                    .replace(/-+/g, "-")
                    .replace(/^-|-$/g, "");
            });
        }
    }

    if (document.readyState !== "loading") {
        run();
    } else {
        document.addEventListener("DOMContentLoaded", run);
    }

}());
