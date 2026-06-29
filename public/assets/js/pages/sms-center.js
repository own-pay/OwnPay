/**
 * OwnPay Admin - SMS Center Page JS
 * Handles: tab switching, sandbox sub-tab switching, live regex tester,
 *          Smart SMS Analyzer (Method B), AI Prompt Generator (Method C),
 *          and the new AI JSON template auto-importer.
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

    // --- Tab Switching --------------------------------------------------------
    document.querySelectorAll(".op-tab").forEach(function (t) {
        t.addEventListener("click", function () {
            document.querySelectorAll(".op-tab, .op-tab-panel").forEach(function (e) {
                e.classList.remove("active");
            });
            this.classList.add("active");
            var panel = document.getElementById("tab-" + this.dataset.tab);
            if (panel) { panel.classList.add("active"); }
        });
    });

    // --- Sandbox Sub-Tab Switching -------------------------------------------
    document.querySelectorAll("#sandbox-tabs .op-sandbox-tab").forEach(function (tab) {
        tab.addEventListener("click", function () {
            document.querySelectorAll("#sandbox-tabs .op-sandbox-tab, .op-sandbox-panel").forEach(function (e) {
                e.classList.remove("active");
            });
            this.classList.add("active");
            var panel = document.getElementById("sandbox-panel-" + this.dataset.sandboxTab);
            if (panel) { panel.classList.add("active"); }
        });
    });

    // --- Create Template Modal Toggling --------------------------------------
    var btnOpenModal = document.getElementById("btn-open-create-modal");
    var createModal = document.getElementById("create-template-modal");
    var createForm = document.getElementById("create-template-form");

    if (btnOpenModal && createModal) {
        btnOpenModal.addEventListener("click", function () {
            createModal.removeAttribute("hidden");
            createModal.hidden = false;
            var focusEl = createModal.querySelector("select[name='gateway_slug']");
            if (focusEl) { focusEl.focus(); }
        });
    }

    // --- Live Regex Tester ----------------------------------------------------
    var testBtn = document.getElementById("test-regex-btn");
    if (testBtn) {
        testBtn.addEventListener("click", function () {
            var body = document.getElementById("test-sms-body").value;
            var regex = document.getElementById("test-regex").value;
            var field = document.getElementById("test-field").value;
            var box = document.getElementById("test-result-box");
            var wrap = document.getElementById("test-result");

            if (!body || !regex) {
                box.className = "op-alert op-alert-warning";
                box.textContent = "Enter both SMS body and regex.";
                if (wrap) { wrap.classList.remove("op-d-none"); }
                return;
            }

            fetch("/admin/sms-center/test-regex", {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
                body: JSON.stringify({ sms_body: body, regex: regex, field: field })
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (wrap) { wrap.classList.remove("op-d-none"); }
                    if (!data.success) {
                        box.className = "op-alert op-alert-danger";
                        box.textContent = "Error: " + data.error;
                    } else if (data.matched) {
                        box.className = "op-alert op-alert-success";
                        box.innerHTML = "<strong>✓ Match found!</strong><br>Field: <code>" + escapeHtml(data.field) + "</code><br>Extracted: <code>" + escapeHtml(data.match || "(empty)") + "</code>";
                    } else {
                        box.className = "op-alert op-alert-warning";
                        box.textContent = "✗ No match. Adjust your regex pattern.";
                    }
                })
                .catch(function () {
                    box.className = "op-alert op-alert-danger";
                    box.textContent = "Network error.";
                    if (wrap) { wrap.classList.remove("op-d-none"); }
                });
        });
    }

    // --- Method B: Smart SMS Analyzer ----------------------------------------
    var analyzeBtn = document.getElementById("analyze-sms-btn");
    if (analyzeBtn) {
        analyzeBtn.addEventListener("click", function () {
            var sender = document.getElementById("sms-sender-input").value.trim();
            var raw = document.getElementById("raw-sms-input").value.trim();

            if (!sender) { alert("Enter the SMS sender (From field) - e.g. bKash, AD-NAGAD."); return; }
            if (!raw) { alert("Paste the SMS body first."); return; }

            var btn = this;
            var btnText = document.getElementById("analyze-btn-text");
            btnText.textContent = "Analyzing…";
            btn.disabled = true;

            fetch("/admin/sms-center/analyze", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded", "X-CSRF-Token": csrf },
                body: "sender=" + encodeURIComponent(sender) + "&raw_sms=" + encodeURIComponent(raw)
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.success) { alert("Error: " + (res.error || "Unknown")); return; }
                    var d = res.data;
                    var analyzeResultsPanel = document.getElementById("analyze-results");
                    if (analyzeResultsPanel) { analyzeResultsPanel.classList.remove("op-d-none"); }

                    var badge = document.getElementById("confidence-badge");
                    if (d.sender_whitelisted) {
                        badge.className = "op-badge op-badge-success";
                        badge.textContent = "✓ Whitelisted";
                    } else {
                        badge.className = "op-badge op-badge-warning";
                        badge.textContent = "⚠ Unwhitelisted";
                    }

                    var rows = [
                        ["Sender (From)", d.sender || sender, "info"],
                        ["Credit SMS", d.is_credit ? "Yes" : "No", d.is_credit ? "high" : "low"],
                        ["Amount", d.amount || "-", (d.confidence && d.confidence.amount) || "-"],
                        ["TrxID", d.trx_id || "-", (d.confidence && d.confidence.trx_id) || "-"],
                    ];

                    document.getElementById("analyze-fields").innerHTML =
                        '<table class="op-table op-analyze-table" style="font-size:0.75rem; width:100%;">' +
                        "<thead><tr><th>Field</th><th>Match</th><th>Conf.</th></tr></thead><tbody>" +
                        rows.map(function (r) {
                            var cls = r[2] === "high" ? "success" : r[2] === "medium" ? "warning" : r[2] === "info" ? "info" : "muted";
                            return "<tr><td style='padding:6px;'>" + escapeHtml(r[0]) + "</td><td style='padding:6px;'><code>" + escapeHtml(r[1]) + "</code></td><td style='padding:6px;'><span class='op-badge op-badge-" + cls + "' style='font-size:10px; padding:2px 4px;'>" + escapeHtml(r[2]) + "</span></td></tr>";
                        }).join("") +
                        "</tbody></table>";

                    document.getElementById("save-sender-pattern").value = sender;
                    if (d.suggested_regexes && d.suggested_regexes.amount_regex) { document.getElementById("save-amount-regex").value = d.suggested_regexes.amount_regex; }
                    if (d.suggested_regexes && d.suggested_regexes.trx_id_regex) { document.getElementById("save-trxid-regex").value = d.suggested_regexes.trx_id_regex; }
                    if (d.suggested_regexes && d.suggested_regexes.sender_regex) { document.getElementById("save-sender-regex").value = d.suggested_regexes.sender_regex; }
                })
                .catch(function () { alert("Network error - could not analyze SMS"); })
                .finally(function () { btnText.textContent = "⚡ Analyze SMS"; btn.disabled = false; });
        });
    }

    var clearAnalyzeBtn = document.getElementById("clear-analyze-btn");
    if (clearAnalyzeBtn) {
        clearAnalyzeBtn.addEventListener("click", function () {
            document.getElementById("sms-sender-input").value = "";
            document.getElementById("raw-sms-input").value = "";
            var analyzeResultsPanel = document.getElementById("analyze-results");
            if (analyzeResultsPanel) { analyzeResultsPanel.classList.add("op-d-none"); }
        });
    }

    // --- Method C: AI Prompt Generator ---------------------------------------
    var genBtn = document.getElementById("gen-ai-prompt-btn");
    if (genBtn) {
        genBtn.addEventListener("click", function () {
            var sender = document.getElementById("ai-sender-input").value.trim();
            var raw = document.getElementById("ai-sms-input").value.trim();

            if (!sender) { alert("Enter the SMS sender (From field) - e.g. bKash, AD-NAGAD."); return; }
            if (!raw) { alert("Paste the SMS body first."); return; }

            var btn = this;
            var btnText = document.getElementById("gen-btn-text");
            btnText.textContent = "Generating…";
            btn.disabled = true;

            fetch("/admin/sms-center/ai-prompt", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded", "X-CSRF-Token": csrf },
                body: "sender=" + encodeURIComponent(sender) + "&raw_sms=" + encodeURIComponent(raw)
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.success) { alert("Error: " + (res.error || "Unknown")); return; }
                    document.getElementById("ai-prompt-text").textContent = res.prompt;
                    var promptResultPanel = document.getElementById("ai-prompt-result");
                    if (promptResultPanel) { promptResultPanel.classList.remove("op-d-none"); }
                })
                .catch(function () { alert("Network error - could not generate prompt"); })
                .finally(function () { btnText.textContent = "🤖 Generate Prompt"; btn.disabled = false; });
        });
    }

    // --- Safe Clipboard Copying (Rule 3.6 Compliance) ------------------------
    var copyAiBtn = document.getElementById("copy-ai-prompt-btn");
    if (copyAiBtn) {
        copyAiBtn.addEventListener("click", function () {
            var text = document.getElementById("ai-prompt-text").textContent;
            if (window.opCopyText) {
                window.opCopyText(text, this);
            } else {
                // Inline fallback
                var ta = document.createElement("textarea");
                ta.value = text;
                ta.style.fontSize = "12pt";
                ta.style.position = "absolute";
                ta.style.left = "-9999px";
                document.body.appendChild(ta);
                ta.select();
                document.execCommand("copy");
                document.body.removeChild(ta);
                var lbl = document.getElementById("copy-btn-label");
                if (lbl) {
                    lbl.textContent = "✓ Copied!";
                    setTimeout(function () { lbl.textContent = "Copy Prompt"; }, 2000);
                }
            }
        });
    }

    var clearAiBtn = document.getElementById("clear-ai-btn");
    if (clearAiBtn) {
        clearAiBtn.addEventListener("click", function () {
            document.getElementById("ai-sender-input").value = "";
            document.getElementById("ai-sms-input").value = "";
            var promptResultPanel = document.getElementById("ai-prompt-result");
            if (promptResultPanel) { promptResultPanel.classList.add("op-d-none"); }
        });
    }

    // --- AI JSON Importer Auto-fill Logic ------------------------------------
    var btnImportAiJson = document.getElementById("btn-import-ai-json");
    if (btnImportAiJson) {
        btnImportAiJson.addEventListener("click", function () {
            var rawJson = document.getElementById("ai-json-response-input").value.trim();
            if (!rawJson) {
                if (window.opShowToast) {
                    window.opShowToast("Please paste the AI JSON response first.", "warning");
                } else {
                    alert("Please paste the AI JSON response first.");
                }
                return;
            }

            try {
                // Strip markdown code fences if present (e.g. ```json ... ```)
                var cleanJson = rawJson;
                if (cleanJson.indexOf("```") === 0) {
                    cleanJson = cleanJson.replace(/^```json\s*/i, "").replace(/^```\s*/, "").replace(/\s*```$/, "");
                }

                var parsed = JSON.parse(cleanJson);
                if (createForm) {
                    createForm.querySelector("select[name='gateway_slug']").value = parsed.gateway_slug || "";
                    createForm.querySelector("input[name='sender_pattern']").value = parsed.sender_pattern || "";
                    createForm.querySelector("input[name='amount_regex']").value = parsed.amount_regex || "";
                    createForm.querySelector("input[name='trx_id_regex']").value = parsed.trx_id_regex || "";
                    createForm.querySelector("input[name='sender_regex']").value = parsed.sender_regex || "";
                    createForm.querySelector("input[name='priority']").value = parsed.priority !== undefined ? parsed.priority : 10;
                    createForm.querySelector("select[name='status']").value = parsed.status || "active";

                    // Open the template creation modal
                    if (createModal) {
                        createModal.removeAttribute("hidden");
                        createModal.hidden = false;
                    }

                    if (window.opShowToast) {
                        window.opShowToast("Configuration loaded successfully! Please verify and click Create.", "success");
                    }
                }
            } catch (ex) {
                if (window.opShowToast) {
                    window.opShowToast("JSON parsing error: " + ex.message, "danger");
                } else {
                    alert("Failed to parse JSON. Please check JSON format correctness.");
                }
            }
        });
    }

}());
