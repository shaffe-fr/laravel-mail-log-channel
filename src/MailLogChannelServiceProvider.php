<?php

namespace Shaffe\MailLogChannel;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use Shaffe\MailLogChannel\Console\TestMailLogCommand;

class MailLogChannelServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->scoped(QueryCollector::class);

        if ($this->app['log'] instanceof LogManager) {
            $this->app['log']->extend('mail', function ($app, array $config) {
                $logger = new MailLogger();

                return $logger($config);
            });
        }
    }

    /**
     * Boot the service provider.
     *
     * @return void
     */
    public function boot()
    {
        // Only listen for queries if the mail channel is configured.
        // This avoids unnecessary overhead when the package isn't actively used.
        if ($this->isMailChannelConfigured()) {
            $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $event) {
                $this->app->make(QueryCollector::class)->record($event);
            });
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                TestMailLogCommand::class,
            ]);
        }
    }

    /**
     * Determine if any logging channel uses the 'mail' driver.
     */
    protected function isMailChannelConfigured(): bool
    {
        $channels = $this->app['config']->get('logging.channels', []);

        foreach ($channels as $channel) {
            if (is_array($channel) && ($channel['driver'] ?? null) === 'mail') {
                // Check if query logging isn't explicitly disabled
                if (($channel['log_queries'] ?? true) !== false) {
                    return true;
                }
            }
        }

        return false;
    }
}
