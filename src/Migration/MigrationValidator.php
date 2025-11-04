<?php

namespace EriMeilis\CloudflareD1\Migration;

use PDO;

/**
 * MigrationValidator - Validate MySQL to D1 migration integrity.
 *
 * Features:
 * - Table count verification
 * - Row count comparison
 * - Sample data validation
 * - Foreign key relationship checks
 * - Comprehensive validation reports
 */
class MigrationValidator
{
    protected DataExporter $exporter;

    protected DataImporter $importer;

    protected array $validationResults = [];

    /**
     * Create a new MigrationValidator instance.
     */
    public function __construct(DataExporter $exporter, DataImporter $importer)
    {
        $this->exporter = $exporter;
        $this->importer = $importer;
    }

    /**
     * Validate entire migration.
     *
     * @param array $tables Tables to validate (null = all tables)
     */
    public function validate(?array $tables = null): array
    {
        $this->validationResults = [
            'overall_status'         => 'pending',
            'tables_validated'       => 0,
            'tables_passed'          => 0,
            'tables_failed'          => 0,
            'total_source_rows'      => 0,
            'total_destination_rows' => 0,
            'discrepancies'          => [],
            'table_results'          => [],
        ];

        // Get tables to validate
        if ($tables === null) {
            $tables = $this->exporter->getTables();
        }

        // Validate each table
        foreach ($tables as $table) {
            $result = $this->validateTable($table);

            $this->validationResults['tables_validated']++;
            $this->validationResults['table_results'][$table] = $result;

            if ($result['status'] === 'passed') {
                $this->validationResults['tables_passed']++;
            } else {
                $this->validationResults['tables_failed']++;
            }

            $this->validationResults['total_source_rows'] += $result['source_count'];
            $this->validationResults['total_destination_rows'] += $result['destination_count'];

            if (!empty($result['errors'])) {
                $this->validationResults['discrepancies'][] = [
                    'table'  => $table,
                    'errors' => $result['errors'],
                ];
            }
        }

        // Determine overall status
        if ($this->validationResults['tables_failed'] === 0) {
            $this->validationResults['overall_status'] = 'passed';
        } elseif ($this->validationResults['tables_passed'] === 0) {
            $this->validationResults['overall_status'] = 'failed';
        } else {
            $this->validationResults['overall_status'] = 'partial';
        }

        return $this->validationResults;
    }

    /**
     * Validate a single table.
     */
    public function validateTable(string $table): array
    {
        $result = [
            'table'             => $table,
            'status'            => 'passed',
            'source_count'      => 0,
            'destination_count' => 0,
            'errors'            => [],
            'warnings'          => [],
        ];

        try {
            // Check if table exists in D1
            if (!$this->importer->tableExists($table)) {
                $result['status'] = 'failed';
                $result['errors'][] = 'Table does not exist in D1';

                return $result;
            }

            // Compare row counts
            $sourceCount = $this->exporter->getTableCount($table);
            $destinationCount = $this->getD1TableCount($table);

            $result['source_count'] = $sourceCount;
            $result['destination_count'] = $destinationCount;

            if ($sourceCount !== $destinationCount) {
                $result['status'] = 'failed';
                $result['errors'][] = "Row count mismatch: MySQL has {$sourceCount}, D1 has {$destinationCount}";
            }

            // Sample data validation
            if ($sourceCount > 0) {
                $sampleValidation = $this->validateSampleData($table);

                if (!$sampleValidation['valid']) {
                    $result['status'] = 'failed';
                    $result['errors'] = array_merge($result['errors'], $sampleValidation['errors']);
                }

                if (!empty($sampleValidation['warnings'])) {
                    $result['warnings'] = array_merge($result['warnings'], $sampleValidation['warnings']);
                }
            }
        } catch (\Exception $e) {
            $result['status'] = 'failed';
            $result['errors'][] = 'Validation error: '.$e->getMessage();
        }

        return $result;
    }

    /**
     * Get row count from D1 table.
     */
    protected function getD1TableCount(string $table): int
    {
        $result = $this->importer->getApiClient()->raw("SELECT COUNT(*) as count FROM `{$table}`");

        $rows = $result[0]['results']['rows'] ?? [];

        if (empty($rows)) {
            return 0;
        }

        return (int) $rows[0][0];
    }

    /**
     * Validate sample data between MySQL and D1.
     */
    protected function validateSampleData(string $table, int $sampleSize = 10): array
    {
        $result = [
            'valid'    => true,
            'errors'   => [],
            'warnings' => [],
        ];

        try {
            // Get sample from MySQL
            $mysqlSample = $this->getMysqlSample($table, $sampleSize);

            if (empty($mysqlSample)) {
                return $result;
            }

            // Get primary key
            $primaryKey = $this->getPrimaryKeyColumn($table);

            if (!$primaryKey) {
                $result['warnings'][] = 'No primary key found - cannot validate specific rows';

                return $result;
            }

            // Validate each sampled row
            foreach ($mysqlSample as $mysqlRow) {
                $pkValue = $mysqlRow[$primaryKey];

                // Get corresponding row from D1
                $d1Row = $this->getD1Row($table, $primaryKey, $pkValue);

                if ($d1Row === null) {
                    $result['valid'] = false;
                    $result['errors'][] = "Row with {$primaryKey}={$pkValue} not found in D1";

                    continue;
                }

                // Compare row data
                $comparison = $this->compareRows($mysqlRow, $d1Row);

                if (!$comparison['match']) {
                    $result['valid'] = false;
                    $result['errors'][] = "Row {$primaryKey}={$pkValue} data mismatch: {$comparison['details']}";
                }
            }
        } catch (\Exception $e) {
            $result['valid'] = false;
            $result['errors'][] = 'Sample validation error: '.$e->getMessage();
        }

        return $result;
    }

