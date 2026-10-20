<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>@yield('title', 'Overview') · Emoncms Helper</title>
	<link rel="stylesheet" href="{{ asset('css/control-panel.css') }}">
	@stack('head')
</head>
<body>
	<a class="skip-link" href="#main">Skip to content</a>
	<aside class="sidebar">
		<a class="brand" href="{{ route('dashboard') }}" aria-label="Emoncms Helper home">
			<span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 32 32"><path d="M6 23V13l10-7 10 7v10M11 23v-7m5 7V12m5 11v-7"/></svg></span>
			<span>emoncms<span class="brand-sub">HEAT PUMP HELPER</span></span>
		</a>
		<div class="nav-label">YOUR SYSTEM</div>
		<nav aria-label="Main navigation">
			<a class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif><span aria-hidden="true">◫</span> Overview</a>
			<a class="nav-item {{ request()->routeIs('settings*') ? 'active' : '' }}" href="{{ route('settings') }}" @if(request()->routeIs('settings*')) aria-current="page" @endif><span aria-hidden="true">⚙</span> Settings</a>
			<a class="nav-item {{ request()->routeIs('logs.*') && request()->route('type') === 'activity' ? 'active' : '' }}" href="{{ route('logs.index', ['type' => 'activity']) }}" @if(request()->route('type') === 'activity') aria-current="page" @endif><span aria-hidden="true">≡</span> Activity Logs</a>
			<a class="nav-item {{ request()->routeIs('logs.*') && request()->route('type') === 'api' ? 'active' : '' }}" href="{{ route('logs.index', ['type' => 'api']) }}" @if(request()->route('type') === 'api') aria-current="page" @endif><span aria-hidden="true">⇄</span> API Logs</a>
		</nav>
		<div class="sidebar-bottom">
			<div class="small-label">SIGNED IN AS</div>
			<div class="account-name">{{ auth()->user()->name }}</div>
			<form method="post" action="{{ route('logout') }}">@csrf<button class="text-button" type="submit">Sign out <span aria-hidden="true">↗</span></button></form>
		</div>
	</aside>
	<div class="workspace">
		<header class="topbar"><span>Home energy <span class="crumb-divider">/</span> @yield('title', 'Overview')</span><span class="clock">{{ now('Europe/London')->format('D j M Y · H:i') }} UK</span><form class="mobile-signout" method="post" action="{{ route('logout') }}">@csrf<button class="text-button" type="submit">Sign out</button></form></header>
		<main id="main" class="main-content">
			@if(session('status'))<div class="notice success" role="status">{{ session('status') }}</div>@endif
			@yield('content')
		</main>
		<footer class="page-footer"><span>Emoncms Helper</span><span>Your home, at the right temperature.</span></footer>
	</div>
	@stack('scripts')
</body>
</html>
