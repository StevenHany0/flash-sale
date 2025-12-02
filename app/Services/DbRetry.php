<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;
use Illuminate\Database\QueryException;

class DbRetry
{
    /**
     * Execute a callback with retry on deadlock.
     */
    public static function run(Closure $fn, int $attempts = 5, int $delayMs = 50)
{
    $i = 0;
    beginning:
    try {
        return DB::transaction($fn);
    } catch (QueryException $e) {
        $deadlockCodes = ['40001', '1213', '1205'];
        if (++$i < $attempts && in_array($e->getCode(), $deadlockCodes)) {
            usleep($delayMs * 1000);
            goto beginning;
        }
        throw $e;
    }
}
}
