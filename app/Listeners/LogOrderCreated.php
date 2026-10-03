<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class LogOrderCreated implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $backoff = 5;

    public function handle(OrderCreated $event): void
    {
        Log::info('Queued OrderCreated listener processed.', [
            'order_id' => $event->order->id,
            'user_id' => $event->order->user_id,
            'total_amount' => $event->order->total_amount,
        ]);
    }
}