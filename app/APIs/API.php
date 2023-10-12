<?php
	namespace App\APIs;

	use Throwable;
	use App\Models\ActivityLog;
	use App\Models\ApiLog;
	use GuzzleHttp\Client;
	use Psr\Http\Message\ResponseInterface;

	class API
	{
		private $url;
		private $method = 'GET';
		private $headers = [];
		private $payload;
		private $formData;
		private $setup = [];
		private $username;
		private $password;

		public static function get($url)
		{
			return new static($url, 'GET');
		}

		public static function patch($url)
		{
			return new static($url, 'PATCH');
		}

		public static function post($url)
		{
			return new static($url, 'POST');
		}

		public static function put($url)
		{
			return new static($url, 'PUT');
		}

		public static function to($url)
		{
			return new static($url);
		}

		function __construct($url, $method = 'GET')
		{
			$this->url    = $url;
			$this->method = $method;
			$this->ignoreSSL();
		}

		public function addHeader($header, $value)
		{
			$this->headers[$header] = $value;

			return $this;
		}

		public function auth($user, $pass)
		{
			$this->username = $user;
			$this->password = $pass;

			return $this;
		}

		public function headers($headers)
		{
			foreach ($headers as $header => $value)
			{
				$this->addHeader($header, $value);
			}

			return $this;
		}

		public function ignoreSSL()
		{
			$this->setup['verify'] = false;

			return $this;
		}

		public function formData($array)
		{
			$this->formData = $array;

			return $this;
		}

		public function json($data)
		{
			$this->payload = $data;

			return $this;
		}

		public function method($method)
		{
			$this->method = $method;

			return $this;
		}

		/**
		 * @return ResponseInterface
		 */
		public function send()
		{
			$client = new Client($this->setup);

			$options = [];

			$apiLogParams =
			[
				'method'    => $this->method,
				'endpoint'  => substr($this->url, 0, 255), /* take only the first 255 chars to ensure this fits in db column */
			];

			if ($this->headers)
			{
				$options['headers'] = $this->headers;
			}

			if ($this->username)
			{
				$options['auth'] = [$this->username, $this->password];
			}

			if ($this->payload)
			{
				$options['json']         = $this->payload;
				$apiLogParams['payload'] = json_encode($this->payload);
			}

			if ($this->formData)
			{
				$options['form_params'] = $this->formData;
			}

			try
			{
				/* wrapped in a try..catch block - we don't want a failure here to prevent the request from going ahead */
				ApiLog::create($apiLogParams);
			}
			catch (Throwable $e)
			{
				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => "error",
					'message'    => $e->getMessage(),
				]);
			}

			$result = $client->request($this->method, $this->url, $options);

			return $result;
		}
	}
