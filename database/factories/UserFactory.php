<?php

namespace Database\Factories;

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

    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            $profile = [
                'first_name' => fake()->firstName(),
                'last_name' => fake()->lastName(),
                'middle_initial' => fake()->randomLetter(),
                'contact_number' => '0917'.fake()->numerify('#######'),
            ];

            if ($user->isAdmin()) {
                $user->adminProfile()->firstOrCreate([
                    'user_id' => $user->id,
                ], [
                    ...$profile,
                    'display_name' => "{$profile['first_name']} {$profile['last_name']}",
                ]);

                return;
            }

            if ($user->isProvider()) {
                $user->providerProfile()->firstOrCreate([
                    'user_id' => $user->id,
                ], [
                    ...$profile,
                    'provider_name' => fake()->company(),
                    'provider_type' => 'foundation',
                ]);

                return;
            }

            $user->studentProfile()->firstOrCreate([
                'user_id' => $user->id,
            ], $profile);
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'username' => fake()->unique()->userName(),
            'email_verified_at' => now(),
            'role' => 'applicant',
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
}
