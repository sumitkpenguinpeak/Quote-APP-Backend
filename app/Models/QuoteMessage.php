<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuoteMessage extends Model
{
    protected $fillable = ['quote_id', 'sender_type', 'sender_staff_id', 'message', 'is_read'];

    protected $casts = [
        'is_read' => 'boolean',
    ];

    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }

    public function senderStaff()
    {
        return $this->belongsTo(StaffMember::class, 'sender_staff_id');
    }

    public function attachments()
    {
        return $this->hasMany(QuoteAttachment::class);
    }
}
