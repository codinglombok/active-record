<?php

declare(strict_types=1);

namespace LombokClarion\ActiveRecord;

use LombokClarion\ActiveRecord\Exceptions\ActiveRecordException;
use LombokClarion\Persistence\EagerLoader;
use LombokClarion\Persistence\Identifier;
use LombokClarion\Persistence\QueryBuilder;
use LombokClarion\Persistence\Relation;
use PDO;

/**
 * OPTIONAL PACKAGE — `lombokclarion/active-record`.
 *
 * This package is explicitly isolated from the core and from the domain
 * layer (design spec §2.6, §4.12):
 *
 *  - It lives in its own Composer package with `forbidden-layers` metadata
 *    ensuring app/Domain/** never imports it.
 *  - It MUST NOT be used to justify removing the explicit Repository +
 *    QueryBuilder layer: the core path remains Domain Interface →
 *    Infrastructure Repository → QueryBuilder. This is a convenience
 *    shortcut for simple CRUD where the extra ceremony isn't needed.
 *
 * Usage:
 *
 *   class Post extends Model {
 *       protected static string $table = 'posts';
 *       protected static array $fillable = ['title', 'body'];
 *       public static function relations(): array {
 *           return ['comments' => Relation::hasMany('comments', 'post_id')];
 *       }
 *   }
 *
 *   $posts = Post::query()->where('status', '=', 'published')->with('comments')->all();
 *   $post = Post::find('abc123');
 *   $post->update(['title' => 'New title']);
 *   $post->delete();
 *
 * create() and the hydration helpers use `new static()`, which is only sound if
 * every subclass keeps a no-argument-compatible constructor. That was an unwritten
 * assumption until PHPStan was first actually run against this package; the tag
 * below turns it into a checked contract, so a subclass that adds a required
 * constructor argument fails analysis instead of fataling at runtime.
 *
 * @phpstan-consistent-constructor
 */
abstract class Model
{
    protected static string $table;
    /** @var list<string> only these columns can be set via create()/update() */
    protected static array $fillable = [];
    protected static string $primaryKey = 'id';

    /** @var array<string, mixed> */
    protected array $attributes = [];

    private static ?PDO $connection = null;

    /**
     * Connection is set once at boot, not per-model-instance — still an
     * explicit call in services.php, not auto-discovered.
     */
    public static function setConnection(PDO $pdo): void
    {
        self::$connection = $pdo;
    }

    /**
     * Undo setConnection() — static state that can be set must be unsettable,
     * for tests and warm workers recycling a process (added with
     * LaravelFlavor::disable(), which calls this).
     */
    public static function clearConnection(): void
    {
        self::$connection = null;
    }

    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            throw new ActiveRecordException(
                'No database connection set. Call Model::setConnection($pdo) in bootstrap/services.php.'
            );
        }
        return self::$connection;
    }

    // --- Finders --------------------------------------------------------

    public static function find(string|int $id): ?static
    {
        // query()->where() is a self-returning fluent chain; the ModelQueryBuilder<static>
        // template survives it, but PHPStan drops back to the base Model at first().
        /** @var static|null $row */
        $row = static::query()->where(static::$primaryKey, '=', $id)->first();
        return $row;
    }

    public static function findOrFail(string|int $id): static
    {
        return static::find($id) ?? throw new ActiveRecordException(
            static::class . " with " . static::$primaryKey . "=$id not found."
        );
    }

    /**
     * @return ModelQueryBuilder<static>
     */
    public static function query(): ModelQueryBuilder
    {
        return new ModelQueryBuilder(
            new QueryBuilder(static::getConnection(), static::$table),
            static::class,
            static::relations(),
        );
    }

    /**
     * @return list<static>
     */
    public static function all(): array
    {
        return static::query()->all();
    }

    // --- Mutations -------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    public static function create(array $data): static
    {
        $filtered = self::filterFillable($data);
        if ($filtered === []) {
            throw new ActiveRecordException('create() got no fillable attributes.');
        }

        $qb = new QueryBuilder(static::getConnection(), static::$table);
        $insertId = $qb->insert($filtered);

        $instance = new static();
        $instance->attributes = [...$filtered, static::$primaryKey => $filtered[static::$primaryKey] ?? $insertId];
        return $instance;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(array $data): void
    {
        $filtered = self::filterFillable($data);
        if ($filtered === []) {
            return;
        }

        $qb = new QueryBuilder(static::getConnection(), static::$table);
        $qb->where(static::$primaryKey, '=', $this->getKey())->update($filtered);
        $this->attributes = [...$this->attributes, ...$filtered];
    }

    public function delete(): void
    {
        $qb = new QueryBuilder(static::getConnection(), static::$table);
        $qb->where(static::$primaryKey, '=', $this->getKey())->delete();
    }

    // --- Relations -------------------------------------------------------

    /**
     * Override in subclass to declare relations. Keyed by name.
     * @return array<string, Relation>
     */
    public static function relations(): array
    {
        return [];
    }

    // --- Accessors -------------------------------------------------------

    public function getKey(): string|int
    {
        return $this->attributes[static::$primaryKey] ?? throw new ActiveRecordException(
            'Model has no primary key value.'
        );
    }

    public function __get(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    // --- Hydration -------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row): static
    {
        $instance = new static();
        $instance->attributes = $row;
        return $instance;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function filterFillable(array $data): array
    {
        if (static::$fillable === []) {
            throw new ActiveRecordException(
                static::class . '::$fillable is empty. Declare which columns are mass-assignable — ' .
                'mass assignment of undeclared columns is structurally blocked, not just documented against.'
            );
        }

        return array_intersect_key($data, array_flip(static::$fillable));
    }
}
