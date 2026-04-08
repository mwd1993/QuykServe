<?php

declare(strict_types=1);

namespace QuykServe;

use Closure;
use JsonException;
use RuntimeException;
use Throwable;

final class QuykServe
{
    private array $config = [
        'env' => 'production',
        'debug' => false,
        'json_flags' => JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        'json_depth' => 512,
        'default_headers' => [
            'Content-Type' => 'application/json; charset=utf-8',
        ],
        'hooks' => [
            'beforeRequest' => null,
            'beforeResponse' => null,
            'onError' => null,
        ],
    ];

    private ?Request $request = null;
    private ?Response $response = null;

    public function __construct(array $config = [])
    {
        $this->config = $this->mergeConfig($this->config, $config);
    }

    public static function create(array $config = []): self
    {
        return new self($config);
    }

    public function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }

        return $this->config[$key] ?? $default;
    }

    public function request(): Request
    {
        if ($this->request instanceof Request) {
            return $this->request;
        }

        $request = Request::capture();

        $hook = $this->config['hooks']['beforeRequest'] ?? null;
        if ($hook instanceof Closure || is_callable($hook)) {
            $modified = $hook($request, $this);

            if ($modified instanceof Request) {
                $request = $modified;
            }
        }

        $this->request = $request;

        return $request;
    }

    public function response(): Response
    {
        if ($this->response instanceof Response) {
            return $this->response;
        }

        $this->response = new Response(
            defaultHeaders: $this->config['default_headers'],
            jsonFlags: (int) $this->config['json_flags'],
            jsonDepth: (int) $this->config['json_depth']
        );

        return $this->response;
    }

    public function json(
        mixed $data = null,
        int $status = 200,
        array $headers = [],
        ?string $message = null,
        bool $success = true,
        array $meta = [],
        array $errors = []
    ): Response {
        $payload = [
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'errors' => $errors ?: null,
            'meta' => $meta ?: null,
        ];

        return $this->response()->json($this->removeNulls($payload), $status, $headers);
    }

    public function success(
        mixed $data = null,
        ?string $message = 'OK',
        int $status = 200,
        array $headers = [],
        array $meta = []
    ): Response {
        return $this->json(
            data: $data,
            status: $status,
            headers: $headers,
            message: $message,
            success: true,
            meta: $meta
        );
    }

    public function created(
        mixed $data = null,
        ?string $message = 'Created',
        array $headers = [],
        array $meta = []
    ): Response {
        return $this->success(
            data: $data,
            message: $message,
            status: 201,
            headers: $headers,
            meta: $meta
        );
    }

    public function noContent(array $headers = []): Response
    {
        return $this->response()->empty(204, $headers);
    }

    public function fail(
        string $message = 'Request failed',
        int $status = 400,
        array $errors = [],
        array $headers = [],
        array $meta = []
    ): Response {
        return $this->json(
            data: null,
            status: $status,
            headers: $headers,
            message: $message,
            success: false,
            meta: $meta,
            errors: $errors
        );
    }

    public function badRequest(
        string $message = 'Bad request',
        array $errors = [],
        array $headers = []
    ): Response {
        return $this->fail($message, 400, $errors, $headers);
    }

    public function unauthorized(
        string $message = 'Unauthorized',
        array $errors = [],
        array $headers = []
    ): Response {
        return $this->fail($message, 401, $errors, $headers);
    }

    public function forbidden(
        string $message = 'Forbidden',
        array $errors = [],
        array $headers = []
    ): Response {
        return $this->fail($message, 403, $errors, $headers);
    }

    public function notFound(
        string $message = 'Not found',
        array $errors = [],
        array $headers = []
    ): Response {
        return $this->fail($message, 404, $errors, $headers);
    }

    public function validation(
        array $errors,
        string $message = 'Validation failed',
        array $headers = []
    ): Response {
        return $this->fail($message, 422, $errors, $headers);
    }

    public function tooManyRequests(
        string $message = 'Too many requests',
        array $errors = [],
        array $headers = []
    ): Response {
        return $this->fail($message, 429, $errors, $headers);
    }

    public function serverError(
        string $message = 'Server error',
        array $errors = [],
        array $headers = []
    ): Response {
        return $this->fail($message, 500, $errors, $headers);
    }

    public function run(callable $handler): never
    {
        try {
            $request = $this->request();
            $result = $handler($request, $this);

            $response = match (true) {
                $result instanceof Response => $result,
                is_array($result), is_object($result) => $this->success($result),
                $result === null => $this->noContent(),
                default => $this->response()->text((string) $result),
            };

            $this->send($response);
        } catch (Throwable $e) {
            $this->handleException($e);
        }

        exit;
    }

    public function send(Response $response): never
    {
        $hook = $this->config['hooks']['beforeResponse'] ?? null;

        if ($hook instanceof Closure || is_callable($hook)) {
            $modified = $hook($response, $this);

            if ($modified instanceof Response) {
                $response = $modified;
            }
        }

        $response->send();
        exit;
    }

    public function handleException(Throwable $e): never
    {
        $hook = $this->config['hooks']['onError'] ?? null;

        if ($hook instanceof Closure || is_callable($hook)) {
            $hookResult = $hook($e, $this);

            if ($hookResult instanceof Response) {
                $this->send($hookResult);
            }
        }

        if ($e instanceof QuykServeError) {
            $payload = [
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $e->getErrors(),
                'meta' => $this->config['debug']
                    ? [
                        'exception' => get_class($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]
                    : null,
            ];

            $this->send(
                $this->response()->json(
                    $this->removeNulls($payload),
                    $e->getStatus(),
                    $e->getHeaders()
                )
            );
        }

        $payload = [
            'success' => false,
            'message' => $this->config['debug']
                ? $e->getMessage()
                : 'Internal server error',
            'errors' => null,
            'meta' => $this->config['debug']
                ? [
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => explode("\n", $e->getTraceAsString()),
                ]
                : null,
        ];

        $this->send($this->response()->json($this->removeNulls($payload), 500));
    }

    private function mergeConfig(array $base, array $overrides): array
    {
        $merged = $base;

        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = $this->mergeConfig($merged[$key], $value);
                continue;
            }

            $merged[$key] = $value;
        }

        return $merged;
    }

    private function removeNulls(array $payload): array
    {
        return array_filter(
            $payload,
            static fn (mixed $value): bool => $value !== null
        );
    }
}

