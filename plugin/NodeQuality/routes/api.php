<?php
// Explicit registration bypasses a stale Composer negative-class cache under Octane.
require_once __DIR__.'/../Providers/AdminShellV040.php';
app()->register(\Plugin\NodeQuality\Providers\AdminShellV040::class);
use Illuminate\Support\Facades\Route;
use Plugin\NodeQuality\Runtime\V040\Controllers\Api;
Route::prefix('api/v1/node-quality')->group(function(){
    Route::post('agent/enroll',[Api::class,'enroll'])->middleware('throttle:20,1');
    Route::post('agent/sync',[Api::class,'sync'])->middleware('throttle:240,1');
    Route::post('agent/results',[Api::class,'ingest'])->middleware('throttle:240,1');
    Route::middleware('user')->group(function(){
        Route::get('nodes',[Api::class,'nodes']);Route::get('nodes/{node}',[Api::class,'detail'])->whereNumber('node');
        Route::post('nodes/{node}/probe',[Api::class,'manual'])->whereNumber('node');Route::get('tasks/{task}',[Api::class,'task']);
    });
    Route::middleware('admin')->group(function(){Route::get('admin',[Api::class,'admin']);Route::post('admin/{action}',[Api::class,'adminAction']);});
});
