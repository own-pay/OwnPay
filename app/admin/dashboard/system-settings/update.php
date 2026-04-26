<?php
if (!defined('OWNPAY_INIT')) { http_response_code(403); exit('Direct access not allowed'); }
if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'system_settings', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'system_settings', 'view', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
$allowEdit = hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'system_settings', 'edit', $global_user_response['response'][0]['role']);
?>
<div class="op-page-header"><div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <nav class="flex mb-1"><ol class="inline-flex items-center space-x-1 text-sm text-gray-500"><li><a href="javascript:void(0)" onclick="load_content('Settings','<?php echo $site_url.$path_admin ?>/settings?tab=system','nav-item-settings')" class="hover:text-primary-600">Settings</a></li><li class="flex items-center"><svg class="w-3 h-3 mx-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg><span class="text-gray-900 dark:text-white">Update</span></li></ol></nav>
        <h2 class="op-page-title">System Update</h2>
    </div>
    <div class="flex items-center gap-2">
        <span class="global-loaderSpinner"></span>
        <?php if ($allowEdit): ?><button onclick="document.getElementById('modal-updateSettings').classList.remove('hidden')" class="op-btn-secondary text-sm">⚙ Settings</button><?php endif; ?>
    </div>
</div></div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Current Version Card -->
    <div class="op-card p-6">
        <div class="flex items-center gap-4 mb-4">
            <div class="flex-shrink-0 w-12 h-12 rounded-lg bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center">
                <svg class="w-6 h-6 text-primary-600 dark:text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            </div>
            <div>
                <h3 class="text-lg font-semibold dark:text-white">Current Version</h3>
                <p class="text-2xl font-bold text-primary-600 dark:text-primary-400" id="current-version">—</p>
            </div>
        </div>
        <div id="version-details" class="space-y-2 text-sm text-gray-600 dark:text-gray-400">
            <div class="flex justify-between"><span>Build Date:</span><span id="build-date">—</span></div>
            <div class="flex justify-between"><span>Channel:</span><span id="update-channel" class="op-badge-primary">—</span></div>
            <div class="flex justify-between"><span>Last Check:</span><span id="last-check">—</span></div>
        </div>
    </div>

    <!-- Update Status Card -->
    <div class="op-card p-6">
        <div class="flex items-center gap-4 mb-4">
            <div class="flex-shrink-0 w-12 h-12 rounded-lg bg-green-100 dark:bg-green-900/30 flex items-center justify-center" id="update-status-icon">
                <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            </div>
            <div>
                <h3 class="text-lg font-semibold dark:text-white" id="update-status-title">Checking for updates...</h3>
                <p class="text-sm text-gray-500" id="update-status-msg">Please wait</p>
            </div>
        </div>
        <div id="update-available" class="hidden">
            <div class="p-3 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg mb-3">
                <p class="text-sm font-medium text-yellow-800 dark:text-yellow-300">New version <span id="new-version" class="font-bold"></span> available!</p>
                <p class="text-xs text-yellow-600 dark:text-yellow-400 mt-1" id="new-version-notes"></p>
            </div>
            <?php if ($allowEdit): ?><button class="op-btn-primary w-full" onclick="startUpdate()">Update Now</button><?php endif; ?>
        </div>
        <div class="flex gap-2 mt-3">
            <button onclick="checkForUpdates()" class="op-btn-secondary text-sm flex-1">Check Now</button>
        </div>
    </div>
</div>

<!-- Update Progress -->
<div id="update-progress" class="hidden mt-6 op-card p-6">
    <h3 class="text-lg font-semibold mb-4 dark:text-white">Update Progress</h3>
    <div class="w-full bg-gray-200 rounded-full h-3 dark:bg-gray-700 mb-2"><div id="progress-bar" class="bg-primary-600 h-3 rounded-full transition-all duration-500" style="width:0%"></div></div>
    <p class="text-sm text-gray-500" id="progress-text">Initializing...</p>
    <div id="update-log" class="mt-4 max-h-48 overflow-y-auto bg-gray-50 dark:bg-gray-900 rounded-lg p-3 text-xs font-mono text-gray-600 dark:text-gray-400"></div>
</div>

<!-- Settings Modal -->
<div id="modal-updateSettings" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50"><div class="relative w-full max-w-lg p-4"><div class="bg-white rounded-lg shadow dark:bg-gray-800">
    <div class="flex items-center justify-between p-4 border-b dark:border-gray-700"><h3 class="text-lg font-semibold dark:text-white">Update Settings</h3><button onclick="document.getElementById('modal-updateSettings').classList.add('hidden')" class="text-gray-400 hover:text-gray-900"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button></div>
    <div class="p-4 space-y-4">
        <div><label class="op-label">Update Channel</label><select class="op-select" name="update-channel"><option value="stable">Stable</option><option value="beta">Beta</option></select></div>
        <div><label class="flex items-center gap-3 cursor-pointer"><input type="checkbox" class="op-checkbox" name="auto-check" checked><span class="text-sm">Automatically check for updates</span></label></div>
        <div><label class="flex items-center gap-3 cursor-pointer"><input type="checkbox" class="op-checkbox" name="backup-before-update" checked><span class="text-sm">Create backup before updating</span></label></div>
    </div>
    <div class="flex justify-end gap-2 p-4 border-t dark:border-gray-700"><button onclick="document.getElementById('modal-updateSettings').classList.add('hidden')" class="op-btn-secondary">Cancel</button><button class="op-btn-primary" onclick="saveUpdateSettings()">Save Settings</button></div>
