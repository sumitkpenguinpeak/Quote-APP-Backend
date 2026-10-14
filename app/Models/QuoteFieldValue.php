<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuoteFieldValue extends Model
{
    protected $fillable = ['quote_id', 'quote_form_field_id', 'label', 'value'];

    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }

    public function formField()
    {
        return $this->belongsTo(QuoteFormField::class, 'quote_form_field_id');
    }
}
