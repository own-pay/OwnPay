/**
 * OwnPay Admin — Roles & Permissions Page JS
 * Extracted from templates/admin/roles/index.twig
 * Handles: edit role modal, permission group toggle.
 */
(function () {
    "use strict";

    window.openEditModal = function (role) {
        document.getElementById("edit-role-name-display").textContent = role.name;
        document.getElementById("edit-role-name").value = role.name;
        document.getElementById("edit-role-desc").value = role.description || "";
        document.getElementById("edit-role-form").action = "/admin/roles/" + role.id + "/update";

        document.querySelectorAll(".perm-cb").forEach(function (cb) { cb.checked = false; });
        var perms = role.permissions || [];
        perms.forEach(function (slug) {
            var cb = document.querySelector('.perm-cb[data-slug="' + slug + '"]');
            if (cb) {cb.checked = true;}
        });

        document.getElementById("edit-role-modal").style.display = "flex";
    };

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
