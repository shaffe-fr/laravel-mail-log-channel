<?php

namespace Shaffe\MailLogChannel\Monolog\Processors;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Shaffe\MailLogChannel\QueryCollector;

class ContextProcessor implements ProcessorInterface
{
    protected ?QueryCollector $queryCollector;

    public function __construct(?QueryCollector $queryCollector = null)
    {
        $this->queryCollector = $queryCollector;
    }

    /**
     * Process a log record and enrich it with execution context.
     *
     * @param  \Monolog\LogRecord|array  $record
     * @return \Monolog\LogRecord|array
     */
    public function __invoke(LogRecord|array $record): LogRecord|array
    {
        $extra = $record instanceof LogRecord ? $record->extra : ($record['extra'] ?? []);

        $extra['execution_context'] = $this->gatherExecutionContext();
        $extra['environment'] = $this->gatherEnvironment();
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
        if (!function_exists('app')) {
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
                    $context['queue'] = config('queue.connections.' . config('queue.default') . '.queue', 'default');
                } catch (\Throwable $e) {
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
        } catch (\Throwable $e) {
            // Request might not be available
        }

        return $context;
    }

    protected function resolveController($request): ?string
    {
        try {
            $route = $request->route();
            if (!$route) {
                return null;
            }

            $action = $route->getActionName();
            return $action !== 'Closure' ? $action : 'Closure';
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function resolveUser(): ?array
    {
        try {
            $user = auth()->user();
            if (!$user) {
                return null;
            }

            return [
                'id' => $user->getKey(),
                'email' => $user->email ?? null,
            ];
        } catch (\Throwable $e) {
            return null;
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
        } catch (\Throwable $e) {
            // Silently ignore
        }

        return $env;
    }

    protected function extractCodeSnippet($record): ?array
    {
        $context = $record instanceof LogRecord ? $record->context : ($record['context'] ?? []);

        $exception = $context['exception'] ?? null;

        if (!$exception instanceof \Throwable) {
            return null;
        }

        $file = $exception->getFile();
        $line = $exception->getLine();

        if (!$file || !is_readable($file)) {
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
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function gatherSqlQueries(): ?array
    {
        if (!$this->queryCollector) {
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

    protected function gatherAdditionalContext($record): ?array
    {
        $context = $record instanceof LogRecord ? $record->context : ($record['context'] ?? []);

        // Monolog record context (excluding the exception itself)
        $data = array_diff_key($context, ['exception' => true]);

        return !empty($data) ? $data : null;
    }
}
