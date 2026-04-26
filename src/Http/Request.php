<?php
declare(strict_types=1);

namespace OwnPay\Http;

class Request
{
    private array $get;
    private array $post;
    private array $server;

    public function __construct(array $get = [], array $post = [], array $server = [])
    {
        $this->get = $get ?: $_GET;
        $this->post = $post ?: $_POST;
        $this->server = $server ?: $_SERVER;
    }

    public static function createFromGlobals(): self
    {
        return new self($_GET, $_POST, $_SERVER);
    }

    /**
     * Get a value from the GET parameters.
     */
    public function get(string $key, $default = null, bool $sanitize = true)
    {
        $value = $this->get[$key] ?? $default;
        return $sanitize ? $this->sanitize($value) : $value;
    }

    /**
     * Get a value from the POST parameters.
     */
    public function post(string $key, $default = null, bool $sanitize = true)
    {
        $value = $this->post[$key] ?? $default;
        return $sanitize ? $this->sanitize($value) : $value;
    }

    /**
     * Get all POST parameters.
     */
    public function postAll(bool $sanitize = true): array
    {
        return $sanitize ? $this->sanitize($this->post) : $this->post;
    }

    /**
     * Get a value from the POST parameters (alias).
     */
    public function input(string $key, $default = null, bool $sanitize = true)
    {
        return $this->post($key, $default, $sanitize);
    }

    /**
     * Get a header value from the SERVER parameters.
     */
    public function header(string $key, $default = null)
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
        return $this->server[$serverKey] ?? $default;
    }

    /**
     * Check if the request is an AJAX request.
     */
    public function isAjax(): bool
    {
        return isset($this->server['HTTP_X_REQUESTED_WITH']) &&
            strtolower($this->server['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    /**
     * Check if the request method is POST.
     */
    public function isPost(): bool
    {
        return ($this->server['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    /**
     * Check if the request method is GET.
     */
    public function isGet(): bool
    {
        return ($this->server['REQUEST_METHOD'] ?? 'GET') === 'GET';
    }

    /**
     * Basic sanitization logic.
     *
     * Trims whitespace from string inputs. PDO handles SQL safety
     * and the view layer handles HTML encoding via sanitize_html().
     */
    private function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map([$this, 'sanitize'], $value);
        }

        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }
}
