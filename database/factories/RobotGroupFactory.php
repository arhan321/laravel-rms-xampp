<?php

namespace Database\Factories;

use App\Models\RobotGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RobotGroup>
 */
class RobotGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Grup uji '.fake()->unique()->numerify('#####'),
            'area_label' => 'Area pengujian',
            'description' => 'Data pengujian aplikasi.',
            'planned_robots' => null,
            'is_pilot' => false,
            'sort_order' => 0,
        ];
    }
}
