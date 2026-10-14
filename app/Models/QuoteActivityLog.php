<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuoteActivityLog extends Model
{
    protected $fillable = [
        'quote_id', 'action', 'performed_by_type', 'performed_by_staff_id', 'description', 'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }

    public function performedByStaff()
    {
        return $this->belongsTo(StaffMember::class, 'performed_by_staff_id');
    }
}
