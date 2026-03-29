<?php

namespace Database\Factories;

use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BusinessFactory extends Factory
{
    protected $model = Business::class;

    public function definition(): array
    {
        $name = $this->faker->company();

        return [
            'name'      => $name,
            'slug'      => Str::slug($name) . '-' . Str::random(4),
            'settings'  => [],
            'is_active' => true,
        ];
    }
}
