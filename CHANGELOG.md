# Changelog

All notable changes to `jardissupport/repository` are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning follows [SemVer](https://semver.org/). Releases before 1.7.0 are listed in the git history and the GitHub releases.

## [1.7.0] - Unreleased

### Added
- `Handler\Transaction\RunInTransaction`: the transaction bracket - own transaction or savepoint (name given by the caller), rollback on a failed answer or a throw, `$failed` and `commit` inside the guarded block, the cause is never replaced, `$nested` without an open transaction runs the work directly.
- `Handler\Transaction\ResolveConnection`: `ConnectionPoolInterface|\PDO|null` to the writer connection, without a kernel.
- `Exception\TransactionConfigurationException` (`final`, extends `\RuntimeException`): no connection, or a savepoint is needed and the connection offers none.
