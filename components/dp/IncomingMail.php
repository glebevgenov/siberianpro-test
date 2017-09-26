<?php

namespace app\components\dp;

class IncomingMail extends \PhpImap\IncomingMail {

	/** @var IncomingMailMessage[] */
	protected $messages = array();

	public function addMessage(IncomingMailMessage $message) {
		$this->messages[] = $message;
	}

	/**
	 * @return IncomingMailMessage[]
	 */
	public function getMessages() {
		return $this->messages;
	}

	/**
	 * @return int
	 */
	public function getMessagesCount() {
		return count($this->messages);
	}
}

class IncomingMailMessage {

	public $headers;
	public $textPlain;
	public $textHtml;
	public $date;
	public $timestamp;
	public $to;
	public $toString;
}
