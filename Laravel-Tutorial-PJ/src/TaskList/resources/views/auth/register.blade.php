@extends('layouts.app')
@section('title', '会員登録')
@section('content')
    <section class="card form-card">
        <h1>会員登録</h1>
        @include('share.errors')
        <form method="POST" action="{{ route('register') }}">
            @csrf
            <div class="field"><label for="name">名前</label><input id="name" name="name" value="{{ old('name') }}" maxlength="50" autocomplete="name" required></div>
            <div class="field"><label for="email">メールアドレス</label><input id="email" name="email" type="email" value="{{ old('email') }}" maxlength="255" autocomplete="username" required></div>
            @include('auth.passwords._fields')
            <button type="submit">登録する</button>
        </form>
        <p><a href="{{ route('login') }}">ログインはこちら</a></p>
    </section>
@endsection
