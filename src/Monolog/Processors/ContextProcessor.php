<?php

namespace Shaffe\MailLogChannel\Monolog\Processors;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Shaffe\MailLogChannel\QueryCollector;

class ContextProcessor implements ProcessorInterface
{
    /**
     * Field names that are redacted from the request payload by default.
     *
     * Covers common authentication secrets and PCI / payment form fields used
     * by popular gateways (Stripe, Braintree, PayPal, generic card forms).
     *
     * @var array<int, string>
     */
    public const DEFAULT_REDACT_KEYS = [
        // Authentication / secrets
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'secret',
        'token',
        '_token',
        'access_token',
        'refresh_token',
        'api_key',
        'apikey',
        'api_token',
        'authorization',
        'client_secret',

        // Card / payment data (PCI)
        'card_number',
        'cardnumber',
        'card_no',
        'cc_number',
        'ccnumber',
        'cc_num',
        'cvc',
        'cvv',
        'cvv2',
        'cvc2',
        'card_cvc',
        'card_cvv',
        'security_code',
        'exp_month',
        'exp_year',
        'expiry',
        'expiration',
        'expiration_date',
        'exp_date',

        // Stripe
        'stripe_token',
        'stripetoken',
        'payment_method',
        'payment_intent',
        'setup_intent',

        // Braintree / PayPal / other gateways
        'payment_method_nonce',
        'nonce',

        // Bank details
        'iban',
        'bic',
        'account_number',
        'routing_number',
        'sort_code',
    ];

    protected ?QueryCollector $queryCollector;

    protected bool $logRequestPayload;

    /** @var array<int, string> Lowercased field names to redact. */
    protected array $redactKeys;

    protected int $maxValueLength;

    protected int $maxKeys;

    /**
     * @param  array<int, string>  $redactKeys  Extra field names to redact, merged with self::DEFAULT_REDACT_KEYS.
     * @param  int  $maxValueLength  Max length of a scalar value before it is truncated.
     * @param  int  $maxKeys  Max number of keys kept per array level.
     */
    public function __construct(
        ?QueryCollector $queryCollector = null,
        bool $logRequestPayload = false,
        array $redactKeys = [],
        int $maxValueLength = 500,
        int $maxKeys = 50,
    ) {
        $this->queryCollector = $queryCollector;
        $this->logRequestPayload = $logRequestPayload;
        // User-provided keys are additive: the PCI/auth defaults are always
        // applied so a custom list can never accidentally expose them.
        $this->redactKeys = array_values(array_unique(array_map(
            'strtolower',
            array_merge(self::DEFAULT_REDACT_KEYS, $redactKeys)
        )));
        $this->maxValueLength = $maxValueLength;
        $this->maxKeys = $maxKeys;
    }

    /**
     * Process a log record and enrich it with execution context.
     */
    public function __invoke(LogRecord|array $record): LogRecord|array
    {
        $extra = $record instanceof LogRecord ? $record->extra : ($record['extra'] ?? []);

        $extra['execution_context'] = $this->gatherExecutionContext();
        $extra['environment'] = $this->gatherEnvironment();
        $extra['request_payload'] = $this->gatherRequestPayload();
        $extra['code_snippet'] = $this->extractCodeSnippet($record);
        $extra['sql_queries'] = $this->gatherSqlQueries();
        $extra['additional_context'] = $this->gatherAdditionalContext($record);

        if ($record instanceof LogRecord) {
            return $record->with(extra: $extra);
        }

        $record['extra'] = $extra;

        return $record;
    }

    protected function gatherExecutionContext(): array
    {
        if (! function_exists('app')) {
            return ['type' => 'unknown'];
        }

        $app = app();

        if ($app->runningInConsole()) {
            return $this->gatherConsoleContext();
        }

        return $this->gatherHttpContext();
    }

    protected function gatherConsoleContext(): array
    {
        $context = ['type' => 'console'];

        // Detect if running as a queued job
        if ($this->isRunningAsQueueWorker()) {
            $context['type'] = 'queue';
            $context['command'] = 'queue:work';

            // Try to get current job info from context
            if (function_exists('app') && app()->bound('queue')) {
                try {
                    $context['connection'] = config('queue.default', 'sync');
                    $context['queue'] = config('queue.connections.'.config('queue.default').'.queue', 'default');
                } catch (\Throwable) {
                    // Silently ignore
                }
            }

            return $context;
        }

        // Artisan command
        if (isset($_SERVER['argv'])) {
            $context['command'] = implode(' ', $_SERVER['argv']);
        }

        return $context;
    }

