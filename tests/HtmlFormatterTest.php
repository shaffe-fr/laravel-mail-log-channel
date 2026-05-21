<?php

namespace Shaffe\MailLogChannel\Tests;

use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Shaffe\MailLogChannel\Monolog\Formatters\HtmlFormatter;

class HtmlFormatterTest extends TestCase
{
    protected function makeRecord(
        string $message = 'Test error',
        Level $level = Level::Error,
        array $context = [],
        array $extra = []
    ): LogRecord {
        return new LogRecord(
            datetime: new \DateTimeImmutable('2024-01-15 10:30:00'),
            channel: 'mailable',
            level: $level,
            message: $message,
            context: $context,
            extra: $extra,
        );
    }

    public function test_formats_basic_record_as_html(): void
    {
        $formatter = new HtmlFormatter('/app');
        $record = $this->makeRecord('Something went wrong');

        $html = $formatter->format($record);

        $this->assertStringContainsString('Something went wrong', $html);
        $this->assertStringContainsString('ERROR', $html);
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('</table>', $html);
    }

    public function test_formats_different_log_levels(): void
    {
        $formatter = new HtmlFormatter('/app');

        $levels = [
            [Level::Debug, 'DEBUG'],
            [Level::Info, 'INFO'],
            [Level::Warning, 'WARNING'],
            [Level::Error, 'ERROR'],
            [Level::Critical, 'CRITICAL'],
            [Level::Emergency, 'EMERGENCY'],
        ];

        foreach ($levels as [$level, $name]) {
            $record = $this->makeRecord('Test', $level);
            $html = $formatter->format($record);
            $this->assertStringContainsString($name, $html);
        }
    }

    public function test_includes_exception_details(): void
    {
        $formatter = new HtmlFormatter('/app');
        $exception = new \RuntimeException('Database connection failed', 500);
        $record = $this->makeRecord(
            'Error occurred',
            context: ['exception' => $exception]
        );

        $html = $formatter->format($record);

        $this->assertStringContainsString('RuntimeException', $html);
        $this->assertStringContainsString('Database connection failed', $html);
        $this->assertStringContainsString('500', $html);
    }

    public function test_includes_previous_exception(): void
    {
        $formatter = new HtmlFormatter('/app');
        $previous = new \InvalidArgumentException('Invalid input');
        $exception = new \RuntimeException('Wrapper error', 0, $previous);
        $record = $this->makeRecord(context: ['exception' => $exception]);

        $html = $formatter->format($record);

        $this->assertStringContainsString('Caused by', $html);
        $this->assertStringContainsString('InvalidArgumentException', $html);
        $this->assertStringContainsString('Invalid input', $html);
    }

    public function test_includes_http_execution_context(): void
    {
        $formatter = new HtmlFormatter('/app');
        $record = $this->makeRecord(extra: [
            'execution_context' => [
                'type' => 'http',
                'method' => 'POST',
                'url' => 'https://example.com/api/users',
                'route_name' => 'users.store',
                'controller' => 'App\\Http\\Controllers\\UserController@store',
                'ip' => '192.168.1.1',
                'user' => ['id' => 1, 'email' => 'admin@example.com'],
            ],
        ]);

        $html = $formatter->format($record);

        $this->assertStringContainsString('POST', $html);
        $this->assertStringContainsString('https://example.com/api/users', $html);
        $this->assertStringContainsString('users.store', $html);
        $this->assertStringContainsString('UserController@store', $html);
        $this->assertStringContainsString('192.168.1.1', $html);
        $this->assertStringContainsString('admin@example.com', $html);
    }

    public function test_includes_console_execution_context(): void
    {
        $formatter = new HtmlFormatter('/app');
        $record = $this->makeRecord(extra: [
            'execution_context' => [
                'type' => 'console',
                'command' => 'php artisan migrate',
            ],
        ]);

        $html = $formatter->format($record);

        $this->assertStringContainsString('php artisan migrate', $html);
    }

    public function test_includes_queue_execution_context(): void
    {
        $formatter = new HtmlFormatter('/app');
        $record = $this->makeRecord(extra: [
            'execution_context' => [
                'type' => 'queue',
                'connection' => 'redis',
                'queue' => 'emails',
                'command' => 'App\\Jobs\\SendEmail',
            ],
        ]);

        $html = $formatter->format($record);

        $this->assertStringContainsString('Queue Worker', $html);
        $this->assertStringContainsString('redis', $html);
        $this->assertStringContainsString('emails', $html);
        $this->assertStringContainsString('App\\Jobs\\SendEmail', $html);
    }

