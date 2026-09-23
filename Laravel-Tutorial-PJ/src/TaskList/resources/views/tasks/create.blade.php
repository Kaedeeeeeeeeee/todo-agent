@extends('layouts.app')
@section('title', 'タスク作成')
@section('content')
    <section class="card form-card">
        <h1>{{ $folder->title }} にタスクを追加</h1>
        @include('share.errors')
        <form method="POST" action="{{ route('tasks.create', ['folder' => $folder]) }}">
            @csrf
            @include('tasks._fields')
            <button type="submit">保存する</button>
        </form>
        <p><a href="{{ route('tasks.index', ['folder' => $folder]) }}">タスク一覧に戻る</a></p>
    </section>
@endsection
