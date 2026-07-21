# jardissupport/repository

Generic CRUD Repository for raw DB-access array data (no Entities, no Hydration), with Read/Write Splitting via `ConnectionPoolInterface|PDO`, three PK strategies, and consistent `PDOException → PersistException` wrapping.

## Usage essentials

- **Facade `Repository` is the only entry point** — constructor accepts `ConnectionPoolInterface` (real Read/Write Splitting via `getReader()`/`getWriter()`) or plain `PDO` (wrapped internally via `PdoConnectionPool` → same connection for reader and writer). Handlers (`InsertHandler`, `UpdateHandler`, `DeleteHandler`, `DeleteAllHandler`, `FindByIdHandler`, `ExistsHandler`, `QueryExecutor`) are instantiated lazily via `??=`.
- **Raw Data, not Entities:** `insert()/update()/delete()` take `array<string,mixed>`, `findById()/findByQuery()` return `?array`/`array<int,array>`. Hydration and Change-Tracking are the responsibility of `jardissupport/data` — the layer above, not here. `QueryExecutor` forces `PDO::FETCH_ASSOC` explicitly (regardless of PDO default).
- **PK strategies via Enum `PkStrategy` from `jardissupport/contract`:** `AUTOINCREMENT` (default, `lastInsertId()` → `int`), `INTEGER` (MAX+1 with 3 retries on Duplicate Key → `int`, duplicate detection via SQLSTATE `23000` or SQLite string match), `NONE` (caller provides PK in `$values` → `int|string`). Empty `$values` → `PersistException` (including NONE without PK).
- **`findByQuery()` expects a `DbQueryBuilderInterface`** from `jardissupport/dbquery` (no criteria arrays!) — returns full query power (JOINs, Aggregation, Window Functions) with guaranteed `prepared: true`. For COUNT/Aggregation simply use `->select('COUNT(*) AS total')` — result is `[['total' => 42]]`.
- **Consistent Exception wrapping:** All write Handlers (`Insert`, `Update`, `Delete`, `DeleteAll`) catch `PDOException` and throw `PersistException` (from `jardissupport/contract`). `RecordNotFoundException` is defined but not thrown by the Repository itself — only for custom implementations. `$repo->update(..., [])` is a no-op and returns `true` (bool); `$repo->deleteAll(..., [])` is a no-op and returns `void` (no return value).
- **Layer rule:** Repository is a Secondary Port (Hexagonal). The Domain imports **only** `RepositoryInterface` from `jardissupport/contract` — **never** `JardisSupport\Repository\Repository` directly. The implementation lives in `Infrastructure`/Composition Root; `PdoConnection::getDatabaseName()` supports MySQL, PostgreSQL, and SQLite.

## Full reference

https://docs.jardis.io/en/support/repository
