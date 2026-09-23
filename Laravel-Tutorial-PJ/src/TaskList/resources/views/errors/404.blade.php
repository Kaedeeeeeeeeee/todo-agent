@extends('errors.layout')
@section('title', '404')
@section('content')
    <section class="card error-page">
        <p class="error-code">404</p>
        <h1>ページが見つかりません</h1>
        <p>URLが間違っているか、対象のデータが削除されています。</p>
        <a class="button" href="{{ route('home') }}">ホームに戻る</a>
    </section>
@endsection
