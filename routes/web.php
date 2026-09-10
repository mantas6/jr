<?php

use App\Http\Controllers\JiraAttachmentController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('/jira/attachment', JiraAttachmentController::class)
    ->middleware('auth')
    ->name('jira.attachment');
