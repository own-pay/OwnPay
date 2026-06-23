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

    // 2. Autonomous handoff / countdown redirect handling
    var wrapper = document.getElementById("countdown-wrapper");
    if (wrapper) {
        var redirectUrl = wrapper.getAttribute("data-redirect-url");
        var paymentId = wrapper.getAttribute("data-payment-id");
        var status = wrapper.getAttribute("data-status");

        if (redirectUrl) {
            // Construct target URL with query parameters
            var separator = redirectUrl.indexOf("?") !== -1 ? "&" : "?";
            var finalUrl = redirectUrl + separator + "payment_id=" + encodeURIComponent(paymentId) + "&status=" + encodeURIComponent(status);

            // Update all return/merchant links on the page for instant access
            var returnBtns = document.querySelectorAll('a[href="/"], .st-btn');
            returnBtns.forEach(function(btn) {
                if (btn.innerText.indexOf("Return to") !== -1 || btn.getAttribute("href") === "/") {
                    btn.setAttribute("href", finalUrl);
                    var label = btn.querySelector(".st-btn-label");
                    if (label) {
                        label.innerHTML = "Return to<br>Merchant";
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
