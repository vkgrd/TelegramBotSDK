<?

	namespace Telegram\Method;

	class EditMessageReplyMarkup extends EditMessageText {

		public function __construct($chatId, $messageId, $markup) {
			parent::__construct($chatId, $messageId, null);
			$this->setReplyMarkup($markup);
		}

		public function getMethod() {
			return "editMessageReplyMarkup";
		}

		public function getText() {
			return null;
		}

		public function getParams() {
			$s =  [ "chat_id" => $this->chatId, "message_id" => $this->messageId, "reply_markup" => $this->replyMarkUp ];
			return $s;
		}

	}