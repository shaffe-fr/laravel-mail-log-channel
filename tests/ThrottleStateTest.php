<?php

namespace Shaffe\MailLogChannel\Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Shaffe\MailLogChannel\Throttle\ThrottleState;

class ThrottleStateTest extends TestCase
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
        array $context = [],
        string $channel = 'mailable'
    ): LogRecord {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: $channel,
            level: $level,
            message: $message,
            context: $context,
        );
    }

    public function test_first_record_is_not_throttled(): void
    {
        $throttle = new ThrottleState($this->cache, ttl: 60);
        $record = $this->makeRecord('Something went wrong');

        $this->assertFalse($throttle->isThrottled($record));
    }

    public function test_identical_record_is_throttled(): void
    {
        $throttle = new ThrottleState($this->cache, ttl: 60);
        $record = $this->makeRecord('Something went wrong');

        $throttle->isThrottled($record);
        $this->assertTrue($throttle->isThrottled($record));
    }

    public function test_different_message_is_not_throttled(): void
    {
        $throttle = new ThrottleState($this->cache, ttl: 60);

        $record1 = $this->makeRecord('Error A');
        $record2 = $this->makeRecord('Error B');

        $throttle->isThrottled($record1);
        $this->assertFalse($throttle->isThrottled($record2));
    }

    public function test_different_level_is_not_throttled(): void
    {
        $throttle = new ThrottleState($this->cache, ttl: 60);

        $record1 = $this->makeRecord('Same message', Level::Error);
        $record2 = $this->makeRecord('Same message', Level::Critical);

        $throttle->isThrottled($record1);
        $this->assertFalse($throttle->isThrottled($record2));
    }

    public function test_exception_fingerprint_uses_class_code_message_file_line(): void
    {
        $throttle = new ThrottleState($this->cache, ttl: 60);

        $exception = new \RuntimeException('DB connection failed', 42);

        $record = $this->makeRecord('DB connection failed', context: [
            'exception' => $exception,
        ]);

        $this->assertFalse($throttle->isThrottled($record));
        $this->assertTrue($throttle->isThrottled($record));
    }

    public function test_same_exception_different_code_is_not_throttled(): void
    {
        $throttle = new ThrottleState($this->cache, ttl: 60);

        $exception1 = new \RuntimeException('Connection failed', 1);
        $exception2 = new \RuntimeException('Connection failed', 2);

        $record1 = $this->makeRecord('Connection failed', context: ['exception' => $exception1]);
        $record2 = $this->makeRecord('Connection failed', context: ['exception' => $exception2]);

        $throttle->isThrottled($record1);
        $this->assertFalse($throttle->isThrottled($record2));
    }

    public function test_same_exception_different_file_is_not_throttled(): void
    {
        $throttle = new ThrottleState($this->cache, ttl: 60);

        $exception1 = new \RuntimeException('Error');
        $line1 = __LINE__;
        $exception2 = new \RuntimeException('Error');
        $line2 = __LINE__;

        $this->assertNotEquals($line1, $line2);

        $record1 = $this->makeRecord('Error', context: ['exception' => $exception1]);
        $record2 = $this->makeRecord('Error', context: ['exception' => $exception2]);

        $throttle->isThrottled($record1);
        $this->assertFalse($throttle->isThrottled($record2));
    }

    public function test_custom_cache_prefix(): void
    {
        $throttle = new ThrottleState($this->cache, ttl: 60, cachePrefix: 'custom_prefix:');
        $record = $this->makeRecord('Test');

        $throttle->isThrottled($record);
        $this->assertTrue($throttle->isThrottled($record));
    }

    public function test_occurrence_count_is_one_on_first_call(): void
    {
        $throttle = new ThrottleState($this->cache, ttl: 60);
        $record = $this->makeRecord('First time');

        $throttle->isThrottled($record);

        $this->assertEquals(1, $throttle->getOccurrenceCount($record));
    }

    public function test_occurrence_count_increments_on_each_call(): void
    {
        $throttle = new ThrottleState($this->cache, ttl: 60);
        $record = $this->makeRecord('Repeated error');

        $throttle->isThrottled($record);
        $this->assertEquals(1, $throttle->getOccurrenceCount($record));

        for ($i = 0; $i < 5; $i++) {
            $throttle->isThrottled($record);
        }

        $this->assertEquals(6, $throttle->getOccurrenceCount($record));
    }

    public function test_occurrence_count_is_cumulative_across_throttle_windows(): void
    {
        $throttle = new ThrottleState($this->cache, ttl: 60);
        $record = $this->makeRecord('Persistent error');

        // First window: 5 occurrences
        for ($i = 0; $i < 5; $i++) {
            $throttle->isThrottled($record);
        }
        $this->assertEquals(5, $throttle->getOccurrenceCount($record));

        // Simulate TTL expiry on the lock
        $fingerprint = $throttle->fingerprint($record);
        $this->cache->forget('mail_log_throttle:'.$fingerprint);

        // Second window: count continues
        $throttle->isThrottled($record);
        $this->assertEquals(6, $throttle->getOccurrenceCount($record));
    }

    public function test_first_seen_at_is_recorded(): void
    {
        $throttle = new ThrottleState($this->cache, ttl: 60);
        $record = $this->makeRecord('Timestamped error');

        $before = time();
        $throttle->isThrottled($record);
        $after = time();

        $firstSeenAt = $throttle->getFirstSeenAt($record);

        $this->assertNotNull($firstSeenAt);
        $this->assertGreaterThanOrEqual($before, $firstSeenAt);
        $this->assertLessThanOrEqual($after, $firstSeenAt);
    }

    public function test_first_seen_at_does_not_change_on_subsequent_calls(): void
    {
        $throttle = new ThrottleState($this->cache, ttl: 60);
        $record = $this->makeRecord('Stable timestamp');

        $throttle->isThrottled($record);
        $firstSeenAt = $throttle->getFirstSeenAt($record);

        // Subsequent calls should not change the first seen timestamp
        $throttle->isThrottled($record);
        $throttle->isThrottled($record);

        $this->assertEquals($firstSeenAt, $throttle->getFirstSeenAt($record));
    }

    public function test_first_seen_at_is_null_for_unknown_record(): void
    {
        $throttle = new ThrottleState($this->cache, ttl: 60);
        $record = $this->makeRecord('Never seen');

        $this->assertNull($throttle->getFirstSeenAt($record));
    }

    public function test_record_available_again_after_ttl_expires(): void
    {
        $throttle = new ThrottleState($this->cache, ttl: 60);
        $record = $this->makeRecord('Expiring error');

        $this->assertFalse($throttle->isThrottled($record));
        $this->assertTrue($throttle->isThrottled($record));

        // Simulate TTL expiry
        $fingerprint = $throttle->fingerprint($record);
        $this->cache->forget('mail_log_throttle:'.$fingerprint);

        $this->assertFalse($throttle->isThrottled($record));
    }
}
