<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Exceptions\InsufficientStockException;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Reservation;
use App\Models\ReservationHistory;
use Illuminate\Support\Facades\DB;

class ReservationService
{
    protected const DEFAULT_TTL_MINUTES = 15;

    public function reserve(
        int $orderId,
        int $orderItemId,
        int $productId,
        int $warehouseId,
        int $quantity,
        ?int $ttlMinutes = null
    ): Reservation {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Reservation quantity must be greater than zero.');
        }
        return DB::transaction(function () use (
            $orderId,
            $orderItemId,
            $productId,
            $warehouseId,
            $quantity,
            $ttlMinutes
        ) {
            $inventory = Inventory::where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            if (! $inventory) {
                throw new InsufficientStockException(
                    "No inventory record for product {$productId} at warehouse {$warehouseId}."
                );
            }

            if ($inventory->available_quantity < $quantity) {
                throw new InsufficientStockException(
                    "Requested {$quantity}, only {$inventory->available_quantity} available."
                );
            }

            $inventory->available_quantity -= $quantity;
            $inventory->reserved_quantity += $quantity;
            $inventory->save();

            $reservation = Reservation::create([
                'order_id' => $orderId,
                'order_item_id' => $orderItemId,
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'quantity_reserved' => $quantity,
                'quantity_shipped' => 0,
                'status' => ReservationStatus::Reserved,
                'expires_at' => now()->addMinutes($ttlMinutes ?? self::DEFAULT_TTL_MINUTES),
            ]);

            $this->logMovement($productId, $warehouseId, $reservation->id, 'reserve', -$quantity, $inventory->available_quantity);
            $this->logHistory($reservation->id, null, ReservationStatus::Reserved->value, 'Reservation created');

            return $reservation;
        });
    }

    public function release(Reservation $reservation, string $reason = 'Manually released'): Reservation
    {
        return DB::transaction(function () use ($reservation, $reason) {
            $reservation = Reservation::where('id', $reservation->id)->lockForUpdate()->first();

            if (in_array($reservation->status, [
                ReservationStatus::Cancelled,
                ReservationStatus::Expired,
                ReservationStatus::Shipped,
            ])) {
                return $reservation;
            }

            $remaining = $reservation->quantity_reserved - $reservation->quantity_shipped;

            $inventory = Inventory::where('product_id', $reservation->product_id)
                ->where('warehouse_id', $reservation->warehouse_id)
                ->lockForUpdate()
                ->first();

            $inventory->reserved_quantity -= $remaining;
            $inventory->available_quantity += $remaining;
            $inventory->save();

            $fromStatus = $reservation->status;
            $newStatus = $reason === 'Expired automatically'
                ? ReservationStatus::Expired
                : ReservationStatus::Cancelled;

            $reservation->status = $newStatus;
            $reservation->save();

            $this->logMovement($reservation->product_id, $reservation->warehouse_id, $reservation->id, 'release', $remaining, $inventory->available_quantity);
            $this->logHistory($reservation->id, $fromStatus->value, $newStatus->value, $reason);

            return $reservation;
        });
    }

    public function confirmShipment(Reservation $reservation, int $shippedQuantity): Reservation
    {
        if ($shippedQuantity <= 0) {
            throw new \InvalidArgumentException('Shipped quantity must be greater than zero.');
        }
        return DB::transaction(function () use ($reservation, $shippedQuantity) {
            $reservation = Reservation::where('id', $reservation->id)->lockForUpdate()->first();

            $remaining = $reservation->quantity_reserved - $reservation->quantity_shipped;

            if ($shippedQuantity > $remaining) {
                throw new InsufficientStockException(
                    "Cannot ship {$shippedQuantity}, only {$remaining} remain on this reservation."
                );
            }

            $inventory = Inventory::where('product_id', $reservation->product_id)
                ->where('warehouse_id', $reservation->warehouse_id)
                ->lockForUpdate()
                ->first();

            $inventory->reserved_quantity -= $shippedQuantity;
            $inventory->shipped_quantity += $shippedQuantity;
            $inventory->save();

            $fromStatus = $reservation->status;
            $reservation->quantity_shipped += $shippedQuantity;
            $reservation->status = $reservation->quantity_shipped >= $reservation->quantity_reserved
                ? ReservationStatus::Shipped
                : ReservationStatus::PartiallyShipped;
            $reservation->save();

            $this->logMovement($reservation->product_id, $reservation->warehouse_id, $reservation->id, 'ship', -$shippedQuantity, $inventory->shipped_quantity);
            $this->logHistory($reservation->id, $fromStatus->value, $reservation->status->value, "Shipped {$shippedQuantity} units");

            return $reservation;
        });
    }

    public function releaseExpired(): int
    {
        $expired = Reservation::where('status', ReservationStatus::Reserved)
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($expired as $reservation) {
            $this->release($reservation, 'Expired automatically');
        }

        return $expired->count();
    }

    protected function logMovement(int $productId, int $warehouseId, int $reservationId, string $type, int $quantity, int $balanceAfter): void
    {
        InventoryMovement::create([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'reservation_id' => $reservationId,
            'type' => $type,
            'quantity' => $quantity,
            'balance_after' => $balanceAfter,
            'created_at' => now(),
        ]);
    }

    protected function logHistory(int $reservationId, ?string $from, string $to, string $note): void
    {
        ReservationHistory::create([
            'reservation_id' => $reservationId,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'created_at' => now(),
        ]);
    }
}
