<?php

namespace Database\Factories;

use App\Models\AuthentikStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Usuario con cuenta creada por SIRUTA en Authentik.
     */
    public function provisionedInAuthentik(): static
    {
        return $this->state(fn (array $attributes) => [
            'cedula' => fake()->unique()->numerify('##########'),
            'authentik_subject' => hash('sha256', Str::random(32)),
            'authentik_pk' => fake()->unique()->numberBetween(1000, 999999),
            'authentik_uuid' => (string) Str::uuid(),
            'authentik_status' => AuthentikStatus::Provisioned,
            'authentik_provisioning_key' => (string) Str::uuid(),
            'authentik_provisioned_at' => now(),
        ]);
    }

    /**
     * Usuario vinculado a una cuenta preexistente de Authentik.
     */
    public function linkedToAuthentik(): static
    {
        return $this->state(fn (array $attributes) => [
            'authentik_subject' => hash('sha256', Str::random(32)),
            'authentik_status' => AuthentikStatus::Linked,
        ]);
    }
}
