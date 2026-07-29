<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>
        @hasSection('title')
            @yield('title') — {{ config('app.name') }}
        @else
            {{ config('app.name') }}
        @endif
    </title>

    <script>
        (() => {
            const storedTheme = localStorage.getItem('dns-center-theme');
            const preferredTheme = window.matchMedia(
                '(prefers-color-scheme: light)'
            ).matches ? 'light' : 'dark';

            document.documentElement.dataset.theme =
                storedTheme || preferredTheme;
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="@yield('body-class')">
    @yield('content')
</body>
</html>
