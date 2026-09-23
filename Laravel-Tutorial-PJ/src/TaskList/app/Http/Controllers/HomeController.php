<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $folder = $request->user()->folders()->orderBy('id')->first();

        return $folder
            ? redirect()->route('tasks.index', ['folder' => $folder])
            : view('home');
    }
}
