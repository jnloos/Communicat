<?php

namespace Database\Factories;

use App\Models\Expert;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExpertFactory extends Factory
{
    protected $model = Expert::class;

    public function definition(): array
    {
        return [
            'name'        => fake()->name(),
            'job'         => fake()->jobTitle(),
            'description' => fake()->paragraph(),
            'prompt'      => fake()->paragraph(),
            'avatar_url'  => null,
        ];
    }
}
