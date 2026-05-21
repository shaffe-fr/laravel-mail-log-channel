<?php

namespace Shaffe\MailLogChannel\Tests;

use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Shaffe\MailLogChannel\Monolog\Formatters\HtmlFormatter;

class HtmlFormatterThrottleTest extends TestCase
{
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

    public function test_no_throttle_notice_when_no_occurrence_count(): void
    {
        $formatter = new HtmlFormatter('/tmp');
        $record = $this->makeRecord('Normal error');

        $html = $formatter->format($record);

        $this->assertStringNotContainsString('has occurred', $html);
    }

    public function test_no_throttle_notice_when_count_is_one(): void
    {
        $formatter = new HtmlFormatter('/tmp');
        $record = $this->makeRecord('First occurrence', extra: [
            'throttle_occurrence_count' => 1,
        ]);

        $html = $formatter->format($record);

        $this->assertStringNotContainsString('has occurred', $html);
    }

    public function test_throttle_notice_with_count_and_timestamp(): void
    {
        $formatter = new HtmlFormatter('/tmp');
        $timestamp = mktime(14, 30, 0, 3, 15, 2025);

        $record = $this->makeRecord('Repeated error', extra: [
            'throttle_occurrence_count' => 47,
            'throttle_first_seen_at' => $timestamp,
        ]);

        $html = $formatter->format($record);

        $this->assertStringContainsString('47 times since', $html);
        $this->assertStringContainsString('15 Mar 2025', $html);
    }

    public function test_throttle_notice_without_timestamp_fallback(): void
    {
        $formatter = new HtmlFormatter('/tmp');
        $record = $this->makeRecord('Repeated error', extra: [
            'throttle_occurrence_count' => 12,
        ]);

        $html = $formatter->format($record);

        $this->assertStringContainsString('12 times', $html);
    }

    public function test_throttle_notice_with_count_of_two(): void
    {
        $formatter = new HtmlFormatter('/tmp');
        $record = $this->makeRecord('Twice error', extra: [
            'throttle_occurrence_count' => 2,
            'throttle_first_seen_at' => time(),
        ]);

        $html = $formatter->format($record);

        $this->assertStringContainsString('2 times since', $html);
    }
}
