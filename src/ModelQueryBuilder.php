<?php

declare(strict_types=1);

namespace LombokClarion\ActiveRecord;

use LombokClarion\Persistence\EagerLoader;
use LombokClarion\Persistence\QueryBuilder;
use LombokClarion\Persistence\Relation;

/**
 * @template TModel of Model
 *
 * Thin wrapper that delegates query-building to QueryBuilder and adds
 * model-specific hydration + eager loading. All values are still bound
 * parameters — the same injection-proof QueryBuilder runs underneath.
 */
final class ModelQueryBuilder
{
    /** @var list<string> */
    private array $eagerLoad = [];

    /**
     * @param class-string<TModel> $modelClass
     * @param array<string, Relation> $availableRelations
     */
    public function __construct(
        private QueryBuilder $qb,
        private readonly string $modelClass,
        private readonly array $availableRelations = [],
    ) {
    }

    public function where(string $column, string $operator, mixed $value): self
    {
        $clone = clone $this;
        $clone->qb = $this->qb->where($column, $operator, $value);
        return $clone;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $clone = clone $this;
        $clone->qb = $this->qb->orderBy($column, $direction);
        return $clone;
    }

    public function limit(int $n): self
    {
        $clone = clone $this;
        $clone->qb = $this->qb->limit($n);
        return $clone;
    }

    public function with(string ...$relations): self
    {
        $clone = clone $this;
        $clone->eagerLoad = [...$this->eagerLoad, ...$relations];
        return $clone;
    }

    /**
     * @return list<TModel>
     */
    public function all(): array
    {
        $rows = $this->qb->get();

        if ($this->eagerLoad !== [] && $rows !== []) {
            $pdo = $this->modelClass::getConnection();
            $loader = new EagerLoader($pdo);
            $rows = $loader->load($rows, $this->eagerLoad, $this->availableRelations);
        }

        return array_map([$this->modelClass, 'hydrate'], $rows);
    }

    /**
     * @return TModel|null
     */
    public function first(): ?Model
    {
        $rows = $this->qb->limit(1)->get();
        if ($rows === []) {
            return null;
        }

        if ($this->eagerLoad !== []) {
            $pdo = $this->modelClass::getConnection();
            $loader = new EagerLoader($pdo);
            $rows = $loader->load($rows, $this->eagerLoad, $this->availableRelations);
        }

        return $this->modelClass::hydrate($rows[0]);
    }

    public function count(): int
    {
        return $this->qb->count();
    }
}
