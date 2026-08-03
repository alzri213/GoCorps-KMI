<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequestModel extends Model
{
    protected $connection = 'gocorps';
    protected $table = 'tr_request';
    protected $primaryKey = 'Id';
    public $timestamps = false;

    protected $fillable = [
        'RequesterUserId',
        'RequestType',
        'CreatedAt',
        'Status',
        'Purpose',
        'NumberOfTrips',
        'PhoneNumber',
        'Address',
        'TransportMethod',
        'Notes',
        'AdditionalData',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'RequesterUserId', 'Id');
    }

    public function trips()
    {
        return $this->hasMany(TripModel::class, 'RequestId', 'Id');
    }

    public function approvals()
    {
        return $this->hasMany(ApprovalModel::class, 'RequestId', 'Id');
    }

    public function vehicleUsage()
    {
        return $this->hasOne(VehicleUsageModel::class, 'RequestId', 'Id');
    }

    /**
     * Determine next required approval level based on Rules 1, 2, 3:
     * - Staff: SPV -> Manager -> Divisi
     * - SPV: Manager -> Divisi
     * - Manager (Superior = Divisi): Divisi (1x approval)
     * - Manager (Superior = Direksi): Direksi (1x approval)
     */
    public function getNextRequiredApprovalLevel(): string
    {
        $status = $this->Status;
        $requester = $this->requester;
        $positionLevel = $requester ? $requester->getPositionLevel() : 'staff';
        $superiorLevel = $requester ? $requester->getSuperiorLevel() : 'divisi';

        if ($status === 'diajukan') {
            if ($positionLevel === 'staff') {
                return 'spv';
            } elseif ($positionLevel === 'spv') {
                return 'atasan';
            } elseif ($positionLevel === 'manager') {
                return ($superiorLevel === 'direksi') ? 'direksi' : 'divisi';
            } elseif ($positionLevel === 'divisi') {
                return 'divisi';
            } else {
                return 'completed';
            }
        }

        if ($status === 'disetujui_spv') {
            return 'atasan';
        }

        if ($status === 'disetujui_atasan') {
            return 'divisi';
        }

        return 'completed';
    }
}
