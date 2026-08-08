<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('08##########'),
            'password_hash' => static::$password ??= Hash::make('password'),
            'role' => 'sales',
            'status' => 'active',
        ];
    }

    public function role(string $role): static
    {
        return $this->state(fn (array $attributes) => ['role' => $role]);
    }
}
