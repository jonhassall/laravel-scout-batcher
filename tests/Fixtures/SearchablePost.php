<?php

namespace JonHassall\ScoutBatcher\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use JonHassall\ScoutBatcher\Concerns\BatchSearchable;

class SearchablePost extends Model
{
    use BatchSearchable;

    protected $table = 'posts';
    protected $guarded = [];
    public $timestamps = false;
}
