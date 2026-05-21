<?php

namespace Shaffe\MailLogChannel\Tests;

use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Shaffe\MailLogChannel\Monolog\Handlers\MailableHandler;

class MailableHandlerTest extends TestCase
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

    protected function createHandler(): SubjectTestableHandler
    {
        return new SubjectTestableHandler();
    }

    public function test_subject_format_replaces_level_name(): void
    {
        $handler = $this->createHandler();
        $handler->setSubjectFormat('[%level_name%] Error');

        $record = $this->makeRecord('Something broke', Level::Critical);
        $handler->handle($record);

        $this->assertStringContainsString('CRITICAL', $handler->lastSubject);
    }

    public function test_subject_format_replaces_message(): void
    {
        $handler = $this->createHandler();
        $handler->setSubjectFormat('%message%');

        $record = $this->makeRecord('Database timeout');
        $handler->handle($record);

        $this->assertStringContainsString('Database timeout', $handler->lastSubject);
    }

    public function test_subject_format_replaces_env(): void
    {
        $handler = $this->createHandler();
        $handler->setSubjectFormat('[%env%] %message%');

        $record = $this->makeRecord('Error', extra: [
            'environment' => ['app_env' => 'production'],
        ]);
        $handler->handle($record);

        $this->assertStringContainsString('production', $handler->lastSubject);
    }

    public function test_subject_format_replaces_http_context(): void
    {
        $handler = $this->createHandler();
        $handler->setSubjectFormat('%context% — %message%');

        $record = $this->makeRecord('Error', extra: [
            'execution_context' => [
                'type' => 'http',
                'method' => 'GET',
                'url' => 'https://example.com/api/users',
            ],
        ]);
        $handler->handle($record);

        $this->assertStringContainsString('GET /api/users', $handler->lastSubject);
    }

    public function test_subject_format_replaces_console_context(): void
    {
        $handler = $this->createHandler();
        $handler->setSubjectFormat('%context% — %message%');

        $record = $this->makeRecord('Error', extra: [
            'execution_context' => [
                'type' => 'console',
                'command' => 'php artisan migrate:fresh',
            ],
        ]);
        $handler->handle($record);

        $this->assertStringContainsString('migrate:fresh', $handler->lastSubject);
    }

    public function test_subject_format_replaces_queue_context(): void
    {
        $handler = $this->createHandler();
        $handler->setSubjectFormat('%context% — %message%');

        $record = $this->makeRecord('Error', extra: [
            'execution_context' => [
                'type' => 'queue',
                'queue' => 'emails',
            ],
        ]);
        $handler->handle($record);

        $this->assertStringContainsString('queue:emails', $handler->lastSubject);
    }

    public function test_subject_is_truncated_to_252_chars(): void
    {
        $handler = $this->createHandler();
        $handler->setSubjectFormat('%message%');

        $longMessage = str_repeat('A', 300);
        $record = $this->makeRecord($longMessage);
        $handler->handle($record);

        $this->assertLessThanOrEqual(255, strlen($handler->lastSubject));
    }

    public function test_sends_content_and_records(): void
    {
        $handler = $this->createHandler();

        $record = $this->makeRecord('Test message');
        $handler->handle($record);

        $this->assertCount(1, $handler->sentPayloads);
        $this->assertNotEmpty($handler->sentPayloads[0]['content']);
        $this->assertCount(1, $handler->sentPayloads[0]['records']);
    }

    public function test_respects_minimum_level(): void
    {
        $handler = new SubjectTestableHandler(Level::Error);

        $debugRecord = $this->makeRecord('Debug msg', Level::Debug);
        $errorRecord = $this->makeRecord('Error msg', Level::Error);

        $handler->handle($debugRecord);
        $handler->handle($errorRecord);

        $this->assertCount(1, $handler->sentPayloads);
    }
}

/**
 * Testable subclass that captures subject and sent data without mailing.
 */
class SubjectTestableHandler extends MailableHandler
{
    public ?string $lastSubject = null;
    public array $sentPayloads = [];
    protected string $testSubjectFormat = '[%level_name%] [%env%] %context% — %message%';

    public function __construct(Level $level = Level::Debug)
    {
        \Monolog\Handler\AbstractProcessingHandler::__construct($level, true);
        $this->subjectFormat = $this->testSubjectFormat;
        $this->throttle = null;
    }

    public function setSubjectFormat(string $format): void
    {
        $this->subjectFormat = $format;
        $this->testSubjectFormat = $format;
    }

    protected function send(string $content, array $records): void
    {
        $this->setSubject($records);
        $this->sentPayloads[] = ['content' => $content, 'records' => $records];
    }

    protected function setSubject(array $records): void
    {
        $record = $this->getHighestRecord($records);
        $extra = $record->extra ?? [];

        $replacements = [
            '%level_name%' => $record->level->getName(),
            '%message%' => $record->message,
            '%channel%' => $record->channel,
            '%datetime%' => $record->datetime->format('Y-m-d H:i:s'),
            '%env%' => $extra['environment']['app_env'] ?? '',
            '%app_name%' => '',
            '%context%' => $this->formatSubjectContext($extra['execution_context'] ?? []),
        ];

        $subject = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $this->subjectFormat
        );

        $subject = preg_replace('/\s+/', ' ', trim($subject));
        $this->lastSubject = \Illuminate\Support\Str::limit($subject, 252);
    }
}
