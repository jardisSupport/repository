<?php

declare(strict_types=1);

/**
 * Peer process of RepositoryPostgresTransientConflictTest: a second PostgreSQL session that holds a lock,
 * waits for the "go" line on stdin, then requests the lock the test session holds (blocking -> deadlock
 * cycle). Not a test: started via proc_open.
 *
 * argv[1] = JSON {dsn, user, password, first, second}
 * stdout  = "ready" (first statement done) ... "done" | "error:<SQLSTATE>"
 */

$cfg = json_decode((string) ($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);

$pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("SET deadlock_timeout = '300ms'");
$pdo->beginTransaction();
$pdo->exec($cfg['first']);

fwrite(STDOUT, "ready\n");
fflush(STDOUT);
fgets(STDIN);

try {
    $pdo->exec($cfg['second']);
    $pdo->commit();
    fwrite(STDOUT, "done\n");
} catch (PDOException $e) {
    $pdo->rollBack();
    fwrite(STDOUT, 'error:' . ($e->errorInfo[0] ?? '') . "\n");
}
