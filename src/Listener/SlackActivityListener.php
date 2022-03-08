<?php
namespace Slack\Listener;

use Application\Module as ApplicationModule;
use Application\Model\IModelDAO;
use Application\View\Helper\Avatar;
use Events\Listener\AbstractEventListener;
use Comments\Model\Comment;
use Reviews\Model\Review;
use Users\Model\User;
use Zend\EventManager\Event;
use Zend\Http\Client;
use Zend\Http\Request;

class SlackActivityListener extends AbstractEventListener
{
    const EVENTS_TO_HANDLE = [
        "task.review",
        "task.comment",
        "task.commit"
    ];
    const ACTIONS_TO_MENTION = [
        "edited reviewers on",
        "requested",
        "requested revisions to",
        "requested further review of",
        "updated files in",
        "approved"
    ];
    const ACTIONS_TO_IGNORE = [
        "disabled notifications on",
        "re-enabled notifications on",
        "joined",
        "left",
        "updated",
        "re-enabled notifications on",
        "disabled notifications on",
        "archived comment on",
        "unarchived comment on"
    ];

    public function handleEvent(Event $event)
    {
        if (!in_array($event->getName(), self::EVENTS_TO_HANDLE)) {
            return;
        }
        $activity = $event->getParam('activity');
        $action = is_null($activity) ? null : $activity->get('action');
        if (!is_null($action) && in_array($action, self::ACTIONS_TO_IGNORE)) {
            return;
        }
        $logger = $this->services->get('logger');
        $data  = (array) $event->getParam('data') + ['quiet' => null];
        $quiet = $event->getParam('quiet', $data['quiet']);
        if ($quiet === true) {
            $logger->info("Slack: event is silent(notifications are being batched), returning.");
            return;
        }
        $review = $this->getReview($event);
        if (!is_null($review)) {
            try {
                $this->postEvent($review, $event);
                $logger->info("Slack: handleEvent end.");
            } catch (\Exception $e) {
                $logger->err("Slack: error when fetching review : " . $e->getMessage());
            }
        }
    }

    private function getReview($event)
    {
        $config = $this->services->get('config');
        $p4Admin = $this->services->get('p4_admin');

        $review = $event->getParam('review');
        if (is_null($review)) {
            $reviewId = -1;
            switch($event->getName())
            {
            case "task.comment.batch":
                if (preg_match('/^reviews\/(\d+)$/', $event->getParam('id'), $matches))
                {
                    $reviewId = $matches[1];
                }
                break;
            case "task.review":
                $reviewId = $event->getParam('id');
                $eventData = $event->getParam('data');
                if (isset($eventData['isAdd']) && $eventData['isAdd'])
                {
                    $reviewId = -1;
                }
                break;
            case "task.comment":
                try {
                    $comment = Comment::fetch($event->getParam('id'), $p4Admin);
                    $topic = $comment->get('topic');
                    if (preg_match('/^reviews\/(\d+)$/', $topic, $matches))
                    {
                        $reviewId = $matches[1];
                    }
                } catch (\Exception $e) {
                    $logger->err("Slack: error when fetching comment : " . $e->getMessage());
                }
            }
            if ($reviewId > 0) {
                $review = Review::fetch($reviewId, $p4Admin);
            }
        }
        return $review;
    }

    private function getCacheDir()
    {
        $dir = DATA_PATH . '/cache/slack';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (!is_writable($dir)) {
            @chmod($dir, 0700);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new \RuntimeException(
                "Cannot write to cache directory ('" . $dir . "'). Check permissions."
            );
        }
        return $dir;
    }

    private function getCachedUserId($user)
    {
        $cacheFile  = $this->getCacheDir() . '/user_' . $user;
        if (file_exists($cacheFile)) {
            return unserialize(file_get_contents($cacheFile));
        }
        else {
            return null;
        }
    }

    private function setCachedUserId($user, $slackUserId)
    {
        $cacheFile  = $this->getCacheDir() . '/user_' . $user;
        file_put_contents($cacheFile, serialize($slackUserId));
    }

    private function getCachedThreadId($reviewId)
    {
        $cacheFile  = $this->getCacheDir() . '/thread_' . $reviewId;
        if (file_exists($cacheFile)) {
            return unserialize(file_get_contents($cacheFile));
        }
        else {
            return null;
        }
    }

    private function setCachedThreadId($reviewId, $threadTS)
    {
        $cacheFile  = $this->getCacheDir() . '/thread_' . $reviewId;
        file_put_contents($cacheFile, serialize($threadTS));
    }

    private function makeReviewLink($reviewId)
    {
        $config = $this->services->get('config');
        $host = $config['slack']['swarm_host'];
        return "<" . $host . "reviews/" . $reviewId ."|" . $reviewId . ">";
    }

