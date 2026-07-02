<?php

namespace Shaffe\MailLogChannel\Monolog\Formatters;

use Monolog\Formatter\HtmlFormatter as BaseHtmlFormatter;
use Monolog\LogRecord;

class HtmlFormatter extends BaseHtmlFormatter
{
    /** @var string|null Base path to strip from file paths */
    protected $projectBasePath;

    /** @var bool Whether to collapse vendor frames in stack trace */
    protected bool $collapseVendorFrames;

    public function __construct(?string $basePath = null, ?string $dateFormat = null, bool $collapseVendorFrames = true)
    {
        parent::__construct($dateFormat);

        $this->projectBasePath = $basePath ?: $this->detectBasePath();
        $this->collapseVendorFrames = $collapseVendorFrames;
    }

    /**
     * Format a log record into HTML.
     */
    public function format(LogRecord|array $record): string
    {
        $output = $this->buildHeader($record);
        $output .= $this->buildThrottleNotice($record);
        $output .= $this->buildExecutionContextSection($record);
        $output .= $this->buildEnvironmentSection($record);
        $output .= $this->buildRequestPayloadSection($record);
        $output .= $this->buildExceptionSection($record);
        $output .= $this->buildAdditionalContextSection($record);
        $output .= $this->buildCodeSnippetSection($record);
        $output .= $this->buildStackTraceSection($record);
        $output .= $this->buildSqlQueriesSection($record);
        $output .= '</table>';

        return $output;
    }

    protected function buildHeader($record): string
    {
        $level = $record instanceof LogRecord ? $record->level->getName() : ($record['level_name'] ?? 'ERROR');
        $message = $record instanceof LogRecord ? $record->message : ($record['message'] ?? '');
        $datetime = $record instanceof LogRecord ? $record->datetime : ($record['datetime'] ?? new \DateTimeImmutable());
        $color = $this->getLevelColorByName($level);

        return '<table cellspacing="0" cellpadding="0" width="100%" style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; font-size: 14px; border-collapse: collapse;">'
            .'<tr><td style="background: '.$color.'; color: #fff; padding: 10px 15px; font-size: 16px; font-weight: bold; border-radius: 4px 4px 0 0;">'
            .htmlspecialchars($level).'</td></tr>'
            .'<tr><td style="padding: 15px; font-size: 18px; font-weight: bold; color: #1a1a1a; border-bottom: 1px solid #e5e5e5;">'
            .htmlspecialchars($message).'</td></tr>'
            .'<tr><td style="padding: 8px 15px; color: #888; font-size: 12px; border-bottom: 1px solid #e5e5e5;">'
            .$datetime->format('d M Y H:i:s T').'</td></tr>';
    }

    protected function buildThrottleNotice($record): string
    {
        $extra = $record instanceof LogRecord ? $record->extra : ($record['extra'] ?? []);
        $count = $extra['throttle_occurrence_count'] ?? 0;

        if ($count <= 1) {
            return '';
        }

        $firstSeenAt = $extra['throttle_first_seen_at'] ?? null;

        if ($firstSeenAt !== null) {
            $date = (new \DateTimeImmutable('@'.$firstSeenAt))->format('d M Y H:i:s T');
            $label = 'This error has occurred '.$count.' times since '.$date.'.';
        } else {
            $label = 'This error has occurred '.$count.' times.';
        }

        return '<tr><td style="padding: 8px 15px; background: #fef3cd; color: #856404; font-size: 13px; border-bottom: 1px solid #e5e5e5;">'
            .'⚠️ '.htmlspecialchars($label)
            .'</td></tr>';
    }

