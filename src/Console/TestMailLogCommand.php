<?php

namespace Shaffe\MailLogChannel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Shaffe\MailLogChannel\MailLogger;

class TestMailLogCommand extends Command
{
    protected $signature = 'mail-log:test
        {--level=error : The log level to test (debug, info, notice, warning, error, critical, alert, emergency)}
        {--channel= : The log channel to use (auto-detects the first channel using the mail driver if omitted)}
        {--with-query : Simulate database queries to test query logging}
        {--with-payload : Simulate a request payload with sensitive data}
        {--with-context : Add extra context data to the log record}
        {--all : Enable all simulations (queries, payload, context)}';

    protected $description = 'Send a test error email to verify the mail log channel configuration';

    public function handle(): int
    {
        $level = $this->option('level');
        $channel = $this->option('channel') ?: $this->resolveMailChannel();

        if (! $channel) {
            $this->error('No log channel using the "mail" driver found. Please specify one with --channel.');

            return self::FAILURE;
        }

        $validLevels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

        if (! in_array($level, $validLevels, true)) {
            $this->error("Invalid log level: {$level}. Valid levels: ".implode(', ', $validLevels));

            return self::FAILURE;
        }

        $all = $this->option('all');

        if ($all || $this->option('with-query')) {
            $this->simulateQueries();
        }

        if ($all || $this->option('with-payload')) {
            $this->simulateRequestPayload();
        }

        $context = [
            'exception' => new \RuntimeException(
                'Test exception from mail-log:test command — you can safely ignore this.',
                42
            ),
        ];

        if ($all || $this->option('with-context')) {
            $context = array_merge($context, $this->buildExtraContext());
        }

        $this->info("Sending test log message via [{$channel}] channel at [{$level}] level...");

        $channelConfig = config("logging.channels.{$channel}", []);

        try {
            $logger = app(MailLogger::class)($channelConfig);
        } catch (\Throwable $e) {
            $this->error("Failed to initialize channel [{$channel}]: {$e->getMessage()}");

            return self::FAILURE;
        }

        try {
            $logger->log($level, 'This is a test message from mail-log:test', $context);
        } catch (\Throwable $e) {
            $this->error("Failed to send test email: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Test email sent successfully. Check your inbox.');

        return self::SUCCESS;
    }

    /**
     * Fire real database queries so the QueryCollector captures them.
     */
    protected function simulateQueries(): void
    {
        $this->components->task('Simulating database queries', function () {
            try {
                // Simple queries that work on any database driver
                DB::select('SELECT 1 as test_connection');
                DB::select('SELECT ? as bound_param, ? as another_param', ['hello', 42]);
                DB::select("SELECT 'mail-log:test' as source, CURRENT_TIMESTAMP as executed_at");
            } catch (\Throwable) {
                // Database may not be available — that's fine, skip silently.
                return false;
            }

            return true;
        });
    }

    /**
     * Fake a request with payload data so the ContextProcessor captures it.
     */
    protected function simulateRequestPayload(): void
    {
        $this->components->task('Simulating request payload', function () {
            try {
                $request = request();

                // Merge fake form data including sensitive fields that should be redacted
                $request->merge([
                    'email' => 'test@example.com',
                    'name' => 'Test User',
                    'password' => 'super-secret-password',
                    'password_confirmation' => 'super-secret-password',
                    'card_number' => '4111111111111111',
                    'cvv' => '123',
                    'api_key' => 'sk_test_fake_key_12345',
                    'order' => [
                        'product_id' => 42,
                        'quantity' => 3,
                        'notes' => 'This is a test order from mail-log:test',
                    ],
                ]);

                return true;
            } catch (\Throwable) {
                return false;
            }
        });
    }

    /**
     * Build extra context data to enrich the log record.
     */
    protected function buildExtraContext(): array
    {
        return [
            'user_id' => 1,
            'action' => 'mail-log:test',
            'tags' => ['test', 'diagnostic'],
            'metadata' => [
                'triggered_at' => now()->toIso8601String(),
                'php_version' => PHP_VERSION,
                'os' => PHP_OS,
            ],
        ];
    }

    /**
     * Find the first logging channel configured with the 'mail' driver.
     */
    protected function resolveMailChannel(): ?string
    {
        $channels = config('logging.channels', []);

        foreach ($channels as $name => $config) {
            if (is_array($config) && ($config['driver'] ?? null) === 'mail') {
                return $name;
            }
        }

        return null;
    }
}
