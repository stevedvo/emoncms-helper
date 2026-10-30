<?php
	namespace App\Http\Controllers;

	use App\Http\Requests\UpdateControlSettingsRequest;
	use App\Http\Requests\UpdateTariffPeriodsRequest;
	use App\Services\ControlSettings;
	use App\Services\TariffPeriods;

	class SettingsController extends Controller
	{
		public function index()
		{
			ControlSettings::initialize();
			$values = ControlSettings::values();
			$tariff = TariffPeriods::settings();

			return view('settings',
			[
				'groups'         => collect(ControlSettings::definitions())->groupBy('group', true),
				'values'         => $values,
				'revision'       => ControlSettings::revision($values),
				'tariff'         => $tariff,
				'tariffRevision' => TariffPeriods::revision($tariff),
			]);
		}

		public function update(UpdateControlSettingsRequest $request)
		{
			$count = ControlSettings::save($request->parameters(), $request->validated('revision'), $request->user()->id);

			return redirect()->route('settings')->with('status', $count ? $count.' setting'.($count === 1 ? '' : 's').' saved. The controls will use the new values on their next run.' : 'No settings were changed.');
		}

		public function updateTariff(UpdateTariffPeriodsRequest $request)
		{
			TariffPeriods::save($request->periods(), $request->validated('tariff_revision'), $request->user()->id);

			return redirect()->to(route('settings').'#tariff-periods')->with('status', 'Tariff periods saved. The next control calculation will use them.');
		}
	}