    protected function buildExecutionContextSection($record): string
    {
        $extra = $record instanceof LogRecord ? $record->extra : ($record['extra'] ?? []);
        $ctx = $extra['execution_context'] ?? null;

        if (! $ctx || ! isset($ctx['type'])) {
            return '';
        }

        $output = $this->sectionTitle('Execution');

        if ($ctx['type'] === 'http') {
            $method = $ctx['method'] ?? 'GET';
            $url = $ctx['url'] ?? '';
            $output .= $this->keyValueRow('Request', '<strong>'.htmlspecialchars($method).'</strong> '.htmlspecialchars($url));

            if (! empty($ctx['route_name'])) {
                $output .= $this->keyValueRow('Route', htmlspecialchars($ctx['route_name']));
            }
            if (! empty($ctx['controller'])) {
                $output .= $this->keyValueRow('Controller', '<code>'.htmlspecialchars($ctx['controller']).'</code>');
            }
            if (! empty($ctx['ip'])) {
                $output .= $this->keyValueRow('IP', '<a href="https://whatismyipaddress.com/ip/'.htmlspecialchars($ctx['ip']).'" rel="noopener noreferrer" style="color: inherit; text-decoration: underline; text-decoration-color: #ccc;">'.htmlspecialchars($ctx['ip']).'</a>');
            }
            if (! empty($ctx['user'])) {
                $userStr = '#'.$ctx['user']['id'];
                if (! empty($ctx['user']['email'])) {
                    $userStr .= ' ('.htmlspecialchars($ctx['user']['email']).')';
                }
                $output .= $this->keyValueRow('User', $userStr);
            }
        } elseif ($ctx['type'] === 'console') {
            $output .= $this->keyValueRow('Command', '<code>'.htmlspecialchars($ctx['command'] ?? 'unknown').'</code>');
        } elseif ($ctx['type'] === 'queue') {
            $output .= $this->keyValueRow('Type', 'Queue Worker');
            if (! empty($ctx['connection'])) {
                $output .= $this->keyValueRow('Connection', htmlspecialchars($ctx['connection']));
            }
            if (! empty($ctx['queue'])) {
                $output .= $this->keyValueRow('Queue', htmlspecialchars($ctx['queue']));
            }
            if (! empty($ctx['command'])) {
                $output .= $this->keyValueRow('Command', '<code>'.htmlspecialchars($ctx['command']).'</code>');
            }
        }

        return $output;
    }

    protected function buildEnvironmentSection($record): string
    {
        $extra = $record instanceof LogRecord ? $record->extra : ($record['extra'] ?? []);
        $env = $extra['environment'] ?? null;

        if (! $env) {
            return '';
        }

        $badges = [];
        if (! empty($env['app_env'])) {
            $envColor = $this->getEnvColor($env['app_env']);
            $badges[] = '<span style="display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: bold; color: #fff; background: '.$envColor.';">'.htmlspecialchars(strtoupper($env['app_env'])).'</span>';
        }
        if (! empty($env['laravel_version'])) {
            $badges[] = '<span style="display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; background: #eee; color: #555;">Laravel '.htmlspecialchars($env['laravel_version']).'</span>';
        }
        if (! empty($env['php_version'])) {
            $badges[] = '<span style="display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; background: #eee; color: #555;">PHP '.htmlspecialchars($env['php_version']).'</span>';
        }
        if (! empty($env['server'])) {
            $badges[] = '<span style="display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; background: #eee; color: #555;">'.htmlspecialchars($env['server']).'</span>';
        }
        if (! empty($env['memory_peak'])) {
            $badges[] = '<span style="display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; background: #eee; color: #555;">'.$this->formatBytes($env['memory_peak']).'</span>';
        }
        if (isset($env['execution_time']) && $env['execution_time'] !== null) { /** @phpstan-ignore notIdentical.alwaysTrue */
            $badges[] = '<span style="display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; background: #eee; color: #555;">'.$this->formatDuration($env['execution_time']).'</span>';
        }

        if (empty($badges)) {
            return '';
        }

        return '<tr><td style="padding: 10px 15px; border-bottom: 1px solid #e5e5e5;">'.implode(' ', $badges).'</td></tr>';
    }

    protected function buildRequestPayloadSection($record): string
    {
        $extra = $record instanceof LogRecord ? $record->extra : ($record['extra'] ?? []);
        $payload = $extra['request_payload'] ?? null;

        if (! $payload) {
            return '';
        }

        $output = '';

        if (! empty($payload['data'])) {
            $output .= $this->sectionTitle('Request Payload');

            foreach ($payload['data'] as $key => $value) {
                $display = is_scalar($value) || $value === null
                    ? (string) ($value ?? 'null')
                    : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                $output .= $this->keyValueRow((string) $key, '<code>'.htmlspecialchars($display).'</code>');
            }

            if (! empty($payload['truncated_keys'])) {
                $output .= '<tr><td style="padding: 4px 15px; font-size: 12px; color: #999; font-style: italic;">'
                    .'+ '.(int) $payload['truncated_keys'].' more field'.($payload['truncated_keys'] > 1 ? 's' : '').' omitted'
                    .'</td></tr>';
            }
        }

        if (! empty($payload['files'])) {
            $output .= $this->sectionTitle('Uploaded Files');

            foreach ($payload['files'] as $file) {
                $name = $file['name'] ?? 'unknown';
                $meta = [];
                if (! empty($file['type'])) {
                    $meta[] = htmlspecialchars($file['type']);
                }
                if (isset($file['size'])) {
                    $meta[] = $this->formatBytes((int) $file['size']);
                }

                $metaStr = $meta ? ' <span style="color: #999;">('.implode(', ', $meta).')</span>' : '';
                $output .= $this->keyValueRow('File', '<code>'.htmlspecialchars($name).'</code>'.$metaStr);
            }
        }

        return $output;
    }

