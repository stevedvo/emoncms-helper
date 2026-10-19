<?php
	namespace App\Http\Controllers;

	use App\Http\Requests\UpdateControlSettingsRequest;
	use App\Services\ControlSettings;

	class SettingsController extends Controller
	{
		public function index()
		{
			ControlSettings::initialize();
			$values = ControlSettings::values();

			return view('settings',
			[
				'groups' => collect(ControlSettings::definitions())->groupBy('group', true),
				'values' => $values, 'revision' => ControlSettings::revision($values),
			]);
		}

		public function update(UpdateControlSettingsRequest $request)
		{
			$count = ControlSettings::save($request->parameters(), $request->validated('revision'), $request->user()->id);

			return redirect()->route('settings')->with('status', $count ? $count.' setting'.($count === 1 ? '' : 's').' saved. The controls will use the new values on their next run.' : 'No settings were changed.');
		}
	}
