<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    protected $fillable = ['name', 'status'];

    public function issueTypes()
    {
        return $this->hasMany(IssueType::class);
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }
}
