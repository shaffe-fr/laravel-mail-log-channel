<?php

namespace Shaffe\MailLogChannel\Tests;

use Illuminate\Container\Container;
use Illuminate\Config\Repository as ConfigRepository;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Shaffe\MailLogChannel\Monolog\Processors\ContextProcessor;
use Shaffe\MailLogChannel\QueryCollector;

class ContextProcessorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = \Mockery::mock(Container::class)->makePartial();
        $app->shouldReceive('runningInConsole')->andReturn(true);

        $config = new ConfigRepository([
            'app' => ['env' => 'testing'],
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
        array $context = []
    ): LogRecord {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'mailable',
            level: $level,
            message: $message,
            context: $context,
        );
    }

    public function test_adds_environment_info(): void
    {
        $processor = new ContextProcessor();
        $record = $this->makeRecord();

        $result = $processor($record);

        $this->assertArrayHasKey('environment', $result->extra);
        $this->assertEquals('testing', $result->extra['environment']['app_env']);
        $this->assertEquals(PHP_VERSION, $result->extra['environment']['php_version']);
    }

    public function test_adds_execution_context(): void
    {
        $processor = new ContextProcessor();
        $record = $this->makeRecord();

        $result = $processor($record);

        $this->assertArrayHasKey('execution_context', $result->extra);
        $this->assertArrayHasKey('type', $result->extra['execution_context']);
    }

    public function test_console_context_includes_command(): void
    {
        // Simulate artisan command
        $_SERVER['argv'] = ['artisan', 'migrate:fresh'];

        $processor = new ContextProcessor();
        $record = $this->makeRecord();

        $result = $processor($record);

        $this->assertEquals('console', $result->extra['execution_context']['type']);
        $this->assertStringContainsString('migrate:fresh', $result->extra['execution_context']['command']);

        unset($_SERVER['argv']);
    }

    public function test_extracts_code_snippet_from_exception(): void
    {
        $processor = new ContextProcessor();
        $exception = new \RuntimeException('Test exception');
        $record = $this->makeRecord(context: ['exception' => $exception]);

        $result = $processor($record);

        $this->assertArrayHasKey('code_snippet', $result->extra);
        $this->assertNotNull($result->extra['code_snippet']);
        $this->assertArrayHasKey('file', $result->extra['code_snippet']);
        $this->assertArrayHasKey('line', $result->extra['code_snippet']);
        $this->assertArrayHasKey('code', $result->extra['code_snippet']);
    }

    public function test_code_snippet_is_null_without_exception(): void
    {
        $processor = new ContextProcessor();
        $record = $this->makeRecord();

        $result = $processor($record);

        $this->assertNull($result->extra['code_snippet']);
    }

    public function test_includes_sql_queries_when_collector_provided(): void
    {
        $collector = new QueryCollector();

        $event = new \Illuminate\Database\Events\QueryExecuted(
            'SELECT * FROM users WHERE id = ?',
            [1],
            12.5,
            $this->createMock(\Illuminate\Database\Connection::class)
        );
        $collector->record($event);

        $processor = new ContextProcessor($collector);
        $record = $this->makeRecord();

        $result = $processor($record);

        $this->assertArrayHasKey('sql_queries', $result->extra);
        $this->assertNotNull($result->extra['sql_queries']);
        $this->assertCount(1, $result->extra['sql_queries']['items']);
        $this->assertEquals(1, $result->extra['sql_queries']['total']);
        $this->assertEquals('SELECT * FROM users WHERE id = ?', $result->extra['sql_queries']['items'][0]['sql']);
    }

    public function test_sql_queries_null_without_collector(): void
    {
        $processor = new ContextProcessor(null);
        $record = $this->makeRecord();

        $result = $processor($record);

        $this->assertNull($result->extra['sql_queries']);
    }

    public function test_additional_context_extracted_from_record(): void
    {
        $processor = new ContextProcessor();
        $record = $this->makeRecord(context: [
            'user_id' => 42,
            'action' => 'payment',
        ]);

        $result = $processor($record);

        $this->assertArrayHasKey('additional_context', $result->extra);
        $this->assertEquals(42, $result->extra['additional_context']['user_id']);
        $this->assertEquals('payment', $result->extra['additional_context']['action']);
    }

    public function test_additional_context_excludes_exception(): void
    {
        $processor = new ContextProcessor();
        $exception = new \RuntimeException('Test');
        $record = $this->makeRecord(context: [
            'exception' => $exception,
            'user_id' => 42,
        ]);

        $result = $processor($record);

        $this->assertArrayHasKey('additional_context', $result->extra);
        $this->assertArrayNotHasKey('exception', $result->extra['additional_context']);
        $this->assertEquals(42, $result->extra['additional_context']['user_id']);
    }

    public function test_additional_context_null_when_empty(): void
    {
        $processor = new ContextProcessor();
        $record = $this->makeRecord(context: []);

        $result = $processor($record);

        $this->assertNull($result->extra['additional_context']);
    }
}
