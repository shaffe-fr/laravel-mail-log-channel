<?php

namespace Shaffe\MailLogChannel;

use Illuminate\Database\Events\QueryExecuted;

class QueryCollector
{
    protected array $queries = [];

    protected int $limit;

    public function __construct(int $limit = 10)
    {
        $this->limit = $limit;
    }

    public function record(QueryExecuted $event): void
    {
        $this->queries[] = [
            'sql' => $event->sql,
            'time' => $event->time,
            'bindings' => $event->bindings,
        ];

        if (count($this->queries) > $this->limit) {
            array_shift($this->queries);
        }
    }

    public function getQueries(): array
    {
        return $this->queries;
    }
}
