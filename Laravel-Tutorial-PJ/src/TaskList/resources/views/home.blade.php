@extends('layouts.app')
@section('title', 'ホーム')
@section('content')
    <section class="card form-card">
        <h1>最初のフォルダを作りましょう</h1>
        <a class="button" href="{{ route('folders.create') }}">フォルダを作成</a>
    </section>
@endsection
