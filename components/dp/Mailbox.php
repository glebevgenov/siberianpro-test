<?php 

namespace app\components\dp;

class Mailbox extends \PhpImap\Mailbox {

    /**
     * @inheritdoc
     */
	public function getMail($mailId, $markAsSeen = true) {
		$headersRaw = imap_fetchheader($this->getImapStream(), $mailId, FT_UID);
		$head = imap_rfc822_parse_headers($headersRaw);

		$mail = new IncomingMail();
		$mail->headersRaw = $headersRaw;
		$mail->headers = $head;
		$mail->id = $mailId;
		$mail->date = $this->convertDate($head->date);
		$mail->subject = isset($head->subject) ? $this->decodeMimeStr($head->subject, $this->serverEncoding) : null;
		if (isset($head->from)) {
			$mail->fromName = isset($head->from[0]->personal) ? $this->decodeMimeStr($head->from[0]->personal, $this->serverEncoding) : null;
			$mail->fromAddress = strtolower($head->from[0]->mailbox . '@' . $head->from[0]->host);
		} elseif (preg_match("/smtp.mailfrom=[-0-9a-zA-Z.+_]+@[-0-9a-zA-Z.+_]+.[a-zA-Z]{2,4}/", $headersRaw, $matches)) {
			$mail->fromAddress = substr($matches[0], 14);
		}
		if(isset($head->to)) {
            $to = $this->convertTo($head->to);
            $mail->to = $to['toArray'];
			$mail->toString = $to['toString'];
		}

		if(isset($head->cc)) {
			foreach($head->cc as $cc) {
				$mail->cc[strtolower($cc->mailbox . '@' . $cc->host)] = isset($cc->personal) ? $this->decodeMimeStr($cc->personal, $this->serverEncoding) : null;
			}
		}
		
		if(isset($head->bcc)) {
			foreach($head->bcc as $bcc) {
				$mail->bcc[strtolower($bcc->mailbox . '@' . $bcc->host)] = isset($bcc->personal) ? $this->decodeMimeStr($bcc->personal, $this->serverEncoding) : null;
			}
		}

		if(isset($head->reply_to)) {
			foreach($head->reply_to as $replyTo) {
				$mail->replyTo[strtolower($replyTo->mailbox . '@' . $replyTo->host)] = isset($replyTo->personal) ? $this->decodeMimeStr($replyTo->personal, $this->serverEncoding) : null;
			}
		}

		if(isset($head->message_id)) {
			$mail->messageId = $head->message_id;
		}

		$mailStructure = imap_fetchstructure($this->getImapStream(), $mailId, FT_UID);

		if(empty($mailStructure->parts)) {
			$this->initMailPart($mail, $mailStructure, 0, $markAsSeen);
		}
		else {
			foreach($mailStructure->parts as $partNum => $partStructure) {
				$this->initMailMessagePart($mail, $partStructure, $partNum + 1, $markAsSeen);
			}
		}

		return $mail;
	}

