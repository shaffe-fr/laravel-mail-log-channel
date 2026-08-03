<?php

namespace Shaffe\MailLogChannel\Tests;

use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Shaffe\MailLogChannel\Monolog\Formatters\HtmlFormatter;

class LaravelContextSectionTest extends TestCase
{
    protected function makeRecord(array $extra = []): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable('2024-01-15 10:30:00'),
            channel: 'mailable',
            level: Level::Error,
            message: 'Test error',
            context: [],
            extra: $extra,
        );
    }

    public function test_renders_application_context_section_with_custom_extra_keys(): void
    {
        $formatter = new HtmlFormatter('/app');

        $record = $this->makeRecord([
            'execution_context' => ['type' => 'http', 'method' => 'POST', 'url' => '/api/users'],
            'tenant_id' => 42,
            'correlation_id' => 'abc-123-def',
        ]);

        $html = $formatter->format($record);

        $this->assertStringContainsString('Application Context', $html);
        $this->assertStringContainsString('tenant_id', $html);
        $this->assertStringContainsString('42', $html);
        $this->assertStringContainsString('correlation_id', $html);
        $this->assertStringContainsString('abc-123-def', $html);
    }

    public function test_does_not_duplicate_known_structured_keys_in_application_context(): void
    {
        $formatter = new HtmlFormatter('/app');

        $record = $this->makeRecord([
            'execution_context' => ['type' => 'console', 'command' => 'artisan test'],
            'environment' => ['app_env' => 'production'],
            'additional_context' => ['order_id' => 123],
            'sql_queries' => ['items' => [], 'total' => 0],
            'code_snippet' => ['file' => '/app/test.php', 'line' => 1, 'code' => []],
            'request_payload' => ['data' => ['foo' => 'bar']],
            'throttle_occurrence_count' => 3,
            'throttle_first_seen_at' => 1700000000,
            'custom_key' => 'custom_value',
        ]);

        $html = $formatter->format($record);

        // The "Application Context" section should only contain custom_key
        $this->assertStringContainsString('Application Context', $html);
        $this->assertStringContainsString('custom_key', $html);
        $this->assertStringContainsString('custom_value', $html);

        // Verify the known keys are NOT rendered inside the Application Context section
        // by checking that execution_context, environment etc. don't appear as key labels in that section.
        // We isolate the section between "Application Context" and the next section or end.
        $sectionStart = strpos($html, 'Application Context');
        $sectionContent = substr($html, $sectionStart);

        $this->assertStringNotContainsString('execution_context', $sectionContent);
        $this->assertStringNotContainsString('additional_context', $sectionContent);
        $this->assertStringNotContainsString('sql_queries', $sectionContent);
        $this->assertStringNotContainsString('throttle_occurrence_count', $sectionContent);
    }

    public function test_no_application_context_section_when_no_extra_custom_keys(): void
    {
        $formatter = new HtmlFormatter('/app');

        $record = $this->makeRecord([
            'execution_context' => ['type' => 'http', 'method' => 'GET', 'url' => '/'],
            'additional_context' => ['user_id' => 42],
        ]);

        $html = $formatter->format($record);

        $this->assertStringNotContainsString('Application Context', $html);
    }

    public function test_renders_array_values_as_json(): void
    {
        $formatter = new HtmlFormatter('/app');

        $record = $this->makeRecord([
            'request_metadata' => ['ip' => '127.0.0.1', 'tags' => ['internal', 'retry']],
        ]);

        $html = $formatter->format($record);

        $this->assertStringContainsString('Application Context', $html);
        $this->assertStringContainsString('127.0.0.1', $html);
        $this->assertStringContainsString('request_metadata', $html);
    }

    public function test_truncates_very_long_values(): void
    {
        $formatter = new HtmlFormatter('/app');

        $longValue = str_repeat('A', 3000);
        $record = $this->makeRecord([
            'large_data' => $longValue,
        ]);

        $html = $formatter->format($record);

        $this->assertStringContainsString('Application Context', $html);
        $this->assertStringContainsString('large_data', $html);
        // Should be truncated to 2000 chars + ellipsis
        $this->assertStringNotContainsString($longValue, $html);
        $this->assertStringContainsString('…', $html);
    }

    public function test_escapes_html_in_context_values(): void
    {
        $formatter = new HtmlFormatter('/app');

        $record = $this->makeRecord([
            'user_input' => '<script>alert("xss")</script>',
        ]);

        $html = $formatter->format($record);

        $this->assertStringContainsString('Application Context', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
