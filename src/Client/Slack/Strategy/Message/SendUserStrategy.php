<?php

/**
 * @license MIT
 * @copyright 2010-2019 Tim Gunter
 */

namespace Kaecyra\ChatBot\Client\Slack\Strategy\Message;

use Kaecyra\ChatBot\Bot\Strategy\AbstractStrategy;

/**
 * Send user message strategy
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @package chatbot
 */
class SendUserStrategy extends AbstractStrategy {

    protected $phases = [
        'getconversation',
        'waitconversation',
        'sendmessage'
    ];

}
