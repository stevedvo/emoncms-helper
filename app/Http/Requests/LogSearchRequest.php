<?php
	namespace App\Http\Requests;

	use App\Services\LogSearch;
	use Illuminate\Foundation\Http\FormRequest;
	use Illuminate\Validation\Rule;

	class LogSearchRequest extends FormRequest
	{
		public function authorize() : bool
		{
			return $this->user() !== null;
		}

		public function rules() : array
		{
			return [
				'filters'            => ['sometimes', 'array', 'max:20'],
				'filters.*'          => ['array:field,operator,value'],
				'filters.*.field'    => ['required', Rule::in(array_keys(LogSearch::fields($this->route('type'))))],
				'filters.*.operator' => ['required', Rule::in(array_keys(LogSearch::OPERATORS))],
				'filters.*.value'    => ['nullable', 'string', 'max:1000'],
				'from'               => ['nullable', 'date_format:Y-m-d'],
				'to'                 => ['nullable', 'date_format:Y-m-d', $this->filled('from') ? 'after_or_equal:from' : 'sometimes'],
				// Keep old bookmarks and saved searches using 25 or 50 entries usable.
				'per_page'           => ['sometimes', Rule::in([25, 50, ...LogSearch::PAGE_SIZES])],
				'page'               => ['sometimes', 'integer', 'min:1'],
				'saved_search'       => ['sometimes', 'integer', 'min:1'],
				'name'               => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:100'],
			];
		}

		protected function getRedirectUrl() : string
		{
			return route('logs.index', ['type' => $this->route('type')]);
		}
	}
