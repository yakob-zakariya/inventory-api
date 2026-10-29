<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

it('backs up, cleans up, monitors and verifies every night', function () {
    $commands = collect(app(Schedule::class)->events())
        ->map(fn (Event $event) => preg_replace('/^.*artisan\'?\s+/', '', $event->command))
        ->all();

    expect($commands)->toContain('backup:run', 'backup:clean', 'backup:monitor', 'inventory:verify');
});

it('backs up the database and the uploaded images', function () {
    expect(config('backup.backup.source.databases'))->toBe([config('database.default')])
        ->and(config('backup.backup.source.files.include'))->toBe([storage_path('app/public')]);
});
