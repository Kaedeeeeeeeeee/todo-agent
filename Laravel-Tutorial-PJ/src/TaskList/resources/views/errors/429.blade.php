@extends('errors.layout')
@section('title', '429')
@section('content')
    <section class="card error-page">
        <p class="error-code">429</p>
        <h1>しばらくお待ちください</h1>
        <p>試行回数が多すぎます。1分ほど待ってから、もう一度お試しください。</p>
        <a class="button" href="{{ route('home') }}">ホームに戻る</a>
    </section>
@endsection
