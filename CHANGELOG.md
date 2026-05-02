# Changelog

All notable changes to the Laravel Cloudflare D1 Driver will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2026-05-03

### Added
- **Laravel 13 Support** — Full compatibility with Laravel 13
  - Added `illuminate/database:^13.0` and `illuminate/support:^13.0`
  - Added Orchestra Testbench `^11.0` and PHPUnit `^12.0`
  - CI matrix updated with PHP 8.3/8.4 + Laravel 13 test combinations
- **D1SchemaBuilder** — Proper SQLite-specific schema builder extending `SQLiteBuilder`
  - Gains `getTables()`, `getColumns()`, `getViews()`, `dropAllTables()`, `pragma()`
- **SQLiteProcessor** — Proper column/index/foreign-key introspection
- **EscapesSqlValues trait** — Unified SQL value escaping shared across classes
- **Connection overrides** — `getDriverTitle()`, `isUniqueConstraintError()`, `escapeBinary()`
- **D1Pdo safety overrides** — `quote()`, `errorCode()`, `errorInfo()`

### Fixed
- **Operator precedence bug** in D1ApiClient error handling (`!$body['success'] ?? false`)
- **Named parameter binding order** — values now bind in SQL appearance order, not array key order
- **rowCount() wrong path** — was reading from `results` instead of `meta` in D1 API response
- **TypeError in DataExporter::close()** — non-nullable PDO property set to null
- **SQL injection in pragma()** — public method now validates name and escapes value
- **Infinite loop** — DataExporter::setChunkSize() now validates size >= 1
- **Inconsistent identifier quoting** — DataImporter/MigrationValidator now escape backticks
- **Regex table name matching** — INSERT parsing now matches backtick-quoted identifiers
- **Uninitialized typed properties** in LocalD1ApiClient test mock
- **Config wiring** — cloudflare-d1 config values now actually consumed by ServiceProvider

### Changed
- **D1QueryGrammar** — removed 3 dead pass-through methods
- **D1SchemaGrammar** — removed 12 unnecessary type overrides identical to parent SQLiteGrammar
- **D1SchemaGrammar** — removed broken `compileDropAllTables()` (parent handles it properly)
- **escapeValue()** — extracted from 3 classes into shared trait with type validation

### Migration Notes
- **Behavior Fix**: `rowCount()` now returns correct values for INSERT/UPDATE/DELETE (previously returned 0). Code that depended on the broken `0` return must be updated.
- **Behavior Fix**: Named parameter binding order corrected (previously bound values to wrong placeholders by array key order instead of SQL appearance order).
- PHP requirement remains `^8.2` for Laravel 11/12 backward compatibility.
- Laravel 13 requires PHP `^8.3` minimum; Composer enforces this automatically.

## [1.1.0] - 2025-11-04

### Added
- **Automatic Raw SQL Optimization for Bulk Inserts** — Major performance breakthrough!
  - Transparently converts bulk INSERT operations to raw SQL with escaped values
  - Bypasses D1's 100 SQL parameter limit by leveraging 100KB raw SQL limit
  - Automatic chunking at 95KB for optimal batch sizes
  - **20x performance improvement** — Real-world test: 250 rows from 47s → 2.3s
  - Works transparently with existing Laravel code — no application changes needed

- **Extended Bulk Operation Support**
  - Full support for `insert()` — standard bulk inserts
  - Full support for `insertOrIgnore()` — INSERT OR IGNORE operations
  - Full support for `upsert()` — INSERT with ON CONFLICT DO UPDATE
  - All bulk operations automatically optimized

### Changed
- **QueryBatcher**: Updated `splitLargeQuery()` to use raw SQL approach instead of prepared statements
- **D1Connection**: Added `insert()` override to intercept and optimize bulk operations
- **Performance**: Applications can now pass entire datasets to `insert()` without manual chunking

### Technical Details
- Added `isBulkInsert()` method to detect bulk operations (>10 parameters)
- Added `insertUsingRawSql()` to handle conversion and chunking
- Added `escapeValue()` for proper SQLite value escaping (strings, numbers, bools, NULL)
- Supports INSERT variants: INSERT, INSERT OR IGNORE, INSERT OR REPLACE
- Handles ON CONFLICT clauses for upsert operations
- Regex-based SQL parsing to preserve all INSERT statement features

### Migration Notes
- **Breaking Change**: None — fully backward compatible
- **Recommendation**: Remove manual chunking code from applications — package handles it automatically
- **Performance**: Applications will see immediate 10-20x performance improvement on bulk inserts

## [1.0.0] - 2025-11-03

### Added

#### Core Database Driver
- Full Laravel database driver for Cloudflare D1
- Custom PDO implementation for D1 REST API
- Complete Eloquent ORM support (models, relationships, migrations)
- SQLite-based query grammar optimized for D1
- Schema builder with migrations support
- Foreign key constraints (enabled by default)
- Unique constraints and indexes

#### Performance Optimization
- Intelligent query batching (33x faster bulk operations)
- Automatic transaction-based batching
- SQLite parameter limit handling (100 params)
- Optimized parameter binding for D1 batch API
- Validated performance gains against production D1

#### MySQL Migration Tools
- **Complete Schema Conversion**
  - Automatic data type mapping (INT→INTEGER, VARCHAR→TEXT, etc.)
  - AUTO_INCREMENT → AUTOINCREMENT conversion
  - ENUM → TEXT with CHECK constraint
  - Foreign key preservation with CASCADE support
  - Index conversion (regular and UNIQUE)
  
- **Efficient Data Migration**
  - Chunked data export with memory efficiency
  - Batch INSERT operations (33x performance improvement)
  - Automatic parameter limit handling
  - Progress tracking and statistics
  
- **Artisan Command** (`d1:migrate-from-mysql`)
  - One-command MySQL to D1 migration
  - Selective table migration (include/exclude filters)
  - Structure-only or data-only modes
  - Dry-run support for safe planning
  - Real-time progress reporting
  
- **Migration Validation**
  - Table existence verification
  - Row count comparison
  - Sample data integrity checks
  - Comprehensive validation reports

### Fixed
- D1 batch API parameter binding
- Laravel 11/12 schema grammar compatibility
- Foreign key constraint enforcement
- Unique constraint enforcement
- Multi-column drop operations
- Database persistence across queries

### Documentation
- Complete installation and setup guide (README.md)
- Comprehensive MySQL migration guide (MIGRATION_GUIDE.md)
- Performance optimization best practices
- Troubleshooting guide

### Testing
- 57 automated tests (100% passing)
- Production D1 validation
- Performance benchmarks

---

## Version History

**Major.Minor.Patch** - Following [Semantic Versioning](https://semver.org/)
- **Major**: Breaking changes
- **Minor**: New features, backward compatible
- **Patch**: Bug fixes and minor improvements
