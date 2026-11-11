@extends('layouts.app')
@section('title', 'ホーム')
@section('content')
    <div data-folder-starter data-create-url="{{ route('folders.create') }}">
        <section class="card form-card">
            <h1>最初のフォルダを作りましょう</h1>
            <a data-create-folder class="button" href="{{ route('folders.create') }}">フォルダを作成</a>
        </section>
    </div>
@endsection
