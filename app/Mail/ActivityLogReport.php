<?php
	namespace App\Mail;

	use Illuminate\Bus\Queueable;
	use Illuminate\Database\Eloquent\Collection;
	use Illuminate\Mail\Mailable;
	use Illuminate\Queue\SerializesModels;

	class ActivityLogReport extends Mailable
	{
		use Queueable, SerializesModels;

		public $data;
		public $fromDateTimeString;
		public $logType;

		/**
		 * Create a new message instance.
		 *
		 * @param $data
		 */
		public function __construct(string $logType, string $fromDateTimeString, Collection $data)
		{
			$this->logType            = $logType;
			$this->fromDateTimeString = $fromDateTimeString;
			$this->data               = $data;
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
