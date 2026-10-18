<?php

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TicketEvent> */
class TicketEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'actor_id' => User::factory(),
            'type' => 'created',
            'occurred_at' => now(),
            'metadata' => [],
        ];
    }
}
