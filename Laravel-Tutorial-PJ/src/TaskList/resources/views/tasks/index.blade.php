@extends('layouts.app')
@section('title', 'タスク一覧')
@section('content')
    @php
        $workspaceProps = [
            'folders' => $folders->map(fn ($item) => ['id' => $item->id, 'title' => $item->title, 'url' => route('tasks.index', ['folder' => $item])])->values(),
            'folder' => ['id' => $currentFolder->id, 'title' => $currentFolder->title],
            'tasks' => $tasks->map(fn ($task) => [
                'id' => $task->id, 'title' => $task->title, 'status' => (int) $task->status,
                'dueDate' => $task->due_date->format('Y-m-d'), 'dateLabel' => $task->due_date->format('Y/m/d'),
                'editUrl' => route('tasks.edit', ['folder' => $currentFolder, 'task' => $task]),
                'deleteUrl' => route('tasks.delete', ['folder' => $currentFolder, 'task' => $task]),
            ])->values(),
            'routes' => [
                'graphql' => route('graphql'),
                'login' => route('login'),
                'createFolder' => route('folders.create'),
                'createTask' => route('tasks.create', ['folder' => $currentFolder]),
                'editFolder' => route('folders.edit', ['folder' => $currentFolder]),
                'deleteFolder' => route('folders.delete', ['folder' => $currentFolder]),
            ],
            'csrf' => csrf_token(), 'today' => today()->format('Y-m-d'),
        ];
    @endphp
    <script id="task-workspace-props" type="application/json">@json($workspaceProps)</script>
    <div data-task-workspace>
    <div class="workspace">
        <aside class="card">
            <h2>フォルダ一覧</h2>
            <ul class="folder-list">
                @foreach ($folders as $folder)
                    <li @class(['selected' => $folder->id === $currentFolder->id])>
                        <a href="{{ route('tasks.index', ['folder' => $folder]) }}" @if ($folder->id === $currentFolder->id) aria-current="page" @endif>{{ $folder->title }}</a>
                    </li>
                @endforeach
            </ul>
            <p><a href="{{ route('folders.create') }}">＋ フォルダを作成</a></p>
        </aside>
        <section class="card">
            <h1>{{ $currentFolder->title }} のタスク</h1>
            <div class="actions">
                <a class="button" href="{{ route('tasks.create', ['folder' => $currentFolder]) }}">タスクを追加</a>
                <a href="{{ route('folders.edit', ['folder' => $currentFolder]) }}">フォルダ名を編集</a>
                <a class="danger-link" href="{{ route('folders.delete', ['folder' => $currentFolder]) }}">フォルダを削除</a>
            </div>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>タスク名</th><th>状態</th><th>期限</th><th>操作</th></tr></thead>
                    <tbody>
                        @forelse ($tasks as $task)
                            <tr>
                                <td>{{ $task->title }}</td>
                                <td><span class="status {{ $task->status_class }}">{{ $task->status_label }}</span></td>
                                <td>{{ $task->formatted_due_date }}</td>
                                <td>
                                    <a href="{{ route('tasks.edit', ['folder' => $currentFolder, 'task' => $task]) }}">編集</a>
                                    <a class="danger-link" href="{{ route('tasks.delete', ['folder' => $currentFolder, 'task' => $task]) }}">削除</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="empty">このフォルダにはタスクがありません。</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    </div>
@endsection
