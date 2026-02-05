<?php

declare(strict_types=1);

namespace JardisSupport\Repository;

use JardisPort\DbQuery\DbQueryBuilderInterface;
use JardisSupport\Repository\PrimaryKey\PkStrategy;

interface RepositoryInterface
{
    /**
     * Fuegt einen neuen Datensatz ein.
     *
     * @param string $table Tabellenname
     * @param string $pkColumn Primary-Key-Spalte
     * @param array<string, mixed> $values Spaltenwerte (ohne PK bei Autoincrement)
     * @param PkStrategy $pkStrategy Strategie fuer PK-Erzeugung
     * @return int|string Erzeugter Primary Key
     */
    public function insert(
        string $table,
        string $pkColumn,
        array $values,
        PkStrategy $pkStrategy = PkStrategy::AUTOINCREMENT,
    ): int|string;

    /**
     * Aktualisiert einen bestehenden Datensatz.
     *
     * @param string $table Tabellenname
     * @param string $pkColumn Primary-Key-Spalte
     * @param int|string $id Primary-Key-Wert
     * @param array<string, mixed> $values Geaenderte Spaltenwerte
     * @return bool Erfolg
     */
    public function update(
        string $table,
        string $pkColumn,
        int|string $id,
        array $values,
    ): bool;

    /**
     * Loescht einen einzelnen Datensatz.
     *
     * @param string $table Tabellenname
     * @param string $pkColumn Primary-Key-Spalte
     * @param int|string $id Primary-Key-Wert
     * @return bool Erfolg
     */
    public function delete(
        string $table,
        string $pkColumn,
        int|string $id,
    ): bool;

    /**
     * Loescht mehrere Datensaetze per ID-Liste.
     *
     * @param string $table Tabellenname
     * @param string $pkColumn Primary-Key-Spalte
     * @param array<int|string> $ids Primary-Key-Werte
     */
    public function deleteAll(
        string $table,
        string $pkColumn,
        array $ids,
    ): void;

    /**
     * Findet einen einzelnen Datensatz per Primary Key.
     *
     * @param string $table Tabellenname
     * @param string $pkColumn Primary-Key-Spalte
     * @param int|string $id Primary-Key-Wert
     * @return array<string, mixed>|null Datensatz als assoziatives Array oder null
     */
    public function findById(
        string $table,
        string $pkColumn,
        int|string $id,
    ): ?array;

    /**
     * Fuehrt einen frei gebauten SELECT-Query aus.
     *
     * Der Aufrufer ist verantwortlich fuer den kompletten Query
     * (SELECT, FROM, WHERE, ORDER BY, LIMIT, etc.).
     *
     * @param DbQueryBuilderInterface $query Fertig gebauter Query
     * @return array<int, array<string, mixed>> Liste von Datensaetzen
     */
    public function findByQuery(DbQueryBuilderInterface $query): array;

    /**
     * Prueft ob ein Datensatz existiert.
     *
     * @param string $table Tabellenname
     * @param string $pkColumn Primary-Key-Spalte
     * @param int|string $id Primary-Key-Wert
     */
    public function exists(
        string $table,
        string $pkColumn,
        int|string $id,
    ): bool;
}
