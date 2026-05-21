<?php

namespace Shaffe\MailLogChannel\Tests;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Shaffe\MailLogChannel\Monolog\Formatters\HtmlFormatter;
use Shaffe\MailLogChannel\Monolog\Processors\ContextProcessor;

class EnvironmentInfoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = \Mockery::mock(Container::class)->makePartial();
        $app->shouldReceive('runningInConsole')->andReturn(true);
        $app->shouldReceive('version')->andReturn('11.0.0');

        $config = new ConfigRepository([
            'app' => ['env' => 'testing', 'editor' => null],
            'queue' => [
                'default' => 'sync',
                'connections' => ['sync' => ['queue' => 'default']],
            ],
        ]);

        $app->instance('config', $config);
        $app->instance('app', $app);
        $app->singleton(\Illuminate\Contracts\Config\Repository::class, fn () => $config);

        Container::setInstance($app);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        \Mockery::close();
        parent::tearDown();
    }

    protected function makeRecord(
        string $message = 'Test message',
        Level $level = Level::Error,
        array $context = [],
        array $extra = []
    ): LogRecord {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'mailable',
            level: $level,
            message: $message,
            context: $context,
            extra: $extra,
        );
    }

    // ── ContextProcessor: memory_peak ────────────────────────

    public function test_environment_includes_memory_peak(): void
    {
        $processor = new ContextProcessor();
        $record = $this->makeRecord();

        $result = $processor($record);

        $this->assertArrayHasKey('memory_peak', $result->extra['environment']);
        $this->assertIsInt($result->extra['environment']['memory_peak']);
        $this->assertGreaterThan(0, $result->extra['environment']['memory_peak']);
    }

    // ── ContextProcessor: execution_time ─────────────────────

    public function test_environment_includes_execution_time_when_laravel_start_defined(): void
    {
        if (! defined('LARAVEL_START')) {
            define('LARAVEL_START', microtime(true) - 0.5); // simulate 500ms ago
        }

        $processor = new ContextProcessor();
        $record = $this->makeRecord();

        $result = $processor($record);

        $this->assertArrayHasKey('execution_time', $result->extra['environment']);
        $this->assertIsFloat($result->extra['environment']['execution_time']);
        $this->assertGreaterThan(0, $result->extra['environment']['execution_time']);
    }

    // ── HtmlFormatter: memory badge ──────────────────────────

    public function test_formatter_displays_memory_peak_badge(): void
    {
        $formatter = new HtmlFormatter('/tmp/test');

        $record = $this->makeRecord(extra: [
            'environment' => [
                'app_env' => 'production',
                'memory_peak' => 52428800, // 50 MB
            ],
        ]);

        $html = $formatter->format($record);

        $this->assertStringContainsString('50', $html);
        $this->assertStringContainsString('MB', $html);
    }

    public function test_formatter_displays_memory_in_kb_when_small(): void
    {
        $formatter = new HtmlFormatter('/tmp/test');

        $record = $this->makeRecord(extra: [
            'environment' => [
                'app_env' => 'testing',
                'memory_peak' => 524288, // 512 KB
            ],
        ]);

        $html = $formatter->format($record);

        $this->assertStringContainsString('512', $html);
        $this->assertStringContainsString('KB', $html);
    }

    // ── HtmlFormatter: execution time badge ──────────────────

    public function test_formatter_displays_execution_time_in_ms(): void
    {
        $formatter = new HtmlFormatter('/tmp/test');

        $record = $this->makeRecord(extra: [
            'environment' => [
                'app_env' => 'testing',
                'execution_time' => 245.3, // 245.3ms
            ],
        ]);

        $html = $formatter->format($record);

        $this->assertStringContainsString('245.3ms', $html);
    }

    public function test_formatter_displays_execution_time_in_seconds_when_large(): void
    {
        $formatter = new HtmlFormatter('/tmp/test');

        $record = $this->makeRecord(extra: [
            'environment' => [
                'app_env' => 'testing',
                'execution_time' => 3456.7, // 3.46s
            ],
        ]);

        $html = $formatter->format($record);

        $this->assertStringContainsString('3.46s', $html);
    }

    public function test_formatter_does_not_display_execution_time_when_null(): void
    {
        $formatter = new HtmlFormatter('/tmp/test');

        $record = $this->makeRecord(extra: [
            'environment' => [
                'app_env' => 'testing',
                'execution_time' => null,
            ],
        ]);

        $html = $formatter->format($record);

        $this->assertStringNotContainsString('ms', $html);
        // Should not contain a time-related badge (but may contain other content)
    }
}
