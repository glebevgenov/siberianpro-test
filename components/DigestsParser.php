<?php

namespace app\components;

use Yii;
use yii\base\Component;
use yii\helpers\FileHelper;
use app\components\dp\Mailbox;
use app\components\dp\IncomingMail;
use app\helpers\Dom;
use yii\helpers\StringHelper;
use yii\helpers\Inflector;
use app\models\User;
use app\models\Profile;
use app\models\Project;
use app\models\Post;
use app\models\UsersToPost;

class DigestsParser extends Component
{
    const DATETIME_FORMAT = 'Y-m-d H:i:s';

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
     * @return \yii\redis\Connection
     */
    public function getRedis()
    {
        return Yii::$app->tmpRedis;
    }

    /**
     * @return int
     */
    public function getLatestTimestamp()
    {
        return Post::find()
            ->select('created_at')
            ->orderBy(['created_at' => SORT_DESC])
            ->scalar();
    }

    /**
     * @return int
     */
    public function getLatestTimestampText()
    {
        return (new \DateTime('@' . $this->getLatestTimestamp()))
            ->format(self::DATETIME_FORMAT);
    }

    /**
     * @return void
     */
    public function parse($verbose = true)
    {
        $verbose || ob_start();
        $this->fetch();
        $this->load();
        $this->clean();
        $verbose || ob_end_clean();
    }

    /**
     * @return void
     */
    public function fetch()
    {
        $mailbox = new Mailbox('{' . $this->host . ':993/ssl}INBOX', 
            $this->login, 
            $this->password
        );
        $this->fetchDigests($mailbox);
        unset($mailbox);
        
        $this->fetchPosts();
        $this->fetchProjects();
        $this->fetchUsers();
    }

    /**
     * @param Mailbox $mailbox
     * @return void
     */
    public function fetchDigests($mailbox)
    {
        echo "Fetching digests...\n";

        $mailIds = $mailbox->searchMailbox('ALL');

        $latestTimestamp = $this->getLatestTimestamp();
        $this->redis->set('digests', 0);
        foreach ($mailIds as $mailId) {
            $mail = $mailbox->getMail($mailId); 
            if ($mail->getMessagesCount() == 0) {
                continue;
            }
            printf("Message #%d contains %d digests. Transfering into a temporary storage...\n", $mail->id, count($mail->getMessages())); 
            foreach ($mail->getMessages() as $message) {
                $postDate = $this->getPostDate($message->date);
                if ($postDate['ts'] <= $latestTimestamp) {
                    echo "A digest is too old: {$message->date}. Skipping...\n";
                    continue;
                }
                $this->redis->hmset(
                    'digest-' . $this->redis->get('digests'),
                    'to', is_array($message->to) && count($message->to) > 0 
                        ? array_keys($message->to)[0]
                        : '',
                    'date', $message->date,
                    'html', $message->textHtml
                );
                $this->redis->incr('digests');
            }
            unset($mail);
        }
    }

    /**
     * @return void
     */
    public function fetchPosts()
    {
        echo "Fetching posts...\n";

        $postHashes = $this->redis->zrange('posts', 0, -1);
        foreach ($postHashes as $postHash) {
            $postKey = 'post-' . $postHash;
            $this->redis->del($postKey);
        }
        $this->redis->del('posts');

        // disable libxml E_WARNINGs on corrupted sources
        libxml_use_internal_errors(true);

        for ($i = 0; $i < $this->redis->get('digests'); $i++) {
            $postDate = $this->getPostDate($this->redis->hget('digest-' . $i, 'date'));
            $posts = $this->parseDigest($this->redis->hget('digest-' . $i, 'html'));
            libxml_clear_errors();
            foreach ($posts as $post) {
                $postHash = md5($postDate['text'] 
                    . $post['project']
                    . $post['sender']
                    . $post['html']
                );
                $postKey = 'post-' . $postHash;
                if (!$this->redis->exists($postKey)) {
                    $this->redis->hmset(
                        $postKey,
                        'project', $post['project'],
                        'sender', $post['sender'],
                        'date', $postDate['text'],
                        'timestamp', $postDate['ts'],
                        'html', $post['html']
                    );
                    $this->redis->zadd('posts', $postDate['ts'], $postHash);
                }
                $recipientsKey = $postKey . '-recipients';
                $this->redis->sadd($recipientsKey, $this->redis->hget('digest-' . $i, 'to'));
            }    
        }

        libxml_use_internal_errors(false);
    }

    /**
     * @param string $digestDate
     * @return array
     */
    public function getPostDate($digestDate)
    {
        $postDate = \DateTime::createFromFormat(self::DATETIME_FORMAT, $digestDate);
        $dayDec = $postDate->format('w') == 1 ? 2 : 1;
        $postDate->sub(new \DateInterval('P' . $dayDec . 'D'))->setTime(0, 0);
        return [
            'text' => $postDate->format(self::DATETIME_FORMAT),
            'ts' => $postDate->getTimestamp(),
        ];
    }

    /**
     * @param string $html
     * @return array
     */
    public function parseDigest($html)
    {
        $html = trim($html);
        if (strlen($html) == 0) {
            return [];
        }
        $dom = new \DOMDocument();
        $dom->loadHTML($html);
        $xPath = new \DOMXPath($dom);
        $project = '';
        $posts = [];
        $rows = $xPath->query('/html/body/table/tr');
        foreach ($rows as $row) {
            $projectCells = $xPath->query('./td[@class="project-name"]', $row);
            if ($projectCells->length > 0) {
                $project = trim($projectCells[0]->nodeValue);
                continue;
            }
            $postCells = $xPath->query('./td/table/tr[position()<3]/td[position()<2]', $row);
            if ($postCells->length != 2) {
                continue;
            }
            $posts[] = [
                'project' => $project,
                'sender' => trim($postCells[0]->nodeValue),
                'html' => trim(Dom::getInnerHtml($postCells[1])),
            ];
        }
        return $posts;
    }

