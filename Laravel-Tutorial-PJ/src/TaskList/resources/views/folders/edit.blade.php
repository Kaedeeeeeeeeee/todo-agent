@extends('layouts.app')
@section('title', 'フォルダ編集')
@section('content')
    <section class="card form-card">
        <h1>フォルダ名を編集する</h1>
        @include('share.errors')
        <form method="POST" action="{{ route('folders.edit', ['folder' => $folder]) }}">
            @csrf
            <div class="field">
                <label for="title">フォルダ名</label>
                <input id="title" name="title" value="{{ old('title', $folder->title ?? '') }}" maxlength="20" required>
            </div>
            <button type="submit">更新する</button>
        </form>
        <p><a href="{{ route('tasks.index', ['folder' => $folder]) }}">一覧に戻る</a></p>
    </section>
@endsection
