<?php

/**
 * @license MIT
 * @copyright 2017 Tim Gunter
 */

namespace Kaecyra\ChatBot;

use Kaecyra\ChatBot\Error\FatalErrorHandler;
use Kaecyra\ChatBot\Error\LogErrorHandler;

use Garden\Daemon\Daemon;
use Garden\Daemon\AppInterface;
use Garden\Daemon\ErrorHandler;
use Garden\Container\Container;
use Garden\Container\Reference;
use Garden\Cli\Cli;
use Garden\Cli\Args;

use Kaecyra\AppCommon\AbstractConfig;
use Kaecyra\AppCommon\ConfigInterface;
use Kaecyra\AppCommon\ConfigCollection;
use Kaecyra\AppCommon\Event\EventAwareInterface;
use Kaecyra\AppCommon\Event\EventAwareTrait;
use Kaecyra\AppCommon\Addon\AddonManager;
use Kaecyra\AppCommon\Log\LoggerBoilerTrait;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LogLevel;

/**
 * Payload Context
 *
 * ChatBot is an asynchronous robot designed to join company instant messaging
 * rooms and provide useful and humorous commands and responses.
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @version 0.1
 */
class ChatBot implements AppInterface, LoggerAwareInterface, EventAwareInterface {

    use LoggerAwareTrait;
    use LoggerBoilerTrait;
    use EventAwareTrait;

    /**
     * Dependency Injection Container
     * @var ContainerInterface
     */
    protected $container;

    /**
     * Commandline handler
     * @var Cli
     */
    protected $cli;

    /**
     * Commandline args
     * @var Args
     */
    protected $args;

    /**
     * App configuration
     * @var Config
     */
    protected $config;

    /**
     * Addon manager
     * @var AddonManager
     */
    protected $addons;

    /**
     * Bootstrap
     *
     * @param ContainerInterface $container
     */
    public static function bootstrap(ContainerInterface $container, array $daemonConfig) {
        global $logger;

        // Reflect on ourselves for the version
        $matched = preg_match('`@version ([\w\d\.-]+)$`im', file_get_contents(__FILE__), $matches);
        if (!$matched) {
            echo "Unable to read version\n";
            exit;
        }
        $version = $matches[1];
        define('APP_VERSION', $version);

        define('APP', 'chatbot');
        define('PATH_ROOT', getcwd());
        date_default_timezone_set('UTC');

        // Check environment

        if (PHP_VERSION_ID < 70000) {
            die(APP." requires PHP 7.0 or greater.");
        }

        if (posix_getuid() != 0) {
            echo "Must be root.\n";
            exit;
        }

        // Report and track all errors

        error_reporting(E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR);
        ini_set('display_errors', 0);
        ini_set('track_errors', 1);

        define('PATH_CONFIG', PATH_ROOT.'/conf');

        $daemonConfig = array_merge([
            'appversion'        => APP_VERSION,
            'appdir'            => PATH_ROOT,
        ], $daemonConfig);

        // Prepare Dependency Injection

        $container
            ->rule(ContainerInterface::class)
            ->addAlias(Container::class)
            ->setInstance(Container::class, $container)

            ->defaultRule()
            ->setShared(true)

            ->rule(ConfigCollection::class)
            ->addAlias(AbstractConfig::class)
            ->addAlias(ConfigInterface::class)
            ->addCall('addFile', [paths(PATH_ROOT, 'conf/config.json'), false])
            ->addCall('addFolder', [paths(PATH_ROOT, 'conf/conf.d'), 'json'])

            ->rule(LoggerAwareInterface::class)
            ->addCall('setLogger')

            ->rule(EventAwareInterface::class)
            ->addCall('setEventManager')

            ->rule(AddonManager::class)
            ->setConstructorArgs([new Reference([AbstractConfig::class, 'addons.scan'])])

            ->rule(Daemon::class)
            ->setConstructorArgs([
                $daemonConfig,
                new Reference([AbstractConfig::class, 'daemon'])
            ])
            ->addCall('configure', [new Reference([AbstractConfig::class, "daemon"])]);

        // Set up loggers

        $logger = new \Kaecyra\AppCommon\Log\AggregateLogger;
        $logLevel = $container->get(AbstractConfig::class)->get('log.level');
        $loggers = $container->get(AbstractConfig::class)->get('log.loggers');
        foreach ($loggers as $logConfig) {
            $loggerClass = "Kaecyra\\AppCommon\\Log\\".ucfirst($logConfig['destination']).'Logger';
            if ($container->has($loggerClass)) {
                $subLogger = $container->getArgs($loggerClass, [PATH_ROOT, $logConfig]);
                $logger->addLogger($subLogger, $logConfig['level'] ?? $logLevel, $logConfig['key'] ?? null);
            }
        }

        $logger->disableLogger('persist');
        $container->setInstance(LoggerInterface::class, $logger);
    }

    /**
     * Construct app
     *
     * @param ContainerInterface $container
     * @param Cli $cli
     * @param ConfigInterface $config
     * @param AddonManager $addons
     * @param ErrorHandler $errorHandler
     */
    public function __construct(
        ContainerInterface $container,
        Cli $cli,
        ConfigInterface $config,
        AddonManager $addons,
        ErrorHandler $errorHandler
    ) {
        $this->container = $container;
        $this->cli = $cli;
        $this->config = $config;
        $this->addons = $addons;

        // Add logging error handler
        $logHandler = $this->container->get(LogErrorHandler::class);
        $errorHandler->addHandler([$logHandler, 'error'], E_ALL);

        // Add fatal error handler
        $fatalHandler = $this->container->get(FatalErrorHandler::class);
        $errorHandler->addHandler([$fatalHandler, 'error']);
    }

    /**
     * Check environment for app runtime compatibility
     *
     * Provide any custom CLI configuration, and check validity of configuration.
     *
     */
    public function preflight() {
        $this->log(LogLevel::INFO, " preflight checking");
    }

    /**
     * Initialize app and disable console logging
     *
     * This occurs in the main daemon process, prior to worker forking. No
     * connections should be established here, since this method's actions are
     * pre-worker forking, and will be shared to child processes.
     *
     * @param Args $args
     */
    public function initialize(Args $args) {
        $this->log(LogLevel::INFO, " initializing");

        // Remove echo logger

        $this->log(LogLevel::INFO, " transitioning logger");
        $this->getLogger()->removeLogger('echo', false);
        $this->getLogger()->enableLogger('persist');

        $this->addons->startAddons($this->config->get('addons.active'));

        $this->fire('queueStart');
    }

    /**
     * Dismiss app
     *
     * This occurs in the main daemon process when all child workers have been
     * reaped and we're about to shut down.
     */
    public function dismiss() {
        // NOOP
    }

    /**
     * Run payload
     *
     * This method is the main program scope for the payload. Forking has already
     * been handled at this point, so this scope is confined to a single process.
     *
     * Returning from this function ends the process.
     *
     * @param array $workerConfig
     */
    public function run($workerConfig) {

        // Execute payload

    }

}