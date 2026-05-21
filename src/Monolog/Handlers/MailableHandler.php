<?php

namespace Shaffe\MailLogChannel\Monolog\Handlers;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Str;
use Monolog\Handler\MailHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Shaffe\MailLogChannel\Throttle\ThrottleState;

class MailableHandler extends MailHandler
{
    /** @var \Illuminate\Mail\Mailable */
    protected $mailable;

    /** @var string */
    protected $subjectFormat;

    /** @var \Shaffe\MailLogChannel\Throttle\ThrottleState|null */
    protected $throttle;

    /**
     * Level-based recipient routing configuration.
     *
     * When null, the mailable's original recipients are used (legacy behavior).
     * When set, it maps level names to recipient arrays, with an optional 'default' key.
     *
     * @var array<string, array>|null
     */
    protected ?array $levelRecipients = null;

    /**
     * Create the mailable handler.
     *
     * @param  int|string|\Monolog\Level  $level  The minimum logging level at which this handler will be triggered
     * @param  bool  $bubble  Whether the messages that are handled can bubble up the stack or not
     * @param  array<string, array>|null  $levelRecipients
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function __construct(
        Mailable $mailable,
        string $subjectFormat = '[%level_name%] [%env%] %context% — %message%',
        $level = Logger::DEBUG,
        bool $bubble = true,
        ?ThrottleState $throttle = null,
        ?array $levelRecipients = null
    ) {
        parent::__construct($level, $bubble);
        $this->mailable = $mailable;
        $this->subjectFormat = $subjectFormat;
        $this->throttle = $throttle;
        $this->levelRecipients = $levelRecipients;
    }

    /**
     * {@inheritdoc}
     */
    protected function write(LogRecord $record): void
    {
        // If level-based routing is active, check if this level has recipients
        if ($this->levelRecipients !== null) {
            $levelName = strtolower($record->level->getName());

            // Level explicitly set to empty (null/'') — suppress email
            if (array_key_exists($levelName, $this->levelRecipients) && empty($this->levelRecipients[$levelName])) {
                return;
            }

            // No explicit config for this level and no default — skip
            if (! array_key_exists($levelName, $this->levelRecipients) && ! array_key_exists('default', $this->levelRecipients)) {
                return;
            }

            // Default is explicitly empty — suppress
            if (! array_key_exists($levelName, $this->levelRecipients)
                && array_key_exists('default', $this->levelRecipients)
                && empty($this->levelRecipients['default'])) {
                return;
            }
        }

        if ($this->throttle && $this->throttle->isThrottled($record)) {
            return;
        }

        parent::write($record);
    }

    /**
     * Set the subject on the given mailable.
     *
     *
     * @return void
     */
    protected function setSubjectOn(Mailable $mailable, array $records)
    {
        $record = $this->getHighestRecord($records);
        $extra = $record->extra ?? []; /** @phpstan-ignore nullCoalesce.property */
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

        $mailable->subject(Str::limit($subject, 252));
    }

    /**
     * @deprecated Use setSubjectOn() instead. Kept for backward compatibility with test subclasses.
     */
    protected function setSubject(array $records)
    {
        $this->setSubjectOn($this->mailable, $records);
    }

    protected function formatSubjectContext(array $ctx): string
    {
        $type = $ctx['type'] ?? null;

        if ($type === 'http') {
            $method = $ctx['method'] ?? '';
            $url = $ctx['url'] ?? '';
            $path = parse_url($url, PHP_URL_PATH) ?: '/';

            return $method ? $method.' '.$path : '';
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

            return 'queue:'.$queue;
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
        if ($this->throttle && ! empty($records)) { /** @phpstan-ignore empty.variable */
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

        // Clone the mailable to prevent state leaking between sends
        $mailable = clone $this->mailable;

        $mailable->with(
            [
                'content' => $content,
                'records' => $records,
            ]
        );

        $this->setSubjectOn($mailable, $records);

        // Apply level-based recipients if configured
        if ($this->levelRecipients !== null) {
            $recipients = $this->resolveRecipientsForRecords($records);
            if (empty($recipients)) {
                return;
            }
            $mailable->to($recipients);
        }

        $this->resolveMailer()->send($mailable);
    }

    /**
     * Resolve the mailer instance lazily from the container.
     */
    protected function resolveMailer(): \Illuminate\Contracts\Mail\Mailer
    {
        return app('mailer');
    }

    /**
     * Resolve recipients based on the highest log level in the records.
     */
    protected function resolveRecipientsForRecords(array $records): array
    {
        $record = $this->getHighestRecord($records);
        $levelName = strtolower($record->level->getName());

        return $this->levelRecipients[$levelName]
            ?? $this->levelRecipients['default']
            ?? [];
    }
}
