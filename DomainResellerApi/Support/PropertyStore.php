<?php

namespace Paymenter\Extensions\Servers\DomainResellerApi\Support;

/**
 * Read/write access to Paymenter "properties" (key/value pairs attached to
 * services and users through the HasProperties trait).
 */
final class PropertyStore
{
    public function __construct(private readonly object $model) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->model->properties()->where('key', $key)->value('value');

        return $value ?? $default;
    }

    public function set(string $key, mixed $value, ?string $name = null): void
    {
        if ($value === null) {
            $this->forget($key);

            return;
        }

        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (is_array($value)) {
            $value = json_encode(array_values($value));
        }

        $attributes = ['value' => (string) $value];
        if ($name !== null) {
            $attributes['name'] = $name;
        }

        $this->model->properties()->updateOrCreate(['key' => $key], $attributes);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $names
     */
    public function setMany(array $values, array $names = []): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $names[$key] ?? null);
        }
    }

    public function forget(string $key): void
    {
        $this->model->properties()->where('key', $key)->delete();
    }
}
