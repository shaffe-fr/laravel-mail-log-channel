<?php

namespace Shaffe\MailLogChannel\Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Mail\Mailable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Shaffe\MailLogChannel\MailLogger;

/**
 * Integration tests verifying that all recipient formats work end-to-end
 * with level-based routing, from config through to the mailable.
 */
class LevelBasedRecipientFormatsTest extends TestCase
{
    protected \Mockery\MockInterface $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        $app = \Mockery::mock(Container::class)->makePartial();
        $app->shouldReceive('basePath')->andReturn('/tmp/test-app');
        $app->shouldReceive('runningInConsole')->andReturn(true);
        $app->shouldReceive('version')->andReturn('11.0.0');

        $config = new ConfigRepository([
            'mail' => [
                'from' => [
                    'address' => 'noreply@example.com',
                    'name' => 'App Errors',
                ],
            ],
        ]);

        $app->instance('config', $config);
        $app->instance('app', $app);
        $app->singleton(\Illuminate\Contracts\Config\Repository::class, fn () => $config);

        $this->mailer = \Mockery::mock(\Illuminate\Contracts\Mail\Mailer::class);
        $this->mailer->shouldReceive('send')->byDefault();
        $app->instance('mailer', $this->mailer);

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
        // Verify Mockery expectations and count them as assertions
        if ($container = \Mockery::getContainer()) {
            $this->addToAssertionCount($container->mockery_getExpectationCount());
        }
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

    // ── Simple string per level ──────────────────────────────

    public function test_simple_string_recipient_per_level(): void
    {
        $this->mailer->shouldReceive('send')->once()->with(\Mockery::on(function (Mailable $mailable) {
            $to = $mailable->to;

            return count($to) === 1 && $to[0]['address'] === 'dev@example.com';
        }));

        $logger = (new MailLogger())([
            'to' => [
                'default' => 'dev@example.com',
            ],
            'throttle' => false,
        ]);

        $logger->error('Something broke');
    }

    // ── Array of strings per level ───────────────────────────

    public function test_array_of_strings_recipient_per_level(): void
    {
        $this->mailer->shouldReceive('send')->once()->with(\Mockery::on(function (Mailable $mailable) {
            $to = $mailable->to;

            return count($to) === 2
                && $to[0]['address'] === 'dev@example.com'
                && $to[1]['address'] === 'ops@example.com';
        }));

        $logger = (new MailLogger())([
            'to' => [
                'error' => ['dev@example.com', 'ops@example.com'],
            ],
            'throttle' => false,
        ]);

        $logger->error('Something broke');
    }

    // ── Named format (email => name) per level ───────────────

    public function test_named_format_recipient_per_level(): void
    {
        $this->mailer->shouldReceive('send')->once()->with(\Mockery::on(function (Mailable $mailable) {
            $to = $mailable->to;

            return count($to) === 1
                && $to[0]['address'] === 'oncall@example.com'
                && $to[0]['name'] === 'On-Call Team';
        }));

        $logger = (new MailLogger())([
            'to' => [
                'error' => ['oncall@example.com' => 'On-Call Team'],
            ],
            'throttle' => false,
        ]);

        $logger->error('Something broke');
    }

    // ── Structured format per level ──────────────────────────

    public function test_structured_format_recipient_per_level(): void
    {
        $this->mailer->shouldReceive('send')->once()->with(\Mockery::on(function (Mailable $mailable) {
            $to = $mailable->to;

            return count($to) === 2
                && $to[0]['address'] === 'oncall@example.com'
                && $to[0]['name'] === 'On-Call'
                && $to[1]['address'] === 'cto@example.com'
                && $to[1]['name'] === 'CTO';
        }));

        $logger = (new MailLogger())([
            'to' => [
                'error' => [
                    ['address' => 'oncall@example.com', 'name' => 'On-Call'],
                    ['address' => 'cto@example.com', 'name' => 'CTO'],
                ],
            ],
            'throttle' => false,
        ]);

        $logger->error('Something broke');
    }

    // ── Mixed: level routes to different recipients ──────────

    public function test_different_levels_route_to_different_recipients(): void
    {
        $sentTo = [];

        $this->mailer->shouldReceive('send')->twice()->with(\Mockery::on(function (Mailable $mailable) use (&$sentTo) {
            $sentTo[] = array_column($mailable->to, 'address');

            return true;
        }));

        $logger = (new MailLogger())([
            'to' => [
                'error' => 'dev@example.com',
                'critical' => 'oncall@example.com',
            ],
            'throttle' => false,
        ]);

        $logger->error('Error happened');
        $logger->critical('Critical happened');

        $this->assertEquals(['dev@example.com'], $sentTo[0]);
        $this->assertEquals(['oncall@example.com'], $sentTo[1]);
    }

    // ── Suppressed level does not send ───────────────────────

    public function test_suppressed_level_does_not_send(): void
    {
        $this->mailer->shouldReceive('send')->once(); // only the error, not warning

        $logger = (new MailLogger())([
            'to' => [
                'default' => 'dev@example.com',
                'warning' => null,
            ],
            'throttle' => false,
            'level' => 'debug',
        ]);

        $logger->warning('This should be suppressed');
        $logger->error('This should send');
    }

    // ── Numeric level key works ──────────────────────────────

    public function test_numeric_level_key_routes_correctly(): void
    {
        $this->mailer->shouldReceive('send')->once()->with(\Mockery::on(function (Mailable $mailable) {
            $to = $mailable->to;

            return count($to) === 1 && $to[0]['address'] === 'oncall@example.com';
        }));

        $logger = (new MailLogger())([
            'to' => [
                Level::Critical->value => 'oncall@example.com',
            ],
            'throttle' => false,
            'level' => 'debug',
        ]);

        $logger->critical('Critical happened');
    }

    // ── Recipients do not accumulate across sends ────────────

    public function test_recipients_do_not_accumulate_across_multiple_sends(): void
    {
        $sentRecipients = [];

        $this->mailer->shouldReceive('send')->times(3)->with(\Mockery::on(function (Mailable $mailable) use (&$sentRecipients) {
            $sentRecipients[] = $mailable->to;

            return true;
        }));

        $logger = (new MailLogger())([
            'to' => [
                'error' => 'dev@example.com',
                'critical' => 'oncall@example.com',
            ],
            'throttle' => false,
            'level' => 'debug',
        ]);

        $logger->error('First error');
        $logger->critical('A critical');
        $logger->error('Second error');

        // Each send should have exactly 1 recipient — no accumulation
        $this->assertCount(1, $sentRecipients[0]);
        $this->assertEquals('dev@example.com', $sentRecipients[0][0]['address']);

        $this->assertCount(1, $sentRecipients[1]);
        $this->assertEquals('oncall@example.com', $sentRecipients[1][0]['address']);

        $this->assertCount(1, $sentRecipients[2]);
        $this->assertEquals('dev@example.com', $sentRecipients[2][0]['address']);
    }
}