final class Request
{
    private array $query;
    private array $body;
    private array $server;
    private array $cookies;
    private array $files;
    private array $headers;
    private string $rawBody;

    public function __construct(
        array $query = [],
        array $body = [],
        array $server = [],
        array $cookies = [],
        array $files = [],
        array $headers = [],
        string $rawBody = ''
    ) {
        $this->query = $query;
        $this->body = $body;
        $this->server = $server;
        $this->cookies = $cookies;
        $this->files = $files;
        $this->headers = $headers;
        $this->rawBody = $rawBody;
    }

    public static function capture(): self
    {
        $server = $_SERVER ?? [];
        $query = $_GET ?? [];
        $post = $_POST ?? [];
        $cookies = $_COOKIE ?? [];
        $files = $_FILES ?? [];
        $headers = self::normalizeHeaders(function_exists('getallheaders') ? (getallheaders() ?: []) : []);
        $rawBody = file_get_contents('php://input') ?: '';

        $body = $post;

        if (empty($body) && $rawBody !== '') {
            $contentType = self::contentTypeFromHeaders($headers);

            if (str_contains($contentType, 'application/json')) {
                try {
                    $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $body = $decoded;
                    }
                } catch (JsonException) {
                    $body = [];
                }
            } elseif (str_contains($contentType, 'application/x-www-form-urlencoded')) {
                parse_str($rawBody, $parsed);
                if (is_array($parsed)) {
                    $body = $parsed;
                }
            }
        }

