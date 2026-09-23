@extends('layouts.app')
@section('title', 'タスク削除')
@section('content')
    <section class="card form-card">
        <h1>タスクを削除しますか？</h1>
        <div class="summary">
            <strong>{{ $task->title }}</strong>
            <p>{{ $task->status_label }} ／ 期限 {{ $task->formatted_due_date }}</p>
        </div>
        <p>この操作は取り消せません。</p>
        <form method="POST" action="{{ route('tasks.delete', ['folder' => $folder, 'task' => $task]) }}">
            @csrf
            <div class="actions">
                <a class="button secondary" href="{{ route('tasks.index', ['folder' => $folder]) }}">キャンセル</a>
                <button class="danger" type="submit">タスクを削除</button>
            </div>
        </form>
    </section>
@endsection
