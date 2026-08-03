<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleUsageModel extends Model
{
    protected $connection = 'gocorps';
    protected $table = 'tr_vehicle_usage';
    protected $primaryKey = 'Id';
    public $timestamps = false;
 
    protected $fillable = [
        'RequestId',
        'VehicleNumber',
        'DriverName',
        'DepartureTime',
        'DepartureKm',
        'ArrivalTime',
        'ArrivalKm',
        'FilledByHroUserId',
        'FilledByHroAt',
        'FilledBySecurityUserId',
        'FilledBySecurityAt',
        'Notes',
        'CreatedAt',
    ];
}
