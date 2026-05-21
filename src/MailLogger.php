<?php

namespace Shaffe\MailLogChannel;

use Illuminate\Mail\Mailable;
use InvalidArgumentException;
use Monolog\Level;
use Monolog\Logger;
use Shaffe\MailLogChannel\Mail\Log as MailableLog;
use Shaffe\MailLogChannel\Monolog\Formatters\HtmlFormatter;
use Shaffe\MailLogChannel\Monolog\Handlers\MailableHandler;
use Shaffe\MailLogChannel\Monolog\Processors\ContextProcessor;
use Shaffe\MailLogChannel\Throttle\ThrottleState;

class MailLogger
{
    /** @var array */
    protected $config = [];

    /**
     * Create a custom Monolog instance.
     *
     * @param  array  $config
     *
     * @return \Monolog\Logger
     */
    public function __invoke(array $config)
    {
        if (isset($config['level'])) {
            $config['level'] = Logger::toMonologLevel($config['level']);
        }

        $this->config = array_merge(
            ['level' => Logger::DEBUG, 'bubble' => true],
            $config
        );

        $mailHandler = new MailableHandler(
            $this->buildMailable(),
            $this->config('subject_format') ?? '[%level_name%] [%env%] %context% — %message%',
            $this->config('level'),
            $this->config('bubble'),
            $this->buildThrottle(),
            $this->buildLevelRecipients()
        );

        $collapseVendorFrames = $this->config('collapse_vendor_frames') ?? true;

        $mailHandler->setFormatter(new HtmlFormatter(collapseVendorFrames: $collapseVendorFrames));

        $queryCollector = null;

        if ($this->config('log_queries') ?? true) {
            $queryCollector = app(QueryCollector::class);
        }

        $mailHandler->pushProcessor(new ContextProcessor($queryCollector));

        $logger = new Logger('mailable', [$mailHandler]);

        return $logger;
    }

    /**
     * Create the mailable log.
     *
     * @return \Illuminate\Mail\Mailable
     */
    protected function buildMailable(): \Illuminate\Mail\Mailable
    {
        $mailableClass = $this->config('mailable') ?? MailableLog::class;
        /** @var \Illuminate\Mail\Mailable $mailable */
        $mailable = new $mailableClass();

        // When using level-based routing, recipients are set dynamically at send time.
        // We still need at least a default or level-specific recipient configured.
        if ($this->isLevelBasedRouting()) {
            $levelRecipients = $this->buildLevelRecipients();
            // Ensure at least one level has actual recipients
            $hasAnyRecipient = false;
            if ($levelRecipients) {
                foreach ($levelRecipients as $recipients) {
                    if (!empty($recipients)) {
                        $hasAnyRecipient = true;
                        break;
                    }
                }
            }
            if (!$hasAnyRecipient) {
                throw new InvalidArgumentException('"To" address is required. Please check the `to` driver\'s logging config.');
            }
        } else {
            if (! ($recipients = $this->buildRecipients())) {
                throw new InvalidArgumentException('"To" address is required. Please check the `to` driver\'s logging config.');
            }
            $mailable->to($recipients);
        }

        if (! $this->defaultFromAddress() && ! isset($this->config('from')['address'])) {
            throw new InvalidArgumentException('"From" address is required. Please check the `from.address` driver\'s config and the `mail.from.address` config.');
        }

        $mailable->from(
            $this->config('from')['address'] ?? $this->defaultFromAddress(),
            $this->config('from')['name'] ?? $this->defaultFromName()
        );

        return $mailable;
    }

    protected function buildRecipients(): array
    {
        if (! ($to = $this->config('to'))) {
            return [];
        }

        return $this->parseRecipients((array) $to);
    }

    /**
     * Get the default from address.
     *
     * @return string|null
     */
    protected function defaultFromAddress(): ?string
    {
        return config('mail.from.address');
    }

    /**
     * Get the default from name.
     *
     * @return string|null
     */
    protected function defaultFromName(): ?string
    {
        return config('mail.from.name');
    }

