<?php

namespace App\Jobs;

use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Services\MockShippingProvider;
use App\Services\ReservationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use App\Enums\ShipmentStatus;

class ProcessShipmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(protected int $shipmentId) {}

    public function handle(MockShippingProvider $provider, ReservationService $reservationService): void
    {
        $shipment = Shipment::with('reservation')->find($this->shipmentId);

        if (! $shipment || $shipment->status !== ShipmentStatus::Pending) {
            return;
        }

        $idempotencyKey = "shipment-{$shipment->id}-attempt";

        if (ShipmentEvent::where('idempotency_key', $idempotencyKey)->exists()) {
            return;
        }

        $result = $provider->attemptShipment("SHP-{$shipment->id}");

        ShipmentEvent::create([
            'shipment_id' => $shipment->id,
            'idempotency_key' => $idempotencyKey,
            'event_type' => $result['status'],
            'payload' => $result,
            'processed_at' => now(),
            'created_at' => now(),
        ]);

        DB::transaction(function () use ($shipment, $result, $reservationService) {
            match ($result['status']) {
                'delivered' => $this->handleSuccess($shipment, $reservationService),
                'failed' => $shipment->update(['status' => 'failed']),
                'timed_out' => $shipment->update(['status' => 'timed_out']),
            };
        });
    }

    protected function handleSuccess(Shipment $shipment, ReservationService $reservationService): void
    {
        $reservationService->confirmShipment($shipment->reservation, $shipment->quantity);
        $shipment->update(['status' => 'delivered']);
    }
}
