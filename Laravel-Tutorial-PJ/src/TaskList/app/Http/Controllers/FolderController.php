<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateFolder;
use App\Http\Requests\EditFolder;
use App\Models\Folder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FolderController extends Controller
{
    public function showCreateForm(): View
    {
        return view('folders.create');
    }

    public function create(CreateFolder $request): RedirectResponse
    {
        $folder = new Folder;
        $folder->title = $request->validated('title');
        $request->user()->folders()->save($folder);

        return redirect()->route('tasks.index', ['folder' => $folder])->with('status', 'フォルダを作成しました。');
    }

    public function showEditForm(Folder $folder): View
    {
        return view('folders.edit', compact('folder'));
    }

    public function edit(EditFolder $request, Folder $folder): RedirectResponse
    {
        $folder->title = $request->validated('title');
        $folder->save();

        return redirect()->route('tasks.index', ['folder' => $folder])->with('status', 'フォルダ名を更新しました。');
    }

    public function showDeleteForm(Folder $folder): View
    {
        $folder->loadCount('tasks');

        return view('folders.delete', compact('folder'));
    }

    public function delete(Folder $folder): RedirectResponse
    {
        DB::transaction(function () use ($folder): void {
            $folder->tasks()->delete();
            $folder->delete();
        });

        return redirect()->route('home')->with('status', 'フォルダと中のタスクを削除しました。');
    }
}
