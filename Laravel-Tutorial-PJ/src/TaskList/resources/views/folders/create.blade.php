@extends('layouts.app')
@section('title', 'フォルダ作成')
@section('content')
    <section class="card form-card">
        <h1>フォルダを作成する</h1>
        @include('share.errors')
        <form method="POST" action="{{ route('folders.create') }}">
            @csrf
            <div class="field">
                <label for="title">フォルダ名</label>
                <input id="title" name="title" value="{{ old('title', $folder->title ?? '') }}" maxlength="20" required>
            </div>
            <button type="submit">保存する</button>
        </form>
        <p><a href="{{ route('home') }}">一覧に戻る</a></p>
    </section>
@endsection
