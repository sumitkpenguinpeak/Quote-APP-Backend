<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffMember extends Model
{
    protected $fillable = ['shop_id', 'name', 'email', 'role', 'is_active', 'last_login_at'];

    protected $casts = [
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function assignedQuotes()
    {
        return $this->hasMany(Quote::class, 'assigned_staff_id');
    }

    public function quotes()
    {
        return $this->belongsToMany(Quote::class, 'quote_staff_assignments', 'staff_member_id', 'quote_id')
            ->withPivot('assigned_at')
            ->withTimestamps();
    }
}
