<?php

/**
 * @license MIT
 * @copyright 2014-2017 Tim Gunter
 */

namespace Kaecyra\ChatBot\Bot\Command;

/**
 * Simple command
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @package chatbot
 */
class SimpleCommand extends AbstractCommand {

    public function __construct(string $method) {
        parent::__construct();
        $this->setCommand($method);
    }

}