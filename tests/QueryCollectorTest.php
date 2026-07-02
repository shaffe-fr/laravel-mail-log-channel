<?php

namespace Shaffe\MailLogChannel\Tests;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use PHPUnit\Framework\TestCase;
use Shaffe\MailLogChannel\QueryCollector;

class QueryCollectorTest extends TestCase
{
    protected function makeQueryEvent(string $sql = 'SELECT 1', array $bindings = [], float $time = 1.0): QueryExecuted
    {
        return new QueryExecuted(
            $sql,
            $bindings,
            $time,
            $this->createMock(Connection::class)
        );
    }

    public function test_records_a_query(): void
    {
        $collector = new QueryCollector();

        $collector->record($this->makeQueryEvent('SELECT * FROM users'));

        $queries = $collector->getQueries();
        $this->assertCount(1, $queries);
        $this->assertEquals('SELECT * FROM users', $queries[0]['sql']);
    }

    public function test_records_query_time(): void
    {
        $collector = new QueryCollector();

        $collector->record($this->makeQueryEvent('SELECT 1', [], 15.3));

        $this->assertEquals(15.3, $collector->getQueries()[0]['time']);
    }

    public function test_records_query_bindings(): void
    {
        $collector = new QueryCollector();

        $collector->record($this->makeQueryEvent('SELECT * FROM users WHERE id = ?', [42]));

        $this->assertEquals([42], $collector->getQueries()[0]['bindings']);
    }

    public function test_tracks_total_count(): void
    {
        $collector = new QueryCollector();

        $collector->record($this->makeQueryEvent('SELECT 1'));
        $collector->record($this->makeQueryEvent('SELECT 2'));
        $collector->record($this->makeQueryEvent('SELECT 3'));

        $this->assertEquals(3, $collector->getTotal());
    }

    public function test_respects_limit_and_keeps_latest_queries(): void
    {
        $collector = new QueryCollector(limit: 3);

        $collector->record($this->makeQueryEvent('SELECT 1'));
        $collector->record($this->makeQueryEvent('SELECT 2'));
        $collector->record($this->makeQueryEvent('SELECT 3'));
        $collector->record($this->makeQueryEvent('SELECT 4'));
        $collector->record($this->makeQueryEvent('SELECT 5'));

        $queries = $collector->getQueries();
        $this->assertCount(3, $queries);
        $this->assertEquals('SELECT 3', $queries[0]['sql']);
        $this->assertEquals('SELECT 4', $queries[1]['sql']);
        $this->assertEquals('SELECT 5', $queries[2]['sql']);
        $this->assertEquals(5, $collector->getTotal());
    }

    public function test_default_limit_is_ten(): void
    {
        $collector = new QueryCollector();

        for ($i = 1; $i <= 15; $i++) {
            $collector->record($this->makeQueryEvent("SELECT $i"));
        }

        $this->assertCount(10, $collector->getQueries());
        $this->assertEquals(15, $collector->getTotal());
        $this->assertEquals('SELECT 6', $collector->getQueries()[0]['sql']);
    }

    public function test_returns_empty_array_when_no_queries(): void
    {
        $collector = new QueryCollector();

        $this->assertEmpty($collector->getQueries());
        $this->assertEquals(0, $collector->getTotal());
    }

    public function test_normalizes_datetime_bindings(): void
    {
        $collector = new QueryCollector();

        $date = new \DateTime('2026-06-22 23:59:59');
        $collector->record($this->makeQueryEvent('SELECT * FROM t WHERE d = ?', [$date]));

        $this->assertEquals('2026-06-22 23:59:59', $collector->getQueries()[0]['bindings'][0]);
    }

    public function test_normalizes_carbon_bindings(): void
    {
        $collector = new QueryCollector();

        // Carbon extends DateTime, so this covers the real-world case.
        $carbon = \Carbon\Carbon::parse('2026-06-22 23:59:59', 'Europe/Paris');
        $collector->record($this->makeQueryEvent('SELECT * FROM t WHERE ends_at <= ?', [$carbon]));

        $this->assertEquals('2026-06-22 23:59:59', $collector->getQueries()[0]['bindings'][0]);
    }

    public function test_normalizes_unknown_object_bindings(): void
    {
        // Objects that are not DateTimeInterface are left as-is, mirroring
        // what Laravel's Connection::prepareBindings() does. PDO would call
        // __toString() on them if available, or throw — that's an app-level bug.
        $collector = new QueryCollector();

        $obj = new \stdClass();
        $collector->record($this->makeQueryEvent('SELECT 1 WHERE x = ?', [$obj]));

        $this->assertSame($obj, $collector->getQueries()[0]['bindings'][0]);
    }

    public function test_preserves_scalar_bindings(): void
    {
        $collector = new QueryCollector();

        $collector->record($this->makeQueryEvent('SELECT 1', [42, 'foo', null, 3.14]));

        $this->assertEquals([42, 'foo', null, 3.14], $collector->getQueries()[0]['bindings']);
    }

    public function test_normalizes_bool_bindings_to_int(): void
    {
        $collector = new QueryCollector();

        $collector->record($this->makeQueryEvent('SELECT 1 WHERE active = ? AND deleted = ?', [true, false]));

        $this->assertSame([1, 0], $collector->getQueries()[0]['bindings']);
    }

    public function test_pause_stops_recording(): void
    {
        $collector = new QueryCollector();

        $collector->record($this->makeQueryEvent('SELECT 1'));
        $collector->pause();
        $collector->record($this->makeQueryEvent('SELECT 2'));
        $collector->resume();
        $collector->record($this->makeQueryEvent('SELECT 3'));

        $queries = $collector->getQueries();
        $this->assertCount(2, $queries);
        $this->assertEquals('SELECT 1', $queries[0]['sql']);
        $this->assertEquals('SELECT 3', $queries[1]['sql']);
        $this->assertEquals(2, $collector->getTotal());
    }
}
