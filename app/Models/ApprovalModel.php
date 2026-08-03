<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalModel extends Model
{
    protected $connection = 'gocorps';
    protected $table = 'tr_request_approval';
    protected $primaryKey = 'Id';
    public $timestamps = false;

    protected $fillable = [
        'RequestId',
        'ApproverUserId',
        'Level',
        'Status',
        'Comments',
        'ActionAt',
    ];

    public function approver()
    {
        return $this->belongsTo(User::class, 'ApproverUserId', 'Id');
    }
}
