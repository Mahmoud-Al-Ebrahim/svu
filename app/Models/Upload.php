<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A chunked upload in progress or finished; consumed once by a file, recording or attachment. */
class Upload extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'user_id' => 'integer',
            'size_bytes' => 'integer',
            'received_bytes' => 'integer',
            'chunk_size' => 'integer',
        ];
    }

    public function partPath(): string
    {
        return "uploads/parts/{$this->id}.part";
    }
}
