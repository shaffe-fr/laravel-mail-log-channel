<?php

namespace Shaffe\MailLogChannel;

use Illuminate\Database\Events\QueryExecuted;

class QueryCollector
{
    protected array $queries = [];

    protected int $limit;

    protected int $total = 0;

    public function __construct(int $limit = 10)
    {
        $this->limit = $limit;
    }

    public function record(QueryExecuted $event): void
    {
        $this->total++;

        $this->queries[] = [
            'sql' => $event->sql,
            'time' => $event->time,
            'bindings' => $event->bindings,
        ];

        if (count($this->queries) > $this->limit) {
            array_shift($this->queries);
        }
    }

    /**
     * Reset the collector state.
     *
     * Useful for long-running processes (queue workers) to prevent
     * unbounded growth of the total counter between jobs.
     */
    public function reset(): void
    {
        $this->queries = [];
        $this->total = 0;
    }

    public function getQueries(): array
    {
        return $this->queries;
    }

    public function getTotal(): int
    {
        return $this->total;
    }
}
