<?php

namespace LaravelScoutDebouncer\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use LaravelScoutDebouncer\Concerns\BatchSearchable;

class SearchablePost extends Model
{
    use BatchSearchable;

    protected $table = 'posts';
    protected $guarded = [];
    public $timestamps = false;
}
