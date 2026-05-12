/**
 * OwnPay Admin — SMS Center Page JS
 * Extracted from templates/admin/sms-center/index.twig
 * Handles: tab switching, live regex tester, Smart SMS Analyzer (Method B),
 *          AI Prompt Generator (Method C).
 *
 * Requires: window.OP_CSRF (set by base template) or data-csrf attribute.
 */
(function () {
    'use strict';

    var csrf = window.OP_CSRF || '';

    // ─── Tab Switching ────────────────────────────────────────────────────────
    document.querySelectorAll('.op-tab').forEach(function (t) {
        t.addEventListener('click', function () {
            document.querySelectorAll('.op-tab, .op-tab-panel').forEach(function (e) {
                e.classList.remove('active');
            });
            this.classList.add('active');
            var panel = document.getElementById('tab-' + this.dataset.tab);
            if (panel) panel.classList.add('active');
        });
    });

    // ─── Live Regex Tester ────────────────────────────────────────────────────
    var testBtn = document.getElementById('test-regex-btn');
    if (testBtn) {
        testBtn.addEventListener('click', function () {
            var body  = document.getElementById('test-sms-body').value;
            var regex = document.getElementById('test-regex').value;
            var field = document.getElementById('test-field').value;
            var box   = document.getElementById('test-result-box');
            var wrap  = document.getElementById('test-result');

            if (!body || !regex) {
                box.className = 'op-alert op-alert-warning';
                box.textContent = 'Enter both SMS body and regex.';
                wrap.style.display = 'block';
                return;
            }

            fetch('/admin/sms-center/test-regex', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({ sms_body: body, regex: regex, field: field })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                wrap.style.display = 'block';
                if (!data.success) {
                    box.className = 'op-alert op-alert-danger';
                    box.textContent = 'Error: ' + data.error;
                } else if (data.matched) {
                    box.className = 'op-alert op-alert-success';
                    box.innerHTML = '<strong>✓ Match found!</strong><br>Field: <code>' + data.field + '</code><br>Extracted: <code>' + (data.match || '(empty)') + '</code><br>Full matches: <code>' + JSON.stringify(data.full) + '</code>';
                } else {
                    box.className = 'op-alert op-alert-warning';
                    box.textContent = '✗ No match. Adjust your regex pattern.';
                }
            })
            .catch(function () {
                box.className = 'op-alert op-alert-danger';
                box.textContent = 'Network error.';
                wrap.style.display = 'block';
            });
        });
    }

    // ─── Method B: Smart SMS Analyzer ────────────────────────────────────────
    var analyzeBtn = document.getElementById('analyze-sms-btn');
    if (analyzeBtn) {
        analyzeBtn.addEventListener('click', function () {
            var sender = document.getElementById('sms-sender-input').value.trim();
            var raw    = document.getElementById('raw-sms-input').value.trim();

            if (!sender) { alert('Enter the SMS sender (From field) — e.g. bKash, AD-NAGAD.'); return; }
            if (!raw)    { alert('Paste the SMS body first.'); return; }

            var btn = this;
            var btnText = document.getElementById('analyze-btn-text');
            btnText.textContent = 'Analyzing…';
            btn.disabled = true;

            fetch('/admin/sms-center/analyze', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
                body: 'sender=' + encodeURIComponent(sender) + '&raw_sms=' + encodeURIComponent(raw)
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) { alert('Error: ' + (res.error || 'Unknown')); return; }
                var d = res.data;
                document.getElementById('analyze-results').style.display = 'block';

                var badge = document.getElementById('confidence-badge');
                if (d.sender_whitelisted) {
                    badge.className = 'op-badge op-badge-success';
                    badge.textContent = '✓ Sender Whitelisted';
                } else {
                    badge.className = 'op-badge op-badge-warning';
                    badge.textContent = '⚠ Sender Not in Whitelist';
                }

                var rows = [
                    ['Sender (From)', d.sender || sender, 'info'],
                    ['Whitelisted',   d.sender_whitelisted ? 'Yes ✓' : 'No — add as template sender', d.sender_whitelisted ? 'high' : 'low'],
                    ['Credit SMS',    d.is_credit ? 'Yes' : 'No', d.is_credit ? 'high' : 'low'],
                    ['Amount',        d.amount    || '—', (d.confidence && d.confidence.amount)  || '—'],
                    ['TrxID',         d.trx_id    || '—', (d.confidence && d.confidence.trx_id)  || '—'],
                    ['Balance',       d.balance   || '—', (d.confidence && d.confidence.balance) || '—'],
                ];

                document.getElementById('analyze-fields').innerHTML =
                    '<table class="op-table op-analyze-table">' +
                    '<thead><tr><th>Field</th><th>Extracted</th><th>Confidence</th></tr></thead><tbody>' +
                    rows.map(function (r) {
                        var cls = r[2] === 'high' ? 'success' : r[2] === 'medium' ? 'warning' : r[2] === 'info' ? 'info' : 'muted';
                        return '<tr><td>' + r[0] + '</td><td><code>' + r[1] + '</code></td><td><span class="op-badge op-badge-' + cls + '">' + r[2] + '</span></td></tr>';
                    }).join('') +
                    '</tbody></table>';

                document.getElementById('save-sender-pattern').value = sender;
                if (d.suggested_regexes && d.suggested_regexes.amount_regex) document.getElementById('save-amount-regex').value = d.suggested_regexes.amount_regex;
                if (d.suggested_regexes && d.suggested_regexes.trx_id_regex) document.getElementById('save-trxid-regex').value = d.suggested_regexes.trx_id_regex;
                if (d.suggested_regexes && d.suggested_regexes.sender_regex) document.getElementById('save-sender-regex').value = d.suggested_regexes.sender_regex;
            })
            .catch(function () { alert('Network error — could not analyze SMS'); })
            .finally(function () { btnText.textContent = '⚡ Analyze SMS'; btn.disabled = false; });
        });
    }

    var clearAnalyzeBtn = document.getElementById('clear-analyze-btn');
    if (clearAnalyzeBtn) {
        clearAnalyzeBtn.addEventListener('click', function () {
            document.getElementById('sms-sender-input').value = '';
            document.getElementById('raw-sms-input').value = '';
            document.getElementById('analyze-results').style.display = 'none';
        });
    }

    // ─── Method C: AI Prompt Generator ───────────────────────────────────────
    var genBtn = document.getElementById('gen-ai-prompt-btn');
    if (genBtn) {
        genBtn.addEventListener('click', function () {
            var sender = document.getElementById('ai-sender-input').value.trim();
            var raw    = document.getElementById('ai-sms-input').value.trim();

            if (!sender) { alert('Enter the SMS sender (From field) — e.g. bKash, AD-NAGAD.'); return; }
            if (!raw)    { alert('Paste the SMS body first.'); return; }

            var btn = this;
            var btnText = document.getElementById('gen-btn-text');
            btnText.textContent = 'Generating…';
            btn.disabled = true;

            fetch('/admin/sms-center/ai-prompt', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
                body: 'sender=' + encodeURIComponent(sender) + '&raw_sms=' + encodeURIComponent(raw)
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) { alert('Error: ' + (res.error || 'Unknown')); return; }
                document.getElementById('ai-prompt-text').textContent = res.prompt;
                document.getElementById('ai-prompt-result').style.display = 'block';
            })
            .catch(function () { alert('Network error — could not generate prompt'); })
            .finally(function () { btnText.textContent = '✨ Generate AI Prompt'; btn.disabled = false; });
        });
    }

    var copyAiBtn = document.getElementById('copy-ai-prompt-btn');
    if (copyAiBtn) {
        copyAiBtn.addEventListener('click', function () {
            var text = document.getElementById('ai-prompt-text').textContent;
            var lbl  = document.getElementById('copy-btn-label');
            navigator.clipboard.writeText(text).then(function () {
                lbl.textContent = '✓ Copied!';
                setTimeout(function () { lbl.textContent = '📋 Copy Prompt'; }, 2000);
            }).catch(function () {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.cssText = 'position:fixed;opacity:0;';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                lbl.textContent = '✓ Copied!';
                setTimeout(function () { lbl.textContent = '📋 Copy Prompt'; }, 2000);
            });
        });
    }

    var clearAiBtn = document.getElementById('clear-ai-btn');
    if (clearAiBtn) {
        clearAiBtn.addEventListener('click', function () {
            document.getElementById('ai-sender-input').value = '';
            document.getElementById('ai-sms-input').value = '';
            document.getElementById('ai-prompt-result').style.display = 'none';
        });
    }

}());
