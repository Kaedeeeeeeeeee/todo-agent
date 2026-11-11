<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'TaskList') | TaskList</title>
    <meta name="color-scheme" content="light">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <header class="site-header">
        <a class="brand" href="{{ route('home') }}">TaskList</a>
        <nav aria-label="メインメニュー">
            @auth
                <span class="user-name">{{ auth()->user()->name }} さん</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="secondary" type="submit">ログアウト</button>
                </form>
            @else
                <a href="{{ route('login') }}">ログイン</a>
                <a href="{{ route('register') }}">会員登録</a>
            @endauth
        </nav>
    </header>
    <main class="container">
        @if (session('status'))
            <p class="notice" role="status">{{ session('status') }}</p>
        @endif
        @yield('content')
    </main>
    @auth
        <script id="folder-create-props" type="application/json">@json(['endpoint' => route('graphql'), 'csrf' => csrf_token(), 'loginUrl' => route('login')])</script>
        <div data-folder-create-dialog></div>
    @endauth
    <script id="server-errors" type="application/json">@json($errors->messages())</script>
</body>
</html>
