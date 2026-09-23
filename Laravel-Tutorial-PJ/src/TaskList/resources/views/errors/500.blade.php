@extends('errors.layout')
@section('title', '500')
@section('content')
    <section class="card error-page">
        <p class="error-code">500</p>
        <h1>サーバーでエラーが発生しました</h1>
        <p>時間をおいてから、もう一度お試しください。</p>
        <a class="button" href="{{ route('home') }}">ホームに戻る</a>
    </section>
@endsection
