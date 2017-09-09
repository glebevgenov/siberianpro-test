<?php

namespace app\components;

use Yii;
use yii\base\Component;
use yii\helpers\FileHelper;
use PhpImap\Mailbox;

class DigestsParser extends Component
{
    /**
     * @var sting $host
     */
    public $host;

    /**
     * @var sting $host
     */
    public $login;

    /**
     * @var sting $host
     */
    public $password;

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        $this->clean();
        FileHelper::createDirectory($this->tmpDir);
    }

    /**
     * @return string
     */
    public function getTmpDir()
    {
        return sys_get_temp_dir() . '/' . md5(Yii::$app->id . __METHOD__);
    }

    /**
     * @return void
     */
    public function parse($verbose = true)
    {
        $verbose || ob_start();
        $this->fetch();
        $this->load();
        //$this->clean();
        $verbose || ob_end_clean();
    }

    /**
     * @return void
     */
    public function fetch()
    {
        echo "Connecting to mailbox...\n";

        $mailbox = new Mailbox('{' . $this->host . ':993/ssl}INBOX', 
            $this->login, 
            $this->password, 
            $this->tmpDir
        );

        echo "Searching for mail...\n";

        $mailIds = $mailbox->searchMailbox('ALL');


        foreach ($mailIds as $mailId) {
            
            echo "Downloading a mail source...\n";
            
            $mail = $mailbox->getMail($mailId); 
            file_put_contents($this->tmpDir . '/digest-mail-' . $mail->id, $mail->textHtml);
        }
    }

    /**
     * @return void
     */
    public function load()
    {
    }

    /**
     * @return void
     */
    public function clean()
    {
        Yii::$app->tmpRedis->flushdb();
        if (is_dir($this->tmpDir)) {
            FileHelper::removeDirectory($this->tmpDir);
        }
    }
}
