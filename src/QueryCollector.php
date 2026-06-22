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

    /**
     * Set the maximum number of queries to keep.
     */
    public function setLimit(int $limit): void
    {
        $this->limit = $limit;
    }

    public function record(QueryExecuted $event): void
    {
        $this->total++;

        $this->queries[] = [
            'sql' => $event->sql,
            'time' => $event->time,
            'bindings' => $this->normalizeBindings($event->bindings),
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

    /**
     * Normalize query bindings to scalar-safe values.
     *
     * Mirrors what Laravel's Connection::prepareBindings() does before sending
     * values to PDO, so the displayed bindings match what the database actually
     * receives:
     *   - DateTimeInterface (Carbon, DateTime, …) → 'Y-m-d H:i:s' string
     *   - bool → int  (PDO receives 0/1, not true/false)
     *   - everything else is left untouched
     *
     * @param  array<int|string, mixed>  $bindings
     * @return array<int|string, mixed>
     */
    protected function normalizeBindings(array $bindings): array
    {
        return array_map(function (mixed $value): mixed {
            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d H:i:s');
            }

            if (is_bool($value)) {
                return (int) $value;
            }

            return $value;
        }, $bindings);
    }
}
