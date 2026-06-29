<?php
declare(strict_types=1);
// Standalone Premium API Tester for OwnPay (Light Theme Edition)
//
// LOCAL-DEVELOPMENT TOOL ONLY. This page exercises the live merchant API and
// bypasses the front controller, so it must never be reachable on a production
// deployment. The guard below fails safe: it serves the tool only when the
// environment is explicitly non-production (APP_DEBUG=true, or APP_ENV is a
// local/dev value) and otherwise returns 404. To use it locally set APP_DEBUG=true
// (or APP_ENV=local) in .env. Remove this file entirely for the final release.
(static function (): void {
    $appEnv = 'production';
    $appDebug = false;

    $envFile = dirname(__DIR__) . '/.env';
    if (is_readable($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            $key = trim(substr($line, 0, strpos($line, '=') ?: 0));
            $raw = substr($line, (strpos($line, '=') ?: 0) + 1);
            $hashPos = strpos($raw, '#');
            if ($hashPos !== false) {
                $raw = substr($raw, 0, $hashPos);
            }
            $val = strtolower(trim($raw, " \t\"'"));
            if ($key === 'APP_ENV') {
                $appEnv = $val;
            } elseif ($key === 'APP_DEBUG') {
                $appDebug = in_array($val, ['1', 'true', 'on', 'yes'], true);
            }
        }
    }

    $isLocal = $appDebug || in_array($appEnv, ['local', 'development', 'dev', 'testing'], true);
    if (!$isLocal) {
        http_response_code(404);
        exit;
    }
})();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OwnPay - Official Merchant API Tester</title>
    <link rel="icon" type="image/png" href="https://ownpay.org/ownpay_icon.png">
    <!-- Premium Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
                        mono: ['"JetBrains Mono"', 'monospace'],
                    },
                    colors: {
                        brand: {
                            50: '#f5f7ff',
                            100: '#ebf0ff',
                            500: '#4f46e5',
                            600: '#4338ca',
                            700: '#3730a3',
                            900: '#1e1b4b',
                        },
                        slate: {
                            50: '#f8fafc',
                            100: '#f1f5f9',
                            200: '#e2e8f0',
                            300: '#cbd5e1',
                            400: '#94a3b8',
                            500: '#64748b',
                            700: '#334155',
                            800: '#1e293b',
                            900: '#0f172a',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        body { 
            font-family: "Plus Jakarta Sans", sans-serif; 
            background-color: #f8fafc;
            color: #0f172a;
        }
        .premium-shadow {
            box-shadow: 0 4px 30px rgba(79, 70, 229, 0.03), 0 2px 12px rgba(0, 0, 0, 0.02);
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(226, 232, 240, 0.8);
            border-radius: 20px;
        }
        .endpoint-btn {
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .endpoint-btn:hover {
            transform: translateX(4px);
            background-color: #f1f5f9;
        }
        .endpoint-btn.active {
            background-color: #eef2ff;
            border-color: #4f46e5;
            border-left-width: 4px;
            color: #3730a3;
            font-weight: 600;
        }
        /* Method badges */
        .method-GET { background-color: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .method-POST { background-color: #eef2ff; color: #4f46e5; border: 1px solid #c7d2fe; }
        .method-PUT { background-color: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
        .method-DELETE { background-color: #fff5f5; color: #e11d48; border: 1px solid #fecdd3; }
        
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 8px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>
</head>
<body class="h-screen flex flex-col overflow-hidden bg-slate-50">

    <!-- Premium Top Navigation Header -->
    <header class="bg-white/95 border-b border-slate-200/80 px-6 py-4 flex justify-between items-center z-10 premium-shadow">
        <div class="flex items-center justify-between w-full">
            <a href="https://ownpay.org" target="_blank" class="flex items-center gap-3">
                <img src="https://ownpay.org/ownpay_logo.png" alt="OwnPay Logo" class="h-8 object-contain">
                <span class="h-5 w-[1px] bg-slate-200 hidden sm:inline-block"></span>
                <span class="text-slate-800 font-extrabold tracking-tight text-lg hidden sm:inline-block">Merchant API Tester</span>
            </a>
            <!-- Mobile Sidebar Toggle -->
            <button id="mobileMenuBtn" class="lg:hidden p-2 text-slate-700 hover:bg-slate-100 rounded-xl transition">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                </svg>
            </button>
        </div>
    </header>

    <div class="flex flex-1 overflow-hidden relative">
        
        <!-- Sidebar Navigation Drawer (Responsive) -->
        <aside id="sidebar" class="fixed inset-y-0 left-0 transform -translate-x-full lg:relative lg:translate-x-0 transition-transform duration-300 ease-in-out w-80 bg-white border-r border-slate-200 flex flex-col p-4 z-20 lg:z-0">
            <!-- Sidebar Header for Mobile Drawer -->
            <div class="flex items-center justify-between lg:hidden mb-4 pb-2 border-b border-slate-100">
                <span class="font-extrabold text-slate-800 text-sm tracking-tight">API Directories</span>
                <button id="closeSidebarBtn" class="p-2 text-slate-600 hover:bg-slate-100 rounded-xl transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <div class="flex-1 overflow-y-auto pr-1 flex flex-col gap-1" id="sidebarList">
                <!-- Environment Configuration Section -->
                <div class="mb-4 pb-4 border-b border-slate-200 flex flex-col gap-3 px-1.5">
                    <span class="font-extrabold text-slate-700 text-[10px] tracking-wider uppercase mb-1 flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5 text-brand-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        </svg>
                        Environment Config
                    </span>
                    
                    <div class="relative w-full">
                        <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-[9px] font-bold text-slate-400 tracking-wider">HOST</span>
                        <input type="text" id="baseUrl" class="pl-14 w-full bg-slate-50 border border-slate-200 rounded-xl py-2 px-3.5 text-xs focus:border-brand-500 focus:bg-white outline-none transition font-mono" placeholder="Base URL">
                    </div>
                    <div class="relative w-full">
                        <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-[9px] font-bold text-slate-400 tracking-wider font-mono">api_key</span>
                        <input type="password" id="apiKey" class="pl-16 w-full bg-slate-50 border border-slate-200 rounded-xl py-2 px-3.5 text-xs focus:border-brand-500 focus:bg-white outline-none transition font-mono" placeholder="op_apiKey">
                    </div>
                    <div class="relative w-full">
                        <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-[9px] font-bold text-slate-400 tracking-wider">ADMIN MAIL</span>
                        <input type="text" id="superAdminEmail" class="pl-24 w-full bg-slate-50 border border-slate-200 rounded-xl py-2 px-3.5 text-xs focus:border-brand-500 focus:bg-white outline-none transition font-mono" placeholder="admin@example.com">
                    </div>
                </div>
                <!-- Programmatically populated endpoint categories -->
            </div>
        </aside>

        <!-- Main Display Pane -->
        <main class="flex-1 flex flex-col p-4 lg:p-6 overflow-y-auto bg-slate-50 gap-6">
            
            <!-- Welcome Splash Panel -->
            <section id="welcomePanel" class="flex-1 flex flex-col items-center justify-center p-6 md:p-10 bg-white border border-slate-200 rounded-3xl premium-shadow max-w-4xl mx-auto w-full text-center my-auto gap-6 transition animate-fade-in">
                <img src="https://ownpay.org/ownpay_logo.png" alt="OwnPay Logo" class="h-14 object-contain">
                <div>
                    <h2 class="text-2xl md:text-3xl font-extrabold text-slate-800 tracking-tight">OwnPay Merchant API Playground</h2>
                    <p class="text-slate-500 mt-2 text-sm max-w-lg mx-auto leading-relaxed">This secure environment allows developers to debug, test, and trace OwnPay Merchant REST APIs.</p>
                </div>
                
                <!-- Quick Instructions -->
                <div class="w-full text-left bg-slate-50 border border-slate-200/80 rounded-2xl p-5">
                    <h3 class="font-bold text-slate-700 text-xs mb-3 uppercase tracking-wider flex items-center gap-2">
                        <svg class="w-4 h-4 text-brand-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Environment Notes & Security
                    </h3>
                    <ul class="text-xs text-slate-600 space-y-2.5">
                        <li class="flex items-start gap-2">
                            <span class="text-brand-500 font-bold text-sm leading-none">•</span>
                            <span><strong>Merchant API:</strong> Authenticate `/api/v1/*` routes using Bearer API keys formatted as `op_[identifier].[secret]`.</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="text-brand-500 font-bold text-sm leading-none">•</span>
                            <span><strong>CORS Rules:</strong> Calls execute directly from your browser. Ensure your target local server configuration matches incoming origin routes.</span>
                        </li>
                    </ul>
                </div>
                
                <!-- Official Documentation Links -->
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3 w-full mt-2">
                    <a href="https://ownpay.org" target="_blank" class="flex items-center justify-center p-3 rounded-xl border border-slate-200 hover:border-brand-500 hover:bg-brand-50/50 text-slate-600 hover:text-brand-700 transition font-semibold text-xs">Website</a>
                    <a href="https://github.com/own-pay/ownpay" target="_blank" class="flex items-center justify-center p-3 rounded-xl border border-slate-200 hover:border-brand-500 hover:bg-brand-50/50 text-slate-600 hover:text-brand-700 transition font-semibold text-xs">GitHub</a>
                    <a href="https://docs.ownpay.org" target="_blank" class="flex items-center justify-center p-3 rounded-xl border border-slate-200 hover:border-brand-500 hover:bg-brand-50/50 text-slate-600 hover:text-brand-700 transition font-semibold text-xs">Api Reference</a>
                    <a href="https://learn.ownpay.org" target="_blank" class="flex items-center justify-center p-3 rounded-xl border border-slate-200 hover:border-brand-500 hover:bg-brand-50/50 text-slate-600 hover:text-brand-700 transition font-semibold text-xs">Academy</a>
                    <a href="https://facebook.com/ownpay.org" target="_blank" class="flex items-center justify-center p-3 rounded-xl border border-slate-200 hover:border-brand-500 hover:bg-brand-50/50 text-slate-600 hover:text-brand-700 transition font-semibold text-xs">Facebook</a>
                </div>
            </section>

            <!-- Interactive Request Workspace -->
            <section id="requestPanel" class="hidden flex-col gap-6 w-full max-w-6xl mx-auto">
                
                <!-- Request Configuration Block -->
                <div class="glass-card p-6 shadow-sm relative overflow-hidden group">
                    <div class="absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-brand-500 to-indigo-600"></div>
                    <div class="flex flex-col md:flex-row justify-between items-start gap-4 mb-6">
                        <div class="flex-1">
                            <h2 class="text-lg md:text-xl font-bold font-mono flex flex-wrap items-center gap-3">
                                <span id="reqMethod" class="font-bold text-[10px] px-2.5 py-1 rounded-lg uppercase border">GET</span>
                                <span id="reqPath" class="text-slate-800 break-all">/api/v1/health</span>
                            </h2>
                            <p id="reqDesc" class="text-xs text-slate-500 mt-2 font-medium">Check system diagnostics.</p>
                        </div>
                        <button id="sendBtn" class="w-full md:w-auto bg-brand-600 hover:bg-brand-500 text-white font-semibold py-2.5 px-6 rounded-xl shadow-md shadow-brand-500/20 hover:shadow-lg transition transform hover:-translate-y-0.5 active:translate-y-0 flex items-center justify-center gap-2">
                            <span>Execute Call</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                            </svg>
                        </button>
                    </div>

                    <!-- Variable Path Input Fields -->
                    <div id="pathParamsContainer" class="hidden border-t border-slate-100 pt-4 mb-4">
                        <h3 class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-3">Path Parameters</h3>
                        <div id="pathParamsList" class="flex flex-col gap-3"></div>
                    </div>

                    <!-- JSON Body Text Editor -->
                    <div id="bodyContainer" class="hidden border-t border-slate-100 pt-4">
                        <div class="flex justify-between items-center mb-2">
                            <h3 class="text-[10px] font-bold uppercase tracking-wider text-slate-400">JSON Request Body</h3>
                            <button id="formatBodyBtn" class="text-xs font-semibold text-brand-500 hover:text-brand-600 transition">Format JSON</button>
                        </div>
                        <textarea id="reqBody" class="w-full h-48 bg-slate-50 border border-slate-200 rounded-xl p-4 font-mono text-xs text-slate-800 focus:border-brand-500 focus:bg-white outline-none resize-y transition shadow-inner" spellcheck="false"></textarea>
                    </div>
                </div>

                <!-- API Response Outcome Block -->
                <div class="glass-card p-6 shadow-sm flex flex-col relative min-h-[450px]">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 mb-4 pb-4 border-b border-slate-100">
                        <h3 class="text-md font-bold text-slate-850">Response Payload</h3>
                        <div class="flex gap-2 text-[10px] font-mono">
                            <span id="resStatus" class="px-2.5 py-1 rounded-lg bg-slate-100 text-slate-500 font-bold border border-slate-200">AWAITING REQUEST</span>
                            <span id="resTime" class="px-2.5 py-1 rounded-lg bg-slate-100 text-slate-500 font-semibold border border-slate-200">TIME: -</span>
                        </div>
                    </div>
                    
                    <!-- Tab Controllers -->
                    <div class="flex gap-2 border-b border-slate-100 mb-4 text-xs font-semibold">
                        <button class="res-tab px-4 py-2 text-brand-600 border-b-2 border-brand-500" data-target="resBodyPanel">JSON Response</button>
                        <button class="res-tab px-4 py-2 text-slate-400 border-b-2 border-transparent hover:text-slate-600 transition" data-target="resHeadersPanel">Headers</button>
                        <button class="res-tab px-4 py-2 text-slate-400 border-b-2 border-transparent hover:text-slate-600 transition" data-target="resRawPanel">HTTP Raw Trace</button>
                    </div>

                    <!-- Output Panels -->
                    <div class="flex-1 overflow-auto bg-slate-900 rounded-xl p-5 shadow-inner relative max-h-[550px]">
                        <!-- Loader Spinner -->
                        <div id="loader" class="hidden absolute inset-0 bg-slate-900/85 backdrop-blur-xs flex items-center justify-center z-20 rounded-xl">
                            <div class="w-9 h-9 border-4 border-brand-500 border-t-transparent rounded-full animate-spin"></div>
                        </div>
                        <pre id="resBodyPanel" class="font-mono text-xs text-slate-200 h-full overflow-auto break-all">Select an endpoint from the left menu and execute the call...</pre>
                        <pre id="resHeadersPanel" class="font-mono text-xs text-slate-400 h-full overflow-auto hidden"></pre>
                        <pre id="resRawPanel" class="font-mono text-xs text-slate-400 h-full overflow-auto hidden"></pre>
                    </div>
                </div>
            </section>
        </main>
        
        <!-- Mobile Sidebar Overlay drawer background -->
        <div id="sidebarOverlay" class="fixed inset-0 bg-slate-900/40 backdrop-blur-xs z-10 hidden transition-opacity duration-300"></div>
    </div>

    <!-- Universal Responsive Page Footer -->
    <footer class="bg-white border-t border-slate-200/80 py-4 px-6 text-center text-[10px] text-slate-450 flex flex-col sm:flex-row justify-between items-center gap-3 z-10 premium-shadow">
        <div class="text-slate-500">
            &copy; 2026 <a href="https://ownpay.org" class="font-bold text-slate-600 hover:text-brand-600 transition">OwnPay</a> - Built by the <b><i>Community</i></b>, for the <b><i>Community</i></b>
        </div>
        <div class="flex flex-wrap justify-center gap-3.5 font-semibold text-slate-500">
            <a href="https://docs.ownpay.org" target="_blank" class="hover:text-brand-600 transition">Api Reference</a>
            <a href="https://learn.ownpay.org" target="_blank" class="hover:text-brand-600 transition">Academy</a>
            <a href="https://ownpay.org" target="_blank" class="hover:text-brand-600 transition">Website</a>
            <a href="https://github.com/own-pay/ownpay" target="_blank" class="hover:text-brand-600 transition">GitHub</a>
            <a href="https://facebook.com/ownpay.org" target="_blank" class="hover:text-brand-600 transition">Facebook</a>
        </div>
    </footer>

    <!-- Custom Premium Alert Modal -->
    <div id="customAlertModal" class="fixed inset-0 z-50 flex items-center justify-center hidden">
        <!-- Backdrop -->
        <div id="customAlertBackdrop" class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity duration-300"></div>
        
        <!-- Modal Content Card -->
        <div id="customAlertCard" class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-2xl max-w-sm w-full mx-4 transform scale-95 opacity-0 transition-all duration-300 relative z-10 flex flex-col gap-4">
            <div class="flex items-start gap-4">
                <!-- Dynamic Icon Container -->
                <div id="customAlertIconContainer" class="p-3 rounded-2xl flex-shrink-0 border flex items-center justify-center">
                    <!-- Icon gets injected here -->
                </div>
                <div class="flex-1 min-w-0">
                    <h3 id="customAlertTitle" class="font-bold text-slate-800 text-sm tracking-tight truncate">Notification</h3>
                    <p id="customAlertMessage" class="text-xs text-slate-500 mt-1 leading-relaxed break-words"></p>
                </div>
            </div>
            
            <div class="flex justify-end gap-2 mt-2">
                <button id="customAlertCloseBtn" class="bg-brand-600 hover:bg-brand-500 text-white font-semibold text-xs py-2.5 px-6 rounded-xl shadow-md shadow-brand-500/10 hover:shadow-lg transition duration-200">
                    Dismiss
                </button>
            </div>
        </div>
    </div>

    <script>
        const endpoints = [
            // === MERCHANT API ===
            { id: 'health', category: 'Merchant API - Core System', method: 'GET', path: '/api/v1/health', desc: 'Verify server health diagnostics, runtime version tags, and MySQL connection uptime.', hasBody: false },
            { id: 'payment-init', category: 'Merchant API - Payment Intents', method: 'POST', path: '/api/v1/payments', desc: 'Create a payment intent representing a transaction block. Returns the customer checkout URL.', hasBody: true, defaultBody: '{\n  "amount": 1250.00,\n  "currency": "BDT",\n  "description": "Invoice #8930 Premium Pack",\n  "redirect_url": "https://myshop.com/success",\n  "cancel_url": "https://myshop.com/cancel",\n  "customer_name": "John Doe",\n  "customer_mail": "john@example.com",\n  "customer_phone": "+8801700000000",\n  "metadata": {\n    "invoice_id": 8930\n  }\n}' },
            { id: 'payment-show', category: 'Merchant API - Payment Intents', method: 'GET', path: '/api/v1/payments/{payment_id}', desc: 'Retrieve payment status by payment intent UUID.', hasBody: false },
            { id: 'tx-list', category: 'Merchant API - Transactions Ledger', method: 'GET', path: '/api/v1/transactions', desc: 'Query paginated list of settled payments with status criteria filter scopes.', hasBody: false },
            { id: 'tx-show', category: 'Merchant API - Transactions Ledger', method: 'GET', path: '/api/v1/transactions/{trx_id}', desc: 'Fetch transaction details by OwnPay transaction ID or gateway transaction ID.', hasBody: false },
            { id: 'refund-create', category: 'Merchant API - Transaction Refunds', method: 'POST', path: '/api/v1/refunds', desc: 'Process a full or partial charge reversal against a settled transaction using transaction ID or gateway transaction ID.', hasBody: true, defaultBody: '{\n  "transaction_id": "OP-10482938",\n  "amount": 250.00,\n  "reason": "Product return request"\n}' },
            { id: 'refund-list', category: 'Merchant API - Transaction Refunds', method: 'GET', path: '/api/v1/refunds', desc: 'Query paginated list of refunds with status and transaction ID criteria filter scopes.', hasBody: false },
            { id: 'refund-show', category: 'Merchant API - Transaction Refunds', method: 'GET', path: '/api/v1/refunds/{trx_id}', desc: 'Audit details of a reversed refund transaction record by OwnPay transaction ID or gateway transaction ID.', hasBody: false },
            { id: 'customer-list', category: 'Merchant API - Customer Profiles', method: 'GET', path: '/api/v1/customers', desc: 'List customer profiles registered under the merchant context.', hasBody: false },
            { id: 'customer-show', category: 'Merchant API - Customer Profiles', method: 'GET', path: '/api/v1/customers/{identifier}', desc: 'Query customer credentials by database ID, email address, or phone string.', hasBody: false },
            { id: 'customer-create', category: 'Merchant API - Customer Profiles', method: 'POST', path: '/api/v1/customers', desc: 'Register a new customer record under the brand tenant.', hasBody: true, defaultBody: '{\n  "name": "Jane Doe",\n  "email": "jane@example.com",\n  "phone": "+8801700000000"\n}' },
            { id: 'apikey-list', category: 'Merchant API - Credentials', method: 'GET', path: '/api/v1/api-keys', desc: 'List active API keys partially masked for audit views. Requires superadmin verification.', hasBody: false },
            { id: 'apikey-create', category: 'Merchant API - Credentials', method: 'POST', path: '/api/v1/api-keys', desc: 'Generate a new API key scoped to the merchant brand with custom privileges.', hasBody: true, defaultBody: '{\n  "name": "Production Server Key",\n  "scopes": ["read", "write", "admin"]\n}' },
            { id: 'apikey-revoke', category: 'Merchant API - Credentials', method: 'DELETE', path: '/api/v1/api-keys/{id}', desc: 'Revoke and permanently decommission an API key by ID.', hasBody: false },
            { id: 'webhook-test', category: 'Merchant API - Outbound Webhooks', method: 'POST', path: '/api/v1/webhooks/tests', desc: 'Trigger a test HMAC-SHA256 signature event log callback.', hasBody: false },
            { id: 'webhook-deliveries', category: 'Merchant API - Outbound Webhooks', method: 'GET', path: '/api/v1/webhooks/deliveries', desc: 'Trace outbound webhooks logs history status responses.', hasBody: false }
        ];

        let activeEndpoint = null;

        // Load configuration inputs from sessionStorage with fallbacks
        const baseUrlInput = document.getElementById('baseUrl');
        const apiKeyInput = document.getElementById('apiKey');
        const superAdminEmailInput = document.getElementById('superAdminEmail');

        const storedBaseUrl = sessionStorage.getItem('baseUrl');
        const storedApiKey = sessionStorage.getItem('apiKey');
        const storedSuperAdminEmail = sessionStorage.getItem('superAdminEmail');

        if (storedBaseUrl) {
            baseUrlInput.value = storedBaseUrl;
        } else if (window.location.origin) {
            baseUrlInput.value = window.location.origin;
        }

        if (storedApiKey) {
            apiKeyInput.value = storedApiKey;
        }

        if (storedSuperAdminEmail) {
            superAdminEmailInput.value = storedSuperAdminEmail;
        }

        // Live persistence of values to sessionStorage on user input
        baseUrlInput.addEventListener('input', () => {
            sessionStorage.setItem('baseUrl', baseUrlInput.value);
        });
        apiKeyInput.addEventListener('input', () => {
            sessionStorage.setItem('apiKey', apiKeyInput.value.trim());
        });
        superAdminEmailInput.addEventListener('input', () => {
            sessionStorage.setItem('superAdminEmail', superAdminEmailInput.value.trim());
        });
        const sidebarList = document.getElementById('sidebarList');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const closeSidebarBtn = document.getElementById('closeSidebarBtn');

        // Sidebar Render logic
        let currentCategory = '';
        endpoints.forEach(ep => {
            if (ep.category !== currentCategory) {
                const catHeader = document.createElement('div');
                catHeader.className = 'text-[10px] text-slate-400 font-bold uppercase mt-4 mb-1.5 px-3 tracking-wider';
                catHeader.textContent = ep.category;
                sidebarList.appendChild(catHeader);
                currentCategory = ep.category;
            }

            const btn = document.createElement('button');
            btn.className = 'endpoint-btn w-full flex items-center gap-3 px-3 py-2 rounded-xl text-left border border-transparent text-slate-500 font-semibold hover:text-slate-800 transition duration-150 ease-in-out';
            
            // Clean paths for cleaner display
            const renderPath = ep.path.replace('/api/v1', '');
            
            btn.innerHTML = `
                <span class="text-[9px] font-bold px-2 py-0.5 rounded-md w-14 text-center uppercase tracking-wide method-${ep.method}">${ep.method}</span>
                <span class="font-mono text-[11px] truncate flex-1" title="${ep.path}">${renderPath}</span>
            `;
            btn.onclick = () => {
                selectEndpoint(ep, btn);
                closeMobileSidebar();
            };
            sidebarList.appendChild(btn);
        });

        // Mobile sidebar events
        mobileMenuBtn.addEventListener('click', openMobileSidebar);
        closeSidebarBtn.addEventListener('click', closeMobileSidebar);
        sidebarOverlay.addEventListener('click', closeMobileSidebar);

        function openMobileSidebar() {
            sidebar.classList.remove('-translate-x-full');
            sidebarOverlay.classList.remove('hidden');
        }

        function closeMobileSidebar() {
            sidebar.classList.add('-translate-x-full');
            sidebarOverlay.classList.add('hidden');
        }

        function selectEndpoint(ep, btnElement) {
            document.querySelectorAll('.endpoint-btn').forEach(b => b.classList.remove('active'));
            btnElement.classList.add('active');
            
            activeEndpoint = ep;
            document.getElementById('welcomePanel').classList.add('hidden');
            document.getElementById('requestPanel').classList.remove('hidden');
            document.getElementById('requestPanel').classList.add('flex');

            const methodSpan = document.getElementById('reqMethod');
            methodSpan.textContent = ep.method;
            methodSpan.className = `font-bold text-[9px] px-2.5 py-1 rounded-lg uppercase method-${ep.method} border`;
            
            document.getElementById('reqPath').textContent = ep.path;
            document.getElementById('reqDesc').textContent = ep.desc;

            // Render dynamic path params
            const pathParamsList = document.getElementById('pathParamsList');
            pathParamsList.innerHTML = '';
            const matches = ep.path.match(/\{([a-zA-Z0-9_]+)\}/g);
            if (matches) {
                document.getElementById('pathParamsContainer').classList.remove('hidden');
                matches.forEach(m => {
                    const paramName = m.replace(/[{}]/g, '');
                    pathParamsList.innerHTML += `
                        <div class="flex flex-col sm:flex-row items-start sm:items-center gap-2">
                            <label class="font-mono text-xs font-semibold text-slate-500 sm:w-28 sm:text-right">${paramName}:</label>
                            <input type="text" data-param="${paramName}" class="path-param-input bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs w-full sm:w-1/2 focus:border-brand-500 focus:bg-white outline-none transition font-mono" placeholder="value">
                        </div>
                    `;
                });
            } else {
                document.getElementById('pathParamsContainer').classList.add('hidden');
            }

            // Render Request body
            if (ep.hasBody) {
                document.getElementById('bodyContainer').classList.remove('hidden');
                document.getElementById('reqBody').value = ep.defaultBody || '';
            } else {
                document.getElementById('bodyContainer').classList.add('hidden');
                document.getElementById('reqBody').value = '';
            }

            resetResponse();
        }

        function resetResponse() {
            document.getElementById('resStatus').textContent = 'AWAITING REQUEST';
            document.getElementById('resStatus').className = 'px-2.5 py-1 rounded-lg bg-slate-100 text-slate-500 font-bold border border-slate-200';
            document.getElementById('resTime').textContent = 'TIME: -';
            document.getElementById('resBodyPanel').textContent = 'Select an endpoint and execute call...';
            document.getElementById('resHeadersPanel').textContent = '';
            document.getElementById('resRawPanel').textContent = '';
            document.getElementById('loader').classList.add('hidden');
        }

        // Beautify request body JSON
        document.getElementById('formatBodyBtn').addEventListener('click', () => {
            try {
                const body = document.getElementById('reqBody').value;
                const parsed = JSON.parse(body);
                document.getElementById('reqBody').value = JSON.stringify(parsed, null, 2);
            } catch (e) {
                showAlert('Invalid JSON formatting.', 'JSON Error', 'error');
            }
        });

        // Tabs logic
        document.querySelectorAll('.res-tab').forEach(tab => {
            tab.addEventListener('click', (e) => {
                document.querySelectorAll('.res-tab').forEach(t => {
                    t.classList.remove('text-brand-600', 'border-brand-500');
                    t.classList.add('text-slate-400', 'border-transparent');
                });
                e.target.classList.remove('text-slate-400', 'border-transparent');
                e.target.classList.add('text-brand-600', 'border-brand-500');

                document.getElementById('resBodyPanel').classList.add('hidden');
                document.getElementById('resHeadersPanel').classList.add('hidden');
                document.getElementById('resRawPanel').classList.add('hidden');

                document.getElementById(e.target.dataset.target).classList.remove('hidden');
            });
        });

        // AJAX Fetch Sandbox Executions
        document.getElementById('sendBtn').addEventListener('click', async () => {
            if (!activeEndpoint) return;

            const baseUrl = document.getElementById('baseUrl').value.replace(/\/$/, '');
            const apiKey = document.getElementById('apiKey').value.trim();
            
            const isPublicEndpoint = activeEndpoint.id === 'health';
            if (!apiKey && !isPublicEndpoint) {
                showAlert('An API Key / Bearer JWT is required to query authenticated endpoints.', 'Authentication Required', 'warning');
                return;
            }

            let path = activeEndpoint.path;
            const paramInputs = document.querySelectorAll('.path-param-input');
            let missingParams = false;
            paramInputs.forEach(input => {
                if (!input.value.trim()) {
                    missingParams = true;
                    input.classList.add('border-red-500');
                } else {
                    input.classList.remove('border-red-500');
                    path = path.replace(`{${input.dataset.param}}`, encodeURIComponent(input.value.trim()));
                }
            });

            if (missingParams) {
                showAlert('Please provide values for all dynamic path variables.', 'Missing Parameters', 'warning');
                return;
            }

            const url = `${baseUrl}${path}`;
            const options = {
                method: activeEndpoint.method,
                headers: {
                    'Accept': 'application/json',
                }
            };

            if (apiKey) {
                options.headers['Authorization'] = `Bearer ${apiKey}`;
            }

            const superAdminEmail = document.getElementById('superAdminEmail').value.trim();
            if (superAdminEmail) {
                options.headers['X-Super-Admin-Email'] = superAdminEmail;
            }

            let reqBodyRaw = '';
            if (activeEndpoint.hasBody) {
                options.headers['Content-Type'] = 'application/json';
                const bodyVal = document.getElementById('reqBody').value;
                if (bodyVal.trim()) {
                    try {
                        JSON.parse(bodyVal);
                        options.body = bodyVal;
                        reqBodyRaw = bodyVal;
                    } catch (e) {
                        showAlert('Malformed JSON syntax in body.', 'JSON Body Error', 'error');
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
                statusSpan.textContent = `${response.status} ${response.statusText}`;
                if (response.ok) {
                    statusSpan.className = 'px-2.5 py-1 rounded-lg bg-green-50 text-green-700 font-bold border border-green-200';
                } else {
                    statusSpan.className = 'px-2.5 py-1 rounded-lg bg-red-50 text-red-700 font-bold border border-red-200';
                }
                
                document.getElementById('resTime').textContent = `TIME: ${duration}ms`;

                // Render headers
                let headersStr = '';
                response.headers.forEach((val, key) => {
                    headersStr += `${key}: ${val}\n`;
                });
                document.getElementById('resHeadersPanel').textContent = headersStr;

                // Render body with clean highlights
                const text = await response.text();
                let isJson = false;
                try {
                    const json = JSON.parse(text);
                    let formatted = JSON.stringify(json, null, 2);
                    formatted = formatted.replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, function (match) {
                        let cls = 'text-amber-500'; // numbers
                        if (/^"/.test(match)) {
                            if (/:$/.test(match)) {
                                cls = 'text-indigo-400 font-semibold'; // JSON keys
                            } else {
                                cls = 'text-emerald-500'; // strings
                            }
                        } else if (/true|false/.test(match)) {
                            cls = 'text-sky-500'; // booleans
                        } else if (/null/.test(match)) {
                            cls = 'text-rose-450'; // null
                        }
                        return '<span class="' + cls + '">' + match + '</span>';
                    });
                    document.getElementById('resBodyPanel').innerHTML = formatted;
                    isJson = true;
                } catch (e) {
                    document.getElementById('resBodyPanel').textContent = text;
                }

                // Render raw trace
                let rawReq = `${options.method} ${path} HTTP/1.1\n`;
                rawReq += `Host: ${new URL(baseUrl).host}\n`;
                for (const [k, v] of Object.entries(options.headers)) {
                    rawReq += `${k}: ${v}\n`;
                }
                if (reqBodyRaw) rawReq += `\n${reqBodyRaw}\n`;
                
                let rawRes = `HTTP/1.1 ${response.status} ${response.statusText}\n${headersStr}\n${isJson ? JSON.stringify(JSON.parse(text), null, 2) : text}`;
                
                document.getElementById('resRawPanel').textContent = `=== HTTP REQUEST ===\n${rawReq}\n\n=== HTTP RESPONSE ===\n${rawRes}`;

            } catch (error) {
                document.getElementById('resStatus').textContent = `NETWORK ERROR`;
                document.getElementById('resStatus').className = 'px-2.5 py-1 rounded-lg bg-rose-50 text-rose-700 font-bold border border-rose-200';
                document.getElementById('resTime').textContent = `TIME: -`;
                document.getElementById('resBodyPanel').textContent = String(error);
                document.getElementById('resHeadersPanel').textContent = '';
                document.getElementById('resRawPanel').textContent = String(error);
            } finally {
                document.getElementById('loader').classList.add('hidden');
            }
        });

        // Custom Alert modal controls
        function showAlert(message, title = 'Notice', type = 'warning') {
            const modal = document.getElementById('customAlertModal');
            const card = document.getElementById('customAlertCard');
            const iconContainer = document.getElementById('customAlertIconContainer');
            const titleEl = document.getElementById('customAlertTitle');
            const messageEl = document.getElementById('customAlertMessage');
            
            titleEl.textContent = title;
            messageEl.textContent = message;
            
            if (type === 'error') {
                iconContainer.className = 'p-3 bg-rose-50 text-rose-500 rounded-2xl border border-rose-100 flex-shrink-0';
                iconContainer.innerHTML = `
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                `;
            } else if (type === 'success') {
                iconContainer.className = 'p-3 bg-emerald-50 text-emerald-500 rounded-2xl border border-emerald-100 flex-shrink-0';
                iconContainer.innerHTML = `
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                `;
            } else {
                iconContainer.className = 'p-3 bg-amber-50 text-amber-500 rounded-2xl border border-amber-100 flex-shrink-0';
                iconContainer.innerHTML = `
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                `;
            }
            
            modal.classList.remove('hidden');
            requestAnimationFrame(() => {
                card.classList.remove('scale-95', 'opacity-0');
                card.classList.add('scale-100', 'opacity-100');
            });
        }

        function hideAlert() {
            const modal = document.getElementById('customAlertModal');
            const card = document.getElementById('customAlertCard');
            
            card.classList.remove('scale-100', 'opacity-100');
            card.classList.add('scale-95', 'opacity-0');
            
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 300);
        }

        document.getElementById('customAlertCloseBtn').addEventListener('click', hideAlert);
        document.getElementById('customAlertBackdrop').addEventListener('click', hideAlert);
    </script>
</body>
</html>
