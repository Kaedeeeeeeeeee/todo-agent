@extends('errors.layout')
@section('title', '419')
@section('content')
    <section class="card error-page">
        <p class="error-code">419</p>
        <h1>ページの有効期限が切れました</h1>
        <p>フォームを開き直して、もう一度入力してください。</p>
        <a class="button" href="{{ route('home') }}">ホームに戻る</a>
    </section>
@endsection