        return new self(
            query: $query,
            body: $body,
            server: $server,
            cookies: $cookies,
            files: $files,
            headers: $headers,
            rawBody: $rawBody
        );
    }

    public function method(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    public function isMethod(string $method): bool
    {
        return $this->method() === strtoupper($method);
    }

    public function url(): string
    {
        $scheme = $this->scheme();
        $host = $this->host();
        $uri = $this->server['REQUEST_URI'] ?? '/';

        return $scheme . '://' . $host . $uri;
    }

    public function scheme(): string
    {
        $https = $this->server['HTTPS'] ?? '';
        $forwardedProto = $this->header('x-forwarded-proto');

        if ($forwardedProto) {
            return strtolower(explode(',', $forwardedProto)[0]);
        }

        if ($https === 'on' || $https === '1') {
            return 'https';
        }

        return 'http';
    }

    public function host(): string
    {
        return $this->header('host')
            ?? $this->server['HTTP_HOST']
            ?? $this->server['SERVER_NAME']
            ?? 'localhost';
    }

    public function path(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }

        return $this->query[$key] ?? $default;
    }

    public function input(?string $key = null, mixed $default = null): mixed
    {
        $merged = array_replace($this->query, $this->body);

        if ($key === null) {
            return $merged;
        }

        return $merged[$key] ?? $default;
    }

    public function body(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->body;
        }

        return $this->body[$key] ?? $default;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function json(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->body;
        }

        return $this->body[$key] ?? $default;
    }

    public function header(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->headers;
        }

        return $this->headers[strtolower($key)] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('authorization');

        if (!is_string($header) || $header === '') {
            return null;
        }

        if (preg_match('/Bearer\s+(.+)/i', $header, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    public function cookie(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->cookies;
        }

        return $this->cookies[$key] ?? $default;
    }

    public function file(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->files;
        }

        return $this->files[$key] ?? null;
    }

    public function server(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->server;
        }

        return $this->server[$key] ?? $default;
    }

    public function ip(): ?string
    {
        $forwarded = $this->header('x-forwarded-for');
        if (is_string($forwarded) && $forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }

        return $this->server['REMOTE_ADDR'] ?? null;
    }

    public function userAgent(): ?string
    {
        return $this->header('user-agent');
    }

    public function isAjax(): bool
    {
        return strtolower((string) $this->header('x-requested-with', '')) === 'xmlhttprequest';
    }

    public function expectsJson(): bool
    {
        $accept = strtolower((string) $this->header('accept', ''));
        return str_contains($accept, 'application/json');
    }

    public function contentType(): string
    {
        return self::contentTypeFromHeaders($this->headers);
    }

    public function all(): array
    {
        return $this->input();
    }

    public function only(array $keys): array
    {
        $data = $this->input();
        $result = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $result[$key] = $data[$key];
            }
        }

        return $result;
    }

    public function except(array $keys): array
    {
        $data = $this->input();

        foreach ($keys as $key) {
            unset($data[$key]);
        }

        return $data;
    }

    public function has(string $key): bool
    {
        $data = $this->input();
        return array_key_exists($key, $data);
    }

    public function filled(string $key): bool
    {
        $value = $this->input($key);

        return !($value === null || $value === '' || $value === []);
    }

    public function missing(string $key): bool
    {
        return !$this->has($key);
    }

    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $key => $value) {
            $normalized[strtolower((string) $key)] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        if ($normalized !== []) {
            return $normalized;
        }

        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, 'HTTP_')) {
                continue;
            }

            $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
            $normalized[$headerName] = (string) $value;
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $normalized['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $normalized['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        return $normalized;
    }

    private static function contentTypeFromHeaders(array $headers): string
    {
        return strtolower(trim((string) ($headers['content-type'] ?? '')));
    }
}

final class Response
{
    private int $status = 200;
    private array $headers = [];
    private string $body = '';
    private bool $sent = false;
    private int $jsonFlags;
    private int $jsonDepth;