    protected function gatherHttpContext(): array
    {
        $context = ['type' => 'http'];

        try {
            $request = request();
            $context['method'] = $request->method();
            $context['url'] = $request->fullUrl();
            $context['route_name'] = optional($request->route())->getName();
            $context['controller'] = $this->resolveController($request);
            $context['ip'] = $request->ip();

            // Authenticated user
            $user = $this->resolveUser();
            if ($user) {
                $context['user'] = $user;
            }
        } catch (\Throwable) {
            // Request might not be available
        }

        return $context;
    }

    protected function resolveController($request): ?string
    {
        try {
            $route = $request->route();
            if (! $route) {
                return null;
            }

            $action = $route->getActionName();

            return $action !== 'Closure' ? $action : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function resolveUser(): ?array
    {
        try {
            // Resolving a user can trigger database queries (e.g. token or
            // session guards hitting the users table). Pause the collector so
            // these internal lookups don't pollute the SQL section of the email.
            $this->queryCollector?->pause();

            // Try all configured guards to find an authenticated user.
            // This handles multi-guard setups (e.g. web + api + admin).
            $guards = array_keys(config('auth.guards', []));

            foreach ($guards as $guard) {
                try {
                    $user = auth()->guard($guard)->user();

                    if ($user) {
                        // Use the Authenticatable contract's identifier rather
                        // than the Eloquent-specific getKey(), so non-Eloquent
                        // user providers are supported too.
                        return [
                            'id' => $user->getAuthIdentifier(),
                            'email' => $user->email ?? null,
                            'guard' => $guard,
                        ];
                    }
                } catch (\Throwable) {
                    // Guard driver may not be configured — skip silently.
                    continue;
                }
            }

            return null;
        } catch (\Throwable) {
            return null;
        } finally {
            $this->queryCollector?->resume();
        }
    }

    protected function gatherEnvironment(): array
    {
        $env = [];

        try {
            $env['app_env'] = config('app.env', 'unknown');
            $env['php_version'] = PHP_VERSION;
            $env['laravel_version'] = app()->version();
            $env['server'] = gethostname() ?: null;
            $env['memory_peak'] = memory_get_peak_usage(true);
            $env['execution_time'] = defined('LARAVEL_START')
                ? round((microtime(true) - LARAVEL_START) * 1000, 1)
                : null;
        } catch (\Throwable) {
            // Silently ignore
        }

        return $env;
    }

    /**
     * Gather the incoming request payload (opt-in).
     *
     * Returns null when disabled, on the console, or when no request/data is
     * available. Sensitive fields are redacted, values are truncated, and the
     * number of kept keys is bounded. Uploaded files are described (name, type,
     * size) — their contents are never serialized.
     *
     * @return array{data?: array<string, mixed>, files?: array<int, array{name: string, type: string|null, size: int|null}>, truncated_keys?: int}|null
     */
    protected function gatherRequestPayload(): ?array
    {
        if (! $this->logRequestPayload) {
            return null;
        }

        if (! function_exists('app') || ! function_exists('request')) {
            return null;
        }

        try {
            $app = app();

            if ($app->runningInConsole()) {
                return null;
            }

            $request = request();
        } catch (\Throwable) {
            return null;
        }

        $payload = [];

        try {
            // Use request->all() to capture the full input structure, then let
            // sanitizePayload() redact sensitive keys uniformly (shown as [REDACTED]
            // so the developer knows the field was present without seeing its value).
            $data = $request->all();

            [$sanitized, $truncatedKeys] = $this->sanitizePayload($data);

            if ($sanitized !== []) {
                $payload['data'] = $sanitized;
            }

            if ($truncatedKeys > 0) {
                $payload['truncated_keys'] = $truncatedKeys;
            }
        } catch (\Throwable) {
            // Silently ignore — never let payload collection break logging.
        }

        try {
            $files = $this->describeUploadedFiles($request);
            if ($files !== []) {
                $payload['files'] = $files;
            }
        } catch (\Throwable) {
            // Silently ignore
        }

        return $payload !== [] ? $payload : null;
    }

    /**
     * Recursively redact, truncate and bound an input array.
     *
     * @param  array<array-key, mixed>  $data
     * @return array{0: array<array-key, mixed>, 1: int} Sanitized data and the number of keys dropped by the per-level cap.
     */
    protected function sanitizePayload(array $data, int $depth = 0): array
    {
        $result = [];
        $kept = 0;
        $truncatedKeys = 0;

        foreach ($data as $key => $value) {
            // Redacted keys don't consume the maxKeys budget.
            if (is_string($key) && in_array(strtolower($key), $this->redactKeys, true)) {
                $result[$key] = '[REDACTED]';

                continue;
            }

            if ($kept >= $this->maxKeys) {
                $truncatedKeys++;

                continue;
            }

            $kept++;

            if (is_array($value)) {
                // Guard against pathologically deep structures.
                if ($depth >= 5) {
                    $result[$key] = '[…]';

                    continue;
                }

                [$nested, $nestedTruncated] = $this->sanitizePayload($value, $depth + 1);
                $result[$key] = $nested;
                $truncatedKeys += $nestedTruncated;

                continue;
            }

            $result[$key] = $this->truncateValue($value);
        }

        return [$result, $truncatedKeys];
    }

    /**
     * Normalize and truncate a scalar value, annotating its original size.
     */
    protected function truncateValue(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (! is_string($value)) {
            // Objects/closures that slipped through: describe rather than dump.
            return '['.gettype($value).']';
        }

        $length = mb_strlen($value);

        if ($length <= $this->maxValueLength) {
            return $value;
        }

        return mb_substr($value, 0, $this->maxValueLength).'… ['.$length.' chars total]';
    }

    /**
     * Describe uploaded files without reading their contents.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<int, array{name: string, type: string|null, size: int|null}>
     */
    protected function describeUploadedFiles(mixed $request): array
    {
        if (! method_exists($request, 'allFiles')) { /** @phpstan-ignore function.alreadyNarrowedType */
            return [];
        }

        $described = [];

        $walk = function ($files) use (&$walk, &$described) {
            foreach ($files as $file) {
                if (is_array($file)) {
                    $walk($file);

                    continue;
                }

                if ($file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                    $described[] = [
                        'name' => $file->getClientOriginalName(),
                        'type' => $file->getClientMimeType(),
                        'size' => $file->getSize(),
                    ];
                }
            }
        };

        $walk($request->allFiles());

        return $described;
    }

    protected function extractCodeSnippet(LogRecord|array $record): ?array
    {
        $context = $record instanceof LogRecord ? $record->context : ($record['context'] ?? []);

        $exception = $context['exception'] ?? null;

        if (! $exception instanceof \Throwable) {
            return null;
        }

        $file = $exception->getFile();
        $line = $exception->getLine();

        if (! $file || ! is_readable($file)) {
            return null;
        }

        try {
            $lines = file($file);
            if ($lines === false) {
                return null;
            }

            $start = max(0, $line - 6);
            $end = min(count($lines), $line + 5);
            $snippet = [];

            for ($i = $start; $i < $end; $i++) {
                $snippet[$i + 1] = rtrim($lines[$i]);
            }

            return [
                'file' => $file,
                'line' => $line,
                'code' => $snippet,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    protected function gatherSqlQueries(): ?array
    {
        if (! $this->queryCollector) {
            return null;
        }

        $queries = $this->queryCollector->getQueries();

        if (empty($queries)) {
            return null;
        }

        return [
            'items' => $queries,
            'total' => $this->queryCollector->getTotal(),
        ];
    }

    protected function isRunningAsQueueWorker(): bool
    {
        if (isset($_SERVER['argv']) && is_array($_SERVER['argv'])) {
            $command = implode(' ', $_SERVER['argv']);

            return str_contains($command, 'queue:work') || str_contains($command, 'queue:listen');
        }

        return false;
    }

    protected function gatherAdditionalContext(LogRecord|array $record): ?array
    {
        $context = $record instanceof LogRecord ? $record->context : ($record['context'] ?? []);

        // Monolog record context (excluding the exception itself)
        $data = array_diff_key($context, ['exception' => true]);

        return ! empty($data) ? $data : null;
    }
}
