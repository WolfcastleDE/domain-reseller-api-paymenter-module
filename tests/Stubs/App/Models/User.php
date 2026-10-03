<?php

namespace App\Models;

use Tests\Support\FakePropertyRelation;

class User
{
    public int $id = 1;

    public string $first_name = 'Erika';

    public string $last_name = 'Mustermann';

    public string $email = 'erika@example.de';

    private FakePropertyRelation $relation;

    public function __construct(array $properties = [])
    {
        $this->relation = new FakePropertyRelation($properties);
    }

    public function properties(): FakePropertyRelation
    {
        return $this->relation;
    }

    public function __get(string $name)
    {
        if ($name === 'properties') {
            return $this->relation->models();
        }

        return null;
    }
}
