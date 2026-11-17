<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountRequest extends Model
{
    protected $guarded = [];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }
}
