<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\PendingAction;

class PageController extends Controller
{
    public function chat()
    {
        return view('app.chat');
    }

    public function knowledge()
    {
        return view('app.knowledge', [
            'documents' => Document::latest()->paginate(30),
            'collections' => Document::distinct()->orderBy('collection')->pluck('collection'),
        ]);
    }

    public function approvals()
    {
        return view('app.approvals', [
            'pending' => PendingAction::with('conversation')->where('status', 'pending')->latest()->get(),
            'history' => PendingAction::where('status', '!=', 'pending')->latest('decided_at')->limit(30)->get(),
        ]);
    }
}
