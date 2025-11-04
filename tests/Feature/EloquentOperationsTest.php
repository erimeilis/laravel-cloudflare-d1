<?php

namespace EriMeilis\CloudflareD1\Tests\Feature;

use EriMeilis\CloudflareD1\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class EloquentOperationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create test tables
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('content');
            $table->integer('views')->default(0);
            $table->timestamps();
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->text('body');
            $table->timestamps();
        });
    }

    /**
     * Test: Basic model creation.
     */
    public function test_can_create_model(): void
    {
        $user = TestUser::create([
            'name'  => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $this->assertNotNull($user->id);
        $this->assertEquals('John Doe', $user->name);
        $this->assertDatabaseHas('users', ['email' => 'john@example.com']);
    }

    /**
     * Test: Model reading/querying.
     */
    public function test_can_read_models(): void
    {
        TestUser::create(['name' => 'John', 'email' => 'john@example.com']);
        TestUser::create(['name' => 'Jane', 'email' => 'jane@example.com']);

        $users = TestUser::all();
        $this->assertCount(2, $users);

        $john = TestUser::where('name', 'John')->first();
        $this->assertEquals('john@example.com', $john->email);
    }

    /**
     * Test: Model updates.
     */
    public function test_can_update_model(): void
    {
        $user = TestUser::create(['name' => 'John', 'email' => 'john@example.com']);

        $user->update(['name' => 'John Updated']);

        $this->assertEquals('John Updated', $user->fresh()->name);
        $this->assertDatabaseHas('users', ['name' => 'John Updated']);
    }

    /**
     * Test: Model deletion.
     */
    public function test_can_delete_model(): void
    {
        $user = TestUser::create(['name' => 'John', 'email' => 'john@example.com']);
        $id = $user->id;

        $user->delete();

        $this->assertDatabaseMissing('users', ['id' => $id]);
    }

    /**
     * Test: Has many relationship.
     */
    public function test_has_many_relationship_works(): void
    {
        $user = TestUser::create(['name' => 'John', 'email' => 'john@example.com']);

        $user->posts()->create([
            'title'   => 'First Post',
            'content' => 'Content here',
        ]);

        $user->posts()->create([
            'title'   => 'Second Post',
            'content' => 'More content',
        ]);

        $this->assertCount(2, $user->posts);
        $this->assertEquals('First Post', $user->posts->first()->title);
    }

    /**
     * Test: Belongs to relationship.
     */
    public function test_belongs_to_relationship_works(): void
    {
        $user = TestUser::create(['name' => 'John', 'email' => 'john@example.com']);

        $post = $user->posts()->create([
            'title'   => 'Test Post',
            'content' => 'Content',
        ]);

        $this->assertInstanceOf(TestUser::class, $post->user);
        $this->assertEquals($user->id, $post->user->id);
    }

    /**
     * Test: Eager loading (N+1 prevention).
     */
    public function test_eager_loading_works(): void
    {
        $user1 = TestUser::create(['name' => 'John', 'email' => 'john@example.com']);
        $user2 = TestUser::create(['name' => 'Jane', 'email' => 'jane@example.com']);

        $user1->posts()->create(['title' => 'Post 1', 'content' => 'Content 1']);
        $user2->posts()->create(['title' => 'Post 2', 'content' => 'Content 2']);

        \DB::enableQueryLog();

        $users = TestUser::with('posts')->get();

        $queryLog = \DB::getQueryLog();

        // Should be 2 queries: 1 for users, 1 for posts
        // Without eager loading it would be 1 + N queries
        $this->assertLessThanOrEqual(3, count($queryLog));

        foreach ($users as $user) {
            $this->assertNotNull($user->posts);
        }
    }

    /**
     * Test: Has many through relationship.
     */
    public function test_has_many_through_relationship_works(): void
    {
        $user = TestUser::create(['name' => 'John', 'email' => 'john@example.com']);

        $post = $user->posts()->create([
            'title'   => 'Test Post',
            'content' => 'Content',
        ]);

        $post->comments()->create([
            'user_id' => $user->id,
            'body'    => 'Comment 1',
        ]);

        $post->comments()->create([
            'user_id' => $user->id,
            'body'    => 'Comment 2',
        ]);

        // User has comments through posts
        $this->assertCount(2, $user->comments);
    }

    /**
     * Test: Mass assignment.
     */
    public function test_mass_assignment_works(): void
    {
        $data = [
            'name'  => 'John',
            'email' => 'john@example.com',
        ];

        $user = TestUser::create($data);

        $this->assertEquals('John', $user->name);
        $this->assertEquals('john@example.com', $user->email);
    }

    /**
     * Test: Model attribute casting.
     */
    public function test_attribute_casting_works(): void
    {
        $user = TestUser::create([
            'name'   => 'John',
            'email'  => 'john@example.com',
            'active' => true,
        ]);

        $this->assertIsBool($user->active);
        $this->assertTrue($user->active);
    }

    /**
     * Test: Cascade delete through relationships.
     */
    public function test_cascade_delete_works(): void
    {
        $user = TestUser::create(['name' => 'John', 'email' => 'john@example.com']);

        $post = $user->posts()->create([
            'title'   => 'Test Post',
            'content' => 'Content',
        ]);

        $post->comments()->create([
            'user_id' => $user->id,
            'body'    => 'Comment',
        ]);

        $postId = $post->id;

        // Delete user should cascade to posts and comments
        $user->delete();

        $this->assertDatabaseMissing('posts', ['id' => $postId]);
        $this->assertDatabaseMissing('comments', ['post_id' => $postId]);
    }

    /**
     * Test: Query scopes.
     */
    public function test_query_scopes_work(): void
    {
        TestUser::create(['name' => 'Active User', 'email' => 'active@example.com', 'active' => true]);
        TestUser::create(['name' => 'Inactive User', 'email' => 'inactive@example.com', 'active' => false]);

        $activeUsers = TestUser::where('active', true)->get();

        $this->assertCount(1, $activeUsers);
        $this->assertEquals('Active User', $activeUsers->first()->name);
    }

    /**
     * Test: Chunking large result sets.
     */
    public function test_can_chunk_results(): void
    {
        // Create 25 users
        for ($i = 1; $i <= 25; $i++) {
            TestUser::create([
                'name'  => "User {$i}",
                'email' => "user{$i}@example.com",
            ]);
        }

        $processedCount = 0;

        TestUser::chunk(10, function ($users) use (&$processedCount) {
            $processedCount += $users->count();
        });

        $this->assertEquals(25, $processedCount);
    }

    /**
     * Test: Pagination.
     */
    public function test_pagination_works(): void
    {
        // Create 15 users
        for ($i = 1; $i <= 15; $i++) {
            TestUser::create([
                'name'  => "User {$i}",
                'email' => "user{$i}@example.com",
            ]);
        }

        $page1 = TestUser::paginate(10);

        $this->assertEquals(15, $page1->total());
        $this->assertEquals(10, $page1->count());
        $this->assertEquals(2, $page1->lastPage());
    }
}

// Test Models
class TestUser extends Model
{
    protected $table = 'users';
    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function posts()
    {
        return $this->hasMany(TestPost::class, 'user_id');
    }

    public function comments()
    {
        return $this->hasManyThrough(TestComment::class, TestPost::class, 'user_id', 'post_id');
    }
}

class TestPost extends Model
{
    protected $table = 'posts';
    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(TestUser::class, 'user_id');
    }

    public function comments()
    {
        return $this->hasMany(TestComment::class, 'post_id');
    }
}

class TestComment extends Model
{
    protected $table = 'comments';
    protected $guarded = [];

    public function post()
    {
        return $this->belongsTo(TestPost::class, 'post_id');
    }

    public function user()
    {
        return $this->belongsTo(TestUser::class, 'user_id');
    }
}
