@extends('layouts.app')
@section('title', 'パスワード再設定')
@section('content')
    <section class="card form-card">
        <h1>パスワードを再設定</h1>
        <p>登録したメールアドレスへ再設定リンクを送信します。</p>
        @include('share.errors')
        <form method="POST" action="{{ route('password.email') }}">
            @csrf
            <div class="field"><label for="email">メールアドレス</label><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required></div>
            <button type="submit">再設定リンクを送信</button>
        </form>
        <p><a href="{{ route('login') }}">ログインに戻る</a></p>
    </section>
@endsection
