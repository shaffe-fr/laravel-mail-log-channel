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
     *
     * Level-based suppression and throttling are evaluated here, before the
     * record is passed to the parent handler. This is deliberate: Monolog runs
     * the attached processors inside handle() (right before write()), and those
     * processors do expensive work (reading source files for the code snippet,
     * copying and redacting the request payload, collecting SQL). Filtering out
     * suppressed and throttled records up front avoids paying that cost for
     * records that will never produce an email.
     */
    public function handle(LogRecord $record): bool
    {
        if (! $this->isHandling($record)) {
            return false;
        }

        if ($this->shouldSuppressForLevel($record)) {
            return $this->bubble === false;
        }

        if ($this->throttle && $this->throttle->isThrottled($record)) {
            return $this->bubble === false;
        }

        return parent::handle($record);
    }

    /**
     * Determine whether the record should be suppressed by level-based routing.
     */
    protected function shouldSuppressForLevel(LogRecord $record): bool
    {
        if ($this->levelRecipients === null) {
            return false;
        }

        $levelName = strtolower($record->level->getName());

        // Level explicitly set to empty (null/'') — suppress email
        if (array_key_exists($levelName, $this->levelRecipients) && empty($this->levelRecipients[$levelName])) {
            return true;
        }

        // No explicit config for this level and no default — skip
        if (! array_key_exists($levelName, $this->levelRecipients) && ! array_key_exists('default', $this->levelRecipients)) {
            return true;
        }

        // Default is explicitly empty — suppress
        if (! array_key_exists($levelName, $this->levelRecipients)
            && array_key_exists('default', $this->levelRecipients)
            && empty($this->levelRecipients['default'])) {
            return true;
        }

        return false;
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

        // Clone the mailable to prevent state leaking between sends.
        // Use serialization for a deep clone to avoid shared references
        // on complex custom mailables with nested objects.
        try {
            $mailable = unserialize(serialize($this->mailable));
        } catch (\Throwable $e) {
            // Serialization failed (e.g. the mailable holds a closure or a
            // resource). Fall back to a shallow clone, but warn: nested objects
            // are then shared between sends, which reintroduces the state-leak
            // risk this deep clone is meant to prevent.
            error_log(sprintf(
                '[laravel-mail-log-channel] Deep clone of mailable %s failed (%s); '
                .'falling back to a shallow clone. Nested state may leak between sends.',
                get_class($this->mailable),
                $e->getMessage()
            ));
            $mailable = clone $this->mailable;
        }

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
