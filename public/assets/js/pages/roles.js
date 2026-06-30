/**
 * OwnPay Admin - Roles & Permissions Page JS
 * Handles: edit role modal, permission group toggle.
 */
(function () {
    "use strict";

    function openEditRole(role) {
        var nameDisplay = document.getElementById("edit-role-name-display");
        var nameInput = document.getElementById("edit-role-name");
        var descInput = document.getElementById("edit-role-desc");
        var form = document.getElementById("edit-role-form");

        if (nameDisplay) { nameDisplay.textContent = role.name; }
        if (nameInput) { nameInput.value = role.name; }
        if (descInput) { descInput.value = role.description || ""; }
        if (form) { form.action = "/admin/roles/" + role.id + "/update"; }

        document.querySelectorAll(".perm-cb").forEach(function (cb) { cb.checked = false; });
        var perms = role.permissions || [];
        perms.forEach(function (slug) {
            var cb = document.querySelector('.perm-cb[data-slug="' + slug + '"]');
            if (cb) { cb.checked = true; }
        });

        var modal = document.getElementById("edit-role-modal");
        if (modal) {
            modal.hidden = false;
        }
    }

    // Delegate click on Edit Role buttons
    if (!document.opRolesClickRegistered) {
        document.opRolesClickRegistered = true;
        document.addEventListener("click", function (e) {
            if (!e.target) { return; }
            var btn = e.target.closest(".btn-edit-role");
            if (btn) {
                try {
                    var roleData = JSON.parse(btn.getAttribute("data-role"));
                    openEditRole(roleData);
                } catch (err) {
                    console.error("Failed to parse role data", err);
                }
            }
        });
    }

    document.querySelectorAll(".op-group-toggle").forEach(function (toggle) {
        toggle.addEventListener("change", function () {
            var group = this.dataset.group;
            var checked = this.checked;
            document.querySelectorAll('.perm-cb[data-group="' + group + '"]').forEach(function (cb) {
                cb.checked = checked;
            });
        });
    });

}());