    /**
     * Parse a recipient list into a normalized array of ['email' => ..., 'name' => ...].
     *
     * Supports:
     * - Plain strings: ['admin@example.com']
     * - Named format: ['admin@example.com' => 'Admin']
     * - Structured format: [['email' => '...', 'name' => '...'] or ['address' => '...', 'name' => '...']]
     *
     * @param  array  $items
     * @return array<int, array{email: string, name: string|null}>
     */
    protected function parseRecipients(array $items): array
    {
        $recipients = [];

        foreach ($items as $emailOrIndex => $nameOrEmail) {
            if (is_array($nameOrEmail)) {
                $email = $nameOrEmail['email'] ?? $nameOrEmail['address'] ?? null;
                if ($email) {
                    $recipients[] = [
                        'email' => $email,
                        'name' => $nameOrEmail['name'] ?? null,
                    ];
                }
            } elseif (is_string($emailOrIndex)) {
                $recipients[] = [
                    'email' => $emailOrIndex,
                    'name' => $nameOrEmail,
                ];
            } elseif (is_string($nameOrEmail)) {
                $recipients[] = [
                    'email' => $nameOrEmail,
                    'name' => null,
                ];
            }
        }

        return $recipients;
    }

    /**
     * Build the throttle instance if configured.
     */
    protected function buildThrottle(): ?ThrottleState
    {
        $ttl = $this->config('throttle');

        // Default: enabled with 60 seconds TTL
        if ($ttl === null) {
            $ttl = 60;
        }

        // Explicitly disabled
        if ($ttl === 0 || $ttl === false) {
            return null;
        }

        $cache = app('cache')->store(
            $this->config('throttle_cache_store')
        );

        return new ThrottleState($cache, (int) $ttl);
    }

    /**
     * Determine if the `to` config uses level-based routing.
     *
     * Level-based routing is detected when `to` is an associative array
     * where at least one key matches a Monolog level name, a Monolog Level enum,
     * a numeric level value, or 'default'.
     */
    protected function isLevelBasedRouting(): bool
    {
        $to = $this->config('to');

        if (!is_array($to)) {
            return false;
        }

        foreach (array_keys($to) as $key) {
            if ($this->normalizeLevelKey($key) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the level-based recipients map.
     *
     * Returns null if not using level-based routing.
     * Returns an array mapping level names to recipient arrays.
     * A level explicitly set to null or '' will map to an empty array,
     * which suppresses email sending for that level (overrides 'default').
     *
     * Keys can be:
     * - String level names: 'error', 'critical', 'default'
     * - Monolog Level enum values: Level::Error, Level::Critical
     * - Numeric level values: 400, 500
     *
     * @return array<string, array>|null
     */
    protected function buildLevelRecipients(): ?array
    {
        if (!$this->isLevelBasedRouting()) {
            return null;
        }

        $to = $this->config('to');
        $result = [];

        foreach ($to as $key => $value) {
            $normalizedKey = $this->normalizeLevelKey($key);

            if ($normalizedKey === null) {
                continue;
            }

            // Explicitly disabled level: null or empty string means "don't send"
            if ($value === null || $value === '' || $value === false) {
                $result[$normalizedKey] = [];
                continue;
            }

            $result[$normalizedKey] = $this->parseRecipients((array) $value);
        }

        return !empty($result) ? $result : null;
    }

    /**
     * Normalize a level key to a lowercase level name string.
     *
     * Accepts:
     * - 'default' → 'default'
     * - String level names: 'error', 'Error', 'ERROR' → 'error'
     * - Monolog Level enum: Level::Error → 'error'
     * - Numeric level values: 400 → 'error'
     *
     * Returns null if the key is not a recognized level.
     */
    protected function normalizeLevelKey(mixed $key): ?string
    {
        if ($key instanceof Level) {
            return strtolower($key->getName());
        }

        if (is_int($key)) {
            $level = Level::tryFrom($key);
            return $level ? strtolower($level->getName()) : null;
        }

        if (is_string($key)) {
            $lower = strtolower($key);
            $levelNames = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'default'];

            if (in_array($lower, $levelNames, true)) {
                return $lower;
            }
        }

        return null;
    }

    /**
     * Get the value from the passed in config.
     *
     * @param  string  $field
     *
     * @return mixed
     */
    private function config(string $field)
    {
        return $this->config[$field] ?? null;
    }
}