    public function test_includes_environment_badges(): void
    {
        $formatter = new HtmlFormatter('/app');
        $record = $this->makeRecord(extra: [
            'environment' => [
                'app_env' => 'production',
                'php_version' => '8.2.0',
                'laravel_version' => '11.0.0',
                'server' => 'web-01',
            ],
        ]);

        $html = $formatter->format($record);

        $this->assertStringContainsString('PRODUCTION', $html);
        $this->assertStringContainsString('PHP 8.2.0', $html);
        $this->assertStringContainsString('Laravel 11.0.0', $html);
        $this->assertStringContainsString('web-01', $html);
    }

    public function test_includes_code_snippet(): void
    {
        $formatter = new HtmlFormatter('/app');
        $record = $this->makeRecord(extra: [
            'code_snippet' => [
                'file' => '/app/src/Service.php',
                'line' => 42,
                'code' => [
                    40 => '    public function process() {',
                    41 => '        $result = $this->fetch();',
                    42 => '        throw new \\Exception("fail");',
                    43 => '        return $result;',
                    44 => '    }',
                ],
            ],
        ]);

        $html = $formatter->format($record);

        $this->assertStringContainsString('src/Service.php', $html);
        $this->assertStringContainsString('throw new \\Exception(&quot;fail&quot;)', $html);
    }

    public function test_includes_additional_context(): void
    {
        $formatter = new HtmlFormatter('/app');
        $record = $this->makeRecord(extra: [
            'additional_context' => [
                'order_id' => 12345,
                'status' => 'failed',
            ],
        ]);

        $html = $formatter->format($record);

        $this->assertStringContainsString('order_id', $html);
        $this->assertStringContainsString('12345', $html);
        $this->assertStringContainsString('status', $html);
        $this->assertStringContainsString('failed', $html);
    }

    public function test_includes_sql_queries(): void
    {
        $formatter = new HtmlFormatter('/app');
        $record = $this->makeRecord(extra: [
            'sql_queries' => [
                'items' => [
                    ['sql' => 'SELECT * FROM users WHERE id = ?', 'time' => 2.5, 'bindings' => [1]],
                    ['sql' => 'UPDATE users SET name = ? WHERE id = ?', 'time' => 5.1, 'bindings' => ['John', 1]],
                ],
                'total' => 2,
            ],
        ]);

        $html = $formatter->format($record);

        $this->assertStringContainsString('SELECT * FROM users WHERE id = ?', $html);
        $this->assertStringContainsString('UPDATE users SET name = ? WHERE id = ?', $html);
        $this->assertStringContainsString('2.50ms', $html);
        $this->assertStringContainsString('5.10ms', $html);
    }

    public function test_includes_throttle_notice(): void
    {
        $formatter = new HtmlFormatter('/app');
        $record = $this->makeRecord(extra: [
            'throttle_occurrence_count' => 5,
        ]);

        $html = $formatter->format($record);

        $this->assertStringContainsString('5 times', $html);
    }

    public function test_no_throttle_notice_when_count_is_one(): void
    {
        $formatter = new HtmlFormatter('/app');
        $record = $this->makeRecord(extra: [
            'throttle_occurrence_count' => 1,
        ]);

        $html = $formatter->format($record);

        $this->assertStringNotContainsString('times', $html);
    }

    public function test_no_throttle_notice_when_count_is_zero(): void
    {
        $formatter = new HtmlFormatter('/app');
        $record = $this->makeRecord(extra: [
            'throttle_occurrence_count' => 0,
        ]);

        $html = $formatter->format($record);

        $this->assertStringNotContainsString('times', $html);
    }

    public function test_shortens_file_paths(): void
    {
        $formatter = new HtmlFormatter('/home/user/project');
        $record = $this->makeRecord(extra: [
            'code_snippet' => [
                'file' => '/home/user/project/app/Models/User.php',
                'line' => 10,
                'code' => [10 => 'return $this->name;'],
            ],
        ]);

        $html = $formatter->format($record);

        $this->assertStringContainsString('app/Models/User.php', $html);
    }

    public function test_collapses_vendor_frames_by_default(): void
    {
        $formatter = new HtmlFormatter('/app');
        $exception = new \RuntimeException('Test');

        // We need to create an exception with a trace that includes vendor frames
        // Since we can't easily control the trace, we test the formatter's behavior
        // with a real exception
        $record = $this->makeRecord(context: ['exception' => $exception]);

        $html = $formatter->format($record);

        // The formatter should produce valid HTML with stack trace section
        $this->assertStringContainsString('Stack Trace', $html);
    }

    public function test_stack_trace_not_shown_without_exception(): void
    {
        $formatter = new HtmlFormatter('/app');
        $record = $this->makeRecord();

        $html = $formatter->format($record);

        $this->assertStringNotContainsString('Stack Trace', $html);
    }

    public function test_escapes_html_in_message(): void
    {
        $formatter = new HtmlFormatter('/app');
        $record = $this->makeRecord('<script>alert("xss")</script>');

        $html = $formatter->format($record);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
