<?php
	namespace App\Models;

	use Illuminate\Database\Eloquent\Model;
	use Illuminate\Support\Collection;

	class EmonFeed extends Model
	{
		protected $fillable = ["id", "userid", "name", "tag", "public", "size", "engine", "processList", "unit", "time", "value"];
		public Collection $feedItems;

		public function __construct()
		{
			$this->feedItems = new Collection;
		}

		public function getFeedItems() : Collection
		{
			return $this->feedItems;
		}

		public function addFeedItem(FeedItem $feedItem) : void
		{
			if (!($this->feedItems instanceof Collection))
			{
				$this->feedItems = new Collection;
			}

			$this->feedItems->put($feedItem->timestamp, $feedItem);
		}
	}