	protected function initMailMessagePart(IncomingMail $mail, $partStructure, $partNum, $markAsSeen = true, $message = null) {
        $options = FT_UID;
        if(!$markAsSeen) {
            $options |= FT_PEEK;
        }
		$data = $partNum ? imap_fetchbody($this->getImapStream(), $mail->id, $partNum, $options) : imap_body($this->getImapStream(), $mail->id, $options);

		if($partStructure->encoding == 1) {
			$data = imap_utf8($data);
		}
		elseif($partStructure->encoding == 2) {
			$data = imap_binary($data);
		}
		elseif($partStructure->encoding == 3) {
			$data = preg_replace('~[^a-zA-Z0-9+=/]+~s', '', $data); // https://github.com/barbushin/php-imap/issues/88
			$data = imap_base64($data);
		}
		elseif($partStructure->encoding == 4) {
			$data = quoted_printable_decode($data);
		}

		$params = array();
		if(!empty($partStructure->parameters)) {
			foreach($partStructure->parameters as $param) {
				$params[strtolower($param->attribute)] = $this->decodeMimeStr($param->value);
			}
		}
		if(!empty($partStructure->dparameters)) {
			foreach($partStructure->dparameters as $param) {
				$paramName = strtolower(preg_match('~^(.*?)\*~', $param->attribute, $matches) ? $matches[1] : $param->attribute);
				if(isset($params[$paramName])) {
					$params[$paramName] .= $param->value;
				}
				else {
					$params[$paramName] = $param->value;
				}
			}
		}

		// attachments
		$attachmentId = $partStructure->ifid
			? trim($partStructure->id, " <>")
			: (isset($params['filename']) || isset($params['name']) ? mt_rand() . mt_rand() : null);

		// ignore contentId on body when mail isn't multipart (https://github.com/barbushin/php-imap/issues/71)
		if (!$partNum && 'TYPETEXT' === $partStructure->type)
		{
			$attachmentId = null;
		}

		if($attachmentId) {
			if(empty($params['filename']) && empty($params['name'])) {
				$fileName = $attachmentId . '.' . strtolower($partStructure->subtype);
			}
			else {
				$fileName = !empty($params['filename']) ? $params['filename'] : $params['name'];
				$fileName = $this->decodeMimeStr($fileName, $this->serverEncoding);
				$fileName = $this->decodeRFC2231($fileName, $this->serverEncoding);
			}
			$attachment = new \PhpImap\IncomingMailAttachment();
			$attachment->id = $attachmentId;
			$attachment->name = $fileName;
			$attachment->disposition = (isset($partStructure->disposition) ? $partStructure->disposition : null);
			$mail->addAttachment($attachment);
		}
		else {
			if(!empty($params['charset'])) {
				$data = $this->convertStringEncoding($data, $params['charset'], $this->serverEncoding);
			}
			if($partStructure->type == 0 && $data) {
                if ($message) {
                    if(strtolower($partStructure->subtype) == 'plain') {
                        $message->textPlain = $data;
                    } else {
                        $message->textHtml = $data;
                    }
                } else {
                    if(strtolower($partStructure->subtype) == 'plain') {
                        $mail->textPlain .= $data;
                    }
                    else {
                        $mail->textHtml .= $data;
                    }
                }
			}
			elseif($partStructure->type == 2 && $data) {
				$mail->textPlain .= trim($data);
			}
		}
		if(!empty($partStructure->parts)) {
			foreach($partStructure->parts as $subPartNum => $subPartStructure) {
				if($partStructure->type == 2 && $partStructure->subtype == 'RFC822' && (!isset($partStructure->disposition) || $partStructure->disposition !== "attachment")) {
                    if (!$message) {
                        $message = new IncomingMailMessage();
                        $message->headersRaw = imap_fetchbody($this->getImapStream(), $mail->id, $partNum . '.0', $options);
		                $message->headers = imap_rfc822_parse_headers($message->headersRaw);
                        $date = $this->convertDate($message->headers->date);
                        $message->date = $date['text']; 
                        $message->timestamp = $date['ts']; 
                        if(isset($message->headers->to)) {
                            $to = $this->convertTo($message->headers->to);
                            $message->to = $to['toArray'];
                            $message->toString = $to['toString'];
                        }
                        $mail->addMessage($message);
                    }
					$this->initMailMessagePart($mail, $subPartStructure, $partNum, $markAsSeen, $message);
				}
				else {
					$this->initMailMessagePart($mail, $subPartStructure, $partNum . '.' . ($subPartNum + 1), $markAsSeen, $message);
				}
			}
		}
	}

    /**
     * @param string $date
     * @return string
     */
    public function convertDate($date)
    {
        $dt = new \DateTime(isset($date) 
            ? preg_replace('/\(.*?\)/', '', $date)
            : null
        );
        return [
            'text' => $dt->format('Y-m-d H:i:s'),
            'ts' => $dt->getTimestamp(),
        ];
    }

    /**
     * @param array $to
     * @return array
     */
    public function convertTo($to)
    {
        $toArray = [];
        $toStrings = [];
        foreach($to as $toParts) {
            if(!empty($toParts->mailbox) && !empty($toParts->host)) {
                $toEmail = strtolower($toParts->mailbox . '@' . $toParts->host);
                $toName = isset($toParts->personal) ? $this->decodeMimeStr($toParts->personal, $this->serverEncoding) : null;
                $toStrings[] = $toName ? "$toName <$toEmail>" : $toEmail;
                $toArray[$toEmail] = $toName;
            }
        }
        $toString = implode(', ', $toStrings);
        return [
            'toArray' => $toArray,
            'toString' => $toString,
        ];
    }
}
