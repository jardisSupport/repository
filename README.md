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
- **Query Builder Support** — `findByQuery()` accepts any `DbQueryBuilderInterface` for complex SELECT statements
- **Exists Check** — `exists()` avoids full row fetches when only presence matters
- **Batch Delete** — `deleteAll()` removes multiple rows in a single call
- **Lazy Connection Initialization** — reader and writer connections are opened only when first used

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
