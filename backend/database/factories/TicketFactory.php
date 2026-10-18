<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Ticket> */
class TicketFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reference' => 'TKT-'.Str::ulid(),
            'requester_id' => User::factory(),
            'technician_id' => null,
            'category_id' => Category::factory(),
            'title' => fake()->sentence(5),
            'description' => fake()->paragraph(),
            'priority' => 'normal',
            'status' => 'open',
            'resolved_at' => null,
            'closed_at' => null,
        ];
    }
}
