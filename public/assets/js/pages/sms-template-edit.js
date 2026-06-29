/**
 * OwnPay Admin - SMS Template Edit Page JS
 * Handles: live multi-pattern regex tester against /admin/sms-center/test-regex.
 */
(function () {
    "use strict";

    var csrf = window.OP_CSRF || "";

    function escapeHtml(str) {
        if (!str) { return ""; }
        return String(str)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    var testBtn = document.getElementById("test-all-btn");
    if (!testBtn) { return; }

    testBtn.addEventListener("click", function () {
        var sms = document.getElementById("sample-sms").value;
        if (!sms) { alert("Paste a sample SMS first."); return; }

        var patterns = [
            { label: "Amount", regex: document.getElementById("edit-amount-regex").value, field: "amount" },
            { label: "TrxID", regex: document.getElementById("edit-trxid-regex").value, field: "trx_id" },
            { label: "Sender", regex: document.getElementById("edit-sender-regex").value, field: "sender" },
        ];

        var box = document.getElementById("test-results");
        box.innerHTML = '<p class="op-text-muted">Testing\u2026</p>';

        var html = '<div class="op-table-responsive"><table class="op-table"><thead><tr><th>Field</th><th>Regex</th><th>Match</th><th>Extracted</th></tr></thead><tbody>';

        var promises = patterns.filter(function (p) { return p.regex; }).map(function (p) {
            return fetch("/admin/sms-center/test-regex", {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
                body: JSON.stringify({ sms_body: sms, regex: p.regex, field: p.field })
            })
                .then(function (r) { return r.json(); })
                .then(function (d) { return Object.assign({}, d, { label: p.label, regex: p.regex }); });
        });

        Promise.all(promises).then(function (results) {
            results.forEach(function (r) {
                var icon = r.matched ? "\u2713" : "\u2717";
                var cls = r.matched ? "op-text-success" : "op-text-danger";
                html += "<tr><td>" + escapeHtml(r.label) + "</td><td><code>" + escapeHtml(r.regex) + '</code></td><td class="' + cls + '">' + escapeHtml(icon) + "</td><td><code>" + escapeHtml(r.match || "\u2014") + "</code></td></tr>";
            });
            html += "</tbody></table></div>";
            box.innerHTML = html;
        }).catch(function () {
            box.innerHTML = '<div class="op-alert op-alert-danger">Network error.</div>';
        });
    });

}());