    protected function buildExceptionSection($record): string
    {
        $context = $record instanceof LogRecord ? $record->context : ($record['context'] ?? []);
        $exception = $context['exception'] ?? null;

        if (! $exception instanceof \Throwable) {
            return '';
        }

        $output = $this->sectionTitle('Exception');
        $output .= $this->keyValueRow('Class', '<code>'.htmlspecialchars(get_class($exception)).'</code>');
        $output .= $this->keyValueRow('Message', htmlspecialchars($exception->getMessage()));
        $output .= $this->keyValueRow('File', $this->fileLink(
            $exception->getFile(),
            $exception->getLine(),
            '<code>'.htmlspecialchars($this->shortenPath($exception->getFile())).':'.$exception->getLine().'</code>'
        ));

        if ($exception->getCode()) {
            $output .= $this->keyValueRow('Code', htmlspecialchars((string) $exception->getCode()));
        }

        // Show previous exception chain
        $previous = $exception->getPrevious();
        if ($previous) {
            $output .= $this->keyValueRow(
                'Caused by',
                '<code>'.htmlspecialchars(get_class($previous)).'</code>: '.htmlspecialchars($previous->getMessage())
                .' in '.$this->fileLink(
                    $previous->getFile(),
                    $previous->getLine(),
                    '<code>'.htmlspecialchars($this->shortenPath($previous->getFile())).':'.$previous->getLine().'</code>'
                )
            );
        }

        return $output;
    }

    protected function buildCodeSnippetSection($record): string
    {
        $extra = $record instanceof LogRecord ? $record->extra : ($record['extra'] ?? []);
        $snippet = $extra['code_snippet'] ?? null;

        if (! $snippet || empty($snippet['code'])) {
            return '';
        }

        $errorLine = $snippet['line'];
        $code = '';

        foreach ($snippet['code'] as $lineNum => $lineContent) {
            $isError = ($lineNum === $errorLine);
            $bgColor = $isError ? '#fff5f5' : 'transparent';
            $borderLeft = $isError ? '3px solid #e53e3e' : '3px solid transparent';
            $lineNumColor = $isError ? '#e53e3e' : '#999';

            $code .= '<tr>'
                .'<td style="padding: 0 8px; text-align: right; color: '.$lineNumColor.'; font-size: 12px; user-select: none; white-space: nowrap; background: '.$bgColor.'; border-left: '.$borderLeft.'; min-width: 32px;">'.$lineNum.'</td>'
                .'<td style="padding: 0 8px; background: '.$bgColor.';"><pre style="margin: 0; font-family: \'SF Mono\', Monaco, Consolas, monospace; font-size: 12px; line-height: 1.6; white-space: pre-wrap; word-break: break-all;">'.htmlspecialchars($lineContent).'</pre></td>'
                .'</tr>';
        }

        $filePath = $this->shortenPath($snippet['file']);

        return $this->sectionTitle('Code')
            .'<tr><td style="padding: 5px 15px;">'.$this->fileLink(
                $snippet['file'],
                $snippet['line'],
                '<code style="font-size: 12px; color: #666;">'.htmlspecialchars($filePath).'</code>'
            ).'</td></tr>'
            .'<tr><td style="padding: 0 15px 10px;"><table cellspacing="0" cellpadding="0" width="100%" style="border: 1px solid #e5e5e5; border-radius: 4px; overflow: hidden; border-collapse: collapse;">'
            .$code
            .'</table></td></tr>';
    }

    protected function buildAdditionalContextSection($record): string
    {
        $extra = $record instanceof LogRecord ? $record->extra : ($record['extra'] ?? []);
        $data = $extra['additional_context'] ?? null;

        if (! $data) {
            return '';
        }

        $output = $this->sectionTitle('Context');

        foreach ($data as $key => $value) {
            $display = is_scalar($value) ? (string) $value : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $output .= $this->keyValueRow((string) $key, '<code>'.htmlspecialchars($display).'</code>');
        }

        return $output;
    }

