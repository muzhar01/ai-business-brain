<?php

// JSON endpoints shared by the REST API (token) and the web app (session).
use App\Http\Controllers\Brain\ActionController;
use App\Http\Controllers\Brain\ChatController;
use App\Http\Controllers\Brain\CrmController;
use App\Http\Controllers\Brain\DocumentController;
use Illuminate\Support\Facades\Route;

Route::get('providers', [ChatController::class, 'providers']);
Route::get('agents', [ChatController::class, 'agents']);

Route::post('chat', [ChatController::class, 'chat']);
Route::post('chat/stream', [ChatController::class, 'stream']);
Route::get('conversations', [ChatController::class, 'conversations']);
Route::get('conversations/{conversation}', [ChatController::class, 'show']);
Route::get('conversations/{conversation}/export', [ChatController::class, 'export']);
Route::delete('conversations/{conversation}', [ChatController::class, 'destroy']);
Route::post('messages/{message}/rate', [ChatController::class, 'rate']);

Route::get('documents', [DocumentController::class, 'index']);
Route::post('documents', [DocumentController::class, 'store']);
Route::get('documents/{document}', [DocumentController::class, 'show']);
Route::post('documents/{document}/reprocess', [DocumentController::class, 'reprocess']);
Route::delete('documents/{document}', [DocumentController::class, 'destroy']);
Route::get('search', [DocumentController::class, 'search']);

Route::get('actions', [ActionController::class, 'index']);
Route::post('actions/{action}/approve', [ActionController::class, 'approve']);
Route::post('actions/{action}/reject', [ActionController::class, 'reject']);

Route::get('leads', [CrmController::class, 'leads']);
Route::get('tasks', [CrmController::class, 'tasks']);
Route::get('report', [CrmController::class, 'report']);
