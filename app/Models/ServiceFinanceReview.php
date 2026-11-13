<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceFinanceReview extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['responded_at' => 'datetime', 'closed_at' => 'datetime'];
    }
}