    private function startThread($reviewId, $author, $description)
    {
        $msg = $this->makeReviewLink($reviewId) . ": " . $description;
        return $this->postMessage(null, $msg, $author);
    }

    private function getMention($userId)
    {
        $slackUserId = $this->getCachedUserId($userId);
        if (is_null($slackUserId)) {
            $p4Admin = $this->services->get('p4_admin');
            $userDAO = $this->services->get(IModelDAO::USER_DAO);
            $user = $userDAO->exists($userId, $p4Admin)
                ? $userDAO->fetchById($userId, $p4Admin) : null;
            $email = is_null($user) ? null : $user->getEmail();
            $slackUserId = is_null($email) ? null : $this->findSlackUser($email);
            if (!is_null($slackUserId)) {
                $this->setCachedUserId($userId, $slackUserId);
            }
        }
        return is_null($slackUserId) ? null : ("<@" . $slackUserId . ">");
    }

    private function getEventMessage($review, $event)
    {
        $msg = null;
        $activity = $event->getParam('activity');
        $action = is_null($activity) ? null : $activity->get('action');
        switch($event->getName())
        {
        case "task.comment.batch":
            $msg = "Comments added to Review " . $this->makeReviewLink($review->getId());
            break;
        case "task.commit":
            $msg = "Committed Review " . $this->makeReviewLink($review->getId());
            break;
        default:
            if (is_null($action)) {
                $msg = "Updated Review " . $this->makeReviewLink($review->getId());
            }
            else {
                $msg = $action . " Review " . $this->makeReviewLink($review->getId());
            }
            break;
        }
        if (!is_null($msg) && !is_null($action) && in_array($action, self::ACTIONS_TO_MENTION)) {
            $msg = $msg . "\n";
            foreach ($review->getParticipants() as $participant) {
                $mention = $this->getMention($participant);
                if (!is_null($mention)) {
                    $msg = $msg . " " . $mention;
                }
            }
        }
        return $msg;
    }

    private function getEventUser($event)
    {
        $activity = $event->getParam('activity');
        if (is_null($activity)) {
            return null;
        }
        $p4Admin = $this->services->get('p4_admin');
        $userDAO = $this->services->get(IModelDAO::USER_DAO);
        $activityUser = $userDAO->exists($activity->get('user'), $p4Admin)
            ? $userDAO->fetchById($activity->get('user'), $p4Admin) : null;
        return $activityUser;
    }

    private function shouldUpdateThread($event)
    {
        $activity = $event->getParam('activity');
        if (is_null($activity)) {
            return false;
        }
        $action = $activity->get('action');
        return strcasecmp($action, 'updated description of') == 0;
    }

    private function postEvent($review, $event)
    {
        $logger = $this->services->get('logger');
        $reviewId = $review->getId();
        $thread = $this->getCachedThreadId($reviewId);
        if (is_null($thread)) {
            $author = $review->getAuthorObject();
            $description = $review->get('description');
            $thread = $this->startThread($reviewId, $author, $description);
            $this->setCachedThreadId($reviewId, $thread);
        }

        if (!is_null($thread)) {
            if ($this->shouldUpdateThread($event)) {
                $description = $this->makeReviewLink($reviewId) . ": " . $review->get('description');
                $this->editMessage($thread, $description);
            }
            $msg = $this->getEventMessage($review, $event);
            $user = $this->getEventUser($event);
            if (is_null($msg)) {
                $logger->err("No message for event: " . $event->getName());
                return;
            }
            $this->postMessage($thread, $msg, $user);
        }
    }

    private function postMessage($thread, $msg, $impersonate)
    {
        $logger = $this->services->get('logger');
        $config = $this->services->get('config');

        $token = $config['slack']['bot_token'];
        $channel = $config['slack']['channel'];

        try {
            $url = 'https://slack.com/api/chat.postMessage';
            $headers = [
                "Content-type: application/json",
                "Authorization: Bearer " . $token
            ];
            $body = [
                "channel" => $channel,
                "text" => $msg
            ];
            if (!is_null($thread)) {
                $body['thread_ts'] = $thread;
            }
            if (!is_null($impersonate)) {
                $avatarData = Avatar::getAvatarDetails($config, $impersonate->getId(), $impersonate->getEmail());
                $avatarUri = is_null($avatarData) ? null : $avatarData['uri'];
                if (!is_null($avatarUri)) {
                    $body['icon_url'] = $avatarUri;
                }
                $body['username'] = $impersonate->getFullName();
            }

            $json = json_encode($body);
            $logger->info("Slack: sending request:");
            $logger->info($json);

            $request = new Request();
            $request->setMethod('POST');
            $request->setUri($url);
            $request->getHeaders()->addHeaders($headers);
            $request->setContent($json);

            $client = new Client();
            $client->setEncType(Client::ENC_FORMDATA);

            // set the http client options; including any special overrides for our host
            $options = $config + ['http_client_options' => []];
            $options = (array) $options['http_client_options'];
            if (isset($options['hosts'][$client->getUri()->getHost()])) {
                $options = (array) $options['hosts'][$client->getUri()->getHost()] + $options;
            }
            unset($options['hosts']);
            $options['sslverifypeer'] = false;
            $client->setOptions($options);

            // POST request
            $response = $client->dispatch($request);

            $logger->info("Slack: response from server:");
            $logger->info($response->getBody());

            if (!$response->isSuccess()) {
                $logger->err(
                    'Slack failed to POST resource: ' . $url . ' (' .
                    $response->getStatusCode() . " - " . $response->getReasonPhrase() . ').',
                    array(
                        'request'   => $client->getLastRawRequest(),
                        'response'  => $client->getLastRawResponse()
                        )
                    );
                return null;
            }
            $result = json_decode($response->getBody(), true);
            return $result['ts'];
        } catch (\Exception $e) {
            $logger->err($e);
            return null;
        }
    }

