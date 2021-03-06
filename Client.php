<?
	namespace Telegram;

	use ErrorException;
	use Exception;
	use RuntimeException;
	use stdClass;
	use Telegram\Method\BaseMethod;

	class Client {

		/** @var string */
		private $mBotSecret;

		/** @var string */
		private $mApiUrl;

		/** @var string */
		private $mLogFile;

		/** @var int */
		private $mLogMode;

		const BASE_URL = "https://api.telegram.org/bot%s/";

		const LOG_MODE_MESSAGE = 1;
		const LOG_MODE_CALLBACK_QUERY = 2;
		const LOG_MODE_REVERSE = 4;
		const LOG_MODE_INCLUDE_RAW = 8;
		const LOG_MODE_API_RESULT = 16;

		const TYPE_MESSAGE = "MSG";
		const TYPE_CALLBACK = "CQR";
		const TYPE_RAW = "RAW";

		/**
		 * Client constructor.
		 * @param $botSecret
		 */
		public function __construct($botSecret) {
			$this->mBotSecret = $botSecret;
			$this->mApiUrl = sprintf(self::BASE_URL, $this->mBotSecret);
		}

		/**
		 * Call method as HTTP-request
		 * @param string $method
		 * @param array $parameters
		 * @return stdClass|boolean
		 * @throws Exception
		 */
		private function callSingleMethod($method, $parameters) {
			if (!is_string($method)) {
				throw new InvalidParamException("Method name must be a string");
			};

			if (!$parameters) {
				$parameters = [];
			} else if (!is_array($parameters) && !is_object($parameters)) {
				throw new InvalidParamException("Parameters must be an array");
			}

			$parameters["method"] = $method;

			$handle = curl_init($this->mApiUrl);
			curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
			curl_setopt($handle, CURLOPT_TIMEOUT, 60);
			curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($parameters));
			curl_setopt($handle, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);

			$response = curl_exec($handle);

			if ($this->mLogMode & self::LOG_MODE_API_RESULT) {
				$this->log("API", [
					"Method" => $method,
					"Params" => $parameters,
					"Response" => $response
				]);
			}

			if ($response === false) {
				$errno = curl_errno($handle);
				$error = curl_error($handle);
				curl_close($handle);
				throw new RuntimeException("Curl returned error $errno: $error");
			}

			$httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
			curl_close($handle);

			if ($httpCode >= 500) {
				throw new RuntimeException("Telegram API respond with " . $httpCode . " HTTP code");
			} elseif ($httpCode !== 200) {
				throw new RuntimeException("Telegram API respond with " . $httpCode . " HTTP code and reason: " . $response);
			};


			$response = json_decode($response);
			if (!$response->ok) {
				throw new ErrorException(json_encode($response));
			}

			return $response->result;
		}

		/**
		 * Call metod as webhook
		 * @param string $method
		 * @param array $parameters
		 * @return boolean
		 */
		private function callHookMethod($method, $parameters) {
			if (!is_string($method)) {
				throw new InvalidParamException("Method name must be a string");
			}

			if (!$parameters) {
				$parameters = [];
			} else if (!is_array($parameters)) {
				throw new InvalidParamException("Parameters must be an array");
			}

			$parameters["method"] = $method;

			header("Content-Type: application/json");
			echo json_encode($parameters);
			return true;
		}

		/**
		 * Perform method as single HTTP-request
		 * @param BaseMethod $action
		 * @return stdClass
		 * @throws Exception
		 */
		public function performSingleMethod(BaseMethod $action) {
			return $this->callSingleMethod($action->getMethod(), $action->getParams());
		}

		/**
		 * Perform method as webhook
		 * @param BaseMethod $action
		 * @return void
		 */
		public function performHookMethod(BaseMethod $action) {
			$this->callHookMethod($action->getMethod(), $action->getParams());
		}

		/**
		 * Data received from php://input
		 * @var mixed
		 */
		private $mData;

		/**
		 * Read input stream
		 * @return boolean
		 * @throws Exception
		 */
		private function read() {
			if (!$this->mData) {
				$content = file_get_contents("php://input");

				($this->mLogMode & self::LOG_MODE_INCLUDE_RAW) && $this->log(self::TYPE_RAW, ["json" => $content]);

				$update = json_decode($content);

				if (!$update) {
					throw new Exception("Can not get update");
				}

				$this->mData = $update;
			}

			return true;
		}

		/**
		 * Set listener for Message
		 * @param callable $callable
		 * @return bool
		 * @throws Exception
		 */
		public function onMessage($callable) {
			$this->read();

			if (isset($this->mData->message)) {
				$message = new Model\Message($this->mData->message);
				$callable($this, $message);
				$d = [
					"Chat" => "@" . $message->getChat()->getUsername() . " (#" . $message->getChat()->getId() . ")",
					"From" => "@" . $message->getFrom()->getUsername() . " (#" . $message->getFrom()->getId() . ")",
					"FN/LN" => $message->getFrom()->getFirstName() . " ! " . $message->getFrom()->getLastName(),
					"Date" => date("d.m H:i:s", $message->getDate()),
					"Text" => $message->getText()
				];

				if ($d["Chat"] == $d["From"]) {
					unset($d["From"]);
				}

				($this->mLogMode & self::LOG_MODE_MESSAGE) && $this->log(self::TYPE_MESSAGE, $d);
			}

			return true;
		}

		/**
		 * Set listener for callback query
		 * @param callable $callable
		 * @return boolean
		 * @throws Exception
		 */
		public function onCallbackQuery($callable) {
			$this->read();

			if (isset($this->mData->callback_query)) {
				$query = new Model\CallbackQuery($this->mData->callback_query);
				$callable($this, $query);
				($this->mLogMode & self::LOG_MODE_CALLBACK_QUERY) && $this->log(self::TYPE_CALLBACK, [
					"From" => "@" . $query->getFrom()->getUsername(),
					"FN/LN" => $query->getFrom()->getFirstName() . " ! " . $query->getFrom()->getLastName(),
					"Date" => date("d.m H:i:s"),
					"Data" => $query->getData()
				]);
			}

			return true;
		}

		/**
		 * Set logger file
		 * @param string $filename
		 * @param int    $mode
		 * @return $this
		 */
		public function logger($filename, $mode = 0) {
			$this->mLogFile = $filename;
			$this->mLogMode = $mode;
			return $this;
		}

		/**
		 * Logging any actions
		 * @param string $type
		 * @param array $data
		 */
		private function log($type, $data) {
			if (!$this->mLogFile) {
				return;
			}

			$str = [];
			foreach ($data as $key => $value) {
				$str[] = $key . ": " . $value;
			}

			$log = sprintf("%s | %s", $type, join("; ", $str));

			if (!file_exists($this->mLogFile)) {
				fclose(fopen($this->mLogFile, "w+"));
			}

			$fh = fopen($this->mLogFile, "r+");

			$isReverse = $this->mLogMode & self::LOG_MODE_REVERSE; // true - begin, false - end
			$str = $isReverse ? $log . "\n" : "\n" . $log;
			$ft = null;

			$tmpFile = "tglg.tmp";

			if ($isReverse) {
				$ft = fopen($tmpFile, "w+");
				while (!feof($fh)) {
					fwrite($ft, fgets($fh, 4096));
				}
				fseek($fh, 0);
			} else {
				fseek($fh, 0, SEEK_END);
			}

			fwrite($fh, $str);

			if ($isReverse) {
				fseek($ft, 0, SEEK_SET);
				while (!feof($ft)) {
					fwrite($fh, fgets($ft, 4096));
				}
				fclose($ft);
				unlink($tmpFile);
			}

			fclose($fh);
		}

	}