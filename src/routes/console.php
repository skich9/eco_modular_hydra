<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Programar comando de mora para ejecutarse diariamente a las 00:30
Schedule::command('mora:procesar-diaria')->dailyAt('00:30');
