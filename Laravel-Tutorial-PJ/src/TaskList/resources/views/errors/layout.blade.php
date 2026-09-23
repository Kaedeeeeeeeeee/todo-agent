<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') | TaskList</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <header class="site-header"><a class="brand" href="{{ route('home') }}">TaskList</a></header>
    <main class="container">@yield('content')</main>
</body>
</html>
