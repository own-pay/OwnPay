/**
 * OwnPay Dashboard Contributors Page JS
 * Handles anonymous dynamic fetching of contributors from GitHub,
 * 24-hour caching, de-duplication, and XSS-safe rendering.
 */
(function () {
    "use strict";

    var GRID_ID = "dynamic-contributors-grid";
    var CACHE_KEY = "ownpay_contributors_cache";
    var TIMESTAMP_KEY = "ownpay_contributors_timestamp";
    var CACHE_DURATION = 86400000; // 24 hours in milliseconds
    var LEAD_IDENTITY = "fattain-naime";
    var API_URL = "https://api.github.com/repos/own-pay/OwnPay/contributors";

    var grid = document.getElementById(GRID_ID);
    if (!grid) {
        return;
    }

    /**
     * Renders contributor cards safely in the grid, preserving the fixed lead card.
     * @param {Array} contributors List of filtered contributors to render.
     */
    function renderContributors(contributors) {
        // Keep only the first child (the server-rendered fixed lead card)
        var fixedCard = grid.firstElementChild;
        grid.innerHTML = "";
        if (fixedCard) {
            grid.appendChild(fixedCard);
        }

        contributors.forEach(function (c) {
            if (!c || !c.login) {
                return;
            }

            var card = document.createElement("div");
            card.className = "op-contributor-card";

            var avatarContainer = document.createElement("div");
            avatarContainer.className = "op-contributor-avatar";

            if (c.avatar_url) {
                var img = document.createElement("img");
                img.setAttribute("src", c.avatar_url);
                img.setAttribute("alt", c.login);
                img.style.width = "100%";
                img.style.height = "100%";
                img.style.borderRadius = "50%";
                img.style.objectFit = "cover";
                avatarContainer.appendChild(img);
            } else {
                avatarContainer.textContent = c.login.substring(0, 2).toUpperCase();
            }

            var info = document.createElement("div");
            info.className = "op-contributor-info";

            var name = document.createElement("div");
            name.className = "op-contributor-name";

            var link = document.createElement("a");
            link.className = "op-contributor-name-link";
            link.setAttribute("href", "https://github.com/" + c.login);
            link.setAttribute("target", "_blank");
            link.setAttribute("rel", "noopener");
            link.textContent = c.login; // Strict XSS protection

            name.appendChild(link);

            var role = document.createElement("div");
            role.className = "op-contributor-role";
            role.textContent = "Contributor";

            var commits = document.createElement("div");
            commits.className = "op-contributor-commits";
            commits.textContent = c.contributions + " commit" + (c.contributions !== 1 ? "s" : "");

            info.appendChild(name);
            info.appendChild(role);
            info.appendChild(commits);

            card.appendChild(avatarContainer);
            card.appendChild(info);

            grid.appendChild(card);
        });
    }

    /**
     * Gets cached contributor data and its timestamp.
     * @returns {Object|null} Cached payload or null.
     */
    function getCachedData() {
        try {
            var cache = localStorage.getItem(CACHE_KEY);
            var timestamp = localStorage.getItem(TIMESTAMP_KEY);
            if (cache && timestamp) {
                return {
                    data: JSON.parse(cache),
                    timestamp: parseInt(timestamp, 10)
                };
            }
        } catch (e) {
            console.error("[OwnPay] Error reading localStorage cache:", e);
        }
        return null;
    }

    /**
     * Updates localStorage with filtered contributors data and a fresh timestamp.
     * @param {Array} data The filtered contributors array.
     */
    function setCachedData(data) {
        try {
            localStorage.setItem(CACHE_KEY, JSON.stringify(data));
            localStorage.setItem(TIMESTAMP_KEY, Date.now().toString());
        } catch (e) {
            console.error("[OwnPay] Error writing localStorage cache:", e);
        }
    }

    /**
     * Performs anonymous public fetch of contributors from GitHub, filters, and maps.
     * @returns {Promise<Array>} The optimized contributors array.
     */
    function fetchContributors() {
        return fetch(API_URL)
            .then(function (response) {
                if (!response.ok) {
                    throw new Error("GitHub API responded with status: " + response.status);
                }
                return response.json();
            })
            .then(function (rawList) {
                if (!Array.isArray(rawList)) {
                    throw new Error("Invalid GitHub API response payload structure.");
                }

                // Filter out the fixed lead and optimize JSON size
                return rawList
                    .filter(function (user) {
                        return user && user.login && user.login.toLowerCase() !== LEAD_IDENTITY.toLowerCase();
                    })
                    .map(function (user) {
                        return {
                            login: user.login,
                            avatar_url: user.avatar_url,
                            contributions: user.contributions
                        };
                    });
            });
    }

    /**
     * Initialization logic.
     */
    function init() {
        var cached = getCachedData();
        var now = Date.now();

        // Phase A: Cache Evaluation
        if (cached && (now - cached.timestamp < CACHE_DURATION)) {
            renderContributors(cached.data);
            return;
        }

        // Phase B & C & D: Fetch, Filter, Cache, and DOM Insertion
        fetchContributors()
            .then(function (data) {
                setCachedData(data);
                renderContributors(data);
            })
            .catch(function (error) {
                console.warn("[OwnPay] Failed to load fresh contributors from GitHub:", error.message);
                // Phase 3 Fallback: Load expired cache to prevent blank space
                if (cached && cached.data) {
                    console.log("[OwnPay] Rendering expired local cache fallback.");
                    renderContributors(cached.data);
                } else {
                    console.error("[OwnPay] No local cache available for fallback.");
                }
            });
    }

    // Run the cycle
    init();
})();
