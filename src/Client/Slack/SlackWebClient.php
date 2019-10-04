<?php

/**
 * @license MIT
 * @copyright 2010-2019 Tim Gunter
 */

namespace Kaecyra\ChatBot\Client\Slack;

use Exception;
use Garden\Http\HttpClient;
use Garden\Http\HttpResponse;
use Kaecyra\AppCommon\Log\LoggerBoilerTrait;
use Kaecyra\AppCommon\Log\Tagged\TaggedLogInterface;
use Kaecyra\AppCommon\Log\Tagged\TaggedLogTrait;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LogLevel;

/**
 * Slack Web Client
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @package chatbot
 */
class SlackWebClient extends HttpClient implements LoggerAwareInterface, TaggedLogInterface {

    use LoggerAwareTrait;
    use LoggerBoilerTrait;
    use TaggedLogTrait;

    /**
     * API token
     * @var string
     */
    protected $token;

    /**
     * Initialize web client
     *
     * @param string $baseUrl
     * @param string $token
     */
    public function initialize(string $baseUrl, string $token) {
        $this->tLog(LogLevel::NOTICE, "Initializing Web client");

        $this->setBaseUrl($baseUrl);
        $this->tLog(LogLevel::INFO, " url: {baseurl}", [
            'baseurl' => $baseUrl
        ]);

        $this->token = $token;
        $this->tLog(LogLevel::INFO, " token: {token}", [
            'token' => substr($token, 0, 5).str_repeat('*', strlen($token)-10).substr($token, -5)
        ]);

        $this->setDefaultHeader('Accept', 'application/json');
        $this->setDefaultHeader('Authorization', "Bearer {$this->token}");
    }

    /**
     * Passthru method for GET requests
     *
     * @param string $uri
     * @param array $query
     * @param array $headers
     * @param array $options
     * @return mixed
     */
    public function get($uri, array $query = array(), array $headers = array(), $options = array()) {
        try {
            $this->tLog(LogLevel::DEBUG, "GET {$uri}");
            $r = parent::get($uri, $query, $headers, $options);
        } catch (\Exception $ex) {
            throw $ex;
        }

        if ($r['ok'] === false) {
            $this->tLog(LogLevel::ERROR, $r['error'], $r->getBody());
            throw new Exception($r['error']);
        }

        if (($r['warning'] ?? false) !== false) {
            $this->tLog(LogLevel::WARNING, $r['warning'], $r->getBody());
        }

        return $r;
    }

    /**
     * Passthru method for POST requests
     *
     * @param string $uri
     * @param mixed $body
     * @param array $headers
     * @param array $options
     * @return mixed
     */
    public function post($uri, $body = array(), array $headers = array(), $options = array()) {
        try {
            $this->tLog(LogLevel::DEBUG, "POST {$uri}");
            $r = parent::post($uri, $body, $headers, $options);
        } catch (\Exception $ex) {
            throw $ex;
        }

        if ($r['ok'] === false) {
            $this->tLog(LogLevel::ERROR, $r['error'], $r->getBody());
            throw new Exception($r['error']);
        }

        if (($r['warning'] ?? false) !== false) {
            $this->tLog(LogLevel::WARNING, $r['warning'], $r->getBody());
        }

        return $r;
    }

    /**
     * Connect to RTM session
     *
     * Use the internal token to request a new RTM session.
     *
     * @return HttpResponse
     */
    public function rtm_connect(): HttpResponse {
        return $this->get('/rtm.connect');
    }

    /**
     * Get all workspace channels
     *
     * @param bool $archived optional. include archived channels. default false.
     * @return array
     */
    public function conversations_list(bool $archived = false): array {
        $conversations = [];

        $parameters = [
            'types' => 'public_channel,private_channel',
            'exclude_archived' => !$archived ? 'true' : 'false'
        ];
        $cursor = "";

        do {
            $response = $this->get('/conversations.list', $parameters + [
                'cursor' => $cursor
            ]);

            $page = $response->getBody();
            $channels = $page['channels'] ?? [];

            $conversations = array_merge($conversations, $channels);

            $cursor = $page['response_metadata']['next_cursor'] ?? "";
        } while (strlen($cursor));

        return $conversations;
    }

    /**
     * Get a channel by channel ID
     *
     * @param string $channel
     * @return mixed
     * @throws Exception
     */
    public function conversations_info(string $channel) {
        $response = $this->get('/conversations.info', ['channel' => $channel]);
        return $response->getBody()['channel'];
    }

    /**
     * Get channel info
     *
     * @param string $channel
     * @return HttpResponse
     */
    public function channels_info(string $channel): HttpResponse {
        return $this->get('/channels.info', [
            'channel' => $channel
        ]);
    }

    /**
     * Get private channels
     *
     * @param bool $archived optional. include archived channels. default false.
     * @return HttpResponse
     */
    public function groups_list(bool $archived = false): HttpResponse {
        return $this->get('/groups.list', [
            'exclude_archived' => !$archived ? 'true' : 'false'
        ]);
    }

    /**
     * Get group info
     *
     * @param string $channel
     * @return HttpResponse
     */
    public function groups_info(string $channel): HttpResponse {
        return $this->get('/groups.info', [
            'channel' => $channel
        ]);
    }

    /**
     * Get user list
     *
     * @param bool $presence optional. include presence information. default true.
     * @return HttpResponse
     */
    public function users_list(bool $presence = true): HttpResponse {
        return $this->get('/users.list', [
            'presence' => $presence ? 'true' : 'false'
        ]);
    }

    /**
     * Get a user's info
     *
     * @param string $user
     * @return HttpResponse
     */
    public function users_info(string $user): HttpResponse {
        return $this->get('/users.info', [
            'user' => $user
        ]);
    }

    /**
     * Open an IM with a user
     *
     * @param string $user
     * @return HttpResponse
     */
    public function im_open(string $user): HttpResponse {
        return $this->get('/im.open', [
            'user' => $user
        ]);
    }

    /**
     * Close an IM with a user
     *
     * @param string $channel
     * @return HttpResponse
     */
    public function im_close(string $channel): HttpResponse {
        return $this->get('/im.close', [
            'channel' => $channel
        ]);
    }

    /**
     * List IMs
     *
     * @return HttpResponse
     */
    public function im_list(): HttpResponse {
        return $this->get('/im.list');
    }

    /**
     * Post a chat message
     *
     * @param string $channel
     * @param string $text
     * @param array $attachments
     * @param array $options
     * @return HttpResponse
     */
    public function chat_post_message(string $channel, string $text, array $attachments = [], array $options = []): HttpResponse {
        $defaults = [
            'as_user' => true
        ];
        $payload = [
            'channel' => $channel,
            'text' => $text
        ];

        if (count($attachments)) {
            $payload['attachments'] = json_encode($attachments);
        }

        $payload = array_merge($defaults, $options, $payload);
        return $this->post('/chat.postMessage', $payload);
    }

    /**
     * Post a me message
     * 
     * @param string $channel
     * @param string $text
     * @return HttpResponse
     */
    public function chat_me_message(string $channel, string $text): HttpResponse {
        $defaults = [

        ];
        $payload = [
            'channel' => $channel,
            'text' => $text
        ];

        $payload = array_merge($defaults, $payload);
        return $this->post('/chat.meMessage', $payload);
    }

}
