# Jardis Repository

![Build Status](https://github.com/jardisSupport/repository/actions/workflows/ci.yml/badge.svg)
[![License: PolyForm Noncommercial](https://img.shields.io/badge/License-PolyForm%20Noncommercial-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.2-777BB4.svg)](https://www.php.net/)
[![PSR-4](https://img.shields.io/badge/PSR--4-Autoloader-blue.svg)](https://www.php-fig.org/psr/psr-4/)
[![PSR-12](https://img.shields.io/badge/PSR--12-Code%20Style-blue.svg)](https://www.php-fig.org/psr/psr-12/)
[![Coverage](https://img.shields.io/badge/Coverage-91%25-brightgreen.svg)]()

> Part of the **[Jardis Ecosystem](https://jardis.io)** — A modular DDD framework for PHP

Generic CRUD repository for PHP — Raw data persistence with read/write splitting and flexible primary key strategies.

---

## Features

- **Generic CRUD** — Insert, update, delete, find by ID with raw data (no entities, no hydration)
- **Read/Write Splitting** — ConnectionPool integration routes reads to replicas, writes to primary
- **Flexible PK Strategies** — Autoincrement, integer (MAX+1 with retry), or bring your own
- **Full Query Power** — `findByQuery()` accepts any DbQuery with all operators, JOINs, subqueries
- **Lazy Initialization** — Handlers and connections created on first use
- **Prepared Statements** — All values parameterized via DbQuery builder

---

## Installation

```bash
composer require jardissupport/repository
```

## Quick Start

```php
use JardisAdapter\DbConnection\ConnectionPool;
use JardisAdapter\DbConnection\Data\MySqlConfig;
use JardisAdapter\DbConnection\MySql;
use JardisSupport\Repository\Repository;
use JardisSupport\Repository\PrimaryKey\PkStrategy;

// Setup
$pool = new ConnectionPool(
    writer: new MySqlConfig('localhost', 'user', 'pass', 'mydb'),
    readers: [],
    driverClass: MySql::class,
);
$repo = new Repository($pool);

// Insert
$id = $repo->insert('users', 'id', [
    'name' => 'Alice',
    'email' => 'alice@example.com',
], PkStrategy::AUTOINCREMENT);

// Update
$repo->update('users', 'id', $id, ['name' => 'Alice Updated']);

// Find by ID
$user = $repo->findById('users', 'id', $id);

// Delete
$repo->delete('users', 'id', $id);
```

## Query with DbQuery Builder

`findByQuery()` gives you the full power of the DbQuery builder — all operators, JOINs, subqueries, ORDER BY, LIMIT, and more:

```php
use JardisSupport\DbQuery\DbQuery;

$query = (new DbQuery())
    ->select('*')
    ->from('users')
    ->where('status')->equals('active')
    ->and('age')->greater(18)
    ->orderBy('name', 'ASC')
    ->limit(10);

$rows = $repo->findByQuery($query);
```

## Primary Key Strategies

```php
// Database generates the PK (AUTO_INCREMENT / SERIAL)
$repo->insert('users', 'id', $values, PkStrategy::AUTOINCREMENT);

// Repository generates integer PK (MAX+1 with retry on conflict)
$repo->insert('users', 'id', $values, PkStrategy::INTEGER);

// Caller provides the PK in $values
$repo->insert('users', 'id', ['id' => 'my-custom-pk', ...$values], PkStrategy::NONE);
```

## API Reference

| Method | Description | Returns |
|--------|-------------|---------|
| `insert($table, $pkColumn, $values, $pkStrategy)` | Insert a new record | `int\|string` (generated PK) |
| `update($table, $pkColumn, $id, $values)` | Update by primary key | `bool` |
| `delete($table, $pkColumn, $id)` | Delete by primary key | `bool` |
| `deleteAll($table, $pkColumn, $ids)` | Delete multiple by ID list | `void` |
| `findById($table, $pkColumn, $id)` | Find one by primary key | `?array` |
| `findByQuery($query)` | Execute a DbQuery | `array` |
| `exists($table, $pkColumn, $id)` | Check existence by PK | `bool` |

## Documentation

Full documentation, examples and API reference:

**→ [jardis.io/docs/support/repository](https://jardis.io/docs/support/repository)**

## License

This package is licensed under the [PolyForm Noncommercial License 1.0.0](LICENSE).

For commercial use, see [COMMERCIAL.md](COMMERCIAL.md).

---

**[Jardis Ecosystem](https://jardis.io)** by [Headgent Development](https://headgent.com)
