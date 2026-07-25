<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationHistory extends Model
{
    public $timestamps = false;

    protected $table = 'reservation_history';

    protected $fillable = [
        'reservation_id',
        'from_status',
        'to_status',
        'note',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
