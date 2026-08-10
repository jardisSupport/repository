---
name: support-repository
description: jardissupport/repository - Generic CRUD repository with raw data, read/write splitting, PK strategies. Use when working with Repository, InsertHandler, or jardissupport/repository.
user-invocable: false
zone: post-active
persona: C
prerequisites: [rules-architecture, rules-patterns, adapter-dbconnection, support-data]
next: []
---

# REPOSITORY_COMPONENT_SKILL
> `jardissupport/repository` | NS: `JardisSupport\Repository` | PHP 8.2+

## ARCHITECTURE
```
Repository (Facade, implements RepositoryInterface)
  Constructor: ConnectionPoolInterface|PDO
  ├── Adapter/PdoConnectionPool   PDO → ConnectionPoolInterface (internal wrapper)
  ├── Adapter/PdoConnection       PDO → DbConnectionInterface (MySQL/PostgreSQL/SQLite)
  └── Handler/ (lazy ??=)
      ├── QueryExecutor           fetchAll, fetchOne, getDialect, getPdo
      ├── InsertHandler           3 PK strategies, invokable
      ├── UpdateHandler           PDOException → PersistException
      ├── DeleteHandler           PDOException → PersistException
      ├── DeleteAllHandler        IN-clause, PDOException → PersistException
      ├── FindByIdHandler         SELECT * WHERE pk = :id
      ├── ExistsHandler           SELECT 1 WHERE pk = :id LIMIT 1
      └── IntegerPkGenerator      MAX+1 with 3 retries

PkStrategy (Enum from jardissupport/contracts):
  AUTOINCREMENT → DB (AUTO_INCREMENT/SERIAL), lastInsertId → int
  INTEGER       → MAX+1, 3 retries on Duplicate Key → int
  NONE          → caller provides PK in $values → int|string
```

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
- Shared logic: `Handler/Query/ApplyExpectedConditions` (invokable, applies `$expected` to `DbUpdate`/`DbDelete`)

## READ/WRITE SPLITTING
- `insert`, `update`, `delete`, `deleteAll` → `$pool->getWriter()`
- `findById`, `findByQuery`, `exists` → `$pool->getReader()`
- PDO mode: same connection for reader and writer (wrapped via `PdoConnectionPool`)

## QUERY EXECUTOR
```php
$executor = new QueryExecutor($connection);
$executor->fetchAll(DbPreparedQueryInterface $prepared);  // array<int, array<string, mixed>>
$executor->fetchOne(DbPreparedQueryInterface $prepared);  // ?array<string, mixed>
$executor->getDialect();   // 'mysql'|'postgres'|... ('pgsql' → 'postgres')
$executor->getPdo();       // PDO instance
// Fetch mode: always PDO::FETCH_ASSOC regardless of PDO default
```

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
| `RecordNotFoundException` | Defined in contract — not thrown internally; for custom implementations |

## LAYER RULES
- `RepositoryInterface` in Application/Domain; `Repository` in Infrastructure/Composition Root
- Raw data (arrays) — no Entities. Hydration via `jardissupport/data`
- Domain NEVER imports `Repository` directly — only `RepositoryInterface`
- Always `prepared: true` (SQL injection protection)

## DEPENDENCIES
- `jardissupport/contracts` — `RepositoryInterface`, `PkStrategy`, `PersistException`, `RecordNotFoundException`, `ConnectionPoolInterface`, `DbConnectionInterface`
- `jardissupport/dbquery` — `DbQuery`, `DbInsert`, `DbUpdate`, `DbDelete`, `DbPreparedQuery`
