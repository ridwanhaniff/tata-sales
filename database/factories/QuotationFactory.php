<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuotationFactory extends Factory
{
    protected $model = Quotation::class;

    public function definition(): array
    {
        $customer = Customer::factory()->create();

        return [
            'tenant_id' => $customer->tenant_id,
            'customer_id' => $customer->id,
            'status' => 'draft',
            'subtotal' => 0,
            'discount_total' => 0,
            'total' => 0,
            'valid_until' => now()->addDays(7),
        ];
    }

    public function forLead(Lead $lead): static
    {
        return $this->state([
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'customer_id' => $lead->customer_id,
        ]);
    }
}
