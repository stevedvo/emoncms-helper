<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title>Sign in · Emoncms Helper</title>
		<link rel="stylesheet" href="{{ asset('css/control-panel.css') }}">
	</head>
	<body class="login-page">
		<main class="login-card">
			<div class="eyebrow">EMONCMS HELPER</div>
			<h1>A little more comfort.<br>A little more control.</h1>
			<p class="intro">Sign in to view your heat pump and adjust how your home is heated.</p>
			@if($errors->any())
				<div class="notice error" role="alert">{{ $errors->first() }}</div>
			@endif
			<form method="post" action="{{ route('login') }}" class="login-form">
				@csrf
				<label for="email">Email address</label>
				<input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
				<label for="password">Password</label>
				<input id="password" name="password" type="password" autocomplete="current-password" required>
				<button class="button primary" type="submit">Sign in <span aria-hidden="true">→</span></button>
			</form>
			<p class="login-note">Use your control-panel account. Account creation is managed by the installation owner.</p>
		</main>
		<div class="login-decoration" aria-hidden="true">
			<div class="orbit orbit-one"></div>
			<div class="orbit orbit-two"></div>
			<div class="orbit orbit-three"></div>
			<span>Comfort,<br>in balance.</span>
		</div>
	</body>
</html>
