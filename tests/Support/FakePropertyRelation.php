<?php

namespace Tests\Support;

/**
 * In-memory replacement for the morphMany "properties" relation.
 */
class FakePropertyRelation
{
    private ?string $whereKey = null;

    public function __construct(private array $values = []) {}

    public function where(string $column, $value): static
    {
        $clone = clone $this;
        $clone->whereKey = (string) $value;
        // Share storage with the original relation.
        $clone->values = &$this->values;

        return $clone;
    }

    public function value(string $column)
    {
        return $this->values[$this->whereKey] ?? null;
    }

    public function delete(): void
    {
        unset($this->values[$this->whereKey]);
    }

    public function updateOrCreate(array $attributes, array $values): void
    {
        $this->values[$attributes['key']] = $values['value'];
    }

    public function pluck(string $value, string $key): object
    {
        $values = $this->values;

        return new class($values)
        {
            public function __construct(private array $values) {}

            public function all(): array
            {
                return $this->values;
            }
        };
    }

    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * @return object[] property models with key/value
     */
    public function models(): array
    {
        $models = [];
        foreach ($this->values as $key => $value) {
            $models[] = (object) ['key' => $key, 'value' => $value];
        }

        return $models;
    }
}
