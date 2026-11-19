<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationMessage extends Model
{
    public const APPLICANT = 'applicant';

    public const PROVIDER = 'provider';

    public const UPDATED_AT = null;

    protected $fillable = ['sender_id', 'sender_side', 'request_key', 'body'];

    protected $hidden = ['sender_id', 'request_key', 'body'];

    protected function casts(): array
    {
        return ['body' => 'encrypted'];
    }
}
