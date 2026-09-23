@extends('layouts.app')
@section('title', 'ログイン')
@section('content')
    <section class="card form-card">
        <h1>ログイン</h1>
        <p class="muted">自分のフォルダとタスクを管理しましょう。</p>
        @include('share.errors')
        <form method="POST" action="{{ route('login') }}">
            @csrf
            <div class="field"><label for="email">メールアドレス</label><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus></div>
            <div class="field"><label for="password">パスワード</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
            <p><label><input type="checkbox" name="remember" value="1" @checked(old('remember'))> ログイン状態を保持する</label></p>
            <button type="submit">ログイン</button>
        </form>
        <p><a href="{{ route('password.request') }}">パスワードを忘れた方</a></p>
        <p>初めての方は <a href="{{ route('register') }}">会員登録</a></p>
    </section>
@endsection
