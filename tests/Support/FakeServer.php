<?php

namespace Tests\Support;

/**
 * Server model stand-in with a settings relation.
 */
class FakeServer
{
    public int $id = 1;

    public string $extension = 'DomainResellerApi';

    public FakePropertyRelation $relation;

    public function __construct(public array $settings = [])
    {
        $this->relation = new FakePropertyRelation;
    }

    public function settings(): FakePropertyRelation
    {
        return $this->relation;
    }
}
