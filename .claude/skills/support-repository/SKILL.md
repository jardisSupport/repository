---
name: support-repository
description: jardissupport/repository - Generic CRUD repository with raw data, read/write splitting, PK strategies. Use when working with Repository, InsertHandler, or jardissupport/repository.
user-invocable: false
zone: post-active
persona: C
prerequisites: [foundation-architecture, foundation-patterns, adapter-dbconnection, support-data]
next: []
---

# REPOSITORY_COMPONENT_SKILL
> `jardissupport/repository` | NS: `JardisSupport\Repository` | PHP 8.2+

## API
```php
use JardisSupport\Repository\Repository;
use JardisSupport\Contract\Repository\PrimaryKey\PkStrategy;

$repo = new Repository($connectionPool);  // read/write splitting
$repo = new Repository($pdo);             // same connection for reads and writes

// INSERT → int|string (PK)
$id = $repo->insert('users', 'id', ['name' => 'John', 'email' => 'j@x.com']);       // AUTOINCREMENT (default)
$id = $repo->insert('users', 'id', $values, PkStrategy::INTEGER);                   // MAX+1
$id = $repo->insert('users', 'id', ['id' => 42, ...], PkStrategy::NONE);            // caller-provided

// UPDATE → bool
$repo->update('users', 'id', 1, ['name' => 'Jane']);
$repo->update('users', 'id', 1, []);  // no-op, returns true
$repo->update('users', 'id', 1, ['name' => 'Jane'], ['name' => 'John']);  // conditional, see below

// DELETE
$repo->delete('users', 'id', 1);
$repo->delete('users', 'id', 1, ['status' => 'archived']);  // conditional, see below
$repo->deleteAll('users', 'id', [1, 2, 3]);  // IN-clause
$repo->deleteAll('users', 'id', []);          // no-op

// READ
$row    = $repo->findById('users', 'id', 1);   // ?array
$rows   = $repo->findByQuery($dbQueryBuilder);  // array<int, array>
$exists = $repo->exists('users', 'id', 1);      // bool
```

## CONDITIONAL WRITES (optimistic concurrency)
```php
update(string $table, string $pkColumn, int|string $id, array $values, array $expected = []): bool
delete(string $table, string $pkColumn, int|string $id, array $expected = []): bool
```
- `$expected`: column => value the row must still carry (added as `AND`); `null` => `IS NULL`, not `= NULL`
- `false` => row no longer matches `$expected` (or `id` doesn't exist) — 0 rows touched, nothing written
- Values must be `scalar|null` — object/array => `InvalidArgumentException`
- Empty `$values` + non-empty `$expected` => `InvalidArgumentException` (the empty-`$values` no-op shortcut would otherwise skip the check silently)
- Use for optimistic locking / read-then-write races: caller re-supplies the values it read, repository guarantees they still held at write time

## READ/WRITE SPLITTING
- `insert`, `update`, `delete`, `deleteAll` → `$pool->getWriter()`
- `findById`, `findByQuery`, `exists` → `$pool->getReader()`
- PDO mode: same connection for reader and writer (wrapped via `PdoConnectionPool`)
- `Adapter\PdoConnection` (plain-PDO wrapper) also implements `SavepointInterface` (`savepoint`/`releaseSavepoint`/`rollbackToSavepoint`, name `[A-Za-z_][A-Za-z0-9_]*` max 63, no transaction → `RuntimeException`)

## FINDBYQUERY
```php
$rows = $repo->findByQuery(
    (new DbQuery())->select('id, name')->from('users')
        ->where('status')->equals('active')->orderBy('name')->limit(10)
);
```

## EXCEPTIONS
```php
use JardisSupport\Contract\Repository\Exception\{PersistException, RecordNotFoundException};
```

| Trigger | Exception |
|---------|-----------|
| Insert: empty `$values`, `NONE` without PK, `NONE` wrong PK type, `INTEGER` 3 retries exhausted | `PersistException` |
| Update/Delete/DeleteAll: `PDOException` | `PersistException` |
| `$expected` non-scalar value, or empty `$values` with non-empty `$expected` | `InvalidArgumentException` |
| Duplicate Key detection | MySQL/Postgres SQLSTATE `23000`; SQLite string match `UNIQUE constraint failed` |
| `UniqueViolationException` (subtype of `PersistException`, v1.5.0, needs contracts >= 2.5) | Insert (all `PkStrategy`) and `update()`: `getConstraint()`, `getTable()` (= `$table` of the call), `getColumns()` = COMPLETE column list of the violated index in index order, or `[]` — never a partial list (see below); `getPrevious()` = the `PDOException` |

### UNIQUE VIOLATION COLUMNS (`Handler/ResolveViolatedColumns`)
| Dialect | Source | Fallback `[]` |
|---------|--------|---------------|
| MySQL/MariaDB | 1062 `for key` -> `information_schema.STATISTICS` (`TABLE_SCHEMA=DATABASE()`, `ORDER BY SEQ_IN_INDEX`), cached per (table, index); transaction stays usable | lookup error, unreadable index name, functional index |
| Postgres | DETAIL `Key (a, b)=(...)` of the error text (no lookup — transaction is aborted after 23505), quoted names unquoted | expression index, unparsable token |
| SQLite | `UNIQUE constraint failed: t.a, t.b`, table prefix removed (PK included) | `index '...'` form |
- Resolving never throws and never hides the violation (`message`/`constraint`/`previous` always set).
- Tests: Unit `ResolveViolatedColumnsTest`, SQLite unit, integration MySQL + **MariaDB** + Postgres (single + two-column index, insert + update).
| Parameter binding (v1.2.0+) | `Handler/BindTypedParameters` binds each value via `bindValue()` with `PDO::PARAM_BOOL`/`PARAM_INT`/`PARAM_NULL`/`PARAM_STR` — `false` reaches Postgres as BOOLEAN, not `''`. **< v1.2.0:** `execute($bindings)` bound everything as string → Postgres `SQLSTATE[22P02] invalid input syntax for type boolean: ""`; there, bind bools as int 0/1 yourself. |
| `RecordNotFoundException` | Defined in contract — not thrown internally; for custom implementations |

## LAYER RULES
- `RepositoryInterface` in Application/Domain; `Repository` in Infrastructure/Composition Root
- Raw data (arrays) — no Entities. Hydration via `jardissupport/data`
- Domain NEVER imports `Repository` directly — only `RepositoryInterface`
- Always `prepared: true` (SQL injection protection)

## DEPENDENCIES
- `jardissupport/contracts` — `RepositoryInterface`, `PkStrategy`, `PersistException`, `RecordNotFoundException`, `ConnectionPoolInterface`, `DbConnectionInterface`
- `jardissupport/dbquery` — `DbQuery`, `DbInsert`, `DbUpdate`, `DbDelete`, `DbPreparedQuery`
