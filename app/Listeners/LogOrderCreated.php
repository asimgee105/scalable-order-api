<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use Illuminate\Support\Facades\Log;

class LogOrderCreated
{
    public function handle(OrderCreated $event): void
    {
        Log::info('Order created event received.', [
            'order_id' => $event->order->id,
            'user_id' => $event->order->user_id,
            'total_amount' => $event->order->total_amount,
        ]);
    }
}