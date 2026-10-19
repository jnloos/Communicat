<?php

namespace Database\Factories;

use App\Models\PersonaAttribute;
use Illuminate\Database\Eloquent\Factories\Factory;

class PersonaAttributeFactory extends Factory
{
    protected $model = PersonaAttribute::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'self_template' => 'You are :value.',
            'roster_template' => ':value',
        ];
    }
}
