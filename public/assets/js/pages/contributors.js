/**
 * OwnPay Dashboard Contributors Page JS
 * Handles anonymous dynamic fetching of Code Contributors, the Security Hall of
 * Fame, and Custom Contributors from public GitHub sources, with 24-hour
 * localStorage caching, de-duplication, and XSS-safe rendering.
 */
(function () {
    "use strict";

    var GRID_ID = "dynamic-contributors-grid";
    var HALL_OF_FAME_ID = "dynamic-hall-of-fame";
    var CUSTOM_GRID_ID = "dynamic-custom-contributors-grid";
    var CACHE_DURATION = 86400000; // 24 hours in milliseconds
    var LEAD_IDENTITY = "fattain-naime";
    var API_URL = "https://api.github.com/repos/own-pay/OwnPay/contributors";
    var HALL_OF_FAME_URL = "https://raw.githubusercontent.com/own-pay/.github/main/halloffarm.json";
    var CUSTOM_CONTRIBUTORS_URL = "https://raw.githubusercontent.com/own-pay/.github/main/contributors.json";

    var CACHE_KEYS = {
        contributors: { cache: "ownpay_contributors_cache", timestamp: "ownpay_contributors_timestamp" },
        hallOfFame: { cache: "ownpay_hall_of_fame_cache", timestamp: "ownpay_hall_of_fame_timestamp" },
        custom: { cache: "ownpay_custom_contributors_cache", timestamp: "ownpay_custom_contributors_timestamp" }
    };

    var grid = document.getElementById(GRID_ID);
    var hallOfFameContainer = document.getElementById(HALL_OF_FAME_ID);
    var customGrid = document.getElementById(CUSTOM_GRID_ID);

    /**
     * Extracts the raw entries list from a Hall of Fame JSON payload.
     * Accepts either a bare array or an object wrapping an "entries" array.
     * @param {*} data The parsed JSON payload.
     * @returns {Array} List of hall of fame entries.
     */
    function extractEntries(data) {
        if (Array.isArray(data)) {
            return data;
        }
        if (data && Array.isArray(data.entries)) {
            return data.entries;
        }
        return [];
    }

    /**
     * Extracts the contributor list from a GitHub-style JSON payload.
     * Accepts either a bare array or an object wrapping a "contributors" array.
     * @param {*} data The parsed JSON payload.
     * @returns {Array} List of contributors.
     */
    function extractContributors(data) {
        if (Array.isArray(data)) {
            return data;
        }
        if (data && Array.isArray(data.contributors)) {
            return data.contributors;
        }
        return [];
    }

    /**
     * Extracts GitHub API contributors, excluding the server-rendered lead card
     * identity so it is not duplicated.
     * @param {*} data The parsed GitHub contributors API payload.
     * @returns {Array} List of contributors excluding the lead identity.
     */
    function extractGitHubContributors(data) {
        return extractContributors(data).filter(function (user) {
            return user && user.login && user.login.toLowerCase() !== LEAD_IDENTITY.toLowerCase();
        });
    }

    /**
     * Builds a safe link or plain text node. Never interpolates into innerHTML.
     * @param {String} text The visible text.
     * @param {String} url Optional href; when empty a text node is returned.
     * @returns {Node} The link or text node.
     */
    function buildLinkedText(text, url) {
        if (text && url) {
            var link = document.createElement("a");
            link.setAttribute("href", url);
            link.setAttribute("target", "_blank");
            link.setAttribute("rel", "noopener");
            link.className = "op-contributor-name-link";
            link.textContent = text;
            return link;
        }
        return document.createTextNode(text || "");
    }

    /**
     * Maps a severity string to its badge CSS class.
     * @param {String} severity The raw severity value.
     * @returns {String} Badge class suffix.
     */
    function getSeverityClass(severity) {
        var value = String(severity || "").toLowerCase();
        if (value.indexOf("high") !== -1) {
            return "op-sev-high";
        }
        if (value.indexOf("medium") !== -1 || value.indexOf("med") !== -1) {
            return "op-sev-medium";
        }
        if (value.indexOf("low") !== -1) {
            return "op-sev-low";
        }
        return "op-sev-other";
    }

    /**
     * Renders the Hall of Fame table from loaded entries.
     * @param {Array} entries List of hall of fame entries.
     */
    function renderHallOfFame(entries) {
        if (!hallOfFameContainer) {
            return;
        }
        hallOfFameContainer.innerHTML = "";
        if (!entries || entries.length === 0) {
            setPlaceholder(hallOfFameContainer, "No Hall of Fame entries available yet.");
            return;
        }

        var table = document.createElement("table");
        table.className = "op-hall-of-fame-table";

        var thead = document.createElement("thead");
        var headRow = document.createElement("tr");
        ["Contributor", "Vulnerability", "Severity", "CVE", "Year"].forEach(function (label) {
            var th = document.createElement("th");
            th.setAttribute("scope", "col");
            th.textContent = label;
            headRow.appendChild(th);
        });
        thead.appendChild(headRow);
        table.appendChild(thead);

        var tbody = document.createElement("tbody");
        entries.forEach(function (entry) {
            if (!entry || !entry.name) {
                return;
            }

            var tr = document.createElement("tr");

            var tdName = document.createElement("td");
            tdName.appendChild(buildLinkedText(entry.name, entry.name_url || entry.url || ""));
            tr.appendChild(tdName);

            var tdVuln = document.createElement("td");
            tdVuln.textContent = entry.vulnerability || "";
            tr.appendChild(tdVuln);

            var tdSev = document.createElement("td");
            var badge = document.createElement("span");
            badge.className = "op-sev-badge " + getSeverityClass(entry.severity);
            badge.textContent = entry.severity || "N/A";
            tdSev.appendChild(badge);
            tr.appendChild(tdSev);

            var tdCve = document.createElement("td");
            var cve = entry.cve || "";
            if (cve && entry.cve_url) {
                tdCve.appendChild(buildLinkedText(cve, entry.cve_url));
            } else {
                tdCve.textContent = cve || "N/A";
            }
            tr.appendChild(tdCve);

            var tdYear = document.createElement("td");
            tdYear.textContent = entry.year ? String(entry.year) : "";
            tr.appendChild(tdYear);

            tbody.appendChild(tr);
        });
        table.appendChild(tbody);

        hallOfFameContainer.appendChild(table);
    }

    /**
     * Renders a single contributor card from safe DOM building.
     * @param {Object} data Contributor data.
     * @returns {Element} The card element.
     */
    function buildContributorCard(data) {
        var card = document.createElement("div");
        card.className = "op-contributor-card";

        var avatarContainer = document.createElement("div");
        avatarContainer.className = "op-contributor-avatar";

        if (data.avatar_url) {
            var img = document.createElement("img");
            img.setAttribute("src", data.avatar_url);
            img.setAttribute("alt", data.name || data.login || "");
            img.style.width = "100%";
            img.style.height = "100%";
            img.style.borderRadius = "50%";
            img.style.objectFit = "cover";
            avatarContainer.appendChild(img);
        } else {
            avatarContainer.textContent = (data.name || data.login || "?").substring(0, 2).toUpperCase();
        }

        var info = document.createElement("div");
        info.className = "op-contributor-info";

        var name = document.createElement("div");
        name.className = "op-contributor-name";
        name.appendChild(buildLinkedText(data.name || data.login, data.name_url || data.url || ""));

        var role = document.createElement("div");
        role.className = "op-contributor-role";
        role.textContent = data.role || "Contributor";

        info.appendChild(name);
        info.appendChild(role);

        if (data.commits) {
            var commits = document.createElement("div");
            commits.className = "op-contributor-commits";
            commits.textContent = data.commits + " commit" + (data.commits !== 1 ? "s" : "");
            info.appendChild(commits);
        }

        card.appendChild(avatarContainer);
        card.appendChild(info);

        return card;
    }

    /**
     * Renders contributor cards in the dynamic grid, preserving the fixed lead card.
     * @param {Array} contributors List of filtered contributors to render.
     */
    function renderContributors(contributors) {
        if (!grid) {
            return;
        }

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

            grid.appendChild(buildContributorCard({
                name: c.login,
                name_url: "https://github.com/" + c.login,
                avatar_url: c.avatar_url,
                role: "Contributor",
                commits: c.contributions
            }));
        });
    }

    /**
     * Renders custom contributor cards in the custom contributors grid.
     * @param {Array} contributors List of custom contributors to render.
     */
    function renderCustomContributors(contributors) {
        if (!customGrid) {
            return;
        }
        customGrid.innerHTML = "";
        if (!contributors || contributors.length === 0) {
            setPlaceholder(customGrid, "No custom contributors available yet.");
            return;
        }
        contributors.forEach(function (c) {
            if (!c || !c.name) {
                return;
            }
            customGrid.appendChild(buildContributorCard(c));
        });
    }

    /**
     * Replaces the container children with a muted placeholder message.
     * @param {Element} container The target element.
     * @param {String} message The placeholder text.
     */
    function setPlaceholder(container, message) {
        if (!container) {
            return;
        }
        container.innerHTML = "";
        var note = document.createElement("div");
        note.className = "op-contributors-loading";
        note.textContent = message;
        container.appendChild(note);
    }

    /**
     * Gets cached data and its timestamp for a dataset.
     * @param {Object} keys Cache and timestamp localStorage keys.
     * @returns {Object|null} Cached payload or null.
     */
    function getCachedData(keys) {
        try {
            var cache = localStorage.getItem(keys.cache);
            var timestamp = localStorage.getItem(keys.timestamp);
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
     * Updates localStorage with data and a fresh timestamp for a dataset.
     * @param {Object} keys Cache and timestamp localStorage keys.
     * @param {*} data The data to persist.
     */
    function setCachedData(keys, data) {
        try {
            localStorage.setItem(keys.cache, JSON.stringify(data));
            localStorage.setItem(keys.timestamp, Date.now().toString());
        } catch (e) {
            console.error("[OwnPay] Error writing localStorage cache:", e);
        }
    }

    /**
     * Performs a JSON fetch from a public GitHub source.
     * @param {String} url The endpoint to fetch.
     * @returns {Promise<*>} The parsed JSON response.
     */
    function fetchJson(url) {
        return fetch(url)
            .then(function (response) {
                if (!response.ok) {
                    throw new Error("GitHub source responded with status: " + response.status);
                }
                return response.json();
            });
    }

    /**
     * Fetch (or reuse cached) data for a dataset, render it, and persist it.
     * Falls back to expired cache on network failure; renders empty on no data.
     * @param {Object} keys Cache and timestamp localStorage keys.
     * @param {String} url The endpoint to fetch.
     * @param {Function} extract Normalizes the raw payload into an array.
     * @param {Function} render Renders the normalized array.
     */
    function loadDataset(keys, url, extract, render) {
        var cached = getCachedData(keys);
        var now = Date.now();

        if (cached && (now - cached.timestamp < CACHE_DURATION)) {
            render(extract(cached.data));
            return;
        }

        fetchJson(url)
            .then(function (data) {
                setCachedData(keys, data);
                render(extract(data));
            })
            .catch(function (error) {
                console.warn("[OwnPay] Failed to load fresh data from " + url + ":", error.message);
                if (cached && cached.data) {
                    console.log("[OwnPay] Rendering expired local cache fallback.");
                    render(extract(cached.data));
                } else {
                    render([]);
                }
            });
    }

    /**
     * Initialization logic: loads every section present on the page.
     */
    function init() {
        if (grid) {
            loadDataset(CACHE_KEYS.contributors, API_URL, extractGitHubContributors, renderContributors);
        }
        if (hallOfFameContainer) {
            loadDataset(CACHE_KEYS.hallOfFame, HALL_OF_FAME_URL, extractEntries, renderHallOfFame);
        }
        if (customGrid) {
            loadDataset(CACHE_KEYS.custom, CUSTOM_CONTRIBUTORS_URL, extractContributors, renderCustomContributors);
        }
    }

    // Run the cycle
    init();
})();
