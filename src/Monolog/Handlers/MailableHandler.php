<?php

namespace Shaffe\MailLogChannel\Monolog\Handlers;

use Illuminate\Mail\Mailable;
use Monolog\Handler\MailHandler;
use Monolog\LogRecord;
use Monolog\Logger;
use Illuminate\Support\Str;
use Shaffe\MailLogChannel\Throttle\ThrottleState;

class MailableHandler extends MailHandler
{
    /** @var \Illuminate\Mail\Mailable */
    protected $mailable;

    /** @var \Illuminate\Contracts\Mail\Mailer */
    protected $mailer;

    /** @var string */
    protected $subjectFormat;

    /** @var \Shaffe\MailLogChannel\Throttle\ThrottleState|null */
    protected $throttle;

    /**
     * Create the mailable handler.
     *
     * @param  \Illuminate\Mail\Mailable  $mailable
     * @param  string  $subjectFormat
     * @param  int|string|\Monolog\Level  $level  The minimum logging level at which this handler will be triggered
     * @param  bool  $bubble  Whether the messages that are handled can bubble up the stack or not
     * @param  \Shaffe\MailLogChannel\Throttle\ThrottleState|null  $throttle
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function __construct(
        Mailable $mailable,
        string $subjectFormat = '[%level_name%] [%env%] %context% — %message%',
        $level = Logger::DEBUG,
        bool $bubble = true,
        ?ThrottleState $throttle = null
    ) {
        parent::__construct($level, $bubble);
        $this->mailable = $mailable;
        $this->subjectFormat = $subjectFormat;
        $this->mailer = app()->make('mailer');
        $this->throttle = $throttle;
    }

    /**
     * {@inheritdoc}
     */
    protected function write(LogRecord $record): void
    {
        if ($this->throttle && $this->throttle->isThrottled($record)) {
            return;
        }

        parent::write($record);
    }

    /**
     * Set the subject.
     *
     * @param  array  $records
     *
     * @return void
     */
    protected function setSubject(array $records)
    {
        $record = $this->getHighestRecord($records);
        $extra = $record->extra ?? [];

        $replacements = [
            '%level_name%' => $record->level->getName(),
            '%message%' => $record->message,
            '%channel%' => $record->channel,
            '%datetime%' => $record->datetime->format('Y-m-d H:i:s'),
            '%env%' => $extra['environment']['app_env'] ?? '',
            '%app_name%' => $this->resolveAppName(),
            '%context%' => $this->formatSubjectContext($extra['execution_context'] ?? []),
        ];

        $subject = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $this->subjectFormat
        );

        // Clean up double spaces from empty placeholders
        $subject = preg_replace('/\s+/', ' ', trim($subject));

        $this->mailable->subject(Str::limit($subject, 252));
    }

    protected function formatSubjectContext(array $ctx): string
    {
        $type = $ctx['type'] ?? null;

        if ($type === 'http') {
            $method = $ctx['method'] ?? '';
            $url = $ctx['url'] ?? '';
            $path = parse_url($url, PHP_URL_PATH) ?: '/';
            return $method ? $method . ' ' . $path : '';
        }

        if ($type === 'console') {
            $command = $ctx['command'] ?? '';
            if ($command) {
                return preg_replace('/^(php\s+)?artisan\s+/', '', $command);
            }
            return '';
        }

        if ($type === 'queue') {
            $queue = $ctx['queue'] ?? 'default';
            return 'queue:' . $queue;
        }

        return '';
    }

    protected function resolveAppName(): string
    {
        try {
            return config('app.name', '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function send(string $content, array $records): void
    {
        // Inject occurrence count and first seen timestamp if throttle is active
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

                // Reformat with the updated record
                $content = (string) $this->getFormatter()->formatBatch($records);
            }
        }

        $this->mailable->with(
            [
                'content' => $content,
                'records' => $records,
            ]
        );

        $this->setSubject($records);

        $this->mailer->send($this->mailable);
    }
}
