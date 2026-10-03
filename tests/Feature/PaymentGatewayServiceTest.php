<?php

namespace Tests\Feature;

use App\Services\Payments\PaymentGatewayService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PaymentGatewayServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_payment_succeeds_on_first_attempt(): void
    {
        Http::fake([
            '*' => Http::response([
                'payment_id' => 'pay_123',
                'status' => 'paid',
            ], 200),
        ]);

        $result = app(
            PaymentGatewayService::class
        )->charge([
            'amount' => 1000,
            'currency' => 'PKR',
        ], 'payment-test-001');

        $this->assertSame(
            'paid',
            $result['status']
        );

        Http::assertSentCount(1);
    }

    public function test_payment_retries_after_server_error(): void
    {
        Http::fakeSequence()
            ->push([
                'message' => 'Server error',
            ], 500)
            ->push([
                'message' => 'Still unavailable',
            ], 500)
            ->push([
                'payment_id' => 'pay_456',
                'status' => 'paid',
            ], 200);

        $result = app(
            PaymentGatewayService::class
        )->charge([
            'amount' => 500,
            'currency' => 'PKR',
        ], 'payment-test-002');

        $this->assertSame(
            'paid',
            $result['status']
        );

        Http::assertSentCount(3);
    }

    public function test_client_error_is_not_retried(): void
    {
        Http::fake([
            '*' => Http::response([
                'message' => 'Card declined',
            ], 422),
        ]);

        try {
            app(PaymentGatewayService::class)
                ->charge([
                    'amount' => 500,
                    'currency' => 'PKR',
                ], 'payment-test-003');

            $this->fail(
                'Expected payment exception was not thrown.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Payment request was rejected.',
                $exception->getMessage()
            );
        }

        Http::assertSentCount(1);
    }

    public function test_open_circuit_prevents_external_request(): void
    {
        Cache::put(
            'payment_gateway:open_until',
            now()->addSeconds(30)->timestamp,
            30
        );

        Http::fake();

        try {
            app(PaymentGatewayService::class)
                ->charge([
                    'amount' => 500,
                    'currency' => 'PKR',
                ], 'payment-test-004');

            $this->fail(
                'Expected circuit breaker exception was not thrown.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Payment service circuit is temporarily open.',
                $exception->getMessage()
            );
        }

        Http::assertNothingSent();
    }
}