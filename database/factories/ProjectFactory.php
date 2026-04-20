<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'title'       => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'settings'    => [],
            'user_id'     => User::factory(),
        ];
    }
}
