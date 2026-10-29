<?php

namespace Database\Factories;

use App\Models\Expert;
use App\Models\PersonaAttribute;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExpertFactory extends Factory
{
    protected $model = Expert::class;

    /** Gives the expert a value for the Role attribute (created with its standard templates when missing). */
    public function withRole(string $role): static
    {
        return $this->afterCreating(fn (Expert $expert) => $expert->setAttributeValue(PersonaAttribute::role(), $role));
    }

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'description' => fake()->sentence(),
            'avatar_url' => null,
        ];
    }
}
