# Changelog

All notable changes to the Laravel Cloudflare D1 Driver will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
