<?php

namespace App\Modules\Categories\Models;

use Illuminate\Database\Eloquent\Model;

final class Category extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'status'];
}
