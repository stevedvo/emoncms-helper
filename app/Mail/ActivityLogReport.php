<?php
	namespace App\Mail;

	use Illuminate\Bus\Queueable;
	use Illuminate\Mail\Mailable;
	use Illuminate\Queue\SerializesModels;
	use Illuminate\Support\Collection;

	class ActivityLogReport extends Mailable
	{
		use Queueable, SerializesModels;

		public $data;
		public $fromDateTimeString;
		public $logType;
		public $totalCount;

		/**
		 * Create a new message instance.
		 *
		 * @param Collection<int, array<string, mixed>> $data
		 * @param int $totalCount Total number of individual logs represented by the summary.
		 */
		public function __construct(string $logType, string $fromDateTimeString, Collection $data, int $totalCount)
		{
			$this->logType            = $logType;
			$this->fromDateTimeString = $fromDateTimeString;
			$this->data               = $data;
			$this->totalCount         = $totalCount;
		}

		/**
		 * Build the message.
		 *
		 * @return $this
		 */
		public function build()
		{
			return $this->from(config('mail.from.address'))->subject("Activity Log Report [".$this->logType."]")->view('emails.activity_log_report');
		}
	}
