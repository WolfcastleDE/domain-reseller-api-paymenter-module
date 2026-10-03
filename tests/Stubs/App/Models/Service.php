<?php

namespace App\Models;

use Tests\Support\FakePropertyRelation;

class Service
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_SUSPENDED = 'suspended';

    public int $id = 1;

    public string $status = self::STATUS_ACTIVE;

    public ?User $user = null;

    public ?Product $product = null;

    public ?object $plan = null;

    public $expires_at = null;

    public int $saved = 0;

    private FakePropertyRelation $relation;

    public function __construct(array $properties = [], ?User $user = null)
    {
        $this->relation = new FakePropertyRelation($properties);
        $this->user = $user ?? new User([
            'address' => 'Musterstraße 12a',
            'city' => 'Berlin',
            'zip' => '10115',
            'country' => 'Germany',
            'phone' => '030 1234567',
        ]);
    }

    public function save(): bool
    {
        if (is_string($this->expires_at)) {
            $this->expires_at = new \DateTimeImmutable($this->expires_at);
        }
        $this->saved++;

        return true;
    }

    public function __set(string $name, $value)
    {
        $this->{$name} = $value;
    }

    public function properties(): FakePropertyRelation
    {
        return $this->relation;
    }

    /**
     * Current properties as key => value (what ExtensionHelper::getServiceProperties() passes).
     */
    public function props(): array
    {
        return $this->relation->toArray();
    }
}
