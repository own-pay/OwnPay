/**
 * OwnPay Setup Wizard Page JS
 */
(function () {
    "use strict";

    let onboardingBrandId = 0;
    let selectedGatewayType = "";
    let selectedMailProvider = "smtp";

    let pairingPollInterval = null;
    let pairingTimerInterval = null;

    function copyOtpToClipboard() {
        const otpEl = document.getElementById("op-wizard-otp-display");
        const otpText = otpEl ? otpEl.textContent : "";
        if (otpText && otpText !== "------") {
            if (typeof window.opCopyText === "function") {
                window.opCopyText(otpText, otpEl, function () {
                    const copiedEl = document.getElementById("op-wizard-otp-copied");
                    if (copiedEl) {
                        copiedEl.style.display = "inline";
                        setTimeout(() => {
                            copiedEl.style.display = "none";
                        }, 2000);
                    }
                });
            } else {
                navigator.clipboard.writeText(otpText).then(() => {
                    const copiedEl = document.getElementById("op-wizard-otp-copied");
                    if (copiedEl) {
                        copiedEl.style.display = "inline";
                        setTimeout(() => {
                            copiedEl.style.display = "none";
                        }, 2000);
                    }
                });
            }
        }
    }

    function startPairingPoll() {
        if (pairingPollInterval) {clearInterval(pairingPollInterval);}
        
        pairingPollInterval = setInterval(() => {
            fetch("/admin/devices/check-status", {
                method: "GET",
                headers: {
                    "X-Requested-With": "XMLHttpRequest"
                }
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.paired) {
                    stopPairingPoll();
                    showWizardSuccess("🎉 Device paired successfully! Click Finish to launch your platform.");
                    const qrMock = document.querySelector(".op-qr-mock");
                    if (qrMock) {
                        qrMock.style.background = "rgba(16, 185, 129, 0.1)";
                        qrMock.style.borderColor = "#10b981";
                        qrMock.innerHTML = `
                            <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; width:150px; height:150px; color:#10b981;">
                                <svg width="64" height="64" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="animation: bounce 1s infinite alternate;">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <span style="font-weight:800; font-size:0.95rem; margin-top:0.75rem;">CONNECTED</span>
                            </div>
                        `;
                    }
                }
            })
            .catch(err => console.error("Error checking pairing status:", err));
        }, 1500);
    }

    function stopPairingPoll() {
        if (pairingPollInterval) {
            clearInterval(pairingPollInterval);
            pairingPollInterval = null;
        }
        if (pairingTimerInterval) {
            clearInterval(pairingTimerInterval);
            pairingTimerInterval = null;
        }
    }

    function loadPairingDetails() {
        stopPairingPoll();
        
        const qrImg = document.getElementById("op-wizard-qr-img");
        const qrPlaceholder = document.getElementById("op-wizard-qr-placeholder");
        const otpDisplay = document.getElementById("op-wizard-otp-display");
        const otpWrapper = document.getElementById("op-wizard-otp-wrapper");
        const timerSpan = document.getElementById("op-wizard-otp-timer");
        
        if (qrImg) {qrImg.style.display = "none";}
        if (qrPlaceholder) {qrPlaceholder.style.display = "block";}
        if (otpWrapper) {otpWrapper.style.display = "none";}

        fetch("/admin/devices/generate-otp", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": window.OP_CSRF || ""
            }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                if (qrPlaceholder) {qrPlaceholder.style.display = "none";}
                if (qrImg) {
                    qrImg.src = data.qr_svg;
                    qrImg.style.display = "block";
                }
                
                if (otpDisplay) {otpDisplay.textContent = data.otp;}
                if (otpWrapper) {otpWrapper.style.display = "flex";}
                
                let timeLeft = data.expires_in || 300;
                if (pairingTimerInterval) {clearInterval(pairingTimerInterval);}
                pairingTimerInterval = setInterval(() => {
                    timeLeft--;
                    if (timeLeft <= 0) {
                        clearInterval(pairingTimerInterval);
                        if (timerSpan) {
                            timerSpan.textContent = "Expired";
                            timerSpan.style.color = "#ef4444";
                        }
                        if (otpDisplay) {otpDisplay.style.opacity = "0.5";}
                    } else {
                        const mins = Math.floor(timeLeft / 60);
                        const secs = timeLeft % 60;
                        if (timerSpan) {
                            timerSpan.textContent = `Expires in ${mins}:${secs.toString().padStart(2, "0")}`;
                        }
                    }
                }, 1000);
                
                startPairingPoll();
            } else {
                showWizardError(data.error || "Failed to generate device pairing OTP.");
            }
        })
        .catch(err => {
            console.error(err);
            showWizardError("Network error while generating device pairing OTP.");
        });
    }

    function showStep(step) {
        const progressBar = document.getElementById("wizard-progress-bar");
        const percentage = ((step - 1) / 4) * 100;
        if (progressBar) {progressBar.style.width = percentage + "%";}

        document.querySelectorAll(".op-wizard-step").forEach(el => {
            const stepNum = parseInt(el.getAttribute("data-step"));
            el.classList.remove("active", "completed");
            if (stepNum === step) {
                el.classList.add("active");
            } else if (stepNum < step) {
                el.classList.add("completed");
            }
        });

        document.querySelectorAll(".op-wizard-panel").forEach(panel => {
            panel.classList.remove("active");
        });
        const activePanel = document.getElementById("panel-" + step);
        if (activePanel) {activePanel.classList.add("active");}

        const titleEl = document.getElementById("wizard-title");
        const subtitleEl = document.getElementById("wizard-subtitle");
        if (titleEl && subtitleEl) {
            if (step === 1) {
                titleEl.textContent = "1. General Platform Settings";
                subtitleEl.textContent = "Configure baseline system attributes, core branding, and system timezone.";
            } else if (step === 2) {
                titleEl.textContent = "2. Create First Brand / Store";
                subtitleEl.textContent = "OwnPay supports white-labeled brands. Create your first store dashboard details.";
            } else if (step === 3) {
                titleEl.textContent = "3. Outgoing Mail Configuration";
                subtitleEl.textContent = "Configure your transactional email server (SMTP, Mailgun, or SendGrid) to send receipts.";
            } else if (step === 4) {
                titleEl.textContent = "4. Gateway Configuration";
                subtitleEl.textContent = "Connect to common credit card or wallet gateways. Choose one below to quick start.";
            } else if (step === 5) {
                titleEl.textContent = "5. Secure Native Device Pairing";
                subtitleEl.textContent = "Pair native devices to securely audit double-entry ledgers and intercept payments.";
                loadPairingDetails();
            }
        }

        if (step !== 5) {
            stopPairingPoll();
        }

        clearWizardMessages();
    }

    function prevStep(step) {
        showStep(step);
    }

    function showWizardError(msg) {
        const errorEl = document.getElementById("wizard-error");
        const errorTextEl = document.getElementById("wizard-error-text");
        if (errorTextEl) {errorTextEl.textContent = msg;}
        if (errorEl) {
            errorEl.style.display = "flex";
            errorEl.scrollIntoView({ behavior: "smooth", block: "nearest" });
        }
    }

    function showWizardSuccess(msg) {
        const successEl = document.getElementById("wizard-success");
        const successTextEl = document.getElementById("wizard-success-text");
        if (successTextEl) {successTextEl.textContent = msg;}
        if (successEl) {
            successEl.style.display = "flex";
            successEl.scrollIntoView({ behavior: "smooth", block: "nearest" });
        }
    }

    function clearWizardMessages() {
        const errorEl = document.getElementById("wizard-error");
        const successEl = document.getElementById("wizard-success");
        if (errorEl) {errorEl.style.display = "none";}
        if (successEl) {successEl.style.display = "none";}
    }

    function selectMailProvider(provider) {
        selectedMailProvider = provider;
        document.querySelectorAll("#panel-3 .op-choice-card").forEach(el => {
            el.classList.remove("selected");
        });
        const choiceEl = document.getElementById("choice-mail-" + provider);
        if (choiceEl) {choiceEl.classList.add("selected");}

        const smtpFields = document.getElementById("mail-smtp-fields");
        const mailgunFields = document.getElementById("mail-mailgun-fields");
        const sendgridFields = document.getElementById("mail-sendgrid-fields");
        if (smtpFields) {smtpFields.style.display = (provider === "smtp") ? "block" : "none";}
        if (mailgunFields) {mailgunFields.style.display = (provider === "mailgun") ? "block" : "none";}
        if (sendgridFields) {sendgridFields.style.display = (provider === "sendgrid") ? "block" : "none";}
        
        clearWizardMessages();
    }

    function selectGateway(type) {
        selectedGatewayType = type;
        document.querySelectorAll("#panel-4 .op-choice-card").forEach(el => {
            el.classList.remove("selected");
        });
        const choiceEl = document.getElementById("choice-" + type);
        if (choiceEl) {choiceEl.classList.add("selected");}

        const stripeFields = document.getElementById("stripe-fields");
        const paypalFields = document.getElementById("paypal-fields");
        const manualFields = document.getElementById("manual-fields");
        if (stripeFields) {stripeFields.style.display = (type === "stripe") ? "block" : "none";}
        if (paypalFields) {paypalFields.style.display = (type === "paypal") ? "block" : "none";}
        if (manualFields) {manualFields.style.display = (type === "manual") ? "block" : "none";}
        
        clearWizardMessages();
    }

    function submitStep1() {
        clearWizardMessages();
        const siteName = document.getElementById("site_name").value;
        const siteTagline = document.getElementById("site_tagline").value;
        const baseCurrency = document.getElementById("base_currency").value;
        const timezone = document.getElementById("timezone").value;
        const timerMinutes = document.getElementById("timer_minutes").value;
        const requirePhone = document.getElementById("require_customer_phone").checked ? "1" : "0";
        const landingPage = document.getElementById("landing_page_enabled").checked ? "1" : "0";

        fetch("/admin/setup-wizard/save-settings", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": window.OP_CSRF || ""
            },
            body: JSON.stringify({
                site_name: siteName,
                site_tagline: siteTagline,
                currency: baseCurrency,
                timezone: timezone,
                timer_minutes: timerMinutes,
                require_customer_phone: requirePhone,
                landing_page_enabled: landingPage
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const brandCurrency = document.getElementById("brand_currency");
                const brandTimezone = document.getElementById("brand_timezone");
                if (brandCurrency) {brandCurrency.value = baseCurrency;}
                if (brandTimezone) {brandTimezone.value = timezone;}
                showStep(2);
            } else {
                showWizardError(data.error || "Failed to save settings.");
            }
        })
        .catch(err => {
            console.error(err);
            showWizardError("Network error while saving platform settings.");
        });
    }

    function submitStep2() {
        clearWizardMessages();
        const brandName = document.getElementById("brand_name").value;
        const brandEmail = document.getElementById("brand_email").value;
        const brandPhone = document.getElementById("brand_phone").value;
        const brandCurrency = document.getElementById("brand_currency").value;
        const brandTimezone = document.getElementById("brand_timezone").value;

        fetch("/admin/setup-wizard/create-brand", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": window.OP_CSRF || ""
            },
            body: JSON.stringify({
                brand_name: brandName,
                brand_email: brandEmail,
                brand_phone: brandPhone,
                brand_currency: brandCurrency,
                brand_timezone: brandTimezone
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                onboardingBrandId = data.brand_id;
                showStep(3);
            } else {
                showWizardError(data.error || "Failed to create brand.");
            }
        })
        .catch(err => {
            console.error(err);
            showWizardError("Network error while creating brand.");
        });
    }

    function submitStep3() {
        clearWizardMessages();
        const fromName = document.getElementById("mail_from_name").value;
        const fromEmail = document.getElementById("mail_from_email").value;

        const payload = {
            provider: selectedMailProvider,
            from_name: fromName,
            from_email: fromEmail,
            skip: "0"
        };

        if (selectedMailProvider === "smtp") {
            payload.smtp_host = document.getElementById("mail_smtp_host").value;
            payload.smtp_port = document.getElementById("mail_smtp_port").value;
            payload.smtp_user = document.getElementById("mail_smtp_user").value;
            payload.smtp_password = document.getElementById("mail_smtp_password").value;
            payload.smtp_encryption = document.getElementById("mail_smtp_encryption").value;

            if (!payload.smtp_host || !payload.smtp_user || !payload.smtp_password) {
                showWizardError("Please enter SMTP Host, Username, and Password.");
                return;
            }
        } else if (selectedMailProvider === "mailgun") {
            payload.mailgun_domain = document.getElementById("mail_mailgun_domain").value;
            payload.mailgun_key = document.getElementById("mail_mailgun_key").value;

            if (!payload.mailgun_domain || !payload.mailgun_key) {
                showWizardError("Please enter Mailgun Sending Domain and API Key.");
                return;
            }
        } else if (selectedMailProvider === "sendgrid") {
            payload.sendgrid_key = document.getElementById("mail_sendgrid_key").value;

            if (!payload.sendgrid_key) {
                showWizardError("Please enter SendGrid API Key.");
                return;
            }
        }

        const btn = document.getElementById("btn-save-mail");
        if (btn) {
            btn.disabled = true;
            btn.textContent = "Saving...";
        }

        fetch("/admin/setup-wizard/setup-mail", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": window.OP_CSRF || ""
            },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(data => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = 'Save & Continue <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>';
            }
            if (data.success) {
                showStep(4);
            } else {
                showWizardError(data.error || "Failed to save email configuration.");
            }
        })
        .catch(err => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = 'Save & Continue <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>';
            }
            console.error(err);
            showWizardError("Network error while saving mail configuration.");
        });
    }

    function skipMailStep() {
        clearWizardMessages();
        fetch("/admin/setup-wizard/setup-mail", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": window.OP_CSRF || ""
            },
            body: JSON.stringify({ skip: "1" })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showStep(4);
            } else {
                showWizardError("Failed to skip email setup.");
            }
        })
        .catch(err => {
            console.error(err);
            showWizardError("Network error while skipping mail configuration.");
        });
    }

    function submitStep4() {
        clearWizardMessages();
        if (!selectedGatewayType) {
            showWizardError("Please select a payment gateway choice.");
            return;
        }

        const payload = {
            brand_id: onboardingBrandId,
            gateway_type: selectedGatewayType
        };

        if (selectedGatewayType === "stripe") {
            payload.stripe_key = document.getElementById("stripe_key").value;
            payload.stripe_secret = document.getElementById("stripe_secret").value;
            if (!payload.stripe_key || !payload.stripe_secret) {
                showWizardError("Please enter Stripe publishable key and secret key.");
                return;
            }
        } else if (selectedGatewayType === "paypal") {
            payload.paypal_client_id = document.getElementById("paypal_client_id").value;
            payload.paypal_secret = document.getElementById("paypal_secret").value;
            if (!payload.paypal_client_id || !payload.paypal_secret) {
                showWizardError("Please enter PayPal Client ID and Client Secret.");
                return;
            }
        } else if (selectedGatewayType === "manual") {
            payload.manual_name = document.getElementById("manual_name").value;
            payload.manual_details = document.getElementById("manual_details").value;
            if (!payload.manual_name || !payload.manual_details) {
                showWizardError("Please enter manual gateway name and bank/payment instructions.");
                return;
            }
        }

        const btn = document.getElementById("btn-save-gateway");
        if (btn) {
            btn.disabled = true;
            btn.textContent = "Saving...";
        }

        fetch("/admin/setup-wizard/setup-gateway", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": window.OP_CSRF || ""
            },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(data => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = 'Save & Continue <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>';
            }
            if (data.success) {
                showStep(5);
            } else {
                showWizardError(data.error || "Failed to save gateway config.");
            }
        })
        .catch(err => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = 'Save & Continue <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>';
            }
            console.error(err);
            showWizardError("Network error while saving gateway.");
        });
    }

    function completeWizard() {
        clearWizardMessages();
        stopPairingPoll();
        fetch("/admin/setup-wizard/complete", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": window.OP_CSRF || ""
            }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const card = document.getElementById("op-setup-wizard");
                if (card) {
                    card.style.opacity = "0";
                    card.style.transform = "translateY(-30px)";
                    setTimeout(() => {
                        card.remove();
                        location.reload();
                    }, 500);
                }
            } else {
                showWizardError("Failed to complete onboarding.");
            }
        })
        .catch(err => {
            console.error(err);
            showWizardError("Network error while completing setup wizard.");
        });
    }

    function dismissWizard() {
        if (!confirm(window.OP_LANG && window.OP_LANG["wizard.dismiss_confirm"] ? window.OP_LANG["wizard.dismiss_confirm"] : "Are you sure you want to dismiss the setup wizard? You can configure settings later.")) {
            return;
        }
        clearWizardMessages();
        stopPairingPoll();
        fetch("/admin/setup-wizard/dismiss", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": window.OP_CSRF || ""
            }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const card = document.getElementById("op-setup-wizard");
                if (card) {
                    card.style.opacity = "0";
                    card.style.transform = "translateY(-30px)";
                    setTimeout(() => {
                        card.remove();
                        location.reload();
                    }, 500);
                }
            } else {
                showWizardError("Failed to dismiss onboarding.");
            }
        })
        .catch(err => {
            console.error(err);
            showWizardError("Network error while dismissing setup wizard.");
        });
    }

    // Set up event listeners once DOM content is loaded
    document.addEventListener("DOMContentLoaded", function () {
        // Step 1 Form
        const formStep1 = document.getElementById("form-step-1");
        if (formStep1) {
            formStep1.addEventListener("submit", function (e) {
                e.preventDefault();
                submitStep1();
            });
        }

        // Step 2 Form
        const formStep2 = document.getElementById("form-step-2");
        if (formStep2) {
            formStep2.addEventListener("submit", function (e) {
                e.preventDefault();
                submitStep2();
            });
        }

        // Step 3 Form
        const formStep3 = document.getElementById("form-step-3");
        if (formStep3) {
            formStep3.addEventListener("submit", function (e) {
                e.preventDefault();
                submitStep3();
            });
        }

        // Dismiss Wizard Button
        const dismissBtn = document.querySelector(".op-wizard-dismiss");
        if (dismissBtn) {
            dismissBtn.addEventListener("click", dismissWizard);
        }

        // Delegated Step Navigation Buttons (Prev buttons)
        document.addEventListener("click", function (e) {
            if (!e.target) {return;}
            const prevBtn = e.target.closest("[data-prev-step]");
            if (prevBtn) {
                const step = parseInt(prevBtn.getAttribute("data-prev-step"), 10);
                prevStep(step);
            }
        });

        // Mail Provider selection
        document.querySelectorAll("#panel-3 .op-choice-card").forEach(card => {
            card.addEventListener("click", function () {
                const provider = this.getAttribute("data-provider");
                if (provider) {selectMailProvider(provider);}
            });
        });

        // Gateway selection
        document.querySelectorAll("#panel-4 .op-choice-card").forEach(card => {
            card.addEventListener("click", function () {
                const gateway = this.getAttribute("data-gateway");
                if (gateway) {selectGateway(gateway);}
            });
        });

        // Skip Mail Step Button
        const skipMailBtn = document.getElementById("btn-skip-mail");
        if (skipMailBtn) {
            skipMailBtn.addEventListener("click", skipMailStep);
        }

        // Step 4 Gateway Save Button
        const saveGatewayBtn = document.getElementById("btn-save-gateway");
        if (saveGatewayBtn) {
            saveGatewayBtn.addEventListener("click", submitStep4);
        }

        // OTP Display copy action
        const otpDisplay = document.getElementById("op-wizard-otp-display");
        if (otpDisplay) {
            otpDisplay.addEventListener("click", copyOtpToClipboard);
        }

        // Finish Wizard Button
        const finishBtn = document.getElementById("btn-finish-wizard");
        if (finishBtn) {
            finishBtn.addEventListener("click", completeWizard);
        }
    });

}());
