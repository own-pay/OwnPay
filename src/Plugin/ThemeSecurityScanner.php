<?php

declare(strict_types=1);

namespace OwnPay\Plugin;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * Static, activation-time AST scan of a theme's plain-PHP template files.
 *
 * This is defense-in-depth, NOT a runtime sandbox or a hard security boundary - PHP has
 * no true language-level isolation without process separation (a separate PHP-CLI
 * subprocess with a restricted php.ini), which many shared-hosting deployments of this
 * app cannot support (no proc_open/shell access). A sufficiently determined theme author
 * can still obfuscate a dangerous call past static analysis (e.g. dynamically
 * constructing a function name from string fragments and invoking it via
 * call_user_func()). This scan catches accidental misuse, casual bad actors, and obvious
 * data-exfiltration/DB-access attempts - it does not achieve airtight isolation.
 *
 * Scope: only PHP files under a theme's templates/ directory (the plain-PHP engine's
 * actual render targets). A theme's entry class and any other PHP file are already
 * covered by PluginLoader's separate, existing token-based dangerous-function scan.
 */
final class ThemeSecurityScanner
{
    /**
     * Fully-qualified class names a theme template must never reference directly.
     *
     * Not private: the scanning visitor is an anonymous class declared inside scan()'s
     * body. Anonymous classes do NOT inherit the enclosing class's private-member
     * visibility scope for static access (PHP only extends private visibility to
     * anonymous classes for $this-based member access when they extend/use the
     * enclosing class) - accessing a private const via ThemeSecurityScanner::BLOCKED_CLASSES
     * from the visitor fatals with "Cannot access private constant". These constants must
     * be at least protected/public for the visitor to read them.
     *
     * @var array<int, string>
     */
    public const BLOCKED_CLASSES = [
        'OwnPay\\Core\\Database',
        'OwnPay\\Container',
        'PDO',
    ];

    /** @var array<int, string> Safe $_SERVER keys a theme template may read without a warning. */
    public const SAFE_SERVER_KEYS = ['REQUEST_URI', 'HTTP_HOST'];

    /**
     * Scans every .php file under $themeBasePath/templates/ and returns the findings.
     *
     * @param string $themeBasePath Absolute path to the theme's root directory (e.g. modules/themes/my-theme).
     * @return array{blocked: array<int, string>, warnings: array<int, string>}
     */
    public static function scan(string $themeBasePath): array
    {
        $templatesDir = rtrim($themeBasePath, '/\\') . '/templates';
        $blocked = [];
        $warnings = [];

        if (!is_dir($templatesDir)) {
            return ['blocked' => $blocked, 'warnings' => $warnings];
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        foreach (self::findPhpFiles($templatesDir) as $file) {
            $relativePath = 'templates' . substr($file, strlen($templatesDir));
            $content = (string) file_get_contents($file);

            try {
                $ast = $parser->parse($content);
            } catch (\PhpParser\Error) {
                // Unparsable file - not this scanner's job to report syntax errors,
                // leave it alone (the renderer will fail loudly at actual render time).
                continue;
            }

            if ($ast === null) {
                continue;
            }

            $visitor = new class ($relativePath) extends NodeVisitorAbstract {
                /** @var array<int, string> */
                public array $blocked = [];
                /** @var array<int, string> */
                public array $warnings = [];

                public function __construct(private readonly string $relativePath)
                {
                }

                public function enterNode(Node $node)
                {
                    if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
                        $funcName = ltrim($node->name->toString(), '\\');
                        if (PluginSandbox::isDangerousFunction($funcName)) {
                            $this->blocked[] = "{$this->relativePath}:{$node->getLine()}: dangerous function call: {$funcName}()";
                        } elseif (str_starts_with(strtolower($funcName), 'mysqli_')) {
                            $this->blocked[] = "{$this->relativePath}:{$node->getLine()}: dangerous function call: {$funcName}()";
                        }
                    }

                    if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name) {
                        $this->flagIfBlockedClass($node->class->toString(), $node->getLine());
                    }

                    if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
                        $this->flagIfBlockedClass($node->class->toString(), $node->getLine());
                    }

                    if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
                        if ($node->name === 'GLOBALS') {
                            $this->warnings[] = "{$this->relativePath}:{$node->getLine()}: reads \$GLOBALS superglobal";
                        }
                    }

                    if ($node instanceof Node\Expr\ArrayDimFetch
                        && $node->var instanceof Node\Expr\Variable
                        && is_string($node->var->name)
                        && in_array($node->var->name, ['_SERVER', '_ENV', '_REQUEST'], true)
                    ) {
                        $key = ($node->dim instanceof Node\Scalar\String_) ? $node->dim->value : null;
                        if ($node->var->name !== '_SERVER' || $key === null || !in_array($key, ThemeSecurityScanner::SAFE_SERVER_KEYS, true)) {
                            $this->warnings[] = "{$this->relativePath}:{$node->getLine()}: reads \${$node->var->name} superglobal" . ($key !== null ? "['{$key}']" : '');
                        }
                    }

                    return null;
                }

                private function flagIfBlockedClass(string $className, int $line): void
                {
                    $className = ltrim($className, '\\');
                    foreach (ThemeSecurityScanner::BLOCKED_CLASSES as $blockedClass) {
                        if (strcasecmp($className, ltrim($blockedClass, '\\')) === 0) {
                            $this->blocked[] = "{$this->relativePath}:{$line}: references restricted class: {$className}";
                            return;
                        }
                    }
                }
            };

            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            $blocked = array_merge($blocked, $visitor->blocked);
            $warnings = array_merge($warnings, $visitor->warnings);
        }

        return ['blocked' => $blocked, 'warnings' => $warnings];
    }

    /**
     * @return array<int, string> Absolute paths of every .php file under $directory.
     */
    private static function findPhpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
