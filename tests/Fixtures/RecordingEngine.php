<?php

namespace JonHassall\ScoutBatcher\Tests\Fixtures;

use Laravel\Scout\Engines\NullEngine;

class RecordingEngine extends NullEngine
{
    /** @var array<int, array<int, int|string>> */
    public array $updates = [];

    /** @var array<int, array<int, int|string>> */
    public array $deletes = [];

    public function update($models)
    {
        $this->updates[] = $models->map->getScoutKey()->values()->all();
    }

    public function delete($models)
    {
        $this->deletes[] = $models->map->getScoutKey()->values()->all();
    }
}
