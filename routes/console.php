<?php
use Illuminate\Support\Facades\Schedule;

// Prune old agent memories daily at 2am
Schedule::command('agent:prune-memories')->dailyAt('02:00');
