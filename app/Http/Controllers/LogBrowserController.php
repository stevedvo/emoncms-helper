<?php
	namespace App\Http\Controllers;

	use App\Http\Requests\LogSearchRequest;
	use App\Models\SavedLogSearch;
	use App\Services\LogSearch;
	use Illuminate\Http\RedirectResponse;
	use Illuminate\Http\Request;
	use Illuminate\Support\Facades\Validator;
	use Illuminate\View\View;

	class LogBrowserController extends Controller
	{
		public function index(LogSearchRequest $request, string $type) : View|RedirectResponse
		{
			$input = $request->validated();
			$selected = null;
			$searches = SavedLogSearch::where('user_id', $request->user()->id)->where('type', $type);

			if (isset($input['saved_search']))
			{
				$selected = (clone $searches)->findOrFail($input['saved_search']);
				$perPage = $input['per_page'] ?? null;
				// Apply the same validation to stored criteria as to a fresh search.
				$input = Validator::make($selected->criteria, $request->rules())->validate();

				if ($perPage !== null)
				{
					$input['per_page'] = $perPage;
				}
			}

			$criteria = LogSearch::criteria($input);
			$logs = LogSearch::query($type, $criteria)->paginate($criteria['per_page'])->appends($criteria)->fragment('results-heading');

			if ($selected)
			{
				$logs->appends(['saved_search' => $selected->id]);
			}

			if ($logs->currentPage() > $logs->lastPage())
			{
				return redirect()->to($logs->url($logs->lastPage()));
			}

			return view('logs.index',
			[
				'type'      => $type,
				'fields'    => LogSearch::fields($type),
				'operators' => LogSearch::OPERATORS,
				'criteria'  => $criteria,
				'logs'      => $logs,
				'selected'  => $selected,
				'pageSizes' => in_array($criteria['per_page'], LogSearch::PAGE_SIZES, true) ? LogSearch::PAGE_SIZES : [$criteria['per_page'], ...LogSearch::PAGE_SIZES],
				'searches'  => $searches->orderBy('name')->get(),
			]);
		}

		public function store(LogSearchRequest $request, string $type) : RedirectResponse
		{
			$input = $request->validated();
			$search = SavedLogSearch::updateOrCreate(
			[
				'user_id' => $request->user()->id, 'type' => $type, 'name' => $input['name'],
			],
			[
				'criteria' => LogSearch::criteria($input),
			]);

			return redirect()->route('logs.index', ['type' => $type, 'saved_search' => $search->id])->with('status', 'Search saved. Use its name to run it again at any time.');
		}

		public function destroy(Request $request, string $type, int $search) : RedirectResponse
		{
			SavedLogSearch::where('user_id', $request->user()->id)->where('type', $type)->findOrFail($search)->delete();

			return redirect()->route('logs.index', ['type' => $type])->with('status', 'Saved search removed.');
		}
	}