    public function __construct(
        array $defaultHeaders = [],
        int $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        int $jsonDepth = 512
    ) {
        $this->headers = $defaultHeaders;
        $this->jsonFlags = $jsonFlags;
        $this->jsonDepth = $jsonDepth;
    }

    public function status(int $status): self
    {
        $this->status = max(100, min(599, $status));
        return $this;
    }

    public function header(string $key, string $value): self
    {
        $this->headers[$key] = $value;
        return $this;
    }

    public function headers(array $headers): self
    {
        foreach ($headers as $key => $value) {
            $this->header((string) $key, (string) $value);
        }

        return $this;
    }

    public function body(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    public function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $this->status($status);
        $this->headers(array_merge(
            ['Content-Type' => 'application/json; charset=utf-8'],
            $headers
        ));

        try {
            $this->body = json_encode($data, $this->jsonFlags | JSON_THROW_ON_ERROR, $this->jsonDepth);
        } catch (JsonException $e) {
            throw new RuntimeException('Failed to encode JSON response.', 0, $e);
        }

        return $this;
    }

    public function text(string $text, int $status = 200, array $headers = []): self
    {
        $this->status($status);
        $this->headers(array_merge(
            ['Content-Type' => 'text/plain; charset=utf-8'],
            $headers
        ));
        $this->body = $text;

        return $this;
    }

    public function html(string $html, int $status = 200, array $headers = []): self
    {
        $this->status($status);
        $this->headers(array_merge(
            ['Content-Type' => 'text/html; charset=utf-8'],
            $headers
        ));
        $this->body = $html;

        return $this;
    }

    public function redirect(string $url, int $status = 302, array $headers = []): self
    {
        $this->status($status);
        $this->headers($headers);
        $this->header('Location', $url);
        $this->body = '';

        return $this;
    }

    public function download(
        string $content,
        string $filename,
        string $contentType = 'application/octet-stream',
        int $status = 200,
        array $headers = []
    ): self {
        $this->status($status);
        $this->headers(array_merge([
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="' . addslashes($filename) . '"',
            'Content-Length' => (string) strlen($content),
        ], $headers));

        $this->body = $content;

        return $this;
    }

    public function empty(int $status = 204, array $headers = []): self
    {
        $this->status($status);
        $this->headers($headers);
        $this->body = '';

        unset($this->headers['Content-Type']);
        unset($this->headers['Content-Length']);

        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function send(): void
    {
        if ($this->sent) {
            return;
        }

        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $key => $value) {
                header($key . ': ' . $value, true);
            }
        }

        echo $this->body;
        $this->sent = true;
    }
}

final class QuykServeError extends RuntimeException
{
    private int $status;
    private array $errors;
    private array $headers;

    public function __construct(
        string $message = 'Request failed',
        int $status = 400,
        array $errors = [],
        array $headers = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $status, $previous);

        $this->status = max(100, min(599, $status));
        $this->errors = $errors;
        $this->headers = $headers;
    }

    public static function badRequest(string $message = 'Bad request', array $errors = []): self
    {
        return new self($message, 400, $errors);
    }

    public static function unauthorized(string $message = 'Unauthorized', array $errors = []): self
    {
        return new self($message, 401, $errors);
    }

    public static function forbidden(string $message = 'Forbidden', array $errors = []): self
    {
        return new self($message, 403, $errors);
    }

    public static function notFound(string $message = 'Not found', array $errors = []): self
    {
        return new self($message, 404, $errors);
    }

    public static function validation(string $message = 'Validation failed', array $errors = []): self
    {
        return new self($message, 422, $errors);
    }

    public static function tooManyRequests(string $message = 'Too many requests', array $errors = []): self
    {
        return new self($message, 429, $errors);
    }

    public static function serverError(string $message = 'Server error', array $errors = []): self
    {
        return new self($message, 500, $errors);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }
}