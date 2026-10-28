<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Sync otomatis data entity D365.
 *
 * Scheduler dipanggil tiap menit; command sendiri yang memutuskan
 * berdasarkan config (enabled + autoSync + syncIntervalMinutes sejak
 * sync sukses terakhir) — lewat opsi --scheduled agar jalur manual
 * tidak terkena gerbang interval.
 */
Schedule::command('d365:sync --scheduled')->everyMinute();
