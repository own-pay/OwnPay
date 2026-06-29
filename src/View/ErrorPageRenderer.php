<?php
declare(strict_types=1);

namespace OwnPay\View;

/**
 * Self-contained renderer for last-resort error pages.
 *
 * These pages are served when the normal rendering stack (Twig, database,
 * sometimes the DI container itself) may be unavailable - fatal boot errors,
 * database outages, template failures. They are therefore intentionally
 * dependency-free inline HTML: this class must never touch Twig, the database,
 * or any service that could re-trigger the failure being reported.
 *
 * Normal-path errors (404, maintenance 503, production 500) always try their
 * Twig templates first; these renderers are the fallback of the fallback.
 */
final class ErrorPageRenderer
{
    /**
     * Production 500 page - used when Twig is unavailable.
     *
     * @return string Complete HTML document.
     */
    public function internalErrorPage(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Server Error - OwnPay</title>
    <style>
        *{
            margin:0;
            padding:0;
            box-sizing:border-box}
        body{
            font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','Inter',sans-serif;background:#0f172a;color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh}
        .c{text-align:center;max-width:480px;padding:2rem}
        .icon{width:80px;height:80px;margin:0 auto 1.5rem;border-radius:50%;background:rgba(239,68,68,.15);display:flex;align-items:center;justify-content:center}
        .icon svg{width:40px;height:40px;color:#ef4444}
        .code{font-size:4rem;font-weight:800;background:linear-gradient(135deg,#ef4444,#f97316);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;line-height:1;margin-bottom:.75rem}
        h1{font-size:1.25rem;font-weight:600;margin-bottom:.5rem;color:#f1f5f9}
        p{color:#94a3b8;line-height:1.6;margin-bottom:1.5rem;font-size:.9rem}
        .btn{display:inline-block;padding:.7rem 1.5rem;background:linear-gradient(135deg,#3b82f6,#6366f1);color:#fff;text-decoration:none;border-radius:.5rem;font-weight:500;font-size:.9rem;transition:all .2s;border:none;cursor:pointer}
        .btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(99,102,241,.4)}
        .footer{margin-top:2rem;font-size:.75rem;color:#475569}
        </style>
</head>
<body>
    <div class="c">
        <div class="icon">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
            </svg>
        </div>
        <div class="code">500</div>
        <h1>Something went wrong</h1>
        <p>An unexpected error occurred while processing your request. Our team has been notified and is working on it.</p>
        <a class="btn" href="/">Back to Home</a>
        <div class="footer">OwnPay &bull; Secure Payment Gateway</div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * 503 page for transient database-unavailable conditions.
     *
     * @return string Complete HTML document.
     */
    public function serviceUnavailablePage(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Unavailable - OwnPay</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Inter', sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh
        }

        .c {
            text-align: center;
            max-width: 480px;
            padding: 2rem
        }

        .code {
            font-size: 4rem;
            font-weight: 800;
            background: linear-gradient(135deg, #f59e0b, #f97316);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1;
            margin-bottom: .75rem
        }

        h1 {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: .5rem;
            color: #f1f5f9
        }

        p {
            color: #94a3b8;
            line-height: 1.6;
            font-size: .9rem
        }
    </style>
</head>
<body>
    <div class="c">
        <div class="code">503</div>
        <h1>Service Temporarily Unavailable</h1>
        <p>We are experiencing heavy load right now. Please try again in a moment.</p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * 503 maintenance page - used when the Twig maintenance template fails.
     *
     * @param string $reason Operator-supplied maintenance reason (escaped here).
     * @return string Complete HTML document.
     */
    public function maintenancePage(string $reason): string
    {
        $safeReason = htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance - OwnPay</title>
    <style>
    *{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','Inter',sans-serif;background:#0f172a;color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh}.c{text-align:center;max-width:480px;padding:2rem}.code{font-size:4rem;font-weight:800;background:linear-gradient(135deg,#0ea5e9,#6366f1);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;line-height:1;margin-bottom:.75rem}h1{font-size:1.25rem;font-weight:600;margin-bottom:.5rem;color:#f1f5f9}p{color:#94a3b8;line-height:1.6;font-size:.9rem
    }
    </style>
</head>
<body>
    <div class="c">
        <div class="code">503</div>
        <h1>Maintenance In Progress</h1>
        <p>{$safeReason}</p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Styled debug error page - shows sanitized details for developers.
     *
     * Only rendered when APP_DEBUG=true. Paths are made relative and the
     * message is scrubbed of absolute paths and credential fragments.
     *
     * @param \Throwable $e       The uncaught exception.
     * @param string     $rootDir Project root used to relativize file paths.
     * @return string Complete HTML document.
     */
    public function debugErrorPage(\Throwable $e, string $rootDir): string
    {
        $class = htmlspecialchars(get_class($e), ENT_QUOTES, 'UTF-8');
        $message = htmlspecialchars($this->sanitizeErrorMessage($e->getMessage()), ENT_QUOTES, 'UTF-8');
        $file = htmlspecialchars(str_replace($rootDir, '.', $e->getFile()), ENT_QUOTES, 'UTF-8');
        $line = $e->getLine();

        // Sanitize trace - make paths relative, strip args
        $traceLines = '';
        foreach ($e->getTrace() as $i => $frame) {
            $fPath = isset($frame['file']) ? str_replace($rootDir, '.', $frame['file']) : '[internal]';
            $fLine = $frame['line'] ?? '?';
            $fFunc = ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'];
            $traceLines .= sprintf(
                '<tr><td class="n">#%d</td><td class="f">%s</td><td class="l">%s</td><td class="fn">%s()</td></tr>',
                $i,
                htmlspecialchars($fPath, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $fLine, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($fFunc, ENT_QUOTES, 'UTF-8')
            );
        }

        $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
        $envName = $this->safeEnvName();

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug - {$class}</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'JetBrains Mono',monospace,-apple-system,sans-serif;background:#0c0e14;color:#c9d1d9;min-height:100vh}
        .header{background:linear-gradient(135deg,#1e1229,#161b22);border-bottom:1px solid #30363d;padding:1.25rem 2rem;display:flex;align-items:center;gap:1rem}
        .badge{padding:.3rem .7rem;border-radius:.375rem;font-size:.7rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase}
        .badge-err{background:rgba(248,81,73,.15);color:#f85149;border:1px solid rgba(248,81,73,.3)}
        .badge-debug{background:rgba(136,98,234,.15);color:#8862ea;border:1px solid rgba(136,98,234,.3)}
        .title{font-size:1rem;font-weight:600;color:#e6edf3}
        .main{padding:2rem;max-width:1100px;margin:0 auto}
        .card{background:#161b22;border:1px solid #30363d;border-radius:.75rem;margin-bottom:1.5rem;overflow:hidden}
        .card-head{padding:.75rem 1.25rem;background:#1c2129;border-bottom:1px solid #30363d;font-size:.75rem;font-weight:600;color:#8b949e;text-transform:uppercase;letter-spacing:.05em}
        .card-body{padding:1.25rem}
        .msg{font-size:1.1rem;color:#f0883e;word-break:break-all;line-height:1.5}
        .loc{margin-top:.75rem;font-size:.85rem;color:#8b949e}
        .loc span{color:#58a6ff}
        table{width:100%;border-collapse:collapse;font-size:.8rem}
        tr:hover{background:rgba(56,139,253,.06)}
        td{padding:.5rem .75rem;border-bottom:1px solid #21262d;vertical-align:top}
        .n{color:#484f58;width:2rem;text-align:right}
        .f{color:#7ee787;max-width:350px;overflow:hidden;text-overflow:ellipsis}
        .l{color:#d2a8ff;width:3rem;text-align:center}
        .fn{color:#79c0ff}
        .env-bar{display:flex;gap:1rem;flex-wrap:wrap;font-size:.75rem;color:#8b949e}
        .env-bar span{padding:.25rem .5rem;background:#21262d;border-radius:.25rem}
        .warn{margin-top:1rem;padding:.75rem 1rem;background:rgba(210,153,34,.08);border:1px solid rgba(210,153,34,.3);border-radius:.5rem;font-size:.75rem;color:#d29922}
    </style>
</head>
<body>
    <div class="header">
        <span class="badge badge-err">500</span>
        <span class="badge badge-debug">DEBUG MODE</span>
        <span class="title">{$class}</span>
    </div>
    <div class="main">
        <div class="card">
            <div class="card-head">Exception</div>
            <div class="card-body">
                <div class="msg">{$message}</div>
                <div class="loc">in <span>{$file}</span> on line <span>{$line}</span></div>
            </div>
        </div>
        <div class="card">
            <div class="card-head">Stack Trace</div>
            <div class="card-body" style="padding:0">
                <table>{$traceLines}</table>
            </div>
        </div>
        <div class="card">
            <div class="card-head">Environment</div>
            <div class="card-body">
                <div class="env-bar">
                    <span>PHP {$phpVersion}</span>
                    <span>OwnPay v0.1.0</span>
                    <span>{$envName}</span>
                </div>
                <div class="warn">⚠ This debug page is visible because APP_DEBUG=true. Set APP_DEBUG=false in production to show a generic error page.</div>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Sanitize error message - strip file paths and credentials.
     *
     * @param string $message Raw exception message.
     * @return string Scrubbed message safe for debug display or JSON payloads.
     */
    public function sanitizeErrorMessage(string $message): string
    {
        // Strip full file paths
        $message = preg_replace('#[A-Z]:\\\\[^\s:]+#', '[path]', $message) ?? $message;
        $message = preg_replace('#/[^\s:]+\.php#', '[path]', $message) ?? $message;
        // Strip password references
        $message = preg_replace('#using password: (?:YES|NO)#i', 'using password: ***', $message) ?? $message;
        return $message;
    }

    /**
     * Resolve the APP_ENV name with HTML escaping for safe display.
     */
    private function safeEnvName(): string
    {
        $rawEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
        $envStr = is_string($rawEnv) ? $rawEnv : 'production';
        return htmlspecialchars($envStr, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Fallback 429 page when Twig is unavailable.
     *
     * @param int $retryAfter Lockout duration in seconds.
     * @param int $limit Max requests limit.
     * @return string Complete HTML document.
     */
    public function rateLimitPage(int $retryAfter, int $limit): string
    {
        $safeRetry = (int) $retryAfter;
        
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Too Many Requests - OwnPay</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','Inter',sans-serif;background:#0f172a;color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh}
        .c{text-align:center;max-width:480px;padding:2.5rem;background:rgba(30,41,59,0.4);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border:1px solid rgba(255,255,255,0.06);border-radius:1rem;box-shadow:0 20px 25px -5px rgba(0,0,0,0.3),0 10px 10px -5px rgba(0,0,0,0.2)}
        .icon{width:80px;height:80px;margin:0 auto 1.5rem;border-radius:50%;background:rgba(245,158,11,0.12);display:flex;align-items:center;justify-content:center}
        .icon svg{width:40px;height:40px;color:#fbbf24}
        .code{font-size:4.5rem;font-weight:800;background:linear-gradient(135deg,#fbbf24,#ef4444);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;line-height:1;margin-bottom:.75rem}
        h1{font-size:1.25rem;font-weight:600;margin-bottom:.75rem;color:#f1f5f9}
        p{color:#94a3b8;line-height:1.6;margin-bottom:1.5rem;font-size:.9rem}
        .timer-box{font-weight:bold;color:#f59e0b;font-size:1.1rem;margin-bottom:1.5rem}
        .btn{display:inline-block;padding:.7rem 1.75rem;background:linear-gradient(135deg,#f59e0b,#ef4444);color:#fff;text-decoration:none;border-radius:.5rem;font-weight:500;font-size:.9rem;transition:all 0.2s ease;box-shadow:0 2px 8px rgba(239,68,68,0.3);border:none;cursor:pointer}
        .btn:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(239,68,68,0.45)}
        .btn.disabled{background:#475569;box-shadow:none;cursor:not-allowed;transform:none}
        .footer{margin-top:2.5rem;font-size:.7rem;color:#475569;letter-spacing:.03em}
    </style>
</head>
<body>
    <div class="c">
        <div class="icon">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
        </div>
        <div class="code">429</div>
        <h1>Too Many Requests</h1>
        <p id="message-text">You have sent too many requests in a short period of time. Please slow down and try again.</p>
        
        <div class="timer-box" id="timer-box">
            Retry available in <span id="countdown">{$safeRetry}</span> seconds
        </div>
        
        <button id="retry-btn" class="btn disabled" disabled onclick="window.location.reload();">Retry Now</button>
        
        <div class="footer">OwnPay &bull; Secure Payment Gateway</div>
    </div>

    <script>
        (function() {
            var retryAfter = parseInt("{$safeRetry}", 10) || 60;
            var countdownEl = document.getElementById("countdown");
            var retryBtn = document.getElementById("retry-btn");
            var timerBox = document.getElementById("timer-box");
            var msgText = document.getElementById("message-text");

            var timer = setInterval(function() {
                retryAfter--;
                if (countdownEl) {
                    countdownEl.textContent = retryAfter;
                }
                
                if (retryAfter <= 0) {
                    clearInterval(timer);
                    if (timerBox) {
                        timerBox.style.display = "none";
                    }
                    if (msgText) {
                        msgText.textContent = "Cooldown period finished. You can now retry your request.";
                    }
                    if (retryBtn) {
                        retryBtn.removeAttribute("disabled");
                        retryBtn.classList.remove("disabled");
                        retryBtn.textContent = "Reload Page";
                    }
                }
            }, 1000);
        })();
    </script>
</body>
</html>
HTML;
    }
}

