# MySQL to Cloudflare D1 Migration Guide

**Package**: `laravel-cloudflare-d1`
**Version**: 1.2.0
**Last Updated**: May 2026

Complete guide for migrating MySQL databases to Cloudflare D1 using Laravel.

---

## Table of Contents

1. [Overview](#overview)
2. [Prerequisites](#prerequisites)
3. [Migration Components](#migration-components)
4. [Quick Start](#quick-start)
5. [Command Reference](#command-reference)
6. [Migration Process](#migration-process)
7. [Schema Conversion](#schema-conversion)
8. [Validation](#validation)
9. [Troubleshooting](#troubleshooting)
10. [Best Practices](#best-practices)
11. [Limitations](#limitations)

---

## Overview

The migration tooling provides a complete solution for migrating MySQL databases to Cloudflare D1:

- **Automated schema conversion** from MySQL to SQLite/D1
- **Efficient data transfer** with batching and progress tracking
- **Validation tools** to ensure migration integrity
- **One-command migration** via Artisan CLI

### Performance Characteristics

- **Batch operations**: 33.3x faster than sequential operations
- **Network optimized**: Minimizes HTTP round-trips to D1 API
- **Memory efficient**: Streams data in chunks, not all at once
- **Progress tracking**: Real-time feedback during migration

---

## Prerequisites

### Required Configuration

1. **MySQL Source Database**
    - MySQL 5.7+ or MariaDB 10.2+
    - Read access to database
    - Network connectivity from Laravel app

2. **Cloudflare D1 Destination**
    - D1 database created (via Cloudflare dashboard)
    - Account ID, Database ID, API Token
    - Configured in Laravel `config/database.php`

3. **Laravel Application**
    - Laravel 11, 12, or 13
    - Package installed: `erimeilis/laravel-cloudflare-d1`

### Environment Setup

```bash
# .env file
CLOUDFLARE_ACCOUNT_ID=your_account_id
CLOUDFLARE_D1_DATABASE_ID=your_database_id
CLOUDFLARE_D1_API_TOKEN=your_api_token
```

```php
// config/database.php
'connections' => [
    'd1' => [
        'driver' => 'd1',
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'database_id' => env('CLOUDFLARE_D1_DATABASE_ID'),
        'api_token' => env('CLOUDFLARE_D1_API_TOKEN'),
    ],
],
```

---

## Migration Components

### 1. SchemaConverter

Converts MySQL CREATE TABLE statements to SQLite/D1 compatible format.

**Handles**:

- Data type mapping (VARCHAR → TEXT, INT → INTEGER, etc.)
- AUTO_INCREMENT → AUTOINCREMENT conversion
- ENUM → TEXT with CHECK constraint
- Foreign keys with CASCADE support
- Unique constraints and indexes
- Warning generation for lossy conversions

### 2. DataExporter

Extracts data from MySQL database efficiently.

**Features**:

- Chunked data export (configurable batch size)
- Memory efficient streaming
- Respects 100 parameter limit
- Progress callbacks
- Primary key-based ordering for consistency

### 3. DataImporter

Imports data into Cloudflare D1 using batch API.

**Features**:

- Batch INSERT operations (33.3x performance improvement)
- Automatic parameter limit handling
- Schema creation via SchemaConverter
- Progress tracking
- Statistics collection

### 4. MigrateFromMysqlCommand

Artisan command orchestrating the complete migration.

**Capabilities**:

- Connection validation
- Migration planning and preview
- Structure-only or data-only modes
- Table filtering (include/exclude)
- Dry-run mode
- Comprehensive progress reporting

### 5. MigrationValidator

Validates migration integrity and completeness.

**Checks**:

- Table existence
- Row count comparison
- Sample data validation
- Foreign key relationships
- Comprehensive validation reports

---

## Quick Start

### Basic Migration (All Tables)

```bash
php artisan d1:migrate-from-mysql
```

This will:

1. Connect to your default MySQL database
2. Connect to your D1 database
3. Show migration plan with table and row counts
4. Ask for confirmation
5. Migrate all tables with structure and data
6. Show final statistics

### Selective Migration

```bash
# Migrate specific tables only
php artisan d1:migrate-from-mysql --tables=users,posts,comments

# Exclude specific tables
php artisan d1:migrate-from-mysql --exclude=logs,sessions

# Structure only (no data)
php artisan d1:migrate-from-mysql --structure-only

# Data only (tables must exist)
php artisan d1:migrate-from-mysql --data-only
```

### Dry Run

```bash
# Preview what would be migrated without executing
php artisan d1:migrate-from-mysql --dry-run
```

---

## Command Reference

### Full Syntax

```bash
php artisan d1:migrate-from-mysql [options]
```

### Options

| Option             | Description                       | Default     |
|--------------------|-----------------------------------|-------------|
| `--host`           | MySQL host                        | From config |
| `--port`           | MySQL port                        | 3306        |
| `--database`       | MySQL database name               | From config |
| `--username`       | MySQL username                    | From config |
| `--password`       | MySQL password                    | From config |
| `--tables`         | Comma-separated list of tables    | All tables  |
| `--exclude`        | Comma-separated tables to exclude | None        |
| `--structure-only` | Only migrate table structure      | false       |
| `--data-only`      | Only migrate data                 | false       |
| `--chunk-size`     | Rows per chunk                    | 1000        |
| `--dry-run`        | Preview without executing         | false       |
| `--force`          | Skip confirmation                 | false       |

### Examples

```bash
# Migrate from custom MySQL server
php artisan d1:migrate-from-mysql \
  --host=mysql.example.com \
  --database=production_db \
  --username=migrator \
  --password=secret

# Large dataset with smaller chunks
php artisan d1:migrate-from-mysql --chunk-size=500

# Automated migration (no confirmation)
php artisan d1:migrate-from-mysql --force

# Migration in stages
php artisan d1:migrate-from-mysql --structure-only
# ... verify structure ...
php artisan d1:migrate-from-mysql --data-only
```

---

## Migration Process

### Step-by-Step Walkthrough

#### 1. Connection Validation

```
🔍 Validating connections...
✅ MySQL connection successful
✅ D1 connection successful
```

The command validates both source and destination before proceeding.

#### 2. Migration Plan

```
📋 Migration Plan:

┌───────────┬──────────┐
│ Table     │ Rows     │
├───────────┼──────────┤
│ users     │ 1,250    │
│ posts     │ 8,342    │
│ comments  │ 45,123   │
└───────────┴──────────┘

Total tables: 3
Total rows: 54,715
```

Review the plan to ensure correct tables and row counts.

#### 3. Confirmation

```
Proceed with migration? (yes/no) [no]:
```

Type `yes` to continue (skip with `--force`).

#### 4. Migration Execution

```
🚀 Starting migration...

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Migrating table: users
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  ✅ Created table: users
  ⚠️  ENUM converted to TEXT with CHECK constraint
  📤 Exporting data from MySQL...
  📊 Exporting users: 1250/1250 rows (100%)
  📥 Importing data to D1...
  📥 Importing users: 1250/1250 rows (100%)
```

Progress is reported in real-time for each table.

#### 5. Final Statistics

```
📊 Migration Statistics:
   Tables created: 3
   Indexes created: 8
   Rows imported: 54,715
   Batches executed: 165

╔════════════════════════════════════════════════════════════════╗
║                   ✅ Migration Complete!                       ║
╚════════════════════════════════════════════════════════════════╝
```

---

## Schema Conversion

### Data Type Mapping

| MySQL Type                      | D1/SQLite Type | Notes                            |
|---------------------------------|----------------|----------------------------------|
| TINYINT, SMALLINT, INT, BIGINT  | INTEGER        | All integer types → INTEGER      |
| VARCHAR, TEXT, CHAR             | TEXT           | All text types → TEXT            |
| DECIMAL, NUMERIC, FLOAT, DOUBLE | REAL           | May lose precision               |
| DATE, DATETIME, TIMESTAMP       | TEXT           | ISO 8601 format                  |
| BOOLEAN, BOOL                   | INTEGER        | 0 or 1                           |
| JSON                            | TEXT           | Stored as JSON string            |
| ENUM                            | TEXT + CHECK   | CHECK constraint enforces values |
| BLOB, BINARY                    | BLOB           | Binary data preserved            |

### Special Conversions

#### AUTO_INCREMENT

MySQL:

```sql
id INT AUTO_INCREMENT PRIMARY KEY
```

D1/SQLite:

```sql
id INTEGER PRIMARY KEY AUTOINCREMENT
```

#### ENUM Types

MySQL:

```sql
status ENUM('pending', 'approved', 'rejected')
```

D1/SQLite:

```sql
status TEXT
CHECK (status IN ('pending', 'approved', 'rejected'))
```

**Warning**: ENUM conversion generates a warning since it changes the implementation.

#### Foreign Keys

MySQL:

```sql
FOREIGN KEY (user_id) REFERENCES users(id) ON
DELETE CASCADE
```

D1/SQLite:

```sql
FOREIGN KEY (user_id) REFERENCES users(id) ON
DELETE CASCADE
```

Foreign keys are preserved with full CASCADE support.

#### Indexes

MySQL:

```sql
INDEX idx_created_at (created_at),
UNIQUE INDEX idx_email (email)
```

D1/SQLite:

```sql
CREATE INDEX idx_created_at ON table_name (created_at);
CREATE UNIQUE INDEX idx_email ON table_name (email);
```

Indexes are created as separate statements after table creation.

### Unsupported Features

#### FULLTEXT Indexes

```
⚠️  FULLTEXT index 'search_index' not supported - consider using external search
```

D1/SQLite doesn't support FULLTEXT indexes. Consider:

- Cloudflare Workers AI for search
- External search service (Algolia, Typesense)
- Simple LIKE queries for basic search

---

## Validation

### Automatic Validation

The migration command automatically tracks:

- Tables created vs expected
- Rows imported vs exported
- Batch execution count
- Errors encountered

### Manual Validation

Use `MigrationValidator` for thorough validation:

```php
use EriMeilis\CloudflareD1\Migration\{
    DataExporter,
    DataImporter,
    MigrationValidator
};
use EriMeilis\CloudflareD1\Http\D1ApiClient;

// Create connections
$exporter = new DataExporter(
    'localhost',
    'source_db',
    'username',
    'password'
);

$d1Client = new D1ApiClient(
    config('database.connections.d1.account_id'),
    config('database.connections.d1.database_id'),
    config('database.connections.d1.api_token')
);

$importer = new DataImporter($d1Client);

// Validate migration
$validator = new MigrationValidator($exporter, $importer);
$results = $validator->validate(['users', 'posts']);

// Generate report
echo $validator->generateReport();

// Check if passed
if ($validator->passed()) {
    echo "✅ Validation passed!\n";
} else {
    echo "❌ Validation failed!\n";
    print_r($validator->getResults());
}
```

### Validation Output

```
╔════════════════════════════════════════════════════════════════╗
║            Migration Validation Report                         ║
╚════════════════════════════════════════════════════════════════╝

Overall Status: PASSED
Tables Validated: 3
Tables Passed: 3
Tables Failed: 0
Total Source Rows: 54,715
Total Destination Rows: 54,715

✅ All validations passed! Migration was successful.
```

---

## Troubleshooting

### Connection Issues

**Problem**: `❌ Failed to connect to MySQL database`

**Solutions**:

- Verify MySQL host, port, database name
- Check username and password
- Ensure network connectivity
- Verify MySQL user has read permissions

**Problem**: `❌ Failed to connect to Cloudflare D1`

**Solutions**:

- Verify Account ID, Database ID, API Token
- Check token permissions
- Ensure D1 database exists
- Verify network access to Cloudflare API

### Migration Errors

**Problem**: `Wrong number of parameter bindings`

**Cause**: Already fixed in package (v1.0.0+)

**Solution**: Ensure you're using the latest version

**Problem**: `Table has X columns, exceeding the 100 parameter limit`

**Cause**: Table with > 100 columns

**Solutions**:

- Reduce chunk size: `--chunk-size=1`
- Split table vertically (separate into multiple tables)
- Contact Cloudflare support for limit increase

**Problem**: Row count mismatch after migration

**Solutions**:

- Run validation to identify missing rows
- Check for errors during migration
- Retry migration with smaller chunk size
- Verify MySQL table didn't change during migration

### Performance Issues

**Problem**: Migration is very slow

**Solutions**:

- Increase chunk size: `--chunk-size=5000` (test carefully)
- Use structure-only migration first, then data-only
- Migrate tables in batches (use `--tables`)
- Consider migrating during off-peak hours

**Problem**: High memory usage

**Solutions**:

- Decrease chunk size: `--chunk-size=500`
- Migrate tables individually
- Monitor system resources

---

## Best Practices

### Pre-Migration

1. **Backup everything**
    - Export MySQL database backup
    - Document current table structure
    - Save row counts for validation

2. **Test migration on development/staging**
    - Never run first migration on production D1
    - Validate schema conversion warnings
    - Test application with migrated data

3. **Review warnings**
    - ENUM conversions
    - DECIMAL → REAL precision loss
    - FULLTEXT index removals

### During Migration

1. **Use dry-run first**
   ```bash
   php artisan d1:migrate-from-mysql --dry-run
   ```

2. **Migrate in stages**
   ```bash
   # Structure first
   php artisan d1:migrate-from-mysql --structure-only

   # Review and test

   # Data second
   php artisan d1:migrate-from-mysql --data-only
   ```

3. **Monitor progress**
    - Watch for errors in real-time
    - Note warning messages
    - Track statistics

### Post-Migration

1. **Validate thoroughly**
    - Run MigrationValidator
    - Compare row counts manually
    - Test critical queries
    - Verify foreign key relationships

2. **Test application**
    - Run full test suite
    - Manual testing of key features
    - Performance testing
    - Edge case validation

3. **Document differences**
    - Schema changes made
    - Data transformations applied
    - Features that work differently

---

## Limitations

### Platform Limitations (Cloudflare D1)

1. **Database Size**
    - Free: 500 MB limit
    - Paid: 10 GB limit per database

2. **Query Limits**
    - 100 parameters per query (handled automatically)
    - No BigInt support (max 64-bit signed INTEGER)

3. **Unsupported Features**
    - FULLTEXT indexes
    - Stored procedures
    - Triggers
    - Views (must be recreated manually)
    - Events

### Migration Tool Limitations

1. **Data Types**
    - DECIMAL → REAL may lose precision
    - Large numbers may overflow INTEGER (use REAL)

2. **Schema**
    - ENUM converted to TEXT + CHECK
    - Must manually migrate views and stored procedures

3. **Performance**
    - Network latency: 150-500ms per API call
    - Batching required for good performance

### Workarounds

**For FULLTEXT search**:

```php
// Before (MySQL):
WHERE MATCH(content) AGAINST('keyword')

// After (D1):
WHERE content LIKE '%keyword%'
// Or use Cloudflare Workers AI / external search
```

**For precision requirements**:

```php
// Before (MySQL):
DECIMAL(10,2) for currency

// After (D1):
Store as INTEGER (cents): 1999 instead of 19.99
Convert in application layer
```

---

## Migration Checklist

### Pre-Flight

- [ ] MySQL backup created
- [ ] D1 database created and configured
- [ ] Package installed and configured
- [ ] Dry-run executed successfully
- [ ] Warnings reviewed and understood
- [ ] Application tested on staging

### Migration

- [ ] Connection validation passed
- [ ] Migration plan reviewed
- [ ] Structure migration completed
- [ ] Data migration completed
- [ ] No errors reported
- [ ] Statistics look correct

### Post-Flight

- [ ] Row counts validated
- [ ] Sample data spot-checked
- [ ] Foreign keys working
- [ ] Application tests passing
- [ ] Performance acceptable
- [ ] Documentation updated

---

## Support and Resources

### Package Documentation

- GitHub: https://github.com/erimeilis/laravel-cloudflare-d1
- Issues: https://github.com/erimeilis/laravel-cloudflare-d1/issues

### Cloudflare D1 Documentation

- D1 Docs: https://developers.cloudflare.com/d1/
- SQLite Compatibility: https://developers.cloudflare.com/d1/platform/limits/

### Laravel Documentation

- Database: https://laravel.com/docs/database
- Migrations: https://laravel.com/docs/migrations

---

**Last Updated**: May 2026
**Package Version**: 1.2.0
**Tested With**: Laravel 11, Laravel 12, Laravel 13, MySQL 8.0, Cloudflare D1
