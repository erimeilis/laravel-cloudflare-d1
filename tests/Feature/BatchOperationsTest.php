<?php

namespace EriMeilis\CloudflareD1\Tests\Feature;

use EriMeilis\CloudflareD1\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BatchOperationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->timestamps();
        });
    }

    /**
     * Test: Transaction with multiple inserts gets batched.
     */
    public function test_transaction_batches_multiple_inserts(): void
    {
        DB::beginTransaction();

        for ($i = 1; $i <= 10; $i++) {
            DB::table('users')->insert([
                'name'  => "User {$i}",
                'email' => "user{$i}@example.com",
            ]);
        }

        DB::commit();

        $this->assertEquals(10, DB::table('users')->count());
    }

    /**
     * Test: Transaction rollback works.
     */
    public function test_transaction_rollback_works(): void
    {
        DB::beginTransaction();

        DB::table('users')->insert([
            'name'  => 'John',
            'email' => 'john@example.com',
        ]);

        DB::table('users')->insert([
            'name'  => 'Jane',
            'email' => 'jane@example.com',
        ]);

        DB::rollBack();

        $this->assertEquals(0, DB::table('users')->count());
    }

    /**
     * Test: Nested transactions (savepoints).
     */
    public function test_nested_transactions_work(): void
    {
        DB::beginTransaction();

        DB::table('users')->insert(['name' => 'User 1', 'email' => 'user1@example.com']);

        DB::beginTransaction(); // Nested

        DB::table('users')->insert(['name' => 'User 2', 'email' => 'user2@example.com']);

        DB::rollBack(); // Rollback nested

        DB::commit(); // Commit outer

        // Only User 1 should exist
        $this->assertEquals(1, DB::table('users')->count());
        $this->assertDatabaseHas('users', ['name' => 'User 1']);
        $this->assertDatabaseMissing('users', ['name' => 'User 2']);
    }

    /**
     * Test: Transaction with exception rolls back.
     */
    public function test_transaction_with_exception_rolls_back(): void
    {
        try {
            DB::transaction(function () {
                DB::table('users')->insert(['name' => 'John', 'email' => 'john@example.com']);

                // Force an error
                throw new \Exception('Test error');
            });
        } catch (\Exception $e) {
            // Expected
        }

        $this->assertEquals(0, DB::table('users')->count());
    }

    /**
     * Test: Multiple operations in single transaction.
     */
    public function test_mixed_operations_in_transaction(): void
    {
        // Insert initial data
        DB::table('users')->insert(['name' => 'Original', 'email' => 'original@example.com']);

        DB::transaction(function () {
            // Insert
            DB::table('users')->insert(['name' => 'New User', 'email' => 'new@example.com']);

            // Update
            DB::table('users')->where('name', 'Original')->update(['name' => 'Updated']);

            // Delete would go here too
        });

        $this->assertDatabaseHas('users', ['name' => 'New User']);
        $this->assertDatabaseHas('users', ['name' => 'Updated']);
        $this->assertDatabaseMissing('users', ['name' => 'Original']);
    }

    /**
     * Test: Large batch insert (respecting 100 param limit).
     */
    public function test_large_batch_insert(): void
    {
        // D1 has 100 parameter limit per query
        // With 3 columns, max ~33 rows per batch
        $data = [];
        for ($i = 1; $i <= 100; $i++) {
            $data[] = [
                'name'  => "User {$i}",
                'email' => "user{$i}@example.com",
            ];
        }

        DB::transaction(function () use ($data) {
            foreach (array_chunk($data, 30) as $chunk) {
                DB::table('users')->insert($chunk);
            }
        });

        $this->assertEquals(100, DB::table('users')->count());
    }

    /**
     * Test: Batch operations maintain data integrity.
     */
    public function test_batch_maintains_data_integrity(): void
    {
        $users = [];
        for ($i = 1; $i <= 50; $i++) {
            $users[] = [
                'name'  => "User {$i}",
                'email' => "user{$i}@example.com",
            ];
        }

        DB::transaction(function () use ($users) {
            foreach (array_chunk($users, 20) as $chunk) {
                DB::table('users')->insert($chunk);
            }
        });

        // Verify all users inserted correctly
        $this->assertEquals(50, DB::table('users')->count());

        // Check first and last
        $this->assertDatabaseHas('users', ['name' => 'User 1']);
        $this->assertDatabaseHas('users', ['name' => 'User 50']);
    }

    /**
     * Test: Performance comparison - transaction vs individual inserts.
     *
     * Note: This test documents expected behavior but doesn't assert performance
     * since timing can be unreliable in test environments
     */
    public function test_transaction_performance_comparison(): void
    {
        // Individual inserts (slower)
        $start = microtime(true);
        for ($i = 1; $i <= 10; $i++) {
            DB::table('users')->insert([
                'name'  => "Individual {$i}",
                'email' => "individual{$i}@example.com",
            ]);
        }
        $individualTime = microtime(true) - $start;

        // Clear table
        DB::table('users')->truncate();

        // Transaction batch (faster)
        $start = microtime(true);
        DB::transaction(function () {
            for ($i = 1; $i <= 10; $i++) {
                DB::table('users')->insert([
                    'name'  => "Batch {$i}",
                    'email' => "batch{$i}@example.com",
                ]);
            }
        });
        $batchTime = microtime(true) - $start;

        // Just verify both methods work correctly
        $this->assertEquals(10, DB::table('users')->count());

        // Performance note: In production, batch operations are typically 10x faster
        // Individual: ~{$individualTime}s, Batch: ~{$batchTime}s
        $this->assertTrue(true, 'Performance comparison complete');
    }

    /**
     * Test: Transaction with query builder operations.
     */
    public function test_transaction_with_query_builder(): void
    {
        // Insert initial user
        DB::table('users')->insert([
            'name'       => 'John',
            'email'      => 'john@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Update in transaction
        DB::transaction(function () {
            DB::table('users')
                ->where('email', 'john@example.com')
                ->update(['name' => 'John Updated']);
        });

        $this->assertDatabaseHas('users', ['name' => 'John Updated']);
        $this->assertDatabaseMissing('users', ['name' => 'John']);
    }
}
