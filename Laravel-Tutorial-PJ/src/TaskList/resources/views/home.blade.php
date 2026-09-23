@extends('layouts.app')
@section('title', 'ホーム')
@section('content')
    <section class="card form-card">
        <h1>最初のフォルダを作りましょう</h1>
        <p>仕事、勉強、日常など、目的ごとにタスクを整理できます。</p>
        <a class="button" href="{{ route('folders.create') }}">フォルダを作成</a>
    </section>
@endsection
