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
        $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $event) {
            $this->app->make(QueryCollector::class)->record($event);
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                TestMailLogCommand::class,
            ]);
        }
    }
}