</div></div></div>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
window.OP_DASHBOARD_URL='<?php echo $site_url.$path_admin ?>/dashboard';

function checkForUpdates(){
    document.getElementById('update-status-title').textContent='Checking for updates...';
    document.getElementById('update-status-msg').textContent='Please wait';
    document.getElementById('update-available').classList.add('hidden');
    document.querySelector('.global-loaderSpinner').innerHTML='<div class="op-spinner"></div>';
    opFetch('update-check',{}).then(res=>{
        document.querySelector('.global-loaderSpinner').innerHTML='';
        if(res.status==='true'){
            document.getElementById('current-version').textContent=res.current_version||'—';
            document.getElementById('build-date').textContent=res.build_date||'—';
            document.getElementById('update-channel').textContent=res.channel||'stable';
            document.getElementById('last-check').textContent=new Date().toLocaleString();
            if(res.update_available==='true'){
                document.getElementById('update-status-title').textContent='Update Available';
                document.getElementById('update-status-msg').textContent='A new version is ready to install.';
                document.getElementById('update-status-icon').innerHTML='<svg class="w-6 h-6 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>';
                document.getElementById('new-version').textContent=res.new_version||'';
                document.getElementById('new-version-notes').textContent=res.release_notes||'';
                document.getElementById('update-available').classList.remove('hidden');
            }else{
                document.getElementById('update-status-title').textContent='Up to Date';
                document.getElementById('update-status-msg').textContent='You are running the latest version.';
                document.getElementById('update-status-icon').innerHTML='<svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>';
            }
        }else{
            document.getElementById('update-status-title').textContent='Check Failed';
            document.getElementById('update-status-msg').textContent=res.message||'Could not check for updates.';
        }
    }).catch(e=>{document.querySelector('.global-loaderSpinner').innerHTML='';apToastError();});
}

function startUpdate(){
    if(!confirm('Start system update? A backup will be created first.'))return;
    document.getElementById('update-progress').classList.remove('hidden');
    document.getElementById('progress-bar').style.width='10%';
    document.getElementById('progress-text').textContent='Creating backup...';
    appendLog('Starting update process...');
    opFetch('update-start',{}).then(res=>{
        if(res.status==='true'){
            document.getElementById('progress-bar').style.width='30%';
            document.getElementById('progress-text').textContent='Downloading update...';
            appendLog('Backup created successfully.');
            pollUpdateProgress();
        }else{
            document.getElementById('progress-text').textContent='Update failed: '+res.message;
            appendLog('ERROR: '+res.message);
            apToast('error','Update Failed',res.message);
        }
    }).catch(e=>{document.getElementById('progress-text').textContent='Update failed.';apToastError();});
}

function pollUpdateProgress(){
    opFetch('update-progress',{}).then(res=>{
        if(res.status==='true'){
            const pct=parseInt(res.progress)||0;
            document.getElementById('progress-bar').style.width=pct+'%';
            document.getElementById('progress-text').textContent=res.step||'Processing...';
            if(res.log)appendLog(res.log);
            if(pct<100){setTimeout(pollUpdateProgress,2000);}
            else{appendLog('Update completed successfully!');apToast('success','Updated','System updated to '+res.new_version);}
        }else{document.getElementById('progress-text').textContent='Failed: '+res.message;appendLog('ERROR: '+res.message);}
    }).catch(e=>setTimeout(pollUpdateProgress,3000));
}

function appendLog(msg){const l=document.getElementById('update-log');l.innerHTML+=`<div>[${new Date().toLocaleTimeString()}] ${msg}</div>`;l.scrollTop=l.scrollHeight;}

function saveUpdateSettings(){
    const m=document.getElementById('modal-updateSettings');
    const channel=m.querySelector('select[name="update-channel"]').value;
    const autoCheck=m.querySelector('input[name="auto-check"]').checked?'1':'0';
    const backup=m.querySelector('input[name="backup-before-update"]').checked?'1':'0';
    opFetch('update-settings-save',{channel,auto_check:autoCheck,backup_before_update:backup}).then(res=>{
        m.classList.add('hidden');
        res.status==='true'?apToast('success',res.title,res.message):apToast('error',res.title,res.message);
        checkForUpdates();
    }).catch(e=>apToastError());
}

// Load settings and check on init
opFetch('update-settings-load',{}).then(res=>{
    if(res.status==='true'){
        const m=document.getElementById('modal-updateSettings');
        if(res.channel)m.querySelector('select[name="update-channel"]').value=res.channel;
        if(res.auto_check!==undefined)m.querySelector('input[name="auto-check"]').checked=res.auto_check==='1';
        if(res.backup!==undefined)m.querySelector('input[name="backup-before-update"]').checked=res.backup==='1';
    }
}).catch(()=>{});
checkForUpdates();
</script>
