<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class Code
{
    public static function next(string $table, string $prefix, int $width = 5): string
    {
        return DB::transaction(function () use ($table, $prefix, $width): string {
            $last = DB::table($table)->lockForUpdate()->orderByDesc('id')->value('id');
            $number = ((int) $last) + 1;
            return $prefix.'-'.str_pad((string) $number, $width, '0', STR_PAD_LEFT);
        });
    }
}