    private function editMessage($ts, $msg)
    {
        $logger = $this->services->get('logger');
        $config = $this->services->get('config');

        $token = $config['slack']['bot_token'];
        $channel = $config['slack']['channel'];

        try {
            $url = 'https://slack.com/api/chat.update';
            $headers = [
                "Content-type: application/json",
                "Authorization: Bearer " . $token
            ];
            $body = [
                "channel" => $channel,
                "text" => $msg,
                "ts" => $ts
            ];

            $json = json_encode($body);
            $logger->info("Slack: sending request:");
            $logger->info($json);

            $request = new Request();
            $request->setMethod('POST');
            $request->setUri($url);
            $request->getHeaders()->addHeaders($headers);
            $request->setContent($json);

            $client = new Client();
            $client->setEncType(Client::ENC_FORMDATA);

            // set the http client options; including any special overrides for our host
            $options = $config + ['http_client_options' => []];
            $options = (array) $options['http_client_options'];
            if (isset($options['hosts'][$client->getUri()->getHost()])) {
                $options = (array) $options['hosts'][$client->getUri()->getHost()] + $options;
            }
            unset($options['hosts']);
            $options['sslverifypeer'] = false;
            $client->setOptions($options);

            // POST request
            $response = $client->dispatch($request);

            $logger->info("Slack: response from server:");
            $logger->info($response->getBody());

            if (!$response->isSuccess()) {
                $logger->err(
                    'Slack failed to POST resource: ' . $url . ' (' .
                    $response->getStatusCode() . " - " . $response->getReasonPhrase() . ').',
                    array(
                        'request'   => $client->getLastRawRequest(),
                        'response'  => $client->getLastRawResponse()
                        )
                    );
                return false;
            }
            return true;
        } catch (\Exception $e) {
            $logger->err($e);
            return false;
        }
    }

    private function findSlackUser($email)
    {
        $logger = $this->services->get('logger');
        $config = $this->services->get('config');

        $token = $config['slack']['bot_token'];
        
        try {
            $url = 'https://slack.com/api/users.lookupByEmail?email=' . $email;
            $headers = [
                "Content-type: application/json",
                "Authorization: Bearer " . $token
            ];

            $logger->info("Slack: searching user: " . $email);

            $request = new Request();
            $request->setMethod('GET');
            $request->setUri($url);
            $request->getHeaders()->addHeaders($headers);

            $client = new Client();
            $client->setEncType(Client::ENC_FORMDATA);

            // set the http client options; including any special overrides for our host
            $options = $config + ['http_client_options' => []];
            $options = (array) $options['http_client_options'];
            if (isset($options['hosts'][$client->getUri()->getHost()])) {
                $options = (array) $options['hosts'][$client->getUri()->getHost()] + $options;
            }
            unset($options['hosts']);
            $options['sslverifypeer'] = false;
            $client->setOptions($options);

            // GET request
            $response = $client->dispatch($request);

            $logger->info("Slack: response from server:");
            $logger->info($response->getBody());

            if (!$response->isSuccess()) {
                $logger->err(
                    'Slack failed to GET resource: ' . $url . ' (' .
                    $response->getStatusCode() . " - " . $response->getReasonPhrase() . ').',
                    array(
                        'request'   => $client->getLastRawRequest(),
                        'response'  => $client->getLastRawResponse()
                        )
                    );
                return null;
            }
            $result = json_decode($response->getBody(), true);
            return !is_null($result) && array_key_exists('user', $result) ? $result['user']['id'] : null;
        } catch (\Exception $e) {
            $logger->err($e);
            return null;
        }
    }
}