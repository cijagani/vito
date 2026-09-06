<?php

namespace Database\Factories;

use App\Models\ServerHostnameReservation;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerHostnameReservation>
 */
class ServerHostnameReservationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'server_id' => 1,
            'site_id' => Site::factory(),
            'hostname' => fake()->unique()->domainName(),
        ];
    }
}
