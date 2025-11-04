<?php

namespace EriMeilis\CloudflareD1\Tests\Feature;

use EriMeilis\CloudflareD1\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class SchemaBuilderTest extends TestCase
{
    /**
     * Test: Create table with primary key.
     */
    public function test_can_create_table_with_primary_key(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasColumn('users', 'id'));
        $this->assertTrue(Schema::hasColumn('users', 'name'));
    }

    /**
     * Test: Create table with foreign key constraint
     * This was reported as problematic.
     */
    public function test_can_create_table_with_foreign_key(): void
    {
        // Create parent table
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        // Create child table with foreign key
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
        });

        $this->assertTrue(Schema::hasTable('posts'));
        $this->assertTrue(Schema::hasColumn('posts', 'user_id'));
    }

    /**
     * Test: Foreign key constraint is actually enforced.
     */
    public function test_foreign_key_constraint_is_enforced(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
        });

        // Insert valid user
        \DB::table('users')->insert(['name' => 'John']);

        // Should work - valid foreign key
        \DB::table('posts')->insert(['user_id' => 1, 'title' => 'First Post']);
        $this->assertDatabaseHas('posts', ['title' => 'First Post']);

        // Should fail - invalid foreign key
        $this->expectException(\PDOException::class);
        \DB::table('posts')->insert(['user_id' => 999, 'title' => 'Invalid Post']);
    }

    /**
     * Test: Foreign key ON DELETE CASCADE works.
     */
    public function test_foreign_key_cascade_delete_works(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
        });

        \DB::table('users')->insert(['id' => 1, 'name' => 'John']);
        \DB::table('posts')->insert(['user_id' => 1, 'title' => 'Post 1']);
        \DB::table('posts')->insert(['user_id' => 1, 'title' => 'Post 2']);

        // Delete user should cascade to posts
        \DB::table('users')->where('id', 1)->delete();

        $this->assertDatabaseMissing('posts', ['user_id' => 1]);
        $this->assertEquals(0, \DB::table('posts')->count());
    }

    /**
     * Test: Multiple foreign keys in same table.
     */
    public function test_can_create_multiple_foreign_keys(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('category_id')->constrained()->onDelete('cascade');
            $table->string('title');
        });

        $this->assertTrue(Schema::hasTable('posts'));
        $this->assertTrue(Schema::hasColumn('posts', 'user_id'));
        $this->assertTrue(Schema::hasColumn('posts', 'category_id'));
    }

    /**
     * Test: Drop table
     * This was reported as problematic.
     */
    public function test_can_drop_table(): void
    {
        Schema::create('temporary_table', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        $this->assertTrue(Schema::hasTable('temporary_table'));

        Schema::drop('temporary_table');

        $this->assertFalse(Schema::hasTable('temporary_table'));
    }

    /**
     * Test: Drop table if exists.
     */
    public function test_can_drop_table_if_exists(): void
    {
        // Should not throw error even if table doesn't exist
        Schema::dropIfExists('non_existent_table');

        Schema::create('temp', function (Blueprint $table) {
            $table->id();
        });

        Schema::dropIfExists('temp');
        $this->assertFalse(Schema::hasTable('temp'));
    }

    /**
     * Test: Drop table with foreign key constraints
     * This was problematic - need to drop child tables first.
     */
    public function test_can_drop_table_with_foreign_keys(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('title');
        });

        // Drop child table first
        Schema::drop('posts');
        $this->assertFalse(Schema::hasTable('posts'));

        // Now can drop parent
        Schema::drop('users');
        $this->assertFalse(Schema::hasTable('users'));
    }

    /**
     * Test: Add column to existing table.
     */
    public function test_can_add_column_to_existing_table(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable();
        });

        $this->assertTrue(Schema::hasColumn('users', 'email'));
    }

    /**
     * Test: Drop column
     * This was reported as problematic.
     */
    public function test_can_drop_column(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
        });

        $this->assertTrue(Schema::hasColumn('users', 'email'));

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email');
        });

        $this->assertFalse(Schema::hasColumn('users', 'email'));
        $this->assertTrue(Schema::hasColumn('users', 'name')); // Other columns remain
    }

    /**
     * Test: Drop multiple columns at once.
     */
    public function test_can_drop_multiple_columns(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('phone');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['email', 'phone']);
        });

        $this->assertFalse(Schema::hasColumn('users', 'email'));
        $this->assertFalse(Schema::hasColumn('users', 'phone'));
        $this->assertTrue(Schema::hasColumn('users', 'name'));
    }

    /**
     * Test: Rename column.
     */
    public function test_can_rename_column(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('name', 'full_name');
        });

        $this->assertFalse(Schema::hasColumn('users', 'name'));
        $this->assertTrue(Schema::hasColumn('users', 'full_name'));
    }

    /**
     * Test: Column with default value.
     */
    public function test_can_create_column_with_default_value(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->integer('role')->default(1);
        });

        \DB::table('users')->insert(['name' => 'John']);

        $user = \DB::table('users')->first();
        $this->assertEquals(1, $user->active);
        $this->assertEquals(1, $user->role);
    }

    /**
     * Test: Nullable columns.
     */
    public function test_can_create_nullable_columns(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
        });

        // Should allow null
        \DB::table('users')->insert(['name' => 'John', 'email' => null]);
        $this->assertDatabaseHas('users', ['name' => 'John', 'email' => null]);
    }

    /**
     * Test: Unique constraint.
     */
    public function test_can_create_unique_constraint(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
        });

        \DB::table('users')->insert(['email' => 'john@example.com']);

        // Should fail - duplicate unique value
        $this->expectException(\PDOException::class);
        \DB::table('users')->insert(['email' => 'john@example.com']);
    }

    /**
     * Test: Index creation.
     */
    public function test_can_create_index(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->index('email');
        });

        $this->assertTrue(Schema::hasTable('users'));
        // Index existence is implicit - query should work efficiently
    }

    /**
     * Test: Composite unique constraint.
     */
    public function test_can_create_composite_unique_constraint(): void
    {
        Schema::create('user_roles', function (Blueprint $table) {
            $table->foreignId('user_id');
            $table->foreignId('role_id');
            $table->unique(['user_id', 'role_id']);
        });

        \DB::table('user_roles')->insert(['user_id' => 1, 'role_id' => 1]);

        // Can insert same user with different role
        \DB::table('user_roles')->insert(['user_id' => 1, 'role_id' => 2]);

        // Should fail - duplicate composite unique
        $this->expectException(\PDOException::class);
        \DB::table('user_roles')->insert(['user_id' => 1, 'role_id' => 1]);
    }

    /**
     * Test: Timestamps helper.
     */
    public function test_can_use_timestamps_helper(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        $this->assertTrue(Schema::hasColumn('posts', 'created_at'));
        $this->assertTrue(Schema::hasColumn('posts', 'updated_at'));
    }

    /**
     * Test: Soft deletes.
     */
    public function test_can_use_soft_deletes(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->softDeletes();
        });

        $this->assertTrue(Schema::hasColumn('posts', 'deleted_at'));
    }
}
