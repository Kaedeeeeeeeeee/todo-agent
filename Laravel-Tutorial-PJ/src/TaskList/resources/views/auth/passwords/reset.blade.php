@extends('layouts.app')
@section('title', '新しいパスワード')
@section('content')
    <section class="card form-card">
        <h1>新しいパスワードを設定</h1>
        @include('share.errors')
        <form method="POST" action="{{ route('password.update') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <div class="field"><label for="email">メールアドレス</label><input id="email" name="email" type="email" value="{{ old('email', $email) }}" autocomplete="username" required></div>
            @include('auth.passwords._fields')
            <button type="submit">パスワードを再設定</button>
        </form>
        <p><a href="{{ route('password.request') }}">新しい再設定リンクを取得</a></p>
    </section>
@endsection
