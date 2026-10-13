<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Unit\Handler;

use JardisSupport\Repository\Handler\ResolveViolatedColumns;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResolveViolatedColumnsTest extends TestCase
{
    private PDO $sqlite;

    protected function setUp(): void
    {
        $this->sqlite = new PDO('sqlite::memory:');
        $this->sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @param array<array-key, mixed> $info
     */
    private function exception(array $info): PDOException
    {
        $e = new PDOException((string) ($info[2] ?? 'error'));
        $e->errorInfo = $info;

        return $e;
    }

    private function postgresException(string $detail, string $constraint = 'uq_orders_offer'): PDOException
    {
        return $this->exception([
            '23505',
            7,
            'ERROR:  duplicate key value violates unique constraint "' . $constraint . "\"\n" . $detail,
        ]);
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function postgresDetails(): array
    {
        return [
            'english single' => ['DETAIL:  Key (offer_id)=(42) already exists.', ['offer_id']],
            'english multi' => ['DETAIL:  Key (tenant_id, number)=(1, 7) already exists.', ['tenant_id', 'number']],
            'german' => ["DETAIL:  Schl\u{00FC}ssel \u{00BB}(tenant_id, number)=(1, 7)\u{00AB} existiert bereits.",
                ['tenant_id', 'number']],
            'quoted identifiers' => ['DETAIL:  Key ("Order", "a b", plain)=(1, 2, 3) already exists.',
                ['Order', 'a b', 'plain']],
            'quote escape' => ['DETAIL:  Key ("a""b")=(1) already exists.', ['a"b']],
            'value with parentheses' => ['DETAIL:  Key (name)=(x)=(y) already exists.', ['name']],
            'expression index' => ['DETAIL:  Key (lower(email::text))=(a@x.de) already exists.', []],
            'mixed expression and column' => ['DETAIL:  Key (tenant_id, lower(email::text))=(1, a) already exists.',
                []],
            'no detail line' => ['', []],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('postgresDetails')]
    public function testPostgres(string $detail, array $expected): void
    {
        $e = $this->postgresException($detail);

        $this->assertSame(
            $expected,
            (new ResolveViolatedColumns())($e, 'postgres', 'orders', $this->sqlite, 'uq_orders_offer')
        );
    }

    public function testPostgresMessageWithoutNewlineYieldsEmpty(): void
    {
        $e = $this->exception(['23505', 7, 'ERROR: duplicate key value violates unique constraint "u" (a)=(1)']);

        $this->assertSame([], (new ResolveViolatedColumns())($e, 'postgres', 't', $this->sqlite, 'u'));
    }

    public function testPostgresConstraintNameWithParenthesesIsNotMistakenForKeyList(): void
    {
        $e = $this->postgresException('DETAIL:  Key (email)=(a) already exists.', 'weird(x)=(y');

        $this->assertSame(['email'], (new ResolveViolatedColumns())($e, 'postgres', 't', $this->sqlite, 'weird(x)=(y'));
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function sqliteMessages(): array
    {
        return [
            'single' => ['UNIQUE constraint failed: users.email', ['email']],
            'multi' => ['UNIQUE constraint failed: users.tenant_id, users.number', ['tenant_id', 'number']],
            'primary key' => ['UNIQUE constraint failed: users.id', ['id']],
            'expression index' => ["UNIQUE constraint failed: index 'idx.lower'", []],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('sqliteMessages')]
    public function testSqlite(string $message, array $expected): void
    {
        $e = $this->exception(['23000', 19, $message]);

        $this->assertSame(
            $expected,
            (new ResolveViolatedColumns())($e, 'sqlite', 'users', $this->sqlite, 'x')
        );
    }

    public function testSqliteFallsBackToExceptionMessage(): void
    {
        $e = new PDOException('SQLSTATE[23000]: UNIQUE constraint failed: users.a, users.b');

        $this->assertSame(['a', 'b'], (new ResolveViolatedColumns())($e, 'sqlite', 'users', $this->sqlite, 'x'));
    }

    public function testSqliteItemWithoutTablePrefixYieldsEmpty(): void
    {
        $e = $this->exception(['23000', 19, 'UNIQUE constraint failed: users.a, nodot']);

        $this->assertSame([], (new ResolveViolatedColumns())($e, 'sqlite', 'users', $this->sqlite, 'x'));
    }

    public function testUnknownDialectYieldsEmpty(): void
    {
        $e = $this->exception(['23000', 1, 'whatever']);

        $this->assertSame([], (new ResolveViolatedColumns())($e, 'oracle', 'users', $this->sqlite, 'x'));
    }

    public function testMysqlLookupFailureYieldsEmptyAndDoesNotThrow(): void
    {
        // plain SQLite has no information_schema: prepare() throws PDOException
        $e = $this->exception(['23000', 1062, "Duplicate entry 'a' for key 'uniq_email'"]);

        $this->assertSame([], (new ResolveViolatedColumns())($e, 'mysql', 'users', $this->sqlite, 'uniq_email'));
    }

    public function testMysqlUnreadableIndexNameYieldsEmptyWithoutLookup(): void
    {
        $e = $this->exception(['23000', 1062, 'Duplicate entry']);

        $this->assertSame([], (new ResolveViolatedColumns())($e, 'mysql', 'users', $this->sqlite, ''));
    }

    /**
     * SQLite stand-in for information_schema.STATISTICS: proves query shape, ordering,
     * all-or-nothing for expression parts and the per-(table, index) cache.
     */
    private function statisticsStandIn(): PDO
    {
        $this->sqlite->exec("ATTACH DATABASE ':memory:' AS information_schema");
        $this->sqlite->exec(
            'CREATE TABLE information_schema.STATISTICS '
            . '(TABLE_SCHEMA TEXT, TABLE_NAME TEXT, INDEX_NAME TEXT, SEQ_IN_INDEX INT, COLUMN_NAME TEXT)'
        );
        $this->sqlite->sqliteCreateFunction('DATABASE', static fn(): string => 'app');
        $rows = [
            ['app', 'orders', 'uq_two', 2, 'number'],
            ['app', 'orders', 'uq_two', 1, 'tenant_id'],
            ['app', 'orders', 'uq_expr', 1, 'tenant_id'],
            ['app', 'orders', 'uq_expr', 2, null],
            ['other', 'orders', 'uq_foreign', 1, 'x'],
        ];
        $insert = $this->sqlite->prepare('INSERT INTO information_schema.STATISTICS VALUES (?, ?, ?, ?, ?)');
        foreach ($rows as $row) {
            $insert->execute($row);
        }

        return $this->sqlite;
    }

    public function testMysqlLookupReturnsColumnsInIndexOrder(): void
    {
        $pdo = $this->statisticsStandIn();
        $e = $this->exception(['23000', 1062, "Duplicate entry '1-7' for key 'uq_two'"]);

        $this->assertSame(
            ['tenant_id', 'number'],
            (new ResolveViolatedColumns())($e, 'mysql', 'orders', $pdo, 'uq_two')
        );
    }

    public function testMysqlLookupIgnoresOtherSchemaAndUnknownIndex(): void
    {
        $pdo = $this->statisticsStandIn();
        $e = $this->exception(['23000', 1062, "Duplicate entry 'x' for key 'uq_foreign'"]);
        $resolve = new ResolveViolatedColumns();

        $this->assertSame([], $resolve($e, 'mysql', 'orders', $pdo, 'uq_foreign'));
        $this->assertSame([], $resolve($e, 'mysql', 'orders', $pdo, 'does_not_exist'));
    }

    public function testMysqlExpressionPartYieldsEmptyNotPartialList(): void
    {
        $pdo = $this->statisticsStandIn();
        $e = $this->exception(['23000', 1062, "Duplicate entry 'x' for key 'uq_expr'"]);

        $this->assertSame([], (new ResolveViolatedColumns())($e, 'mysql', 'orders', $pdo, 'uq_expr'));
    }

    public function testMysqlLookupIsCachedPerTableAndIndex(): void
    {
        $pdo = $this->statisticsStandIn();
        $e = $this->exception(['23000', 1062, "Duplicate entry '1-7' for key 'uq_two'"]);
        $resolve = new ResolveViolatedColumns();

        $first = $resolve($e, 'mysql', 'orders', $pdo, 'uq_two');
        $pdo->exec('DELETE FROM information_schema.STATISTICS');
        $second = $resolve($e, 'mysql', 'orders', $pdo, 'uq_two');

        $this->assertSame(['tenant_id', 'number'], $first);
        $this->assertSame($first, $second);
        $this->assertSame([], $resolve($e, 'mysql', 'other_table', $pdo, 'uq_two'));
    }

    public function testEmptyLookupResultIsNotCached(): void
    {
        $pdo = $this->statisticsStandIn();
        $pdo->exec('DELETE FROM information_schema.STATISTICS');
        $e = $this->exception(['23000', 1062, "Duplicate entry '1-7' for key 'uq_two'"]);
        $resolve = new ResolveViolatedColumns();

        $this->assertSame([], $resolve($e, 'mysql', 'orders', $pdo, 'uq_two'));

        $pdo->exec("INSERT INTO information_schema.STATISTICS VALUES ('app', 'orders', 'uq_two', 1, 'tenant_id')");
        $this->assertSame(['tenant_id'], $resolve($e, 'mysql', 'orders', $pdo, 'uq_two'));
    }
}
