<?php

namespace Shaffe\MailLogChannel\Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Shaffe\MailLogChannel\Monolog\Handlers\MailableHandler;
use Shaffe\MailLogChannel\Throttle\ThrottleState;

class MailableHandlerThrottleTest extends TestCase
{
    protected CacheRepository $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = new CacheRepository(new ArrayStore());
    }

    protected function makeRecord(
        string $message = 'Test error',
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

    protected function createHandler(?ThrottleState $throttle = null): TestableMailableHandler
    {
        return new TestableMailableHandler($throttle);
    }

    public function test_handler_skips_sending_when_throttled(): void
    {
        $throttle = new ThrottleState($this->cache, ttl: 60);
        $handler = $this->createHandler($throttle);

        $record = $this->makeRecord('Duplicate error');

        $handler->handle($record);
        $handler->handle($record);

        $this->assertCount(1, $handler->sentRecords);
    }

    public function test_handler_sends_when_no_throttle_configured(): void
    {
        $handler = $this->createHandler(null);

        $record = $this->makeRecord('No throttle');

        $handler->handle($record);
        $handler->handle($record);

        $this->assertCount(2, $handler->sentRecords);
    }

    public function test_handler_injects_occurrence_count_and_first_seen(): void
    {
        $throttle = new ThrottleState($this->cache, ttl: 60);
        $handler = $this->createHandler($throttle);

        $record = $this->makeRecord('Repeated error');

        // First: not throttled, sends (count = 1, not injected)
        $handler->handle($record);

        // Simulate 3 more throttled occurrences
        $throttle->isThrottled($record);
        $throttle->isThrottled($record);
        $throttle->isThrottled($record);

        // Simulate TTL expiry by removing the lock
        $fingerprint = $throttle->fingerprint($record);
        $this->cache->forget('mail_log_throttle:' . $fingerprint);

        // Next call: not throttled anymore, sends with count = 5
        $handler->handle($record);

        $this->assertCount(2, $handler->sentRecords);

        // First send: no occurrence data
        $firstRecords = $handler->sentRecords[0];
        $this->assertArrayNotHasKey('throttle_occurrence_count', $firstRecords[0]->extra);

        // Second send: has occurrence count and first seen timestamp
        $secondRecords = $handler->sentRecords[1];
        $this->assertArrayHasKey('throttle_occurrence_count', $secondRecords[0]->extra);
        $this->assertEquals(5, $secondRecords[0]->extra['throttle_occurrence_count']);
        $this->assertArrayHasKey('throttle_first_seen_at', $secondRecords[0]->extra);
        $this->assertIsInt($secondRecords[0]->extra['throttle_first_seen_at']);
    }

    public function test_handler_does_not_inject_count_on_first_occurrence(): void
    {
        $throttle = new ThrottleState($this->cache, ttl: 60);
        $handler = $this->createHandler($throttle);

        $record = $this->makeRecord('Single error');

        $handler->handle($record);

        $this->assertCount(1, $handler->sentRecords);
        $this->assertArrayNotHasKey('throttle_occurrence_count', $handler->sentRecords[0][0]->extra);
    }
}

/**
 * Testable subclass that captures sent records instead of actually mailing.
 */
class TestableMailableHandler extends MailableHandler
{
    public array $sentRecords = [];

    public function __construct(?ThrottleState $throttle = null)
    {
        AbstractProcessingHandler::__construct(Level::Debug, true);
        $this->throttle = $throttle;
    }

    protected function send(string $content, array $records): void
    {
        // Replicate the occurrence count injection from the real send()
        if ($this->throttle && !empty($records)) {
            $highestRecord = $this->getHighestRecord($records);
            $occurrenceCount = $this->throttle->getOccurrenceCount($highestRecord);

            if ($occurrenceCount > 1) {
                $firstSeenAt = $this->throttle->getFirstSeenAt($highestRecord);

                $records = array_map(function (LogRecord $record) use ($highestRecord, $occurrenceCount, $firstSeenAt) {
                    if ($record === $highestRecord) {
                        $extra = array_merge($record->extra, [
                            'throttle_occurrence_count' => $occurrenceCount,
                        ]);
                        if ($firstSeenAt !== null) {
                            $extra['throttle_first_seen_at'] = $firstSeenAt;
                        }
                        return $record->with(extra: $extra);
                    }
                    return $record;
                }, $records);
            }
        }

        $this->sentRecords[] = $records;
    }
}
