<?php

namespace Shaffe\MailLogChannel\Console;

use Illuminate\Console\Command;
use Shaffe\MailLogChannel\MailLogger;

class TestMailLogCommand extends Command
{
    protected $signature = 'mail-log:test
        {--level=error : The log level to test (debug, info, notice, warning, error, critical, alert, emergency)}
        {--channel= : The log channel to use (auto-detects the first channel using the mail driver if omitted)}';

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

        $this->info("Sending test log message via [{$channel}] channel at [{$level}] level...");

        $channelConfig = config("logging.channels.{$channel}", []);

        // Instantiate the logger directly instead of going through Log::channel().
        // Laravel's LogManager swallows exceptions during channel resolution and
        // falls back to the emergency logger — making errors invisible here.
        try {
            $logger = app(MailLogger::class)($channelConfig);
        } catch (\Throwable $e) {
            $this->error("Failed to initialize channel [{$channel}]: {$e->getMessage()}");

            return self::FAILURE;
        }

        try {
            $logger->log($level, 'This is a test message from mail-log:test', [
                'exception' => new \RuntimeException(
                    'Test exception from mail-log:test command — you can safely ignore this.',
                    42
                ),
            ]);
        } catch (\Throwable $e) {
            $this->error("Failed to send test email: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Test email sent successfully. Check your inbox.');

        return self::SUCCESS;
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
