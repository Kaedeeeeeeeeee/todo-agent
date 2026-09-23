@extends('errors.layout')
@section('title', '403')
@section('content')
    <section class="card error-page">
        <p class="error-code">403</p>
        <h1>このページを操作する権限がありません</h1>
        <p>自分のフォルダとタスクから選び直してください。</p>
        <a class="button" href="{{ route('home') }}">ホームに戻る</a>
    </section>
@endsection
