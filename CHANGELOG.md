# Changelog

All notable changes to `jardissupport/repository` are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning follows [SemVer](https://semver.org/). Releases before 1.7.0 are listed in the git history and the GitHub releases.

## [Unreleased]

### Added
- `RunOwnedTransaction::__invoke(…, bool $transactional = true)`: `transactional: false` runs the work without the bracket for a use case that opted out - ownership (no transaction open on entry) and a throw (`OwnedResult::$failure`) are answered exactly as in the bracket case, nothing is begun, committed or rolled back, a missing connection is no configuration error. Additive; the default keeps the bracket.
- `Handler\Transaction\RunOwnedTransaction` and `Data\OwnedResult`: the transaction bracket for a use case that knows only its depth. Savepoint name `sp_<depth>` and "nested" (`depth > 0`) are derived, the ownership (no transaction open on entry) is read before the bracket acts and handed back as `OwnedResult::$owner`; a throw is rolled back as before and comes back as `OwnedResult::$failure` (also `TransactionLostException`, and the `TransactionConfigurationException` of a missing connection with `owner: true`) so the caller maps it with the owner flag. Built on `RunInTransaction`, which stays byte-compatible. Negative depth throws `InvalidArgumentException`.
- `RunInTransaction`: without a `$failed` closure the default also recognises `JardisSupport\Contract\Kernel\OutcomeInterface::isFailed()` (needs a contracts release that ships the interface), next to a `DomainResponseInterface` with status 400 and above. The `__invoke` signature is unchanged; no import of `workflow` or `response`.

### Fixed
- `RunInTransaction`: the savepoint of a bracket inside an open transaction is named `<caller name>_<object id of the work closure>` instead of the caller's name as given. Nested brackets that passed the same name (two doors that both saw depth 0) overwrote one another's savepoint on MySQL/MariaDB (`RELEASE SAVEPOINT` failed with 1305 and surfaced as `TransactionLostException`); the name is now unique among the open brackets of a connection. No signature change. The caller's name is cut at its end when the suffix would exceed `SavepointInterface::MAX_NAME_LENGTH`.

## [1.7.0] - Unreleased

### Added
- `Handler\Transaction\RunInTransaction`: the transaction bracket - own transaction or savepoint (name given by the caller), rollback on a failed answer or a throw, `$failed` and `commit` inside the guarded block, a failing rollback after a throw is a `TransactionLostException` (cause as previous), `$nested` without an open transaction runs the work directly.
- `Handler\Transaction\ResolveConnection`: `ConnectionPoolInterface|\PDO|null` to the writer connection, without a kernel.
- `Exception\TransactionConfigurationException` (`final`, extends `\RuntimeException`): no connection, or a savepoint is needed and the connection offers none.
- `Exception\TransactionLostException` (`final`, extends the contract's `TransientConflictException`): the work threw and the rollback (or the rollback to the savepoint) failed as well. The cause is `getPrevious()`, the rollback failure `getRollbackFailure()`; the driver code of a transient cause is carried on. `RunInTransaction` throws it instead of swallowing the failing rollback and throwing the bare cause. Who owns the transaction answers 409 transient, one who does not own it throws it on; a Persist never translates it. A failing release after a successful rollback and the reject path (a failed answer without a throw) are unchanged.
