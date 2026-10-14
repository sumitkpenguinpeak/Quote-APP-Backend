<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuoteAttachment extends Model
{
    protected $fillable = ['quote_id', 'quote_message_id', 'file_name', 'file_path', 'mime_type', 'file_size'];

    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }

    public function message()
    {
        return $this->belongsTo(QuoteMessage::class, 'quote_message_id');
    }
}
