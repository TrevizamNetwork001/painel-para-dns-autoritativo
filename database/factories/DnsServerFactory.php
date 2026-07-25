<?php

namespace Database\Factories;

use App\Models\DnsServer;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DnsServer>
 */
class DnsServerFactory extends Factory
{
    protected $model = DnsServer::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => 'NS '.$this->faker->unique()->numberBetween(1, 9999),
            'hostname' => $this->faker->unique()->domainName(),
            'ipv4_address' => $this->faker->ipv4(),
            'ipv6_address' => null,
            'role' => 'primary',
            'environment' => 'production',
            'status' => 'pending',
            'enabled' => true,
            'agent_uuid' => null,
            'agent_version' => null,
            'last_seen_at' => null,
            'capabilities' => null,
            'notes' => null,
        ];
    }
}
