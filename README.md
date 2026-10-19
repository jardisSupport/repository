# Jardis Repository

![Build Status](https://github.com/jardisSupport/repository/actions/workflows/ci.yml/badge.svg)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE.md)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.2-777BB4.svg)](https://www.php.net/)
[![PHPStan Level](https://img.shields.io/badge/PHPStan-Level%208-brightgreen.svg)](phpstan.neon)
[![PSR-12](https://img.shields.io/badge/Code%20Style-PSR--12-blue.svg)](phpcs.xml)
[![Coverage](https://img.shields.io/badge/Coverage-90.91%25-brightgreen.svg)](https://github.com/jardisSupport/repository)

> Part of **[Jardis](https://jardis.io)** — the Domain-Driven Design platform for PHP. You model your domain; Jardis generates the production-ready hexagonal code (DTOs, Command/Query handlers, repositories, persistence). This package is part of the open-source foundation that generated code runs on.

An implementation of the repository pattern for PHP: a generic CRUD repository operating on raw data — no entities, no ORM, just arrays in and out. Built-in read/write splitting routes queries to readers and mutations to the writer. Three primary key strategies cover autoincrement, generated integers, and application-supplied keys. It is the persistence building block that the repositories in generated Jardis code rely on.

---

## Features

- **Raw Data** — arrays in, arrays out; no entity mapping, no hydration overhead
- **Read/Write Splitting** — queries automatically route to a dedicated reader; mutations go to the writer
- **3 PK Strategies** — `PkStrategy::AUTOINCREMENT`, `PkStrategy::INTEGER`, `PkStrategy::NONE` for all insert patterns
- **ConnectionPool Integration** — accepts a `ConnectionPoolInterface` or a plain `PDO` instance
- **Savepoints on plain PDO** — the `Adapter\PdoConnection` wrapper implements `SavepointInterface` (`savepoint()`, `releaseSavepoint()`, `rollbackToSavepoint()`) like the dbconnection adapter
- **Query Builder Support** — `findByQuery()` accepts any `DbQueryBuilderInterface` for complex SELECT statements
- **Exists Check** — `exists()` avoids full row fetches when only presence matters
- **Batch Delete** — `deleteAll()` removes multiple rows in a single call
- **Conditional Writes** — optional `$expected` on `update()`/`delete()` guards against stale reads (optimistic concurrency); returns `false` when the row no longer matches
- **Lazy Connection Initialization** — reader and writer connections are opened only when first used
- **Typed Bindings** — every value is bound with its matching `PDO::PARAM_*` type (bool/int/null/string) instead of a blanket `execute($bindings)`; `false` reaches Postgres as a real `BOOLEAN`, not as `''`

---

## Installation

```bash
composer require jardissupport/repository
```

## Quick Start

```php
use JardisSupport\Repository\Repository;
use JardisSupport\Contract\Repository\PrimaryKey\PkStrategy;

$repository = new Repository($pdo);

// Insert a row — returns the new autoincrement id
$id = $repository->insert('orders', 'id', [
    'customer_id' => 42,
    'total'       => 199.99,
    'status'      => 'pending',
]);

// Fetch by primary key
$row = $repository->findById('orders', 'id', $id);

// Update
$repository->update('orders', 'id', $id, ['status' => 'confirmed']);

// Delete
$repository->delete('orders', 'id', $id);
```

## Advanced Usage

```php
use JardisSupport\Repository\Repository;
use JardisSupport\DbQuery\DbQuery;
use JardisSupport\Contract\Repository\PrimaryKey\PkStrategy;

// Read/write splitting via a connection pool
$repository = new Repository($connectionPool);

// Application-supplied UUID key (PkStrategy::NONE — no last-insert-id lookup)
$uuid = $uuidGenerator->generate();
$repository->insert('products', 'uuid', ['uuid' => $uuid, 'name' => 'Widget'], PkStrategy::NONE);

// Complex query via DbQuery builder
$query = (new DbQuery())
    ->select('o.id, o.total, c.email')
    ->from('orders', 'o')
    ->innerJoin('customers', 'o.customer_id = c.id', 'c')
    ->where('o.status')->eq('pending')
    ->and('o.total')->gte(100)
    ->orderBy('o.created_at', 'DESC')
    ->limit(20);

$rows = $repository->findByQuery($query);

// Batch delete
$repository->deleteAll('sessions', 'id', [101, 102, 103]);

// Existence check without fetching the row
if ($repository->exists('users', 'id', $userId)) {
    // ...
}
```

### Conditional writes (optimistic concurrency)

```php
public function update(
    string $table, string $pkColumn, int|string $id, array $values,
    array $expected = [],   // column => value the row must still carry; null => IS NULL
): bool;

public function delete(
    string $table, string $pkColumn, int|string $id,
    array $expected = [],
): bool;
```

`$expected` pins the write to the values a caller already read — one extra `AND` condition per column. If the row no longer carries those values, the statement touches 0 rows and the call returns `false` instead of silently overwriting a stale read.

```php
// Two readers fetch the same row: ['status' => 'pending', ...]
$row = $repository->findById('orders', 'id', $id);

// Reader A writes first — the row still matches, write succeeds
$repository->update('orders', 'id', $id, ['status' => 'confirmed'], ['status' => 'pending']); // true

// Reader B writes second, unaware A already won — the row no longer matches
$repository->update('orders', 'id', $id, ['status' => 'cancelled'], ['status' => 'pending']); // false
```

> - `null` in `$expected` maps to `IS NULL`, not `= NULL`.
> - `false` means the row no longer carries the expected values — or the `id` doesn't exist. For a conflict check both are the same signal: the read this write was based on is no longer valid.
> - `$expected` values must be `scalar|null`; an object or array throws `InvalidArgumentException`.
> - An empty `$values` combined with a non-empty `$expected` throws `InvalidArgumentException` too — the existing "nothing to write" shortcut would otherwise skip the check silently and return `true` unchecked. Use `exists()`/`findById()` for a pure read-side check.
> - **MySQL note:** `rowCount()` counts changed rows — an update that would set identical values reports `0`. This doesn't fire for repositories built from changed-field diffs, but it's a caveat if `$values` is assembled differently.

### Error handling

Write failures surface as `PersistException`. A transient concurrency conflict (snapshot conflict, deadlock) is reported as its subtype `TransientConflictException`, see below. A violated unique constraint (duplicate key) is reported as its subtype `UniqueViolationException`, with the constraint name available via `getConstraint()` (or `null` if the driver does not expose it). Detection is dialect-aware (MySQL/MariaDB, PostgreSQL, SQLite); other integrity errors such as foreign-key violations stay plain `PersistException`. With `PkStrategy::INTEGER`, a collision is only retried when the primary key itself collided — any other unique violation is thrown immediately.

```php
use JardisSupport\Contract\Repository\Exception\UniqueViolationException;

try {
    $repository->insert('users', 'id', ['email' => $email]);
} catch (UniqueViolationException $e) {
    // e.g. respond with HTTP 409 Conflict; $e->getConstraint() names the violated index
    $e->getTable();   // table of the failed insert/update (from the call, never parsed from the message)
    $e->getColumns(); // complete column list of the violated index, in index order, or []
}
```

#### Which columns were violated

`getColumns()` (contracts >= 2.5) carries the **complete** column list of the violated unique index in index order — or `[]` when the dialect cannot deliver it safely. It is **never a partial list**, and resolving it never hides the violation: `getMessage()`, `getConstraint()` and `getPrevious()` are always set. It is resolved only on the error path, for `insert()` (all `PkStrategy` values) and `update()`.

| Dialect | Source of the columns | Notes |
|---------|-----------------------|-------|
| MySQL / MariaDB | index name from error 1062 (`for key`), then `information_schema.STATISTICS` (`ORDER BY SEQ_IN_INDEX`) | one lookup per (table, index), cached; the transaction stays usable after 1062; lookup error or functional (expression) index -> `[]`; the primary key resolves as `PRIMARY` |
| PostgreSQL | DETAIL line `Key (a, b)=(...)` of the error, no extra query | works inside an aborted transaction (25P02) and with translated server messages; quoted identifiers are unquoted; expression index -> `[]` |
| SQLite | message `UNIQUE constraint failed: t.a, t.b` | `t.` prefix removed; also for the primary key; `index '...'` (expression index) -> `[]` |

The integration tests run against MySQL, **MariaDB** and PostgreSQL (`make start` brings up all three services, see `support/docker-compose.yml`) plus SQLite in-process, each with a single-column and a two-column unique index.

#### Transient conflicts (`TransientConflictException`)

(contracts >= 2.6, repository >= 1.6) Every write — `insert()` (all `PkStrategy` values), `update()`, `delete()`, `deleteAll()` — throws `TransientConflictException` (subtype of `PersistException`) when the database rejects the statement because of a **snapshot/serialization conflict or a deadlock**: the statement was not wrong, another transaction got in between. Catch it **before** `PersistException`; the unique-violation path is unchanged.

| Dialect | Detected | Meaning |
|---------|----------|---------|
| MySQL / MariaDB | driver code `1020` | row changed since the snapshot (`innodb_snapshot_isolation`, MariaDB default since 11.6) — ER_CHECKREAD, SQLSTATE `HY000` |
| MySQL / MariaDB | driver code `1213` | deadlock, the transaction was rolled back |
| PostgreSQL | SQLSTATE `40001` | serialization failure (REPEATABLE READ / SERIALIZABLE) |
| PostgreSQL | SQLSTATE `40P01` | deadlock detected |
| SQLite | none | `SQLITE_BUSY` is a lock-availability error and stays `PersistException` |

Not detected on purpose: MySQL/MariaDB `1205` (lock wait timeout) — it rolls back only the statement and says "waited too long", not "conflict resolved by the database"; it stays a `PersistException`.

`getDriverCode()` carries the code as text (`'1020'`, `'1213'`, `'40001'`, `'40P01'`), `getPrevious()` the `PDOException`. **Recommended reaction: roll back and repeat the whole unit of work in a NEW transaction** — repeating only the failed statement inside the same transaction does not help (PostgreSQL has already aborted it; under snapshot isolation the old snapshot still conflicts). For HTTP this maps to `409 Conflict` (retryable), not `500`.

```php
use JardisSupport\Contract\Repository\Exception\TransientConflictException;

try {
    $repository->update('orders', 'id', $id, ['status' => 'paid'], ['status' => 'open']);
} catch (TransientConflictException $e) {
    $e->getDriverCode(); // '1020' | '1213' | '40001' | '40P01'
    // roll back, then repeat the whole unit of work in a new transaction
}
```

The integration tests reproduce the conflicts for real: MariaDB 11.8 with snapshot isolation (two connections, `START TRANSACTION WITH CONSISTENT SNAPSHOT`), PostgreSQL REPEATABLE READ (`40001`) and a deadlock between the test session and a peer process (`40P01`, for insert, update, delete and deleteAll).

## Transaction bracket — RunInTransaction, ResolveConnection (1.7.0)

`RunInTransaction` runs one unit of work in a transaction - its own, or a savepoint inside one that is already open. It knows nothing about processes, responses or kernels.

```php
public function __invoke(
    ?DbConnectionInterface $connection,
    string $savepoint,          // name the caller gives; identifier form, at most SavepointInterface::MAX_NAME_LENGTH
    \Closure $work,             // the unit of work; its return value is returned
    ?\Closure $failed = null,   // fn(mixed $result): bool - true rolls back; null = DomainResponseInterface with status >= 400
    bool $nested = false,       // true: without an open transaction the work runs directly, no transaction of its own
): mixed;
```

Five rules:

1. No open transaction and `$nested = false`: `beginTransaction`, `commit` on success.
2. An open transaction: the work runs in a savepoint with the name you give; success releases it, a failure rolls back to it and then releases it. A connection without `SavepointInterface` throws `TransactionConfigurationException`.
3. `$nested = true` and no open transaction: no transaction of its own, `$work()` runs directly.
4. `$failed($result) === true` or a throw rolls back. `$failed` and `commit` run inside the guarded block: a throw there also rolls back - only while a transaction is open - and stays the cause. A failing release never replaces the cause. A failing rollback (or rollback to the savepoint) after a throw means the transaction is lost: the bracket throws `TransactionLostException` (`final`, extends `TransientConflictException`) with the cause as `getPrevious()` and the rollback error as `getRollbackFailure()`. Every other throw (also `TransientConflictException`) is thrown on unchanged; a failing rollback after a failed answer without a throw propagates as it is.
5. `$connection === null` throws `TransactionConfigurationException` (`final`, extends `\RuntimeException`). Opting out of the transaction means not calling the bracket.

```php
use JardisSupport\Repository\Handler\Transaction\ResolveConnection;
use JardisSupport\Repository\Handler\Transaction\RunInTransaction;

$connection = (new ResolveConnection())($pool);   // ConnectionPoolInterface -> writer, \PDO -> PdoConnection, null -> null

$response = (new RunInTransaction())(
    $connection,
    'jardis_persist_order',                        // used as savepoint name when a transaction is already open
    fn() => $this->persistOrder($handler),         // begin/commit around it, or savepoint/release
);
```

The bracket does not translate conflicts: a `TransientConflictException` leaves the bracket as it came (the 409 answer is the caller's business). `TransactionLostException` is the one exception it adds: the transaction is lost after a failed rollback - the owner of the transaction answers 409 transient, one who does not own it throws it on; a Persist never translates it.

## Documentation

Full documentation, guides, and API reference:

**[docs.jardis.io/en/support/repository](https://docs.jardis.io/en/support/repository)**

## License

This package is licensed under the [MIT License](LICENSE.md).

---

**[Jardis](https://jardis.io)** · [Documentation](https://docs.jardis.io) · [Headgent](https://headgent.com)

<!-- BEGIN jardis/dev-skills README block — do not edit by hand -->
## AI-Assisted Development

This package ships with a skill for Claude Code, Cursor, Continue, and Aider. Install it in your consuming project:

```bash
composer require --dev jardis/dev-skills
```

More details: <https://docs.jardis.io/en/skills>
<!-- END jardis/dev-skills README block -->
