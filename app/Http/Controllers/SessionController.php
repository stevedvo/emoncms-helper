<?php
	namespace App\Http\Controllers;

	use Illuminate\Http\Request;
	use Illuminate\Support\Facades\Auth;
	use Illuminate\Validation\ValidationException;

	class SessionController extends Controller
	{
		public function create()
		{
			return view('login');
		}

		public function store(Request $request)
		{
			$credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

			if (!Auth::attempt($credentials))
			{
				throw ValidationException::withMessages(['email' => 'The email address or password is incorrect.']);
			}

			$request->session()->regenerate();

			return redirect()->intended(route('dashboard'));
		}

		public function destroy(Request $request)
		{
			Auth::logout();
			$request->session()->invalidate();
			$request->session()->regenerateToken();
			return redirect()->route('login');
		}
	}