    /**
     * @return void
     */
    public function fetchProjects()
    {
        echo "Fetching projects...\n";

        $this->redis->del('projects');
        $postHashes = $this->redis->zrange('posts', 0, -1);
        foreach ($postHashes as $postHash) {
            $postKey = 'post-' . $postHash;
            $this->redis->sadd(
                'projects', 
                $this->redis->hget($postKey, 'project')
            );
        }
    }

    /**
     * @return void
     */
    public function fetchUsers()
    {
        echo "Fetching users...\n";

        $this->redis->del('users');
        $postHashes = $this->redis->zrange('posts', 0, -1);
        foreach ($postHashes as $postHash) {
            $postKey = 'post-' . $postHash;
            $this->redis->sadd(
                'senders', 
                $this->redis->hget($postKey, 'sender')
            );
            $recipients = $this->redis->smembers($postKey . '-recipients');
            foreach ($recipients as $recipient) {
                $this->redis->sadd('recipients', $recipient);
            }
        }
    }

    /**
     * @return void
     */
    public function load()
    {
        echo "Loading data into the database...\n";

        $this->loadUsers();
        $this->loadProjects();
        $this->loadPosts();
    }

    /**
     * @return void
     */
    public function loadUsers()
    {
        Yii::$app->db->transaction(function () {
            $this->redis->del('sender-ids');
            $senders = $this->redis->smembers('senders');
            foreach ($senders as $sender) {
                $name = StringHelper::explode($sender, ' ', true, true);
                if (count($name) != 2) {
                    $this->redis->srem('senders', $sender);
                    continue;
                }
                $profile = Profile::findOne([
                    'firstname' => $name[1],
                    'lastname' => $name[0],
                ]);
                if ($profile) {
                    $this->redis->hset('sender-ids', $sender, $profile->user_id);
                    continue;
                }
                $email = strtolower(Inflector::transliterate($name[1] . '.' . $name[0])) 
                    . '@siberian.pro';
                $user = new User([
                    'username' => $email,
                    'email' => $email,
                ]);
                $user->insert(false);
                $profile = new Profile([
                    'user_id' => $user->id,
                    'firstname' => $name[1],
                    'lastname' => $name[0],
                ]);
                $profile->insert(false);
                $this->redis->hset('sender-ids', $sender, $user->id);
            }
            $this->redis->del('recipient-ids');
            $recipients = $this->redis->smembers('recipients');
            foreach ($recipients as $recipient) {
                $user = User::findOne([
                    'email' => $recipient,
                ]);
                if ($user) {
                    $this->redis->hset('recipient-ids', $recipient, $user->id);
                    continue;
                }
                $user = new User([
                    'username' => $recipient,
                    'email' => $recipient,
                ]);
                $user->insert(false);
                $profile = new Profile([
                    'user_id' => $user->id,
                ]);
                $profile->insert(false);
                $this->redis->hset('recipient-ids', $recipient, $user->id);
            }
        });
    }

    /**
     * @return void
     */
    public function loadProjects()
    {
        Yii::$app->db->transaction(function () {
            $this->redis->del('project-ids');
            $projects = $this->redis->smembers('projects');
            foreach ($projects as $name) {
                $project = Project::findOne([
                    'name' => $name,
                ]);
                if ($project) {
                    $this->redis->hset('project-ids', $name, $project->id);
                    continue;
                }
                $project = new Project([
                    'name' => $name,
                ]);
                $project->insert(false);
                $this->redis->hset('project-ids', $name, $project->id);
            }
        });
    }

    /**
     * @param string $sender
     * @return string
     */
    public function getSenderId($sender)
    {
        return $this->redis->hget('sender-ids', $sender);
    }

    /**
     * @param string $recipient
     * @return string
     */
    public function getRecipientId($recipient)
    {
        return $this->redis->hget('recipient-ids', $recipient);
    }

    /**
     * @param string $project
     * @return string
     */
    public function getProjectId($project)
    {
        return $this->redis->hget('project-ids', $project);
    }

    /**
     * @return void
     */
    public function loadPosts()
    {
        Yii::$app->db->transaction(function () {
            $latestTimestamp = $this->getLatestTimestamp();
            $postHashes = $this->redis->zrange('posts', 0, -1);
            foreach ($postHashes as $postHash) {
                $postKey = 'post-' . $postHash;
                $postDate = $this->redis->hget($postKey, 'date');
                $postTimestamp = $this->redis->hget($postKey, 'timestamp');
                if ($postTimestamp <= $latestTimestamp) {
                    echo "A post is too old: {$postDate}. Skipping...\n";
                    continue;
                }
                $post = new Post([
                    'author_id' => $this->getSenderId(
                        $this->redis->hget($postKey, 'sender')
                    ),
                    'project_id' => $this->getProjectId(
                        $this->redis->hget($postKey, 'project')
                    ),
                    'created_at' => $postTimestamp,
                    'updated_at' => $postTimestamp,
                    'body' => $this->redis->hget($postKey, 'html'),
                ]);
                $post->insert(false);
                $recipients = $this->redis->smembers($postKey . '-recipients');
                foreach ($recipients as $recipient) {
                    $userId = $this->getRecipientId($recipient);
                    (new UsersToPost([
                        'post_id' => $post->id,
                        'user_id' => $userId,
                    ]))->insert(false);
                }
            }
        });
    }

    /**
     * @return void
     */
    public function clean()
    {
        $this->redis->flushdb();
    }
}
