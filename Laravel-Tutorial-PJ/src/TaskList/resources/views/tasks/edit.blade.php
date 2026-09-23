@extends('layouts.app')
@section('title', 'タスク編集')
@section('content')
    <section class="card form-card">
        <h1>{{ $folder->title }} のタスクを編集</h1>
        @include('share.errors')
        <form method="POST" action="{{ route('tasks.edit', ['folder' => $folder, 'task' => $task]) }}">
            @csrf
            @include('tasks._fields')
            <button type="submit">更新する</button>
        </form>
        <p><a href="{{ route('tasks.index', ['folder' => $folder]) }}">タスク一覧に戻る</a></p>
    </section>
@endsection
