<?php

namespace LaraBug\Tests;

use RuntimeException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LaraBug\Requests\Sampler;
use LaraBug\Requests\QueryPlan;
use LaraBug\Requests\RequestBuffer;
use LaraBug\Requests\QueryExplainer;
use LaraBug\Requests\RequestMonitor;
use LaraBug\Requests\RequestListeners;
use LaraBug\Tests\Mocks\LaraBugClient;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Database\Events\QueryExecuted;
use LaraBug\Tests\Mocks\ExplainingConnection;
use PHPUnit\Framework\Attributes\DataProvider;

class QueryExplainTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config([
            'larabug.requests.sample_rate' => 1.0,
            'cache.default' => 'array',
        ]);
    }

    #[Test]
    public function it_explains_nothing_by_default()
    {
        $connection = $this->connection('mysql');
        $monitor = $this->sampledRequest();

        $this->runQuery($connection, 'select * from orders where status = ?', ['open']);

        $queries = $this->record($monitor)['sql'];

        $this->assertSame([], $connection->explained);
        $this->assertArrayNotHasKey('plan', $queries[0]);
    }

    #[Test]
    public function it_attaches_the_plan_of_a_slow_select_to_its_query()
    {
        $this->enableExplain();

        $connection = $this->connection('mysql', 'mysql-full-scan');
        $monitor = $this->sampledRequest();

        $this->runQuery($connection, 'select * from users join orders on orders.user_id = users.id where orders.status = ?', ['s2']);

        $query = $this->record($monitor)['sql'][0];

        $this->assertSame([[
            'query' => 'EXPLAIN select * from users join orders on orders.user_id = users.id where orders.status = ?',
            'bindings' => ['s2'],
        ]], $connection->explained);

        $this->assertSame([
            'driver' => 'mysql',
            'full_scan' => true,
            'table' => 'orders',
            'rows_estimate' => 2000,
            'key' => null,
            'possible_keys' => 'orders_user_id_index',
        ], array_diff_key($query['plan'], ['explained_at' => true]));

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $query['plan']['explained_at']);
    }

    #[Test]
    public function it_skips_a_select_faster_than_the_threshold()
    {
        $this->enableExplain();
        config(['larabug.queries.explain.min_duration_ms' => 500]);

        $connection = $this->connection('mysql', 'mysql-indexed');
        $monitor = $this->sampledRequest();

        $this->runQuery($connection, 'select * from orders', time: 499.9);
        $this->runQuery($connection, 'select * from users', time: 500.0);

        $queries = $this->record($monitor)['sql'];

        $this->assertSame(['EXPLAIN select * from users'], array_column($connection->explained, 'query'));
        $this->assertArrayNotHasKey('plan', $queries[0]);
        $this->assertArrayHasKey('plan', $queries[1]);
    }

    #[Test]
    #[DataProvider('statementsThatAreNotAPlainSelect')]
    public function it_only_explains_a_single_read_only_select(string $sql)
    {
        $this->enableExplain();

        $connection = $this->connection('mysql');
        $monitor = $this->sampledRequest();

        $this->runQuery($connection, $sql);

        $this->record($monitor);

        $this->assertSame([], $connection->explained);
    }

    public static function statementsThatAreNotAPlainSelect(): array
    {
        return [
            'insert' => ['insert into orders (status) values (?)'],
            'update' => ['update orders set status = ? where id = ?'],
            'delete' => ['delete from orders where id = ?'],
            'locking read' => ['select * from orders where id = ? for update'],
            'shared locking read' => ['select * from orders where id = ? for share'],
            'mysql shared lock' => ['select * from orders where id = ? lock in share mode'],
            'write in a cte' => ['with gone as (delete from orders returning id) select * from gone'],
            'select into' => ['select * into archived_orders from orders'],
            'two statements' => ['select * from orders; drop table orders'],
            'explain itself' => ['explain select * from orders'],
            'comment hiding a write' => ['/* select */ delete from orders'],
        ];
    }

    #[Test]
    public function it_explains_a_select_behind_comments_and_a_read_only_cte()
    {
        $this->enableExplain();

        $connection = $this->connection('mysql');
        $monitor = $this->sampledRequest();

        $this->runQuery($connection, "/* controller:orders */\n-- dashboard\n select * from orders;");
        $this->runQuery($connection, 'with recent as (select * from orders) select * from recent');

        $this->record($monitor);

        $this->assertSame([
            "EXPLAIN /* controller:orders */\n-- dashboard\n select * from orders",
            'EXPLAIN with recent as (select * from orders) select * from recent',
        ], array_column($connection->explained, 'query'));
    }

    #[Test]
    public function it_only_explains_on_mysql_mariadb_and_postgres()
    {
        $this->enableExplain();

        $sqlite = $this->connection('sqlite');
        $sqlsrv = $this->connection('sqlsrv');
        $mariadb = $this->connection('mariadb', 'mariadb-full-scan');
        $monitor = $this->sampledRequest();

        $this->runQuery($sqlite, 'select * from orders');
        $this->runQuery($sqlsrv, 'select * from orders');
        $this->runQuery($mariadb, 'select * from orders');

        $this->record($monitor);

        $this->assertSame([], $sqlite->explained);
        $this->assertSame([], $sqlsrv->explained);
        $this->assertCount(1, $mariadb->explained);
    }

    #[Test]
    public function it_never_explains_inside_pretend()
    {
        $this->enableExplain();

        $connection = $this->connection('mysql');
        $monitor = $this->sampledRequest();

        $connection->pretend(fn () => $this->runQuery($connection, 'select * from orders'));

        $this->record($monitor);

        $this->assertSame([], $connection->explained);
    }

    #[Test]
    public function its_own_explain_is_neither_recorded_nor_explained()
    {
        $this->enableExplain();

        $connection = $this->connection('mysql', 'mysql-full-scan');
        $monitor = $this->sampledRequest();

        $this->runQuery($connection, 'select * from orders');

        $record = $this->record($monitor);

        $this->assertCount(1, $connection->explained);
        $this->assertSame(1, $record['queries']);
        $this->assertSame(['select * from orders'], array_column($record['sql'], 'sql'));
        $this->assertSame([], $record['cache']);
    }

    #[Test]
    public function it_explains_a_statement_once_per_request()
    {
        $this->enableExplain();

        $connection = $this->connection('mysql', 'mysql-full-scan');
        $monitor = $this->sampledRequest();

        $this->runQuery($connection, 'select * from orders where id = ?', [1]);
        $this->runQuery($connection, 'select * from orders where id = ?', [2]);

        $queries = $this->record($monitor)['sql'];

        $this->assertSame([[1]], array_column($connection->explained, 'bindings'));
        $this->assertArrayHasKey('plan', $queries[0]);
        $this->assertArrayNotHasKey('plan', $queries[1]);
    }

    #[Test]
    public function the_interval_holds_across_processes_through_the_cache()
    {
        $this->enableExplain();
        config(['larabug.queries.explain.interval' => 600]);

        $connection = $this->connection('mysql', 'mysql-full-scan');

        foreach (range(1, 2) as $process) {
            $monitor = $this->sampledRequest();
            $this->runQuery($connection, 'select * from orders');
            $this->record($monitor);
        }

        $this->assertCount(1, $connection->explained);

        $this->travel(601)->seconds();

        $monitor = $this->sampledRequest();
        $this->runQuery($connection, 'select * from orders');
        $this->record($monitor);

        $this->assertCount(2, $connection->explained);
    }

    #[Test]
    public function the_interval_holds_within_a_process_without_a_cache_store()
    {
        $this->enableExplain();
        config([
            'larabug.queries.explain.interval' => 600,
            'cache.default' => null,
        ]);

        $connection = $this->connection('mysql', 'mysql-full-scan');
        $monitor = $this->sampledRequest();

        $this->runQuery($connection, 'select * from orders');
        $this->record($monitor);

        $this->runQuery($connection, 'select * from orders');
        $this->record($monitor);

        $this->assertCount(1, $connection->explained);

        $this->travel(601)->seconds();

        $this->runQuery($connection, 'select * from orders');
        $this->record($monitor);

        $this->assertCount(2, $connection->explained);
    }

    #[Test]
    public function the_interval_is_per_statement()
    {
        $this->enableExplain();

        $connection = $this->connection('mysql', 'mysql-full-scan');

        $monitor = $this->sampledRequest();
        $this->runQuery($connection, 'select * from orders');
        $this->record($monitor);

        $monitor = $this->sampledRequest();
        $this->runQuery($connection, 'select * from users');
        $this->record($monitor);

        $this->assertCount(2, $connection->explained);
    }

    #[Test]
    public function a_failing_explain_is_swallowed_and_reported_as_a_buffer_error()
    {
        $this->enableExplain();
        config(['larabug.jobs.report_buffer_errors' => true]);

        $client = new LaraBugClient('login', 'project');
        $this->app->instance(RequestBuffer::class, new RequestBuffer($client, config('larabug')));

        $connection = $this->connection('mysql');
        $connection->failure = new RuntimeException('EXPLAIN denied');
        $monitor = $this->sampledRequest();

        $this->runQuery($connection, 'select * from orders');

        $queries = $this->record($monitor)['sql'];

        $this->assertCount(1, $queries);
        $this->assertArrayNotHasKey('plan', $queries[0]);

        $client->assertRequestsSent(1);
        $this->assertSame('buffer_error', $client->requests()[0]['type']);
        $this->assertSame(0, $client->requests()[0]['exception']['lost_events']);
    }

    #[Test]
    public function a_failing_explain_is_silent_without_buffer_error_reporting()
    {
        $this->enableExplain();

        $client = new LaraBugClient('login', 'project');
        $this->app->instance(RequestBuffer::class, new RequestBuffer($client, config('larabug')));

        $connection = $this->connection('pgsql');
        $connection->failure = new RuntimeException('EXPLAIN denied');
        $monitor = $this->sampledRequest();

        $this->runQuery($connection, 'select * from orders');

        $this->assertArrayNotHasKey('plan', $this->record($monitor)['sql'][0]);
        $client->assertRequestsSent(0);
    }

    #[Test]
    public function it_asks_postgres_for_a_json_plan()
    {
        $this->enableExplain();

        $connection = $this->connection('pgsql', 'pgsql-full-scan');
        $monitor = $this->sampledRequest();

        $this->runQuery($connection, 'select * from orders where status = ?', ['s2']);

        $plan = $this->record($monitor)['sql'][0]['plan'];

        $this->assertSame('EXPLAIN (FORMAT JSON) select * from orders where status = ?', $connection->explained[0]['query']);
        $this->assertSame('pgsql', $plan['driver']);
        $this->assertTrue($plan['full_scan']);
    }

    #[Test]
    public function it_caps_a_mariadb_explain_at_one_second()
    {
        $this->enableExplain();

        $connection = $this->connection('mariadb', 'mariadb-full-scan');
        $monitor = $this->sampledRequest();

        $this->runQuery($connection, 'select * from orders');
        $this->record($monitor);

        $this->assertSame(
            'SET STATEMENT max_statement_time = 1 FOR EXPLAIN select * from orders',
            $connection->explained[0]['query']
        );
    }

    #[Test]
    #[DataProvider('mysqlPlans')]
    public function it_reads_a_mysql_plan_into_the_contract(string $driver, string $fixture, array $expected)
    {
        $plan = QueryPlan::fromMySql($driver, $this->fixture($fixture));

        $this->assertSame($expected, array_diff_key($plan, ['explained_at' => true]));
    }

    public static function mysqlPlans(): array
    {
        return [
            'mysql table scan in a join' => ['mysql', 'mysql-full-scan', [
                'driver' => 'mysql',
                'full_scan' => true,
                'table' => 'orders',
                'rows_estimate' => 2000,
                'key' => null,
                'possible_keys' => 'orders_user_id_index',
            ]],
            'mysql index lookup' => ['mysql', 'mysql-indexed', [
                'driver' => 'mysql',
                'full_scan' => false,
                'table' => 'orders',
                'rows_estimate' => 40,
                'key' => 'orders_user_id_index',
                'possible_keys' => 'orders_user_id_index',
            ]],
            'mysql whole index read with no usable key' => ['mysql', 'mysql-index-scan', [
                'driver' => 'mysql',
                'full_scan' => true,
                'table' => 'orders',
                'rows_estimate' => 2000,
                'key' => 'orders_user_id_index',
                'possible_keys' => null,
            ]],
            'mariadb table scan in a join' => ['mariadb', 'mariadb-full-scan', [
                'driver' => 'mariadb',
                'full_scan' => true,
                'table' => 'users',
                'rows_estimate' => 50,
                'key' => null,
                'possible_keys' => 'PRIMARY',
            ]],
            'mariadb whole index read with no usable key' => ['mariadb', 'mariadb-index-scan', [
                'driver' => 'mariadb',
                'full_scan' => true,
                'table' => 'orders',
                'rows_estimate' => 2000,
                'key' => 'orders_user_id_index',
                'possible_keys' => null,
            ]],
            'mariadb index lookup' => ['mariadb', 'mariadb-indexed', [
                'driver' => 'mariadb',
                'full_scan' => false,
                'table' => 'orders',
                'rows_estimate' => 40,
                'key' => 'orders_user_id_index',
                'possible_keys' => 'orders_user_id_index',
            ]],
        ];
    }

    #[Test]
    #[DataProvider('postgresPlans')]
    public function it_reads_a_postgres_plan_into_the_contract(string $fixture, array $expected)
    {
        $plan = QueryPlan::fromPostgres($this->fixture($fixture));

        $this->assertSame($expected, array_diff_key($plan, ['explained_at' => true]));
    }

    public static function postgresPlans(): array
    {
        return [
            'largest seq scan in a hash join' => ['pgsql-full-scan', [
                'driver' => 'pgsql',
                'full_scan' => true,
                'table' => 'orders',
                'rows_estimate' => 286,
                'key' => null,
                'possible_keys' => null,
            ]],
            'bitmap index scan' => ['pgsql-indexed', [
                'driver' => 'pgsql',
                'full_scan' => false,
                'table' => 'orders',
                'rows_estimate' => 40,
                'key' => null,
                'possible_keys' => null,
            ]],
        ];
    }

    #[Test]
    public function an_unreadable_plan_is_no_plan()
    {
        $this->assertNull(QueryPlan::fromMySql('mysql', []));
        $this->assertNull(QueryPlan::fromPostgres([]));
        $this->assertNull(QueryPlan::fromPostgres([(object) ['QUERY PLAN' => 'not json']]));
    }

    #[Test]
    public function no_binding_or_raw_plan_reaches_the_payload()
    {
        $this->enableExplain();

        $connection = $this->connection('pgsql', 'pgsql-full-scan');
        $monitor = $this->sampledRequest();

        $this->runQuery($connection, 'select * from users where email = ? and status = ?', ['jane@example.com', 's2']);

        $record = $this->record($monitor);
        $payload = json_encode($record);

        $this->assertArrayHasKey('plan', $record['sql'][0]);
        $this->assertSame(
            ['driver', 'full_scan', 'table', 'rows_estimate', 'key', 'possible_keys', 'explained_at'],
            array_keys($record['sql'][0]['plan'])
        );
        $this->assertStringNotContainsString('jane@example.com', $payload);
        $this->assertStringNotContainsString("'s2'", $payload);
        $this->assertStringNotContainsString('Filter', $payload);
        $this->assertStringNotContainsString('Seq Scan', $payload);
    }

    private function enableExplain(): void
    {
        config([
            'larabug.queries.explain.enabled' => true,
            'larabug.queries.explain.min_duration_ms' => 100,
        ]);
    }

    private function sampledRequest(): RequestMonitor
    {
        $this->app['events']->forget(QueryExecuted::class);

        $monitor = new RequestMonitor();

        $sampler = new Sampler();
        $sampler->decide(Request::create('/orders', 'GET'));

        (new RequestListeners($monitor, $sampler, new QueryExplainer($monitor)))->subscribe($this->app['events']);

        return $monitor;
    }

    private function connection(string $driver, ?string $fixture = null): ExplainingConnection
    {
        $connection = new ExplainingConnection($driver);
        $connection->setEventDispatcher($this->app['events']);
        $connection->plan = $fixture === null ? [] : $this->fixture($fixture);

        return $connection;
    }

    private function runQuery(ExplainingConnection $connection, string $sql, array $bindings = [], float $time = 250.0): void
    {
        $this->app['events']->dispatch(new QueryExecuted($sql, $bindings, $time, $connection));
    }

    private function record(RequestMonitor $monitor): array
    {
        return $monitor->toArray(Request::create('/orders', 'GET'), new Response('', 200), 1.0);
    }

    /**
     * @return array<int, object>
     */
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__."/Fixtures/explain/{$name}.json"));
    }
}
