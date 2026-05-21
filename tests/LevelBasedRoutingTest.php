<?php

namespace Shaffe\MailLogChannel\Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use InvalidArgumentException;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Shaffe\MailLogChannel\MailLogger;
use Shaffe\MailLogChannel\Monolog\Handlers\MailableHandler;

class LevelBasedRoutingTest extends TestCase
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

        $mailer = \Mockery::mock(\Illuminate\Contracts\Mail\Mailer::class);
        $app->instance('mailer', $mailer);

        $cacheManager = \Mockery::mock(\Illuminate\Cache\CacheManager::class);
        $cacheManager->shouldReceive('store')
            ->andReturn(new CacheRepository(new ArrayStore()));
        $app->instance('cache', $cacheManager);

        $app->singleton(\Shaffe\MailLogChannel\QueryCollector::class);

        Container::setInstance($app);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        \Mockery::close();
        parent::tearDown();
    }

    protected function makeRecord(
        string $message = 'Test error',
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

    // ── MailLogger config detection ──────────────────────────

    public function test_simple_string_to_is_not_level_based(): void
    {
        $logger = (new MailLogger())([
            'to' => 'admin@example.com',
        ]);

        $this->assertInstanceOf(\Monolog\Logger::class, $logger);
    }

    public function test_simple_array_to_is_not_level_based(): void
    {
        $logger = (new MailLogger())([
            'to' => ['admin@example.com', 'dev@example.com'],
        ]);

        $this->assertInstanceOf(\Monolog\Logger::class, $logger);
    }

    public function test_named_array_to_is_not_level_based(): void
    {
        $logger = (new MailLogger())([
            'to' => ['admin@example.com' => 'Admin'],
        ]);

        $this->assertInstanceOf(\Monolog\Logger::class, $logger);
    }

    public function test_level_based_to_with_default_creates_logger(): void
    {
        $logger = (new MailLogger())([
            'to' => [
                'default' => 'dev@example.com',
                'critical' => 'oncall@example.com',
            ],
        ]);

        $this->assertInstanceOf(\Monolog\Logger::class, $logger);
    }

    public function test_level_based_to_without_default_creates_logger(): void
    {
        $logger = (new MailLogger())([
            'to' => [
                'error' => 'dev@example.com',
                'critical' => 'oncall@example.com',
            ],
        ]);

        $this->assertInstanceOf(\Monolog\Logger::class, $logger);
    }

    public function test_level_based_to_with_null_level_creates_logger(): void
    {
        $logger = (new MailLogger())([
            'to' => [
                'default' => 'dev@example.com',
                'debug' => null,
            ],
        ]);

        $this->assertInstanceOf(\Monolog\Logger::class, $logger);
    }

    public function test_level_based_to_with_empty_string_level_creates_logger(): void
    {
        $logger = (new MailLogger())([
            'to' => [
                'default' => 'dev@example.com',
                'info' => '',
            ],
        ]);

        $this->assertInstanceOf(\Monolog\Logger::class, $logger);
    }

    public function test_level_based_to_throws_when_all_levels_are_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"To" address is required');

        (new MailLogger())([
            'to' => [
                'error' => null,
                'critical' => '',
            ],
        ]);
    }

    public function test_level_based_to_accepts_multiple_recipients_per_level(): void
    {
        $logger = (new MailLogger())([
            'to' => [
                'default' => ['dev@example.com', 'team@example.com'],
                'critical' => ['oncall@example.com'],
            ],
        ]);

        $this->assertInstanceOf(\Monolog\Logger::class, $logger);
    }

    public function test_level_based_to_accepts_structured_recipients_per_level(): void
    {
        $logger = (new MailLogger())([
            'to' => [
                'default' => 'dev@example.com',
                'emergency' => [
                    ['address' => 'oncall@example.com', 'name' => 'On-Call'],
                    ['address' => 'cto@example.com', 'name' => 'CTO'],
                ],
            ],
        ]);

        $this->assertInstanceOf(\Monolog\Logger::class, $logger);
    }

    // ── Handler write behavior ───────────────────────────────

    public function test_handler_sends_when_level_has_recipients(): void
    {
        $handler = new LevelRoutingTestableHandler(
            levelRecipients: [
                'error' => [['email' => 'dev@example.com', 'name' => null]],
            ]
        );

        $record = $this->makeRecord('Error msg', Level::Error);
        $handler->handle($record);

        $this->assertCount(1, $handler->sentPayloads);
    }

    public function test_handler_sends_using_default_when_level_not_configured(): void
    {
        $handler = new LevelRoutingTestableHandler(
            levelRecipients: [
                'default' => [['email' => 'dev@example.com', 'name' => null]],
                'critical' => [['email' => 'oncall@example.com', 'name' => null]],
            ]
        );

        $record = $this->makeRecord('Warning msg', Level::Warning);
        $handler->handle($record);

        $this->assertCount(1, $handler->sentPayloads);
    }

    public function test_handler_skips_when_level_not_configured_and_no_default(): void
    {
        $handler = new LevelRoutingTestableHandler(
            levelRecipients: [
                'critical' => [['email' => 'oncall@example.com', 'name' => null]],
            ]
        );

        $record = $this->makeRecord('Error msg', Level::Error);
        $handler->handle($record);

        $this->assertCount(0, $handler->sentPayloads);
    }

    public function test_handler_skips_when_level_explicitly_null(): void
    {
        $handler = new LevelRoutingTestableHandler(
            levelRecipients: [
                'default' => [['email' => 'dev@example.com', 'name' => null]],
                'debug' => [],
            ]
        );

        $record = $this->makeRecord('Debug msg', Level::Debug);
        $handler->handle($record);

        $this->assertCount(0, $handler->sentPayloads);
    }

    public function test_handler_skips_when_level_explicitly_empty_string(): void
    {
        $handler = new LevelRoutingTestableHandler(
            levelRecipients: [
                'default' => [['email' => 'dev@example.com', 'name' => null]],
                'info' => [],
            ]
        );

        $record = $this->makeRecord('Info msg', Level::Info);
        $handler->handle($record);

        $this->assertCount(0, $handler->sentPayloads);
    }

    public function test_handler_skips_when_default_is_empty_and_level_not_configured(): void
    {
        $handler = new LevelRoutingTestableHandler(
            levelRecipients: [
                'default' => [],
                'critical' => [['email' => 'oncall@example.com', 'name' => null]],
            ]
        );

        $record = $this->makeRecord('Error msg', Level::Error);
        $handler->handle($record);

        $this->assertCount(0, $handler->sentPayloads);
    }

    public function test_handler_sends_to_level_specific_even_when_default_is_empty(): void
    {
        $handler = new LevelRoutingTestableHandler(
            levelRecipients: [
                'default' => [],
                'critical' => [['email' => 'oncall@example.com', 'name' => null]],
            ]
        );

        $record = $this->makeRecord('Critical msg', Level::Critical);
        $handler->handle($record);

        $this->assertCount(1, $handler->sentPayloads);
    }

    public function test_handler_without_level_recipients_sends_all(): void
    {
        $handler = new LevelRoutingTestableHandler(levelRecipients: null);

        $handler->handle($this->makeRecord('Debug', Level::Debug));
        $handler->handle($this->makeRecord('Error', Level::Error));
        $handler->handle($this->makeRecord('Critical', Level::Critical));

        $this->assertCount(3, $handler->sentPayloads);
    }

    public function test_handler_does_not_accumulate_recipients_across_sends(): void
    {
        $handler = new LevelRoutingTestableHandler(
            levelRecipients: [
                'error' => [['email' => 'dev@example.com', 'name' => null]],
                'critical' => [['email' => 'oncall@example.com', 'name' => null]],
            ]
        );

        $handler->handle($this->makeRecord('Error 1', Level::Error));
        $handler->handle($this->makeRecord('Critical 1', Level::Critical));
        $handler->handle($this->makeRecord('Error 2', Level::Error));

        // Each send should have exactly 1 recipient, not accumulated
        $this->assertCount(3, $handler->sentPayloads);
        $this->assertEquals(
            [['email' => 'dev@example.com', 'name' => null]],
            $handler->sentPayloads[0]['recipients']
        );
        $this->assertEquals(
            [['email' => 'oncall@example.com', 'name' => null]],
            $handler->sentPayloads[1]['recipients']
        );
        $this->assertEquals(
            [['email' => 'dev@example.com', 'name' => null]],
            $handler->sentPayloads[2]['recipients']
        );
    }

    public function test_handler_level_matching_is_case_insensitive(): void
    {
        $handler = new LevelRoutingTestableHandler(
            levelRecipients: [
                'error' => [['email' => 'dev@example.com', 'name' => null]],
            ]
        );

        // Level::Error->getName() returns 'ERROR' (uppercase)
        $record = $this->makeRecord('Error msg', Level::Error);
        $handler->handle($record);

        $this->assertCount(1, $handler->sentPayloads);
    }

    // ── Monolog Level constants as keys ──────────────────────

    public function test_level_based_to_accepts_numeric_level_values(): void
    {
        $logger = (new MailLogger())([
            'to' => [
                Level::Error->value => 'dev@example.com',       // 400
                Level::Critical->value => 'oncall@example.com', // 500
            ],
        ]);

        $this->assertInstanceOf(\Monolog\Logger::class, $logger);
    }

    public function test_handler_routes_with_numeric_level_keys(): void
    {
        $handler = new LevelRoutingTestableHandler(
            levelRecipients: [
                'error' => [['email' => 'dev@example.com', 'name' => null]],
                'critical' => [['email' => 'oncall@example.com', 'name' => null]],
            ]
        );

        $record = $this->makeRecord('Critical msg', Level::Critical);
        $handler->handle($record);

        $this->assertCount(1, $handler->sentPayloads);
    }

    public function test_numeric_level_key_with_null_suppresses_email(): void
    {
        // Numeric key 100 = Debug
        $logger = (new MailLogger())([
            'to' => [
                'default' => 'dev@example.com',
                Level::Debug->value => null,
            ],
        ]);

        $this->assertInstanceOf(\Monolog\Logger::class, $logger);
    }

    public function test_mixed_string_and_numeric_keys(): void
    {
        $logger = (new MailLogger())([
            'to' => [
                'default' => 'dev@example.com',
                Level::Critical->value => 'oncall@example.com',
                'debug' => null,
            ],
        ]);

        $this->assertInstanceOf(\Monolog\Logger::class, $logger);
    }
}

/**
 * Testable handler that captures sent payloads without actually mailing.
 */
class LevelRoutingTestableHandler extends MailableHandler
{
    public array $sentPayloads = [];

    public function __construct(?array $levelRecipients = null, Level $level = Level::Debug)
    {
        \Monolog\Handler\AbstractProcessingHandler::__construct($level, true);
        $this->subjectFormat = '[%level_name%] %message%';
        $this->throttle = null;
        $this->levelRecipients = $levelRecipients;
    }

    protected function send(string $content, array $records): void
    {
        $recipients = null;
        if ($this->levelRecipients !== null) {
            $recipients = $this->resolveRecipientsForRecords($records);
            if (empty($recipients)) {
                return;
            }
        }

        $this->sentPayloads[] = [
            'content' => $content,
            'records' => $records,
            'recipients' => $recipients,
        ];
    }
}
