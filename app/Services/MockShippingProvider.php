<?php

namespace App\Services;

class MockShippingProvider
{
    /**
     * Simulate attempting a shipment with a real-world-shaped provider.
     * Returns an array describing the outcome so the caller can react.
     */
    public function attemptShipment(string $reference): array
    {
        $outcome = $this->randomOutcome();

        return match ($outcome) {
            'success' => [
                'status' => 'delivered',
                'provider_reference' => $reference,
                'duplicate' => false,
            ],
            'failed' => [
                'status' => 'failed',
                'provider_reference' => $reference,
                'duplicate' => false,
            ],
            'timeout' => [
                'status' => 'timed_out',
                'provider_reference' => $reference,
                'duplicate' => false,
            ],
            'duplicate' => [
                'status' => 'delivered',
                'provider_reference' => $reference,
                'duplicate' => true, // simulates the provider re-sending the same confirmation
            ],
        };
    }

    protected function randomOutcome(): string
    {
        // Weighted so success is most common, but all cases are reachable
        $roll = random_int(1, 100);

        return match (true) {
            $roll <= 60 => 'success',   // 60%
            $roll <= 75 => 'failed',    // 15%
            $roll <= 90 => 'timeout',   // 15%
            default => 'duplicate',     // 10%
        };
    }
}
