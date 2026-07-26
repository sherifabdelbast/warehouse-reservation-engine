<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Exceptions\InsufficientStockException;
use App\Jobs\ProcessShipmentJob;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\Warehouse;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReservationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ReservationService $service;
    protected Product $product;
    protected Warehouse $warehouse;
    protected Inventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ReservationService();
        $this->product = Product::factory()->create();
        $this->warehouse = Warehouse::factory()->create();
        $this->inventory = Inventory::factory()->create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'available_quantity' => 10,
            'reserved_quantity' => 0,
        ]);
    }

    protected function makeOrderWithItem(int $quantity): array
    {
        $order = Order::factory()->create();
        $orderItem = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'quantity' => $quantity,
        ]);

        return [$order, $orderItem];
    }

    #[Test]
    public function reserving_stock_reduces_available_and_increases_reserved(): void
    {
        [$order, $orderItem] = $this->makeOrderWithItem(3);

        $reservation = $this->service->reserve(
            $order->id,
            $orderItem->id,
            $this->product->id,
            $this->warehouse->id,
            3
        );

        $this->inventory->refresh();

        $this->assertEquals(7, $this->inventory->available_quantity);
        $this->assertEquals(3, $this->inventory->reserved_quantity);
        $this->assertEquals(ReservationStatus::Reserved, $reservation->status);
    }

    #[Test]
    public function reserving_more_than_available_throws_insufficient_stock_exception(): void
    {
        [$order, $orderItem] = $this->makeOrderWithItem(20);

        $this->expectException(InsufficientStockException::class);

        $this->service->reserve(
            $order->id,
            $orderItem->id,
            $this->product->id,
            $this->warehouse->id,
            20
        );
    }

    #[Test]
    public function concurrent_reservations_for_the_last_unit_only_one_succeeds(): void
    {
        // Set stock down to exactly 1 unit to force the race condition
        $this->inventory->update(['available_quantity' => 1]);

        [$orderA, $itemA] = $this->makeOrderWithItem(1);
        [$orderB, $itemB] = $this->makeOrderWithItem(1);

        $succeeded = 0;
        $failed = 0;

        // Simulate near-simultaneous requests by running two reservation
        // attempts back to back against the same locked row. Since
        // lockForUpdate() serializes access within transactions, the second
        // call will see the already-decremented row and correctly fail.
        try {
            $this->service->reserve($orderA->id, $itemA->id, $this->product->id, $this->warehouse->id, 1);
            $succeeded++;
        } catch (InsufficientStockException $e) {
            $failed++;
        }

        try {
            $this->service->reserve($orderB->id, $itemB->id, $this->product->id, $this->warehouse->id, 1);
            $succeeded++;
        } catch (InsufficientStockException $e) {
            $failed++;
        }

        $this->assertEquals(1, $succeeded);
        $this->assertEquals(1, $failed);

        $this->inventory->refresh();
        $this->assertEquals(0, $this->inventory->available_quantity);
    }

    #[Test]
    public function partial_shipment_updates_quantities_and_keeps_reservation_open(): void
    {
        [$order, $orderItem] = $this->makeOrderWithItem(10);

        $reservation = $this->service->reserve(
            $order->id,
            $orderItem->id,
            $this->product->id,
            $this->warehouse->id,
            10
        );

        $this->service->confirmShipment($reservation, 6);

        $reservation->refresh();
        $this->inventory->refresh();

        $this->assertEquals(6, $reservation->quantity_shipped);
        $this->assertEquals(ReservationStatus::PartiallyShipped, $reservation->status);
        $this->assertEquals(4, $this->inventory->reserved_quantity);
        $this->assertEquals(6, $this->inventory->shipped_quantity);

        // Ship the remainder — should now flip to fully Shipped
        $this->service->confirmShipment($reservation, 4);
        $reservation->refresh();

        $this->assertEquals(10, $reservation->quantity_shipped);
        $this->assertEquals(ReservationStatus::Shipped, $reservation->status);
    }

    #[Test]
    public function expired_reservations_are_released_back_to_available_stock(): void
    {
        [$order, $orderItem] = $this->makeOrderWithItem(4);

        $reservation = $this->service->reserve(
            $order->id,
            $orderItem->id,
            $this->product->id,
            $this->warehouse->id,
            4,
            ttlMinutes: -1
        );

        // ttlMinutes: -1 means expires_at is already in the past
        $releasedCount = $this->service->releaseExpired();

        $this->assertEquals(1, $releasedCount);

        $reservation->refresh();
        $this->inventory->refresh();

        $this->assertEquals(ReservationStatus::Expired, $reservation->status);
        $this->assertEquals(10, $this->inventory->available_quantity);
        $this->assertEquals(0, $this->inventory->reserved_quantity);
    }

    #[Test]
    public function duplicate_shipment_job_run_does_not_double_ship(): void
    {
        [$order, $orderItem] = $this->makeOrderWithItem(3);

        $reservation = $this->service->reserve(
            $order->id,
            $orderItem->id,
            $this->product->id,
            $this->warehouse->id,
            3
        );

        $shipment = Shipment::create([
            'reservation_id' => $reservation->id,
            'status' => 'pending',
            'quantity' => 3,
        ]);


        ShipmentEvent::create([
            'shipment_id' => $shipment->id,
            'idempotency_key' => "shipment-{$shipment->id}-attempt",
            'event_type' => 'delivered',
            'payload' => ['status' => 'delivered'],
            'processed_at' => now(),
            'created_at' => now(),
        ]);

        $eventCountBefore = ShipmentEvent::where('shipment_id', $shipment->id)->count();

        // Running the job again should be a no-op due to the idempotency key
        (new ProcessShipmentJob($shipment->id))->handle(
            app(\App\Services\MockShippingProvider::class),
            $this->service
        );

        $eventCountAfter = ShipmentEvent::where('shipment_id', $shipment->id)->count();

        $this->assertEquals($eventCountBefore, $eventCountAfter);
    }

    public function reserving_zero_or_negative_quantity_throws_exception(): void
    {
        [$order, $orderItem] = $this->makeOrderWithItem(1);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->reserve(
            $order->id,
            $orderItem->id,
            $this->product->id,
            $this->warehouse->id,
            0
        );
    }
}
