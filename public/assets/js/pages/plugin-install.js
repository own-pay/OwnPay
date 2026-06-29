/**
 * OwnPay Admin - Plugin Install Page JS
 * Handles: drag-and-drop dropzone, file selection, upload submit state.
 */
(function () {
    "use strict";

    var dropzone = document.getElementById("dropzone");
    var fileInput = document.getElementById("plugin-file");
    var installBtn = document.getElementById("install-btn");
    var defaultView = document.getElementById("dropzone-default");
    var selectedView = document.getElementById("dropzone-selected");
    var filenameEl = document.getElementById("upload-filename");
    var filesizeEl = document.getElementById("upload-filesize");
    var clearBtn = document.getElementById("clear-file");

    if (!dropzone || !fileInput) { return; }

    dropzone.addEventListener("click", function (e) {
        if (e.target === clearBtn || (clearBtn && clearBtn.contains(e.target))) { return; }
        fileInput.click();
    });

    dropzone.addEventListener("dragover", function (e) {
        e.preventDefault();
        dropzone.classList.add("op-dropzone-hover");
    });

    dropzone.addEventListener("dragleave", function () {
        dropzone.classList.remove("op-dropzone-hover");
    });

    dropzone.addEventListener("drop", function (e) {
        e.preventDefault();
        dropzone.classList.remove("op-dropzone-hover");
        if (e.dataTransfer.files.length > 0) {
            fileInput.files = e.dataTransfer.files;
            onFileSelected(e.dataTransfer.files[0]);
        }
    });

    fileInput.addEventListener("change", function () {
        if (fileInput.files.length > 0) { onFileSelected(fileInput.files[0]); }
    });

    if (clearBtn) {
        clearBtn.addEventListener("click", function (e) {
            e.stopPropagation();
            fileInput.value = "";
            defaultView.style.display = "";
            selectedView.style.display = "none";
            installBtn.disabled = true;
        });
    }

    function onFileSelected(file) {
        if (!file.name.endsWith(".zip")) {
            alert("Only .zip files are allowed");
            return;
        }
        filenameEl.textContent = file.name;
        var sizeMB = (file.size / 1048576).toFixed(2);
        var sizeKB = (file.size / 1024).toFixed(1);
        filesizeEl.textContent = file.size > 1048576 ? sizeMB + " MB" : sizeKB + " KB";
        defaultView.style.display = "none";
        selectedView.style.display = "";
        installBtn.disabled = false;
    }

    var uploadForm = document.getElementById("plugin-upload-form");
    if (uploadForm) {
        uploadForm.addEventListener("submit", function () {
            installBtn.disabled = true;
            installBtn.innerHTML = '<span class="op-spinner-sm"></span> Installing...';
        });
    }

}());
