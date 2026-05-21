<?php

namespace Shaffe\MailLogChannel\Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Container\Container;
use Illuminate\Config\Repository as ConfigRepository;
use InvalidArgumentException;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Shaffe\MailLogChannel\MailLogger;
use Shaffe\MailLogChannel\Monolog\Handlers\MailableHandler;
use Shaffe\MailLogChannel\Monolog\Formatters\HtmlFormatter;

class MailLoggerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = \Mockery::mock(Container::class)->makePartial();
        $app->shouldReceive('basePath')->andReturn('/tmp/test-app');

        $config = new ConfigRepository([
            'mail' => [
                'from' => [
                    'address' => 'default@example.com',
                    'name' => 'Default Sender',
                ],
            ],
        ]);

        $app->instance('config', $config);
        $app->instance('app', $app);
        $app->singleton(\Illuminate\Contracts\Config\Repository::class, fn () => $config);

        // Mock the mailer
        $mailer = \Mockery::mock(\Illuminate\Contracts\Mail\Mailer::class);
        $app->instance('mailer', $mailer);

        // Bind a cache store for throttle
        $cacheManager = \Mockery::mock(\Illuminate\Cache\CacheManager::class);
        $cacheManager->shouldReceive('store')
            ->andReturn(new CacheRepository(new ArrayStore()));
        $app->instance('cache', $cacheManager);

        // Bind QueryCollector
        $app->singleton(\Shaffe\MailLogChannel\QueryCollector::class);

        Container::setInstance($app);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        \Mockery::close();
        parent::tearDown();
    }

    public function test_creates_logger_with_minimal_config(): void
    {
        $logger = (new MailLogger())([
            'to' => ['admin@example.com'],
        ]);

        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertEquals('mailable', $logger->getName());
    }

    public function test_creates_logger_with_custom_level(): void
    {
        $logger = (new MailLogger())([
            'to' => ['admin@example.com'],
            'level' => 'error',
        ]);

        $handlers = $logger->getHandlers();
        $this->assertCount(1, $handlers);
        $this->assertInstanceOf(MailableHandler::class, $handlers[0]);
    }

    public function test_creates_logger_with_custom_from_address(): void
    {
        $logger = (new MailLogger())([
            'to' => ['admin@example.com'],
            'from' => [
                'address' => 'custom@example.com',
                'name' => 'Custom Sender',
            ],
        ]);

        $this->assertInstanceOf(Logger::class, $logger);
    }

    public function test_throws_exception_when_to_is_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"To" address is required');

        (new MailLogger())([]);
    }

    public function test_throws_exception_when_to_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"To" address is required');

        (new MailLogger())(['to' => []]);
    }

    public function test_throws_exception_when_from_address_is_missing(): void
    {
        // Remove default from address
        $config = new ConfigRepository([
            'mail' => ['from' => ['address' => null, 'name' => null]],
        ]);
        $app = Container::getInstance();
        $app->instance('config', $config);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"From" address is required');

        (new MailLogger())([
            'to' => ['admin@example.com'],
        ]);
    }

    public function test_accepts_multiple_recipients_as_array(): void
    {
        $logger = (new MailLogger())([
            'to' => ['admin@example.com', 'dev@example.com'],
        ]);

        $this->assertInstanceOf(Logger::class, $logger);
    }

    public function test_accepts_recipients_with_names(): void
    {
        $logger = (new MailLogger())([
            'to' => ['admin@example.com' => 'Admin User'],
        ]);

        $this->assertInstanceOf(Logger::class, $logger);
    }

    public function test_accepts_recipients_as_associative_arrays(): void
    {
        $logger = (new MailLogger())([
            'to' => [
                ['email' => 'admin@example.com', 'name' => 'Admin'],
                ['address' => 'dev@example.com', 'name' => 'Dev'],
            ],
        ]);

        $this->assertInstanceOf(Logger::class, $logger);
    }

    public function test_handler_uses_html_formatter(): void
    {
        $logger = (new MailLogger())([
            'to' => ['admin@example.com'],
        ]);

        $handler = $logger->getHandlers()[0];
        $this->assertInstanceOf(HtmlFormatter::class, $handler->getFormatter());
    }

    public function test_throttle_disabled_when_set_to_false(): void
    {
        $logger = (new MailLogger())([
            'to' => ['admin@example.com'],
            'throttle' => false,
        ]);

        $this->assertInstanceOf(Logger::class, $logger);
    }

    public function test_throttle_disabled_when_set_to_zero(): void
    {
        $logger = (new MailLogger())([
            'to' => ['admin@example.com'],
            'throttle' => 0,
        ]);

        $this->assertInstanceOf(Logger::class, $logger);
    }

    public function test_queries_disabled_when_log_queries_is_false(): void
    {
        $logger = (new MailLogger())([
            'to' => ['admin@example.com'],
            'log_queries' => false,
        ]);

        $this->assertInstanceOf(Logger::class, $logger);
    }
}
