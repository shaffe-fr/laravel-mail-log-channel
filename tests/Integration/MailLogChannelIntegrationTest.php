<?php

namespace Shaffe\MailLogChannel\Tests\Integration;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Monolog\Logger;
use Orchestra\Testbench\TestCase;
use Shaffe\MailLogChannel\Mail\Log as MailableLog;
use Shaffe\MailLogChannel\MailLogChannelServiceProvider;
use Shaffe\MailLogChannel\QueryCollector;

class MailLogChannelIntegrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [MailLogChannelServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mail.from.address', 'system@example.com');
        $app['config']->set('mail.from.name', 'System');

        $app['config']->set('logging.channels.mail', [
            'driver' => 'mail',
            'to' => ['admin@example.com'],
            'level' => 'error',
            'throttle' => false,
        ]);
    }

    // ------------------------------------------------------------------
    // Service Provider Registration
    // ------------------------------------------------------------------

    public function test_mail_driver_is_registered_in_log_manager(): void
    {
        // The 'mail' driver should be resolvable without throwing
        $logger = Log::channel('mail');

        $this->assertNotNull($logger);
    }

    public function test_channel_returns_a_monolog_logger(): void
    {
        $logger = Log::channel('mail');

        // The underlying logger should be a Monolog Logger
        $this->assertInstanceOf(Logger::class, $logger->getLogger());
    }

    // ------------------------------------------------------------------
    // Mail Sending via Log
    // ------------------------------------------------------------------

    public function test_logging_an_error_sends_a_mail(): void
    {
        Mail::fake();

        Log::channel('mail')->error('Something went wrong');

        Mail::assertSent(MailableLog::class, function (MailableLog $mail) {
            return $mail->hasTo('admin@example.com');
        });
    }

    public function test_logging_below_configured_level_does_not_send_mail(): void
    {
        Mail::fake();

        Log::channel('mail')->info('Just an info message');

        Mail::assertNothingSent();
    }

    public function test_mail_subject_contains_level_and_message(): void
    {
        Mail::fake();

        Log::channel('mail')->error('Database connection lost');

        Mail::assertSent(MailableLog::class, function (MailableLog $mail) {
            $this->assertStringContainsString('ERROR', $mail->subject);
            $this->assertStringContainsString('Database connection lost', $mail->subject);

            return true;
        });
    }

    public function test_multiple_recipients_receive_the_mail(): void
    {
        $this->app['config']->set('logging.channels.mail.to', [
            'admin@example.com',
            'ops@example.com',
        ]);

        Mail::fake();

        Log::channel('mail')->error('Critical failure');

        Mail::assertSent(MailableLog::class, function (MailableLog $mail) {
            return $mail->hasTo('admin@example.com')
                && $mail->hasTo('ops@example.com');
        });
    }

    // ------------------------------------------------------------------
    // Query Collector Integration
    // ------------------------------------------------------------------

    public function test_query_collector_is_registered_as_scoped(): void
    {
        $collector = $this->app->make(QueryCollector::class);

        $this->assertInstanceOf(QueryCollector::class, $collector);
    }

    public function test_query_collector_captures_queries_via_event(): void
    {
        $collector = $this->app->make(QueryCollector::class);

        // Simulate a QueryExecuted event
        $connection = new class {
            public function getName() { return 'testing'; }
        };

        event(new QueryExecuted(
            'SELECT * FROM users WHERE id = ?',
            [1],
            12.5,
            $connection
        ));

        $this->assertCount(1, $collector->getQueries());
        $this->assertEquals('SELECT * FROM users WHERE id = ?', $collector->getQueries()[0]['sql']);
        $this->assertEquals(1, $collector->getTotal());
    }

    public function test_query_collector_is_same_instance_within_request(): void
    {
        $first = $this->app->make(QueryCollector::class);
        $second = $this->app->make(QueryCollector::class);

        $this->assertSame($first, $second);
    }

    // ------------------------------------------------------------------
    // Throttle Integration
    // ------------------------------------------------------------------

    public function test_throttle_prevents_duplicate_mails(): void
    {
        $this->app['config']->set('logging.channels.mail.throttle', 60);

        Mail::fake();

        $channel = Log::channel('mail');
        $channel->error('Same error');
        $channel->error('Same error');
        $channel->error('Same error');

        Mail::assertSentCount(1);
    }

    public function test_throttle_disabled_sends_all_mails(): void
    {
        $this->app['config']->set('logging.channels.mail.throttle', false);

        Mail::fake();

        $channel = Log::channel('mail');
        $channel->error('Repeated error');
        $channel->error('Repeated error');

        Mail::assertSentCount(2);
    }

    // ------------------------------------------------------------------
    // Level-Based Routing Integration
    // ------------------------------------------------------------------

    public function test_level_based_routing_sends_to_correct_recipients(): void
    {
        $this->app['config']->set('logging.channels.mail', [
            'driver' => 'mail',
            'level' => 'debug',
            'throttle' => false,
            'to' => [
                'error' => ['errors@example.com'],
                'critical' => ['critical@example.com'],
                'default' => ['default@example.com'],
            ],
        ]);

        Mail::fake();

        Log::channel('mail')->error('An error occurred');

        Mail::assertSent(MailableLog::class, function (MailableLog $mail) {
            return $mail->hasTo('errors@example.com');
        });
    }

    public function test_level_based_routing_uses_default_for_unconfigured_levels(): void
    {
        $this->app['config']->set('logging.channels.mail', [
            'driver' => 'mail',
            'level' => 'debug',
            'throttle' => false,
            'to' => [
                'critical' => ['critical@example.com'],
                'default' => ['fallback@example.com'],
            ],
        ]);

        Mail::fake();

        Log::channel('mail')->error('Fallback test');

        Mail::assertSent(MailableLog::class, function (MailableLog $mail) {
            return $mail->hasTo('fallback@example.com');
        });
    }

    public function test_level_based_routing_suppresses_when_level_is_empty(): void
    {
        $this->app['config']->set('logging.channels.mail', [
            'driver' => 'mail',
            'level' => 'debug',
            'throttle' => false,
            'to' => [
                'error' => null,
                'default' => ['default@example.com'],
            ],
        ]);

        Mail::fake();

        Log::channel('mail')->error('Should be suppressed');

        Mail::assertNothingSent();
    }
}
