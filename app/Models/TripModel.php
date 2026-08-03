<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TripModel extends Model
{
    protected $connection = 'gocorps';
    protected $table = 'tr_request_trip';
    protected $primaryKey = 'Id';
    public $timestamps = false;

    protected $fillable = [
        'RequestId',
        'Sequence',
        'Origin',
        'Destination',
        'DepartureDate',
        'ReturnDate',
        'Vehicle',
        'VehicleNumber',
        'PassengerCount',
        'Remarks',
    ];

    public function request()
    {
        return $this->belongsTo(RequestModel::class, 'RequestId', 'Id');
    }
}
