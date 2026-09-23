@extends('layouts.app')
@section('title', 'フォルダ削除')
@section('content')
    <section class="card form-card">
        <h1>フォルダを削除しますか？</h1>
        <p class="summary">{{ $folder->title }}</p>
        <p>このフォルダと中のタスク {{ $folder->tasks_count }} 件を削除します。この操作は取り消せません。</p>
        <form method="POST" action="{{ route('folders.delete', ['folder' => $folder]) }}">
            @csrf
            <div class="actions">
                <a class="button secondary" href="{{ route('tasks.index', ['folder' => $folder]) }}">キャンセル</a>
                <button class="danger" type="submit">フォルダとタスクを削除</button>
            </div>
        </form>
    </section>
@endsection
