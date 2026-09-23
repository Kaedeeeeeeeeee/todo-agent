<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateTask;
use App\Http\Requests\EditTask;
use App\Models\Folder;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TaskController extends Controller
{
    public function index(Request $request, Folder $folder): View
    {
        return view('tasks.index', [
            'folders' => $request->user()->folders()->orderBy('id')->get(),
            'currentFolder' => $folder,
            'tasks' => $folder->tasks()->orderBy('id')->get(),
        ]);
    }

    public function showCreateForm(Folder $folder): View
    {
        return view('tasks.create', compact('folder'));
    }

    public function create(CreateTask $request, Folder $folder): RedirectResponse
    {
        $task = new Task;
        $task->title = $request->validated('title');
        $task->due_date = $request->validated('due_date');
        $task->status = 1;
        $folder->tasks()->save($task);

        return redirect()->route('tasks.index', ['folder' => $folder])->with('status', 'タスクを追加しました。');
    }

    public function showEditForm(Folder $folder, Task $task): View
    {
        return view('tasks.edit', compact('folder', 'task'));
    }

    public function edit(EditTask $request, Folder $folder, Task $task): RedirectResponse
    {
        $task->title = $request->validated('title');
        $task->status = $request->validated('status');
        $task->due_date = $request->validated('due_date');
        $task->save();

        return redirect()->route('tasks.index', ['folder' => $folder])->with('status', 'タスクを更新しました。');
    }

    public function showDeleteForm(Folder $folder, Task $task): View
    {
        return view('tasks.delete', compact('folder', 'task'));
    }

    public function delete(Folder $folder, Task $task): RedirectResponse
    {
        $task->delete();

        return redirect()->route('tasks.index', ['folder' => $folder])->with('status', 'タスクを削除しました。');
    }
}
