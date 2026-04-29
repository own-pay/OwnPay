<?php
if (!defined('OWNPAY_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

if (!\OwnPay\Service\Auth\PermissionService::canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'plugins', $global_user_response['response'][0]['role'])) {
    http_response_code(403);
    exit('Access denied.');
}
?>

<!-- Page Header -->
<div class="op-page-header">
    <div>
        <div class="op-page-pretitle">Manage</div>
        <h2 class="op-page-title">Install Plugin</h2>
    </div>
    <div>
        <button onclick="load_content('Plugins', OP_DASHBOARD_URL + '/plugins', 'nav-item-plugins')" class="op-btn-secondary text-sm">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 me-1 inline" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l14 0"/><path d="M5 12l6 6"/><path d="M5 12l6 -6"/></svg>
            Back to Plugins
        </button>
    </div>
</div>

<!-- Upload Card -->
<div class="op-card max-w-xl">
    <div class="p-6">
        <div class="mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Upload Plugin ZIP</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400">Upload a plugin, gateway, or theme package as a .zip file. The package must contain a valid manifest.json.</p>
        </div>

        <form id="plugin-upload-form" enctype="multipart/form-data">
            <!-- Drop Zone -->
            <div id="drop-zone" class="flex flex-col items-center justify-center w-full h-48 border-2 border-dashed rounded-lg cursor-pointer border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors mb-4">
                <div class="flex flex-col items-center justify-center pt-5 pb-6" id="drop-zone-content">
                    <svg class="w-10 h-10 mb-3 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" /></svg>
                    <p class="mb-2 text-sm text-gray-500 dark:text-gray-400"><span class="font-semibold">Click to upload</span> or drag and drop</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">ZIP files only (max 50MB)</p>
                </div>
                <input id="plugin-file-input" type="file" class="hidden" accept=".zip" />
            </div>

            <!-- Selected file info -->
            <div id="file-info" class="hidden mb-4 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <svg class="w-5 h-5 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                    <span id="file-name" class="text-sm font-medium text-blue-700 dark:text-blue-300"></span>
                    <span id="file-size" class="text-xs text-blue-500 dark:text-blue-400"></span>
                </div>
                <button type="button" id="clear-file-btn" class="text-blue-500 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-200">
                    <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                </button>
            </div>

            <!-- Progress bar -->
            <div id="upload-progress" class="hidden mb-4">
                <div class="w-full bg-gray-200 rounded-full h-2 dark:bg-gray-700">
                    <div id="progress-bar" class="bg-blue-600 h-2 rounded-full transition-all" style="width: 0%"></div>
                </div>
                <p id="progress-text" class="text-xs text-gray-500 mt-1">Uploading...</p>
            </div>

            <!-- Result -->
            <div id="upload-result" class="hidden mb-4 p-3 rounded-lg"></div>

            <!-- Submit -->
            <button type="submit" id="upload-btn" class="op-btn-primary text-sm w-full" disabled>
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 me-1 inline" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" /></svg>
                Install Plugin
            </button>
        </form>
    </div>
</div>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
    window.OP_DASHBOARD_URL = '<?php echo $site_url . $path_admin ?>/dashboard';

    var dropZone = document.getElementById('drop-zone');
    var fileInput = document.getElementById('plugin-file-input');
    var fileInfo = document.getElementById('file-info');
    var uploadBtn = document.getElementById('upload-btn');
    var form = document.getElementById('plugin-upload-form');

    // Click to select file
    dropZone.addEventListener('click', function() { fileInput.click(); });

    // Drag and drop
    dropZone.addEventListener('dragover', function(e) {
        e.preventDefault();
        dropZone.classList.add('border-blue-500', 'bg-blue-50', 'dark:bg-blue-900/20');
    });
    dropZone.addEventListener('dragleave', function(e) {
        e.preventDefault();
        dropZone.classList.remove('border-blue-500', 'bg-blue-50', 'dark:bg-blue-900/20');
    });
    dropZone.addEventListener('drop', function(e) {
        e.preventDefault();
        dropZone.classList.remove('border-blue-500', 'bg-blue-50', 'dark:bg-blue-900/20');
        if (e.dataTransfer.files.length > 0) {
            fileInput.files = e.dataTransfer.files;
            showFileInfo(e.dataTransfer.files[0]);
        }
    });

    // File input change
    fileInput.addEventListener('change', function() {
        if (fileInput.files.length > 0) {
            showFileInfo(fileInput.files[0]);
        }
    });

    // Clear file
    document.getElementById('clear-file-btn').addEventListener('click', function() {
        fileInput.value = '';
        fileInfo.classList.add('hidden');
        uploadBtn.disabled = true;
        document.getElementById('upload-result').classList.add('hidden');
    });

    function showFileInfo(file) {
        document.getElementById('file-name').textContent = file.name;
        document.getElementById('file-size').textContent = formatBytes(file.size);
        fileInfo.classList.remove('hidden');

        var ext = file.name.split('.').pop().toLowerCase();
        uploadBtn.disabled = ext !== 'zip';
    }

    function formatBytes(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    // Form submit
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        if (!fileInput.files.length) return;

        var formData = new FormData();
        formData.append('plugin_file', fileInput.files[0]);
        formData.append('action', 'plugins-install');
        formData.append('op-token', window._ap_csrf_token || '');

        uploadBtn.disabled = true;
        document.getElementById('upload-progress').classList.remove('hidden');
        document.getElementById('upload-result').classList.add('hidden');

        var xhr = new XMLHttpRequest();
        xhr.open('POST', OP_DASHBOARD_URL, true);

        xhr.upload.addEventListener('progress', function(e) {
            if (e.lengthComputable) {
                var pct = Math.round((e.loaded / e.total) * 100);
                document.getElementById('progress-bar').style.width = pct + '%';
                document.getElementById('progress-text').textContent = 'Uploading... ' + pct + '%';
            }
        });

        xhr.onload = function() {
            document.getElementById('upload-progress').classList.add('hidden');
            uploadBtn.disabled = false;
            var resultDiv = document.getElementById('upload-result');
            resultDiv.classList.remove('hidden');
            resultDiv.replaceChildren();

            try {
                var res = JSON.parse(xhr.responseText);
                if (res.csrf_token) apRotateCsrf(res.csrf_token);

                var msg = document.createElement('p');
                msg.className = 'text-sm';

                if (res.status === 'true') {
                    resultDiv.className = 'mb-4 p-3 rounded-lg bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-300';
                    msg.textContent = res.message;
                    resultDiv.appendChild(msg);

                    var linkBtn = document.createElement('button');
                    linkBtn.type = 'button';
                    linkBtn.className = 'mt-2 text-sm font-medium text-green-700 dark:text-green-300 underline';
                    linkBtn.textContent = 'Go to Plugins';
                    linkBtn.addEventListener('click', function() {
                        load_content('Plugins', OP_DASHBOARD_URL + '/plugins', 'nav-item-plugins');
                    });
                    resultDiv.appendChild(linkBtn);

                    apToast('success', res.title, res.message);
                } else {
                    resultDiv.className = 'mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300';
                    msg.textContent = res.message;
                    resultDiv.appendChild(msg);
                    apToast('error', res.title, res.message);
                }
            } catch(err) {
                resultDiv.className = 'mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300';
                var errMsg = document.createElement('p');
                errMsg.className = 'text-sm';
                errMsg.textContent = 'Unexpected response from server.';
                resultDiv.appendChild(errMsg);
            }
        };

        xhr.onerror = function() {
            document.getElementById('upload-progress').classList.add('hidden');
            uploadBtn.disabled = false;
            apToastError();
        };

        xhr.send(formData);
    });
</script>