    protected function buildStackTraceSection($record): string
    {
        $context = $record instanceof LogRecord ? $record->context : ($record['context'] ?? []);
        $exception = $context['exception'] ?? null;

        if (! $exception instanceof \Throwable) {
            return '';
        }

        $trace = $exception->getTrace();
        if (empty($trace)) {
            return '';
        }

        $output = $this->sectionTitle('Stack Trace');
        $output .= '<tr><td style="padding: 5px 15px 10px;"><table cellspacing="0" cellpadding="0" width="100%" style="border: 1px solid #e5e5e5; border-radius: 4px; border-collapse: collapse;">';

        $vendorCount = 0;

        foreach ($trace as $i => $frame) {
            $file = $frame['file'] ?? null;
            $line = $frame['line'] ?? null;
            $isVendor = $file && $this->isVendorFrame($file);

            if ($this->collapseVendorFrames && $isVendor) {
                $vendorCount++;

                continue;
            }

            // Flush collapsed vendor frames before this app frame
            if ($vendorCount > 0) {
                $output .= $this->vendorFramesRow($vendorCount);
                $vendorCount = 0;
            }

            $location = $file ? $this->shortenPath($file).':'.$line : '(unknown)';
            $call = $this->formatFrameCall($frame);

            $color = $isVendor ? '#bbb' : '#1a1a1a';
            $locationHtml = '<code style="color: '.$color.'; font-weight: 500;">'.htmlspecialchars($location).'</code>';
            if ($file && $line) {
                $locationHtml = $this->fileLink($file, $line, $locationHtml);
            }

            $output .= '<tr>'
                .'<td style="padding: 6px 10px; border-bottom: 1px solid #f0f0f0; font-size: 13px;">'
                .$locationHtml
                .($call ? '<br><span style="color: #888; font-size: 12px;">'.htmlspecialchars($call).'</span>' : '')
                .'</td></tr>';
        }

        // Flush remaining vendor frames
        if ($vendorCount > 0) {
            $output .= $this->vendorFramesRow($vendorCount);
        }

        $output .= '</table></td></tr>';

        return $output;
    }

    protected function buildSqlQueriesSection($record): string
    {
        $extra = $record instanceof LogRecord ? $record->extra : ($record['extra'] ?? []);
        $queries = $extra['sql_queries'] ?? null;

        if (! $queries || empty($queries['items'])) {
            return '';
        }

        $items = $queries['items'];
        $total = $queries['total'];
        $shown = count($items);

        $title = 'Queries ('.($total - $shown + 1).'-'.$total.' of '.$total.')';

        $output = $this->sectionTitle($title);
        $output .= '<tr><td style="padding: 5px 15px 10px;"><table cellspacing="0" cellpadding="0" width="100%" style="border: 1px solid #e5e5e5; border-radius: 4px; border-collapse: collapse;">';

        foreach ($items as $query) {
            $time = isset($query['time']) ? number_format($query['time'], 2).'ms' : '';
            $sql = $query['sql'] ?? '';

            $output .= '<tr>'
                .'<td style="padding: 6px 10px; border-bottom: 1px solid #f0f0f0; font-size: 12px;">'
                .'<pre style="margin: 0; font-family: \'SF Mono\', Monaco, Consolas, monospace; font-size: 12px; white-space: pre-wrap; word-break: break-all; color: #333;">'.htmlspecialchars($sql).'</pre>';

            if (! empty($query['bindings'])) {
                $bindings = array_map(fn ($b) => is_string($b) ? '"'.mb_strimwidth($b, 0, 50, '…').'"' : var_export($b, true), $query['bindings']);
                $output .= '<div style="margin-top: 2px; font-size: 11px; color: #999;">['.htmlspecialchars(implode(', ', $bindings)).']</div>';
            }

            $output .= '</td>';

            if ($time) {
                $output .= '<td style="padding: 6px 10px; border-bottom: 1px solid #f0f0f0; font-size: 12px; color: #888; text-align: right; white-space: nowrap;">'.$time.'</td>';
            }

            $output .= '</tr>';
        }

        $output .= '</table></td></tr>';

        return $output;
    }

    // ── Helpers ──────────────────────────────────────────────

    protected function sectionTitle(string $title): string
    {
        return '<tr><td style="padding: 12px 15px 4px; font-size: 13px; font-weight: bold; color: #555; text-transform: uppercase; letter-spacing: 0.5px;">'
            .htmlspecialchars($title).'</td></tr>';
    }

    protected function keyValueRow(string $key, string $valueHtml): string
    {
        return '<tr><td style="padding: 4px 15px; font-size: 13px;">'
            .'<span style="color: #888;">'.htmlspecialchars($key).':</span> '
            .$valueHtml
            .'</td></tr>';
    }

