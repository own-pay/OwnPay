document.addEventListener("DOMContentLoaded", function() {
    // 1. Back button handling
    var backBtns = document.querySelectorAll(".op-back-btn");
    backBtns.forEach(function(btn) {
        btn.addEventListener("click", function(e) {
            if (window.history.length > 1) {
                e.preventDefault();
                window.history.back();
            }
        });
    });

    // 1b. Download Receipt - triggers the browser print dialog. Wired via a real event
    // listener (not a `javascript:` href) since the checkout CSP's script-src has no
    // 'unsafe-inline' and nonces don't cover javascript: URIs - a javascript: href is
    // silently blocked and never fires.
    document.addEventListener("click", function (e) {
        var target = e.target.closest('[data-action="print-receipt"]');
        if (!target) { return; }
        e.preventDefault();
        window.print();
    });

    // 1c. Refresh Status - same javascript:-href CSP block as Download Receipt above, applied
    // to the pending/processing status page's manual refresh button.
    document.addEventListener("click", function (e) {
        var target = e.target.closest('[data-action="refresh-status"]');
        if (!target) { return; }
        e.preventDefault();
        window.location.reload();
    });

    // 2. Autonomous handoff / countdown redirect handling
    var wrapper = document.getElementById("countdown-wrapper");
    if (wrapper) {
        var redirectUrl = wrapper.getAttribute("data-redirect-url");
        var paymentId = wrapper.getAttribute("data-payment-id");
        var status = wrapper.getAttribute("data-status");

        var finalUrl = "";
        try {
            if (redirectUrl) {
                // Parse and resolve against origin to handle both relative and absolute URLs.
                var parsedUrl = new URL(redirectUrl, window.location.origin);
                // Enforce safe URL schemes (http: and https: only).
                if (parsedUrl.protocol === "http:" || parsedUrl.protocol === "https:") {
                    parsedUrl.searchParams.set("payment_id", paymentId || "");
                    parsedUrl.searchParams.set("status", status || "");
                    finalUrl = parsedUrl.href;
                }
            }
        } catch {
            // Invalid URL - fallback to empty (fail closed)
        }

        if (finalUrl !== "") {

            // Update all return/merchant links on the page for instant access
            var returnBtns = document.querySelectorAll('a[href="/"], .st-btn');
            returnBtns.forEach(function(btn) {
                if (btn.innerText.indexOf("Return to") !== -1 || btn.getAttribute("href") === "/") {
                    btn.setAttribute("href", finalUrl);
                    var label = btn.querySelector(".st-btn-label");
                    if (label) {
                        label.textContent = "";
                        label.appendChild(document.createTextNode("Return to"));
                        label.appendChild(document.createElement("br"));
                        label.appendChild(document.createTextNode("Merchant"));
                    }
                }
            });

            var instantBtn = document.getElementById("countdown-instant");
            if (instantBtn) {
                instantBtn.setAttribute("href", finalUrl);
            }

            var timeLeft = 5;
            var circle = document.getElementById("countdown-circle");
            var numberText = document.getElementById("countdown-number");
            var textSpan = document.getElementById("countdown-text");

            if (circle) {
                var radius = 16;
                var circumference = 2 * Math.PI * radius; // ~100.53
                circle.style.strokeDasharray = circumference;
                circle.style.strokeDashoffset = 0;
            }

            var timer = setInterval(function() {
                timeLeft--;
                if (textSpan) {textSpan.innerText = timeLeft;}
                if (numberText) {numberText.innerText = timeLeft;}

                if (circle) {
                    var offset = ( (5 - timeLeft) / 5 ) * circumference;
                    circle.style.strokeDashoffset = offset;
                }

                if (timeLeft <= 0) {
                    clearInterval(timer);
                    window.location.href = finalUrl;
                }
            }, 1000);
        }
    }

    // 3. Auto-refresh for pending/processing status pages
    if (document.querySelector(".st-refresh-tip")) {
        setTimeout(function () {
            window.location.reload();
        }, 15000);
    }
});
