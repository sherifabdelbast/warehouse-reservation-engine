<?php

namespace App\Console\Commands;

use App\Jobs\ProcessShipmentJob;
use App\Models\Shipment;
use Illuminate\Console\Command;

class ProcessPendingShipments extends Command
{
    protected $signature = 'shipments:process';
    protected $description = 'Dispatch jobs for all pending shipments';

    public function handle(): int
    {
        $pending = Shipment::where('status', 'pending')->pluck('id');

        foreach ($pending as $id) {
            ProcessShipmentJob::dispatch($id);
        }

        $this->info("Dispatched {$pending->count()} shipment job(s).");

        return self::SUCCESS;
    }
}
