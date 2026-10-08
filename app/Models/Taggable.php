<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One assignment: this model carries this value of this category. */
class Taggable extends Model
{
    protected $fillable = ['tag_value_id', 'tag_category_id', 'taggable_type', 'taggable_id'];
}
