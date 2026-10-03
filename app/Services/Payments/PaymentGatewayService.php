<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PaymentGatewayService
{
    private const FAILURE_KEY = 'payment_gateway:failures';

    private const OPEN_UNTIL_KEY = 'payment_gateway:open_until';

    private const FAILURE_THRESHOLD = 5;

    private const CIRCUIT_OPEN_SECONDS = 30;

    public function charge(
        array $payload,
        string $idempotencyKey
    ): array {
        $this->ensureCircuitIsClosed();

        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::acceptJson()
                    ->withHeaders([
                        'Idempotency-Key' => $idempotencyKey,
                    ])
                    ->timeout(3)
                    ->post(
                        config('services.payment_gateway.url').'/charges',
                        $payload
                    );

                /*
                |--------------------------------------------------------------------------
                | Successful payment
                |--------------------------------------------------------------------------
                */

                if ($response->successful()) {
                    $this->resetCircuit();

                    return $response->json();
                }

                /*
                |--------------------------------------------------------------------------
                | 4xx = client/business error
                |--------------------------------------------------------------------------
                |
                | Example:
                | card declined
                | invalid request
                |
                | Isko retry nahi karna.
                |
                */

                if ($response->clientError()) {
                    throw new RuntimeException(
                        'Payment request was rejected.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | 5xx = provider/server temporary problem
                |--------------------------------------------------------------------------
                */

                if ($response->serverError()) {
                    if ($attempt === $maxAttempts) {
                        $this->recordFailure();

                        throw new RuntimeException(
                            'Payment service is temporarily unavailable.'
                        );
                    }
                }
            } catch (ConnectionException $exception) {

                /*
                |--------------------------------------------------------------------------
                | Connection / timeout error
                |--------------------------------------------------------------------------
                */

                if ($attempt === $maxAttempts) {
                    $this->recordFailure();

                    throw new RuntimeException(
                        'Could not connect to payment service.',
                        0,
                        $exception
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Exponential Backoff
            |--------------------------------------------------------------------------
            |
            | Attempt 1 → 200ms
            | Attempt 2 → 400ms
            | Attempt 3 → no further retry
            |
            */

            if ($attempt < $maxAttempts) {
                $delayMilliseconds = 200 * (2 ** ($attempt - 1));

                usleep(
                    $delayMilliseconds * 1000
                );
            }
        }

        throw new RuntimeException(
            'Payment service request failed.'
        );
    }

    private function ensureCircuitIsClosed(): void
    {
        $openUntil = Cache::get(
            self::OPEN_UNTIL_KEY
        );

        if (! $openUntil) {
            return;
        }

        if (now()->timestamp < (int) $openUntil) {
            throw new RuntimeException(
                'Payment service circuit is temporarily open.'
            );
        }

        Cache::forget(
            self::OPEN_UNTIL_KEY
        );
    }

    private function recordFailure(): void
    {
        $failures = (int) Cache::get(
            self::FAILURE_KEY,
            0
        );

        $failures++;

        Cache::put(
            self::FAILURE_KEY,
            $failures,
            now()->addMinute()
        );

        if ($failures >= self::FAILURE_THRESHOLD) {
            Cache::put(
                self::OPEN_UNTIL_KEY,
                now()
                    ->addSeconds(self::CIRCUIT_OPEN_SECONDS)
                    ->timestamp,
                now()->addSeconds(
                    self::CIRCUIT_OPEN_SECONDS
                )
            );

            Cache::forget(
                self::FAILURE_KEY
            );
        }
    }

    private function resetCircuit(): void
    {
        Cache::forget(
            self::FAILURE_KEY
        );

        Cache::forget(
            self::OPEN_UNTIL_KEY
        );
    }
}
