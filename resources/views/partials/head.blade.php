<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="csrf-token" content="{{ csrf_token() }}" />

<title>{{ config('app.name') . ': ' . ($title ?? 'Laravel') }}</title>

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

<link rel="icon" type="image/svg+xml" href="{{ url('/static/img/specificat-logo-inverted.svg')  }}">

@vite(['resources/css/app.css', 'resources/js/app.js'])
@stack('scripts')
@fluxAppearance