    protected function vendorFramesRow(int $count): string
    {
        return '<tr><td style="padding: 4px 10px; border-bottom: 1px solid #f0f0f0; font-size: 12px; color: #bbb; font-style: italic;">'
            .$count.' vendor frame'.($count > 1 ? 's' : '').'…'
            .'</td></tr>';
    }

    protected function formatFrameCall(array $frame): string
    {
        $parts = [];
        if (isset($frame['class'])) {
            $parts[] = $frame['class'];
            $parts[] = $frame['type'] ?? '::';
        }
        if (isset($frame['function'])) {
            $parts[] = $frame['function'].'()';
        }

        return implode('', $parts);
    }

    protected function isVendorFrame(string $file): bool
    {
        $normalized = str_replace('\\', '/', $file);

        return str_contains($normalized, '/vendor/');
    }

    protected function shortenPath(string $path): string
    {
        if (! $this->projectBasePath) {
            return $path;
        }

        $normalizedPath = str_replace('\\', '/', $path);
        $normalizedBase = rtrim(str_replace('\\', '/', $this->projectBasePath), '/').'/';

        if (str_starts_with($normalizedPath, $normalizedBase)) {
            return substr($normalizedPath, strlen($normalizedBase));
        }

        return $path;
    }

    protected function detectBasePath(): ?string
    {
        if (function_exists('base_path')) {
            return base_path();
        }

        return null;
    }

    protected function getLevelColorByName(string $level): string
    {
        return match (strtoupper($level)) {
            'EMERGENCY', 'ALERT', 'CRITICAL' => '#dc2626',
            'ERROR' => '#ea580c',
            'WARNING' => '#d97706',
            'NOTICE' => '#2563eb',
            'INFO' => '#059669',
            'DEBUG' => '#6b7280',
            default => '#6b7280',
        };
    }

    protected function getEnvColor(string $env): string
    {
        return match (strtolower($env)) {
            'production', 'prod' => '#dc2626',
            'staging', 'stage' => '#d97706',
            'testing', 'test' => '#2563eb',
            default => '#059669',
        };
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }

        return round($bytes / 1024).' KB';
    }

    protected function formatDuration(float $ms): string
    {
        if ($ms >= 1000) {
            return round($ms / 1000, 2).'s';
        }

        return round($ms, 1).'ms';
    }

    /**
     * Known editor URL schemes.
     */
    protected array $editorHrefs = [
        'phpstorm' => 'phpstorm://open?file={file}&line={line}',
        'vscode' => 'vscode://file/{file}:{line}',
        'vscode-insiders' => 'vscode-insiders://file/{file}:{line}',
        'sublime' => 'subl://open?url=file://{file}&line={line}',
        'textmate' => 'txmt://open?url=file://{file}&line={line}',
        'atom' => 'atom://core/open/file?filename={file}&line={line}',
        'nova' => 'nova://core/open/file?filename={file}&line={line}',
        'idea' => 'idea://open?file={file}&line={line}',
        'cursor' => 'cursor://file/{file}:{line}',
    ];

    protected function resolveEditorHref(string $file, ?int $line = null): ?string
    {
        try {
            $editor = config('app.editor');
        } catch (\Throwable $e) {
            return null;
        }

        if (! $editor) {
            return null;
        }

        $href = is_array($editor) && isset($editor['href'])
            ? $editor['href']
            : ($this->editorHrefs[$editor['name'] ?? $editor] ?? sprintf('%s://open?file={file}&line={line}', $editor['name'] ?? $editor));

        $editorBasePath = is_array($editor) ? ($editor['base_path'] ?? false) : false;

        if ($editorBasePath !== false && $this->projectBasePath) {
            $file = str_replace(
                str_replace('\\', '/', $this->projectBasePath),
                str_replace('\\', '/', $editorBasePath),
                str_replace('\\', '/', $file)
            );
        }

        return str_replace(
            ['{file}', '{line}'],
            [$file, (string) ($line ?? 1)],
            $href,
        );
    }

    protected function fileLink(string $file, ?int $line, string $displayHtml): string
    {
        $href = $this->resolveEditorHref($file, $line);

        if (! $href) {
            return $displayHtml;
        }

        return '<a href="'.htmlspecialchars($href).'" rel="noopener noreferrer" style="color: inherit; text-decoration: underline; text-decoration-color: #ccc;">'.$displayHtml.'</a>';
    }
}