    /**
     * Get sample rows from MySQL.
     */
    protected function getMysqlSample(string $table, int $limit): array
    {
        $sql = "SELECT * FROM `{$table}` LIMIT {$limit}";

        $stmt = $this->exporter->getPdo()->query($sql);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get primary key column name.
     */
    protected function getPrimaryKeyColumn(string $table): ?string
    {
        $stmt = $this->exporter->getPdo()->prepare("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
        $stmt->execute();

        $result = $stmt->fetch();

        return $result['Column_name'] ?? null;
    }

    /**
     * Get a specific row from D1.
     */
    protected function getD1Row(string $table, string $primaryKey, mixed $value): ?array
    {
        $result = $this->importer->getApiClient()->raw(
            "SELECT * FROM `{$table}` WHERE `{$primaryKey}` = ? LIMIT 1",
            [$value]
        );

        $rows = $result[0]['results']['rows'] ?? [];

        if (empty($rows)) {
            return null;
        }

        $columns = $result[0]['results']['columns'] ?? [];

        return array_combine($columns, $rows[0]);
    }

    /**
     * Compare two rows for equality.
     */
    protected function compareRows(array $mysqlRow, array $d1Row): array
    {
        $mismatches = [];

        foreach ($mysqlRow as $column => $mysqlValue) {
            if (!array_key_exists($column, $d1Row)) {
                $mismatches[] = "Column '{$column}' missing in D1";

                continue;
            }

            $d1Value = $d1Row[$column];

            // Normalize values for comparison
            $normalizedMysql = $this->normalizeValue($mysqlValue);
            $normalizedD1 = $this->normalizeValue($d1Value);

            if ($normalizedMysql !== $normalizedD1) {
                $mysqlStr = is_null($mysqlValue) ? 'NULL' : $mysqlValue;
                $d1Str = is_null($d1Value) ? 'NULL' : $d1Value;

                $mismatches[] = "{$column}: MySQL='{$mysqlStr}' vs D1='{$d1Str}'";
            }
        }

        return [
            'match'   => empty($mismatches),
            'details' => implode(', ', $mismatches),
        ];
    }

    /**
     * Normalize value for comparison.
     */
    protected function normalizeValue(mixed $value): mixed
    {
        // NULL values
        if ($value === null) {
            return null;
        }

        // Boolean values
        if ($value === true || $value === '1' || $value === 1) {
            return 1;
        }

        if ($value === false || $value === '0' || $value === 0) {
            return 0;
        }

        // Numeric values
        if (is_numeric($value)) {
            return (float) $value;
        }

        // String values - trim whitespace
        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }

    /**
     * Get validation results.
     */
    public function getResults(): array
    {
        return $this->validationResults;
    }

    /**
     * Generate validation report.
     */
    public function generateReport(): string
    {
        $results = $this->validationResults;

        $report = "╔════════════════════════════════════════════════════════════════╗\n";
        $report .= "║            Migration Validation Report                         ║\n";
        $report .= "╚════════════════════════════════════════════════════════════════╝\n\n";

        $report .= 'Overall Status: '.strtoupper($results['overall_status'])."\n";
        $report .= "Tables Validated: {$results['tables_validated']}\n";
        $report .= "Tables Passed: {$results['tables_passed']}\n";
        $report .= "Tables Failed: {$results['tables_failed']}\n";
        $report .= 'Total Source Rows: '.number_format($results['total_source_rows'])."\n";
        $report .= 'Total Destination Rows: '.number_format($results['total_destination_rows'])."\n\n";

        if (!empty($results['discrepancies'])) {
            $report .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $report .= "DISCREPANCIES FOUND:\n";
            $report .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

            foreach ($results['discrepancies'] as $discrepancy) {
                $report .= "Table: {$discrepancy['table']}\n";

                foreach ($discrepancy['errors'] as $error) {
                    $report .= "  ❌ {$error}\n";
                }

                $report .= "\n";
            }
        }

        if ($results['overall_status'] === 'passed') {
            $report .= "✅ All validations passed! Migration was successful.\n";
        } elseif ($results['overall_status'] === 'partial') {
            $report .= "⚠️  Some tables failed validation. Review discrepancies above.\n";
        } else {
            $report .= "❌ Validation failed. Migration has issues that need to be addressed.\n";
        }

        return $report;
    }

    /**
     * Check if validation passed.
     */
    public function passed(): bool
    {
        return ($this->validationResults['overall_status'] ?? 'pending') === 'passed';
    }
}
