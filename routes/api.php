<?php

use Illuminate\Support\Facades\Route;
use MlSolutions\NovaLogsView\Http\Controllers\LogVisibilityController;

/*
|--------------------------------------------------------------------------
| Tool API Routes
|--------------------------------------------------------------------------
|
| Here is where you may register API routes for your tool. These routes
| are loaded by the ServiceProvider of your tool. They are protected
| by your tool's "Authorize" middleware by default. Now, go build!
|
*/

Route::get('/entries', [LogVisibilityController::class, 'index'])
    ->name('nova-logs-view.entries.index');
Route::get('/entries/{entry}', [LogVisibilityController::class, 'show'])
    ->name('nova-logs-view.entries.show');
