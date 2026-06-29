/**
 * OwnPay Admin - Invoice Edit Page JS
 * Handles: dynamic line item add/remove.
 * Requires: window.OP_INVOICE_IDX (set by template)
 */
(function () {
    "use strict";
    var container = document.getElementById("items-container");
    var addBtn = document.getElementById("add-item");
    if (!container || !addBtn) { return; }
    var idx = container.querySelectorAll(".op-item-row").length;
    addBtn.addEventListener("click", function () {
        container.insertAdjacentHTML("beforeend",
            '<div class="op-item-row op-form-row op-mb-2">' +
            '<div class="op-form-group op-col-5"><input type="text" name="items[' + idx + '][description]" class="op-input" placeholder="Description" required></div>' +
            '<div class="op-form-group op-col-2"><input type="number" name="items[' + idx + '][quantity]" value="1" class="op-input" min="1" required></div>' +
            '<div class="op-form-group op-col-3"><input type="number" name="items[' + idx + '][amount]" class="op-input" step="0.01" placeholder="Amount" required></div>' +
            '<div class="op-form-group op-col-2"><button type="button" class="op-btn op-btn-sm op-btn-danger op-item-remove">✕</button></div>' +
            "</div>");
        idx++;
    });
    container.addEventListener("click", function (e) {
        if (e.target.classList.contains("op-item-remove")) { e.target.closest(".op-item-row").remove(); }
    });
}());
