<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'channel', 'subject', 'body', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
