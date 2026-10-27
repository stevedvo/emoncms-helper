<?php
	namespace App\Http\Controllers;

	use App\Models\ActivityLog;
	use App\Models\NibeFeedItem;
	use App\Models\Setting;
	use App\Services\ControlSettings;
	use App\Services\LocalEmonFeeds;
	use App\Services\HotWaterStatus;
	use Carbon\CarbonImmutable;
	use Illuminate\Support\Facades\DB;
	use Throwable;

	class DashboardController extends Controller
	{
		public function index()
		{
			$latest = NibeFeedItem::selectRaw('MAX(id) as id')->groupBy('parameterId');
			$readings = NibeFeedItem::joinSub($latest, 'latest', fn ($join) => $join->on('nibe_feed_items.id', '=', 'latest.id'))
				->leftJoin('nibe_parameters', 'nibe_parameters.parameterId', '=', 'nibe_feed_items.parameterId')
				->select('nibe_feed_items.*', 'nibe_parameters.title', 'nibe_parameters.unit')->get()->keyBy('parameterId');
			$state = Setting::whereIn('key', ['htgMode', 'radiatorHeatDemand', 'hotWaterControl'])->get()->keyBy('key');
			$demand = json_decode($state->get('radiatorHeatDemand')?->value ?? 'null', true);
			$demandAge = is_array($demand) && is_int($demand['calculatedAt'] ?? null) ? CarbonImmutable::now()->timestamp - $demand['calculatedAt'] : null;
			$demandValid = $demandAge !== null && $demandAge >= 0 && $demandAge <= ControlSettings::get('hive.radiatorDemandMaxAgeMinutes') * 60 && is_bool($demand['required'] ?? null)
				&& ControlSettings::get('nibe.dmOverride') && ControlSettings::get('nibe.loadCompensationOn');
			$priority = isset($readings[49994]) ? (int) $readings[49994]->rawValue : null;
			$warnings = ActivityLog::where('created_at', '>=', now()->subDay())->whereIn('level', ['error', 'warning'])->select('level', DB::raw('COUNT(*) as total'))->groupBy('level')->pluck('total', 'level');

			return view('dashboard',
			[
				'readings'               => $readings,
				'mode'                   => $state->get('htgMode')?->value,
				'priority'               =>
				[
					10 => 'Idle',
					20 => 'Hot water',
					30 => 'Heating',
					60 => 'Cooling'
				][$priority] ?? 'Unknown',
				'priorityCode'           => $priority,
				'radiatorDemand'         => $demandValid ? ($demand['required'] ? 'Heat requested' : 'No heat requested') : 'Fallback / unavailable',
				'demandAge'              => $demandAge,
				'controlEnabled'         => ControlSettings::get('nibe.dmOverride'),
				'warnings'               => $warnings,
				'changes'                => ActivityLog::where('controller', ControlSettings::class)->where('method', 'save')->latest('id')->limit(6)->get(),
				'latestAt'               => $readings->max('timestamp'),
				'hotWaterParameters'     =>
				[
					40014 => 'Hot water charging',
					40013 => 'Hot water top',
				],
				'hotWaterStatus'         => HotWaterStatus::rows($state->get('hotWaterControl')?->value),
				'hotWaterControlEnabled' => ControlSettings::get('nibe.hotWaterControl'),
			]);
		}

		public function feeds(LocalEmonFeeds $emon)
		{
			try
			{
				return response()->json(
				[
					'feeds'     => $emon->latest(),
					'checkedAt' => CarbonImmutable::now()->format('c'),
				])->header('Cache-Control', 'no-store');
			}
			catch (Throwable $exception)
			{
				// Do not expose a service URL, API key, or raw exception in the browser.
				return response()->json(['message' => 'Local Emon is unavailable. Stored NIBE readings are still shown; the dashboard will retry.'], 503)->header('Cache-Control', 'no-store');
			}
		}
	}
