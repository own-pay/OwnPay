<?php
declare(strict_types=1);
// Standalone API Tester for OwnPay Merchant API
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OwnPay Merchant API Tester</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: '#3b82f6',
                        dark: '#0f172a',
                        darker: '#020617',
                        panel: '#1e293b'
                    }
                }
            }
        }
    </script>
    <style>
        body { background-color: #020617; color: #f8fafc; font-family: 'Inter', sans-serif; }
        .glass { background: rgba(30, 41, 59, 0.7); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.1); }
        .endpoint-btn { transition: all 0.2s; }
        .endpoint-btn:hover { transform: translateX(5px); background: rgba(59, 130, 246, 0.2); border-color: #3b82f6; }
        .endpoint-btn.active { background: rgba(59, 130, 246, 0.3); border-color: #3b82f6; border-left-width: 4px; }
        pre { white-space: pre-wrap; word-wrap: break-word; }
        .method-GET { color: #10b981; }
        .method-POST { color: #f59e0b; }
        .method-PUT { color: #3b82f6; }
        .method-DELETE { color: #ef4444; }
    </style>
</head>
<body class="h-screen flex flex-col overflow-hidden">
    <header class="glass p-4 flex justify-between items-center z-10">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center font-bold text-white">OP</div>
            <h1 class="text-xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-blue-400 to-purple-400">Merchant API Tester</h1>
        </div>
        <div class="flex gap-4 items-center w-1/2">
            <input type="text" id="baseUrl" value="https://ownpay.test" class="bg-dark border border-gray-700 rounded px-3 py-1.5 text-sm w-1/3 focus:border-primary outline-none transition" placeholder="Base URL">
            <input type="password" id="apiKey" class="bg-dark border border-gray-700 rounded px-3 py-1.5 text-sm w-2/3 focus:border-primary outline-none transition" placeholder="Bearer API Key (op_xxxxxxxx.yyyyyyyy...)">
        </div>
    </header>

    <div class="flex flex-1 overflow-hidden">
        <!-- Sidebar -->
        <div class="w-80 glass overflow-y-auto flex flex-col p-2 z-0" id="sidebar">
            <div class="text-xs text-gray-400 font-semibold uppercase tracking-wider mb-2 px-2 mt-2">Endpoints</div>
            <!-- Populated by JS -->
        </div>

        <!-- Main Workspace -->
        <div class="flex-1 flex flex-col p-6 overflow-y-auto bg-darker">
            <div id="welcomePanel" class="flex-1 flex items-center justify-center text-gray-500 flex-col gap-4">
                <svg class="w-16 h-16 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                <p>Select an endpoint from the sidebar to begin.</p>
            </div>

            <div id="requestPanel" class="hidden flex-col h-full gap-4">
                <!-- Request Config -->
                <div class="glass p-5 rounded-xl shadow-lg border border-gray-700 relative overflow-hidden group">
                    <div class="absolute inset-0 bg-gradient-to-r from-blue-500/5 to-purple-500/5 opacity-0 group-hover:opacity-100 transition-opacity"></div>
                    <div class="flex justify-between items-start mb-4 relative z-10">
                        <div>
                            <h2 class="text-2xl font-bold font-mono flex items-center gap-3">
                                <span id="reqMethod" class="font-black text-sm px-2 py-1 rounded bg-gray-800">GET</span>
                                <span id="reqPath" class="text-gray-200">/api/v1/health</span>
                            </h2>
                            <p id="reqDesc" class="text-sm text-gray-400 mt-2">Check system health.</p>
                        </div>
                        <button id="sendBtn" class="bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white font-bold py-2 px-6 rounded-lg shadow-[0_0_15px_rgba(59,130,246,0.5)] transition transform hover:scale-105 active:scale-95 flex items-center gap-2">
                            <span>Send Request</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path></svg>
                        </button>
                    </div>

                    <div id="pathParamsContainer" class="hidden relative z-10">
                        <h3 class="text-sm font-semibold text-gray-400 mb-2">Path Variables</h3>
                        <div id="pathParamsList" class="flex flex-col gap-2 mb-4"></div>
                    </div>

                    <div id="bodyContainer" class="hidden relative z-10">
                        <h3 class="text-sm font-semibold text-gray-400 mb-2 flex justify-between">
                            <span>JSON Body</span>
                            <button id="formatBodyBtn" class="text-xs text-blue-400 hover:text-blue-300">Format</button>
                        </h3>
                        <textarea id="reqBody" class="w-full h-40 bg-[#0f172a] border border-gray-700 rounded-lg p-3 font-mono text-sm text-green-400 focus:border-blue-500 outline-none resize-y transition shadow-inner" spellcheck="false"></textarea>
                    </div>
                </div>

                <!-- Response Panel -->
                <div class="flex-1 glass p-5 rounded-xl shadow-lg border border-gray-700 flex flex-col relative">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold text-gray-200">Response</h3>
                        <div class="flex gap-3 text-sm font-mono">
                            <span id="resStatus" class="px-2 py-1 rounded bg-gray-800 text-gray-400">Status: -</span>
                            <span id="resTime" class="px-2 py-1 rounded bg-gray-800 text-gray-400">Time: -</span>
                        </div>
                    </div>
                    
                    <!-- Tabs -->
                    <div class="flex gap-1 border-b border-gray-700 mb-4">
                        <button class="res-tab px-4 py-2 text-sm font-medium text-blue-400 border-b-2 border-blue-400" data-target="resBodyPanel">Body</button>
                        <button class="res-tab px-4 py-2 text-sm font-medium text-gray-400 border-b-2 border-transparent hover:text-gray-300" data-target="resHeadersPanel">Headers</button>
                        <button class="res-tab px-4 py-2 text-sm font-medium text-gray-400 border-b-2 border-transparent hover:text-gray-300" data-target="resRawPanel">Raw</button>
                    </div>

                    <div class="flex-1 overflow-auto bg-[#0f172a] rounded-lg border border-gray-700 p-4 shadow-inner relative">
                        <div id="loader" class="hidden absolute inset-0 bg-[#0f172a]/80 backdrop-blur-sm flex items-center justify-center z-20 rounded-lg">
                            <div class="w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full animate-spin"></div>
                        </div>
                        <pre id="resBodyPanel" class="font-mono text-sm text-gray-300 h-full overflow-auto">Awaiting request...</pre>
                        <pre id="resHeadersPanel" class="font-mono text-sm text-gray-400 h-full overflow-auto hidden"></pre>
                        <pre id="resRawPanel" class="font-mono text-sm text-gray-400 h-full overflow-auto hidden"></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const endpoints = [
            { id: 'health', category: 'System', method: 'GET', path: '/api/v1/health', desc: 'System health + mobile status + gateway/customer counts (requires auth)', hasBody: false },
            
            { id: 'payment-init', category: 'Payments', method: 'POST', path: '/api/v1/payments/initiate', desc: 'Initiate a new payment (gateway is optional)', hasBody: true, defaultBody: '{\n  "amount": 100.50,\n  "currency": "USD",\n  "reference": "ORD-12345",\n  "customer_email": "test@example.com",\n  "callback_url": "https://example.com/callback"\n}' },
            { id: 'payment-show', category: 'Payments', method: 'GET', path: '/api/v1/payments/{trx_id}', desc: 'Get payment by transaction ID (OP-XXXX)', hasBody: false },
            
            { id: 'tx-list', category: 'Transactions', method: 'GET', path: '/api/v1/transactions', desc: 'List transactions (supports ?page=1&per_page=25)', hasBody: false },
            { id: 'tx-show', category: 'Transactions', method: 'GET', path: '/api/v1/transactions/{trx_id}', desc: 'Get transaction by ID (OP-XXXX)', hasBody: false },
            
            { id: 'refund-create', category: 'Refunds', method: 'POST', path: '/api/v1/refunds', desc: 'Create a refund for a transaction', hasBody: true, defaultBody: '{\n  "transaction_id": 1,\n  "amount": 50.00,\n  "reason": "customer requested"\n}' },
            { id: 'refund-show', category: 'Refunds', method: 'GET', path: '/api/v1/refunds/{trx_id}', desc: 'Get refund by transaction ID (OP-XXXX)', hasBody: false },
            
            { id: 'customer-list', category: 'Customers', method: 'GET', path: '/api/v1/customers', desc: 'List customers', hasBody: false },
            { id: 'customer-show', category: 'Customers', method: 'GET', path: '/api/v1/customers/{identifier}', desc: 'Find customer by email or phone', hasBody: false },
            { id: 'customer-create', category: 'Customers', method: 'POST', path: '/api/v1/customers', desc: 'Create a new customer', hasBody: true, defaultBody: '{\n  "name": "John Doe",\n  "email": "john@example.com",\n  "phone": "+1234567890"\n}' },
            
            { id: 'apikey-list', category: 'API Keys', method: 'GET', path: '/api/v1/api-keys', desc: 'List API keys', hasBody: false },
            { id: 'apikey-create', category: 'API Keys', method: 'POST', path: '/api/v1/api-keys', desc: 'Generate a new API key', hasBody: true, defaultBody: '{\n  "name": "Production Key",\n  "expires_at": "2027-01-01 00:00:00"\n}' },
            { id: 'apikey-revoke', category: 'API Keys', method: 'POST', path: '/api/v1/api-keys/{id}/revoke', desc: 'Revoke an API key', hasBody: false },
            
            { id: 'webhook-test', category: 'Webhooks', method: 'POST', path: '/api/v1/webhooks/test', desc: 'Send a test webhook to configured URL', hasBody: false },
            { id: 'webhook-deliveries', category: 'Webhooks', method: 'GET', path: '/api/v1/webhooks/deliveries', desc: 'List webhook delivery attempts', hasBody: false },
        ];

        let activeEndpoint = null;
        const sidebar = document.getElementById('sidebar');

        // Render Sidebar
        let currentCategory = '';
        endpoints.forEach(ep => {
            if (ep.category !== currentCategory) {
                const catHeader = document.createElement('div');
                catHeader.className = 'text-xs text-gray-500 font-bold uppercase mt-4 mb-1 px-3';
                catHeader.textContent = ep.category;
                sidebar.appendChild(catHeader);
                currentCategory = ep.category;
            }

            const btn = document.createElement('button');
            btn.className = 'endpoint-btn flex flex-col text-left px-3 py-2 rounded-lg border border-transparent cursor-pointer text-gray-300 mb-1';
            btn.innerHTML = `
                <div class="flex items-center gap-2">
                    <span class="text-xs font-bold method-${ep.method} w-10">${ep.method}</span>
                    <span class="text-sm font-mono truncate" title="${ep.path}">${ep.path.replace('/api/v1', '')}</span>
                </div>
            `;
            btn.onclick = () => selectEndpoint(ep, btn);
            sidebar.appendChild(btn);
        });

        function selectEndpoint(ep, btnElement) {
            document.querySelectorAll('.endpoint-btn').forEach(b => b.classList.remove('active'));
            btnElement.classList.add('active');
            
            activeEndpoint = ep;
            document.getElementById('welcomePanel').classList.add('hidden');
            document.getElementById('requestPanel').classList.remove('hidden');
            document.getElementById('requestPanel').classList.add('flex');

            const methodSpan = document.getElementById('reqMethod');
            methodSpan.textContent = ep.method;
            methodSpan.className = `font-black text-sm px-2 py-1 rounded bg-gray-800 method-${ep.method}`;
            
            document.getElementById('reqPath').textContent = ep.path;
            document.getElementById('reqDesc').textContent = ep.desc;

            // Handle Path Params
            const pathParamsList = document.getElementById('pathParamsList');
            pathParamsList.innerHTML = '';
            const matches = ep.path.match(/\{([a-zA-Z0-9_]+)\}/g);
            if (matches) {
                document.getElementById('pathParamsContainer').classList.remove('hidden');
                matches.forEach(m => {
                    const paramName = m.replace(/[{}]/g, '');
                    pathParamsList.innerHTML += `
                        <div class="flex items-center gap-3">
                            <label class="font-mono text-sm text-gray-400 w-24 text-right">${paramName}:</label>
                            <input type="text" data-param="${paramName}" class="path-param-input bg-dark border border-gray-700 rounded px-3 py-1.5 text-sm flex-1 focus:border-primary outline-none transition font-mono" placeholder="value">
                        </div>
                    `;
                });
            } else {
                document.getElementById('pathParamsContainer').classList.add('hidden');
            }

            // Handle Body
            if (ep.hasBody) {
                document.getElementById('bodyContainer').classList.remove('hidden');
                document.getElementById('reqBody').value = ep.defaultBody || '';
            } else {
                document.getElementById('bodyContainer').classList.add('hidden');
                document.getElementById('reqBody').value = '';
            }

            // Reset Response
            resetResponse();
        }

        function resetResponse() {
            document.getElementById('resStatus').textContent = 'Status: -';
            document.getElementById('resStatus').className = 'px-2 py-1 rounded bg-gray-800 text-gray-400';
            document.getElementById('resTime').textContent = 'Time: -';
            document.getElementById('resBodyPanel').textContent = 'Awaiting request...';
            document.getElementById('resHeadersPanel').textContent = '';
            document.getElementById('resRawPanel').textContent = '';
            document.getElementById('loader').classList.add('hidden');
        }

        // Format Body Button
        document.getElementById('formatBodyBtn').addEventListener('click', () => {
            try {
                const body = document.getElementById('reqBody').value;
                const parsed = JSON.parse(body);
                document.getElementById('reqBody').value = JSON.stringify(parsed, null, 2);
            } catch (e) {
                alert('Invalid JSON');
            }
        });

        // Tabs
        document.querySelectorAll('.res-tab').forEach(tab => {
            tab.addEventListener('click', (e) => {
                document.querySelectorAll('.res-tab').forEach(t => {
                    t.classList.remove('text-blue-400', 'border-blue-400');
                    t.classList.add('text-gray-400', 'border-transparent');
                });
                e.target.classList.remove('text-gray-400', 'border-transparent');
                e.target.classList.add('text-blue-400', 'border-blue-400');

                document.getElementById('resBodyPanel').classList.add('hidden');
                document.getElementById('resHeadersPanel').classList.add('hidden');
                document.getElementById('resRawPanel').classList.add('hidden');

                document.getElementById(e.target.dataset.target).classList.remove('hidden');
            });
        });

        // Send Request
        document.getElementById('sendBtn').addEventListener('click', async () => {
            if (!activeEndpoint) return;

            const baseUrl = document.getElementById('baseUrl').value.replace(/\/$/, '');
            const apiKey = document.getElementById('apiKey').value;
            
            // Health check is public — no key needed
            const isPublicEndpoint = activeEndpoint.id === 'health';
            if (!apiKey && !isPublicEndpoint) {
                alert('Please enter a Bearer API Key');
                return;
            }

            let path = activeEndpoint.path;
            const paramInputs = document.querySelectorAll('.path-param-input');
            let missingParams = false;
            paramInputs.forEach(input => {
                if (!input.value) {
                    missingParams = true;
                    input.classList.add('border-red-500');
                } else {
                    input.classList.remove('border-red-500');
                    path = path.replace(`{${input.dataset.param}}`, encodeURIComponent(input.value));
                }
            });

            if (missingParams) {
                alert('Please fill all path variables.');
                return;
            }

            const url = `${baseUrl}${path}`;
            const options = {
                method: activeEndpoint.method,
                headers: {
                    'Accept': 'application/json',
                }
            };

            // Only add Authorization header when API key is provided
            if (apiKey) {
                options.headers['Authorization'] = `Bearer ${apiKey}`;
            }

            let reqBodyRaw = '';
            if (activeEndpoint.hasBody) {
                options.headers['Content-Type'] = 'application/json';
                const bodyVal = document.getElementById('reqBody').value;
                if (bodyVal.trim()) {
                    try {
                        JSON.parse(bodyVal); // validate
                        options.body = bodyVal;
                        reqBodyRaw = bodyVal;
                    } catch (e) {
                        alert('Invalid JSON in body.');
                        return;
                    }
                }
            }

            document.getElementById('loader').classList.remove('hidden');
            const startTime = performance.now();

            try {
                const response = await fetch(url, options);
                const endTime = performance.now();
                const duration = Math.round(endTime - startTime);

                const statusSpan = document.getElementById('resStatus');
                statusSpan.textContent = `Status: ${response.status} ${response.statusText}`;
                if (response.ok) {
                    statusSpan.className = 'px-2 py-1 rounded bg-green-900/50 text-green-400 border border-green-800';
                } else {
                    statusSpan.className = 'px-2 py-1 rounded bg-red-900/50 text-red-400 border border-red-800';
                }
                
                document.getElementById('resTime').textContent = `Time: ${duration}ms`;

                // Headers
                let headersStr = '';
                response.headers.forEach((val, key) => {
                    headersStr += `${key}: ${val}\n`;
                });
                document.getElementById('resHeadersPanel').textContent = headersStr;

                // Body
                const text = await response.text();
                let isJson = false;
                try {
                    const json = JSON.parse(text);
                    // syntax highlight simple json
                    let formatted = JSON.stringify(json, null, 2);
                    formatted = formatted.replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, function (match) {
                        let cls = 'text-blue-400'; // number
                        if (/^"/.test(match)) {
                            if (/:$/.test(match)) {
                                cls = 'text-purple-400'; // key
                            } else {
                                cls = 'text-green-400'; // string
                            }
                        } else if (/true|false/.test(match)) {
                            cls = 'text-yellow-400'; // boolean
                        } else if (/null/.test(match)) {
                            cls = 'text-red-400'; // null
                        }
                        return '<span class="' + cls + '">' + match + '</span>';
                    });
                    document.getElementById('resBodyPanel').innerHTML = formatted;
                    isJson = true;
                } catch (e) {
                    document.getElementById('resBodyPanel').textContent = text;
                }

                // Raw
                let rawReq = `${options.method} ${path} HTTP/1.1\n`;
                rawReq += `Host: ${new URL(baseUrl).host}\n`;
                for (const [k, v] of Object.entries(options.headers)) {
                    rawReq += `${k}: ${v}\n`;
                }
                if (reqBodyRaw) rawReq += `\n${reqBodyRaw}\n`;
                
                let rawRes = `HTTP/1.1 ${response.status} ${response.statusText}\n${headersStr}\n${isJson ? JSON.stringify(JSON.parse(text), null, 2) : text}`;
                
                document.getElementById('resRawPanel').textContent = `=== REQUEST ===\n${rawReq}\n\n=== RESPONSE ===\n${rawRes}`;

            } catch (error) {
                document.getElementById('resStatus').textContent = `Error: Network Request Failed`;
                document.getElementById('resStatus').className = 'px-2 py-1 rounded bg-red-900/50 text-red-400 border border-red-800';
                document.getElementById('resTime').textContent = `Time: -`;
                document.getElementById('resBodyPanel').textContent = String(error);
                document.getElementById('resHeadersPanel').textContent = '';
                document.getElementById('resRawPanel').textContent = String(error);
            } finally {
                document.getElementById('loader').classList.add('hidden');
            }
        });
    </script>
</body>
</html>
