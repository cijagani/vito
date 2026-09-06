<?php

namespace Database\Factories;

use App\Enums\PortProtocol;
use App\Models\ServerPortReservation;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerPortReservation>
 */
class ServerPortReservationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'server_id' => 1,
            'site_id' => Site::factory(),
            'protocol' => PortProtocol::TCP,
            'port' => fake()->unique()->numberBetween(1024, 65535),
            'purpose' => 'application',
        ];
    }
}
