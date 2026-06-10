/**
 * OwnPay Public Landing Page - Core Javascript Logic
 * File: public/assets/js/main.js
 */

document.addEventListener('DOMContentLoaded', () => {
    // ─── NAV SCROLL STYLE ───
    const navbar = document.getElementById('navbar');
    window.addEventListener('scroll', () => {
        if (window.scrollY > 20) {
            navbar.classList.add('scrolled');
        } else {
            navbar.classList.remove('scrolled');
        }
    });

    // ─── WAITLIST AJAX FORM ───
    const waitlistForm = document.getElementById('waitlist-form');
    const waitlistEmail = document.getElementById('waitlist-email');
    const waitlistBtn = document.getElementById('waitlist-btn');
    const waitlistSuccess = document.getElementById('waitlist-success');
    const waitlistError = document.getElementById('waitlist-error');
    const errorMsg = document.getElementById('error-msg');

    if (waitlistForm) {
        waitlistForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const email = waitlistEmail.value.trim();
            if (!email) return;

            // Simple client validation
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                showError('Please enter a valid email address.');
                return;
            }

            // Show loading state
            waitlistBtn.disabled = true;
            const btnText = waitlistBtn.querySelector('span');
            const originalText = btnText.textContent;
            btnText.textContent = 'Submitting...';

            try {
                const response = await fetch('/waitlist', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ email: email })
                });

                const data = await response.json();
                
                if (response.ok && data.success) {
                    // Show success
                    waitlistForm.classList.add('hidden');
                    waitlistError.classList.add('hidden');
                    waitlistSuccess.classList.remove('hidden');
                    waitlistSuccess.textContent = data.message || "Success! You are on the list.";
                } else {
                    showError(data.message || 'Something went wrong. Please try again.');
                }
            } catch (err) {
                showError('Could not connect to the server. Please try again later.');
            } finally {
                waitlistBtn.disabled = false;
                btnText.textContent = originalText;
            }
        });
    }

    function showError(msg) {
        waitlistError.classList.remove('hidden');
        errorMsg.textContent = msg;
    }

    // ─── GITHUB STARS API FETCH WITH AUTO-FALLBACK ───
    const githubStarsBadge = document.querySelectorAll('.github-stars-badge');
    const repoPath = 'own-pay/ownpay';

    if (githubStarsBadge.length > 0) {
        fetch(`https://api.github.com/repos/${repoPath}`)
            .then(res => {
                if (!res.ok) throw new Error('API Rate Limited or Not Found');
                return res.json();
            })
            .then(data => {
                const starsCount = data.stargazers_count;
                if (starsCount !== undefined) {
                    githubStarsBadge.forEach(badge => {
                        badge.textContent = `${starsCount} stars`;
                    });
                    // Silently sync stars back to server for cache
                    fetch('/api/sync-stars', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({ stars: starsCount })
                    }).catch(() => {});
                }
            })
            .catch(err => {
                // If GitHub API fails, falls back gracefully to the server-cached value already loaded in HTML
            });
    }

    // ─── FAQ ACCORDIONS ───
    const faqItems = document.querySelectorAll('.faq-item');
    faqItems.forEach(item => {
        const header = item.querySelector('.faq-header');
        header.addEventListener('click', () => {
            const isActive = item.classList.contains('active');
            
            // Close all items
            faqItems.forEach(i => {
                i.classList.remove('active');
                i.querySelector('.faq-content').style.maxHeight = null;
            });

            // Open clicked
            if (!isActive) {
                item.classList.add('active');
                const content = item.querySelector('.faq-content');
                content.style.maxHeight = content.scrollHeight + 'px';
            }
        });
    });

    // ─── INTERSECTION OBSERVER FOR SCROLL REVEALS ───
    const revealElements = document.querySelectorAll('.reveal');
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('reveal-active');
                // Trigger path animation on active node
                if (entry.target.classList.contains('deployment-flow')) {
                    const paths = entry.target.querySelectorAll('.flow-path');
                    paths.forEach(p => p.classList.add('flow-path-active'));
                }
                observer.unobserve(entry.target);
            }
        });
    }, {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    });

    revealElements.forEach(el => observer.observe(el));

    // ─── ANNOUNCEMENT BAR CLOSE ───
    const closeAnnounce = document.getElementById('close-announcement');
    const announceBar = document.getElementById('announcement-bar');
    if (closeAnnounce && announceBar) {
        closeAnnounce.addEventListener('click', () => {
            announceBar.style.display = 'none';
        });
    }
});
