<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\WhatsappMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

class WhatsappMessageFactory extends Factory
{
    protected $model = WhatsappMessage::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Customer::factory()->create()->tenant_id,
            'lead_id' => Lead::factory(),
            'to_phone' => '6281234567890',
            'provider' => 'echo',
            'status' => 'queued',
            'message' => $this->faker->sentence(),
        ];
    }
}
