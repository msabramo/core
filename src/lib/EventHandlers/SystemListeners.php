<?php
/**
 * Copyright 2010 Zikula Foundation
 *
 * This work is contributed to the Zikula Foundation under one or more
 * Contributor Agreements and licensed to You under the following license:
 *
 * @license GNU/LGPLv3 (or at your option, any later version).
 * @package Zikula_View
 *
 * Please see the NOTICE file distributed with this source code for further
 * information regarding copyright and licensing.
 */

use Zikula\Core\Event\GenericEvent;
use Zikula\Common\ServiceManager\Definition;
use Zikula\Common\ServiceManager\Reference;
use Zikula\Core\CoreEvents;

/**
 * Event handler to override templates.
 */
class SystemListeners extends Zikula_AbstractEventHandler
{
    /**
     * Setup handler definitions.
     *
     * @return void
     */
    protected function setupHandlerDefinitions()
    {
        $this->addHandlerDefinition('bootstrap.getconfig', 'initialHandlerScan', 100);
        $this->addHandlerDefinition('bootstrap.getconfig', 'getConfigFile');
        $this->addHandlerDefinition('setup.errorreporting', 'defaultErrorReporting');
        $this->addHandlerDefinition(CoreEvents::PREINIT, 'systemCheck');
        $this->addHandlerDefinition(CoreEvents::INIT, 'setupLoggers');
        $this->addHandlerDefinition('log', 'errorLog');
        $this->addHandlerDefinition(CoreEvents::INIT, 'sessionLogging');
        $this->addHandlerDefinition('session.require', 'requireSession');
        $this->addHandlerDefinition(CoreEvents::INIT, 'systemPlugins');
        $this->addHandlerDefinition(CoreEvents::INIT, 'setupRequest');
        $this->addHandlerDefinition(CoreEvents::INIT, 'setupDebugToolbar');
        $this->addHandlerDefinition('log.sql', 'logSqlQueries');
        $this->addHandlerDefinition(CoreEvents::INIT, 'setupAutoloaderForGeneratedCategoryModels');
        $this->addHandlerDefinition('installer.module.uninstalled', 'deleteGeneratedCategoryModelsOnModuleRemove');
        $this->addHandlerDefinition('pageutil.addvar_filter', 'coreStylesheetOverride');
        $this->addHandlerDefinition('module_dispatch.postexecute', 'addHooksLink');
        $this->addHandlerDefinition('module_dispatch.postexecute', 'addServiceLink');
        $this->addHandlerDefinition(CoreEvents::INIT, 'initDB');
        $this->addHandlerDefinition(CoreEvents::INIT, 'setupCsfrProtection');
        $this->addHandlerDefinition('theme.init', 'clickJackProtection');
        $this->addHandlerDefinition('frontcontroller.predispatch', 'sessionExpired', 3);
        $this->addHandlerDefinition('frontcontroller.predispatch', 'siteOff', 7);
    }

    /**
     * Event: 'frontcontroller.predispatch'.
     *
     * @param GenericEvent $event
     *
     * @return void
     */
    public function sessionExpired(GenericEvent $event)
    {
        if (SessionUtil::hasExpired()) {
            // Session has expired, display warning
            header('HTTP/1.0 403 Access Denied');
            $return = ModUtil::apiFunc('Users', 'user', 'expiredsession');
            System::shutdown();
        }
    }

    /**
     * Listens for 'frontcontroller.predispatch'.
     *
     * @param GenericEvent $event
     *
     * @return void
     */
    public function siteOff(GenericEvent $event)
    {
        // Get variables
        $module = FormUtil::getPassedValue('module', '', 'GETPOST', FILTER_SANITIZE_STRING);
        $func = FormUtil::getPassedValue('func', '', 'GETPOST', FILTER_SANITIZE_STRING);

        // Check for site closed
        if (System::getVar('siteoff') && !SecurityUtil::checkPermission('Settings::', 'SiteOff::', ACCESS_ADMIN) && !($module == 'Users' && $func == 'siteofflogin') || (Zikula\Core\Core::VERSION_NUM != System::getVar('Version_Num'))) {
            if (SecurityUtil::checkPermission('Users::', '::', ACCESS_OVERVIEW) && UserUtil::isLoggedIn()) {
                UserUtil::logout();
            }
            header('HTTP/1.1 503 Service Unavailable');
            require_once System::getSystemErrorTemplate('siteoff.tpl');
            System::shutdown();
        }
    }

    /**
     * Listen for the CoreEvents::INIT event & STAGE_DECODEURLS.
     *
     * This is basically a hack until the routing framework takes over (drak).
     *
     * @param GenericEvent $event Event.
     *
     * @return void
     */
    public function setupRequest(GenericEvent $event)
    {
        if ($event['stage'] & Zikula\Core\Core::STAGE_DECODEURLS) {
            $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
            $this->serviceManager->attachService('request', $request);

            $module = FormUtil::getPassedValue('module', null, 'GETPOST', FILTER_SANITIZE_STRING);
            $controller = FormUtil::getPassedValue('type', null, 'GETPOST', FILTER_SANITIZE_STRING);
            $action = FormUtil::getPassedValue('func', null, 'GETPOST', FILTER_SANITIZE_STRING);

            $request->attributes->set('_module', $module);
            $request->attributes->set('_controller', $controller);
            $request->attributes->set('_action', $action);
            $request->setLocale(ZLanguage::getLanguageCode());

            $session = $this->serviceManager->getService('session');
            $request->setSession($session);
        }
    }

    /**
     * Listens for 'bootstrap.getconfig' event.
     *
     * @param GenericEvent $event Event.
     *
     * @return void
     */
    public function initialHandlerScan(GenericEvent $event)
    {
        $core = $this->serviceManager->getService('zikula');
        ServiceUtil::getManager($core);
        EventUtil::getManager($core);
        $core->attachHandlers('config/EventHandlers');
    }

    /**
     * Listen on CoreEvents::INIT module.
     *
     * @param GenericEvent $event Event.
     *
     * @return void
     */
    public function setupCsfrProtection(GenericEvent $event)
    {
        if ($event['stage'] & Zikula\Core\Core::STAGE_MODS) {
            $this->serviceManager->setArgument('signing.key', System::getVar('signingkey'));
        }
    }

    /**
     * If enabled and logged in, save login name of user in Apache session variable for Apache logs.
     *
     * Implements CoreEvents::INIT event when Zikula\Core\Core::STAGE_SESSIONS.
     *
     * @param GenericEvent $event The event handler.
     *
     * @return void
     */
    public function sessionLogging(GenericEvent $event)
    {
        if ($event['stage'] & Zikula\Core\Core::STAGE_SESSIONS) {
            // If enabled and logged in, save login name of user in Apache session variable for Apache logs
            if (isset($GLOBALS['ZConfig']['Log']['log.apache_uname']) && ($GLOBALS['ZConfig']['Log']['log.apache_uname']) && UserUtil::isLoggedIn()) {
                if (function_exists('apache_setenv')) {
                    apache_setenv('Zikula-Username', UserUtil::getVar('uname'));
                }
            }
        }
    }

    /**
     * If enabled and logged in, save login name of user in Apache session variable for Apache logs.
     *
     * Implements 'session.require'.
     *
     * @param GenericEvent $event The event handler.
     *
     * @return void
     */
    public function requireSession(GenericEvent $event)
    {
        $session = $this->serviceManager->getService('session');
        $request = $this->serviceManager->getService('request');
        $request->setSession($session);

        try {
            if (!$session->start()) {
                throw new RuntimeException('Failed to start session');
            }
        } catch (Exception $e) {
            // session initialization failed so display templated error
            header('HTTP/1.1 503 Service Unavailable');
            require_once System::getSystemErrorTemplate('sessionfailed.tpl');
            System::shutdown();
        }
    }

    /**
     * Initialise DB connection.
     *
     * Implements CoreEvents::INIT event when Zikula\Core\Core::STAGE_DB.
     *
     * @param GenericEvent $event The event handler.
     *
     * @return void
     */
    public function initDB(GenericEvent $event)
    {
        if ($event['stage'] & Zikula\Core\Core::STAGE_DB) {
            $dbEvent = new GenericEvent();
            $this->eventManager->dispatch('doctrine.init_connection', $dbEvent);
        }
    }

    /**
     * Load system plugins.
     *
     * Implements CoreEvents::INIT event when Zikula\Core\Core::STAGE_TABLES.
     *
     * @param GenericEvent $event The event handler.
     *
     * @return void
     */
    public function systemPlugins(GenericEvent $event)
    {
        if ($event['stage'] & Zikula\Core\Core::STAGE_TABLES) {
            if (!System::isInstalling()) {
                ServiceUtil::loadPersistentServices();
                PluginUtil::loadPlugins(realpath(realpath('.').'/plugins'), "SystemPlugin");
                EventUtil::loadPersistentEvents();
            }
        }
    }

    /**
     * Setup default error reporting.
     *
     * Implements 'setup.errorreporting' event.
     *
     * @param GenericEvent $event The event.
     *
     * @return void
     */
    public function defaultErrorReporting(GenericEvent $event)
    {
        if (!$this->serviceManager['log.enabled']) {
            return;
        }

        if ($this->serviceManager->hasService('system.errorreporting')) {
            return;
        }

        $class = 'Zikula\\Framework\\ErrorHandler\\Standard';
        if ($event['stage'] & Zikula\Core\Core::STAGE_AJAX) {
            $class = 'Zikula\\Framework\\ErrorHandler\\Ajax';
        }

        $errorHandler = new $class($this->serviceManager);
        $this->serviceManager->attachService('system.errorreporting', $errorHandler);
        set_error_handler(array($errorHandler, 'handler'));
        $event->stopPropagation();
    }

    /**
     * Establish the necessary instances for logging.
     *
     * Implements CoreEvents::INIT event when Zikula\Core\Core::STAGE_CONFIG.
     *
     * @param GenericEvent $event The event to log.
     *
     * @return void
     */
    public function setupLoggers(GenericEvent $event)
    {
        if (!($event['stage'] & Zikula\Core\Core::STAGE_CONFIG)) {
            return;
        }

        if (!$this->serviceManager['log.enabled']) {
            return;
        }

        if ($this->serviceManager['log.to_display'] || $this->serviceManager['log.sql.to_display']) {
            $displayLogger = $this->serviceManager->attachService('zend.logger.display', new Zend_Log());
            // load writer first because of hard requires in the Zend_Log_Writer_Stream
            $writer = new Zend_Log_Writer_Stream('php://output');
            $formatter = new Zend_Log_Formatter_Simple('%priorityName% (%priority%): %message% <br />' . PHP_EOL);
            $writer->setFormatter($formatter);
            $displayLogger->addWriter($writer);
        }

        if ($this->serviceManager['log.to_file'] || $this->serviceManager['log.sql.to_file']) {
            $fileLogger = $this->serviceManager->attachService('zend.logger.file', new Zend_Log());
            $filename = LogUtil::getLogFileName();
            // load writer first because of hard requires in the Zend_Log_Writer_Stream
            $writer = new Zend_Log_Writer_Stream($filename);
            $formatter = new Zend_Log_Formatter_Simple('%timestamp% %priorityName% (%priority%): %message%' . PHP_EOL);

            $writer->setFormatter($formatter);
            $fileLogger->addWriter($writer);
        }
    }

    /**
     * Log an error.
     *
     * Implements 'log' event.
     *
     * @param GenericEvent $event The log event to log.
     *
     * @throws Zikula_Exception_Fatal Thrown if the handler for the event is an instance of Zikula_ErrorHandler_Ajax.
     *
     * @return void
     */
    public function errorLog(GenericEvent $event)
    {
        // Check for error supression.  if error @ supression was used.
        // $errno wil still contain the real error that triggered the handler - drak
        if (error_reporting() == 0) {
            return;
        }

        $handler = $event->getSubject();

        // array('trace' => $trace, 'type' => $type, 'errno' => $errno, 'errstr' => $errstr, 'errfile' => $errfile, 'errline' => $errline, 'errcontext' => $errcontext)
        $message = $event['errstr'];
        if (is_string($event['errstr'])) {
            if ($event['errline'] == 0) {
                $message = __f('PHP issued an error at line 0, so reporting entire trace to be more helpful: %1$s: %2$s', array(Zikula_AbstractErrorHandler::translateErrorCode($event['errno']), $event['errstr']));
                $fullTrace = $event['trace'];
                array_shift($fullTrace); // shift is performed on copy so as not to disturn the event args
                foreach ($fullTrace as $trace) {
                    $file = isset($trace['file']) ? $trace['file'] : null;
                    $line = isset($trace['line']) ? $trace['line'] : null;

                    if ($file && $line) {
                        $message .= ' ' . __f('traced in %1$s line %2$s', array($file, $line)) . "#\n";
                    }
                }
            } else {
                $message = __f('%1$s: %2$s in %3$s line %4$s', array(Zikula_AbstractErrorHandler::translateErrorCode($event['errno']), $event['errstr'], $event['errfile'], $event['errline']));
            }
        }

        if ($this->serviceManager['log.to_display'] && !$handler instanceof Zikula_ErrorHandler_Ajax) {
            if (abs($handler->getType()) <= $this->serviceManager['log.display_level']) {
                $this->serviceManager->getService('zend.logger.display')->log($message, abs($event['type']));
            }
        }

        if ($this->serviceManager['log.to_file']) {
            if (abs($handler->getType()) <= $this->serviceManager['log.file_level']) {
                $this->serviceManager->getService('zend.logger.file')->log($message, abs($event['type']));
            }
        }

        if ($handler instanceof Zikula_ErrorHandler_Ajax) {
            if (abs($handler->getType()) <= $this->serviceManager['log.display_ajax_level']) {
                // autoloaders don't work inside error handlers!
                include_once 'lib/Zikula/Exception.php';
                include_once 'lib/Zikula/Exception/Fatal.php';
                throw new Zikula_Exception_Fatal($message);
            }
        }
    }

    /**
     * Listener for 'log.sql' events.
     *
     * This listener logs the queries via Zend_Log to file / console.
     *
     * @param GenericEvent $event Event.
     *
     * @return void
     */
    public function logSqlQueries(GenericEvent $event)
    {
        if (!$this->serviceManager['log.enabled']) {
            return;
        }

        $message = __f('SQL Query: %s took %s sec', array($event['query'], $event['time']));

        if ($this->serviceManager['log.sql.to_display']) {
            $this->serviceManager->getService('zend.logger.display')->log($message, Zend_Log::DEBUG);
        }

        if ($this->serviceManager['log.sql.to_file']) {
            $this->serviceManager->getService('zend.logger.file')->log($message, Zend_Log::DEBUG);
        }
    }

    /**
     * Debug toolbar startup.
     *
     * Implements CoreEvents::INIT event when Zikula\Core\Core::STAGE_CONFIG in development mode.
     *
     * @param GenericEvent $event Event.
     *
     * @return void
     */
    public function setupDebugToolbar(GenericEvent $event)
    {
        if ($event['stage'] == Zikula\Core\Core::STAGE_CONFIG && System::isDevelopmentMode() && $event->getSubject()->getServiceManager()->getArgument('log.to_debug_toolbar')) {
            // autoloaders don't work inside error handlers!
            include_once 'lib/Zikula/Framework/DebugToolbar/Panel/Log.php';

            // create definitions
            $toolbar = new Definition(
                            'Zikula\Framework\DebugToolbar\DebugToolbar',
                            array(new Reference('zikula.eventmanager')),
                            array('addPanels' => array(0 => array(
                                                    new Reference('debug.toolbar.panel.version'),
                                                    new Reference('debug.toolbar.panel.config'),
                                                    new Reference('debug.toolbar.panel.memory'),
                                                    new Reference('debug.toolbar.panel.rendertime'),
                                                    new Reference('debug.toolbar.panel.sql'),
                                                    new Reference('debug.toolbar.panel.view'),
                                                    new Reference('debug.toolbar.panel.exec'),
                                                    new Reference('debug.toolbar.panel.logs'))))
            );

            $versionPanel = new Definition('Zikula\Framework\DebugToolbar\Panel\Version');
            $configPanel = new Definition('Zikula\Framework\DebugToolbar\Panel\Config');
            $momoryPanel = new Definition('Zikula\Framework\DebugToolbar\Panel\Memory');
            $rendertimePanel = new Definition('Zikula\Framework\DebugToolbar\Panel\RenderTime');
            $sqlPanel = new Definition('Zikula\Framework\DebugToolbar\Panel\SQL');
            $viewPanel = new Definition('Zikula\Framework\DebugToolbar\Panel\View');
            $execPanel = new Definition('Zikula\Framework\DebugToolbar\Panel\Exec');
            $logsPanel = new Definition('Zikula\Framework\DebugToolbar\Panel\Log');

            // save start time (required by rendertime panel)
            $this->serviceManager->setArgument('debug.toolbar.panel.rendertime.start', microtime(true));

            // register services
            $this->serviceManager->registerService('debug.toolbar.panel.version', $versionPanel, true);
            $this->serviceManager->registerService('debug.toolbar.panel.config', $configPanel, true);
            $this->serviceManager->registerService('debug.toolbar.panel.memory', $momoryPanel, true);
            $this->serviceManager->registerService('debug.toolbar.panel.rendertime', $rendertimePanel, true);
            $this->serviceManager->registerService('debug.toolbar.panel.sql', $sqlPanel, true);
            $this->serviceManager->registerService('debug.toolbar.panel.view', $viewPanel, true);
            $this->serviceManager->registerService('debug.toolbar.panel.exec', $execPanel, true);
            $this->serviceManager->registerService('debug.toolbar.panel.logs', $logsPanel, true);
            $this->serviceManager->registerService('debug.toolbar', $toolbar, true);

            // setup rendering event listeners
            $this->eventManager->attach('theme.prefetch', array($this, 'debugToolbarRendering'));
            $this->eventManager->attach('theme.postfetch', array($this, 'debugToolbarRendering'));

            // setup event listeners
            $this->eventManager->attach('view.init', new Zikula_ServiceHandler('debug.toolbar.panel.view', 'initRenderer'));
            $this->eventManager->attach('module_dispatch.preexecute', new Zikula_ServiceHandler('debug.toolbar.panel.exec', 'modexecPre'), 20);
            $this->eventManager->attach('module_dispatch.postexecute', new Zikula_ServiceHandler('debug.toolbar.panel.exec', 'modexecPost'), 20);
            $this->eventManager->attach('module_dispatch.execute_not_found', new Zikula_ServiceHandler('debug.toolbar.panel.logs', 'logExecNotFound'), 20);
            $this->eventManager->attach('log', new Zikula_ServiceHandler('debug.toolbar.panel.logs', 'log'));
            $this->eventManager->attach('log.sql', new Zikula_ServiceHandler('debug.toolbar.panel.sql', 'logSql'));
            $this->eventManager->attach('controller.method_not_found', new Zikula_ServiceHandler('debug.toolbar.panel.logs', 'logModControllerNotFound'), 20);
            $this->eventManager->attach('controller_api.method_not_found', new Zikula_ServiceHandler('debug.toolbar.panel.logs', 'logModControllerAPINotFound'), 20);
        }
    }

    /**
     * Debug toolbar rendering (listener for 'theme.prefetch' and 'theme.postfetch' events).
     *
     * @param GenericEvent $event Event.
     *
     * @return void
     */
    public function debugToolbarRendering(GenericEvent $event)
    {
        if (!$event->getSubject() instanceof Zikula_ErrorHandler_Ajax) {
            if ($event->getName() == 'theme.prefetch') {
                // force object construction (debug toolbar constructor registers javascript and css files via PageUtil)
                $this->serviceManager->getService('debug.toolbar');
            } else {
                $toolbar = $this->serviceManager->getService('debug.toolbar');
                $html = $toolbar->getContent() . "\n</body>";
                $event->setData(str_replace('</body>', $html, $event->getData()));
            }
        }
    }

    /**
     * Adds an autoloader entry for the cached (generated) doctrine models.
     *
     * Implements CoreEvents::INIT events when Zikula\Core\Core::STAGE_CONFIG.
     *
     * @param GenericEvent $event Event.
     *
     * @return void
     */
    public function setupAutoloaderForGeneratedCategoryModels(GenericEvent $event)
    {
        if ($event['stage'] == Zikula\Core\Core::STAGE_CONFIG) {
            ZLoader::addAutoloader('GeneratedDoctrineModel', CacheUtil::getLocalDir('doctrinemodels'));
        }
    }

    /**
     * On an module remove hook call this listener deletes all cached (generated) doctrine models for the module.
     *
     * Listens for the 'installer.module.uninstalled' event.
     *
     * @param GenericEvent $event Event.
     *
     * @return void
     */
    public function deleteGeneratedCategoryModelsOnModuleRemove(GenericEvent $event)
    {
        $moduleName = $event['name'];

        // remove generated category models for this record
        $dir = 'doctrinemodels/GeneratedDoctrineModel/' . $moduleName;
        if (file_exists(CacheUtil::getLocalDir($dir))) {
            CacheUtil::removeLocalDir($dir, true);
        }

        // remove saved data about the record
        $modelsInfo = ModUtil::getVar('Categories', 'EntityCategorySubclasses', array());
        foreach ($modelsInfo as $class => $info) {
            if ($info['module'] == $moduleName) {
                unset($modelsInfo[$class]);
            }
        }
        ModUtil::setVar('Categories', 'EntityCategorySubclasses', $modelsInfo);
    }

    /**
     * Core stylesheet override.
     *
     * Implements 'pageutil.addvar_filter' event.
     *
     * @param GenericEvent $event The event handler.
     *
     * @return void
     */
    public function coreStylesheetOverride(GenericEvent $event)
    {
        if ($event->getSubject() == 'stylesheet' && ($key = array_search('style/core.css', (array)$event->data)) !== false) {
            if (file_exists('config/style/core.css')) {
                $event->data[$key] = 'config/style/core.css';
            }

            $event->stopPropagation();
        }
    }

    /**
     * Dynamically add Hooks link to administration.
     *
     * Listens for 'module_dispatch.postexecute' events.
     *
     * @param GenericEvent $event The event handler.
     *
     * @return void
     */
    public function addHooksLink(GenericEvent $event)
    {
        // check if this is for this handler
        if (!($event['modfunc'][1] == 'getlinks' && $event['type'] == 'admin' && $event['api'] == true)) {
            return;
        }

        if (!SecurityUtil::checkPermission($event['modname'] . '::Hooks', '::', ACCESS_ADMIN)) {
            return;
        }

        // return if module is not subscriber or provider capable
        if (!HookUtil::isSubscriberCapable($event['modname']) && !HookUtil::isProviderCapable($event['modname'])) {
            return;
        }

        $event->data[] = array(
                'url' => ModUtil::url($event['modname'], 'admin', 'hooks'),
                'text' => __('Hooks'),
                'class' => 'z-icon-es-hook'
        );
    }

    /**
     * Dynamically add menu links to administration for system services.
     *
     * Listens for 'module_dispatch.postexecute' events.
     *
     * @param GenericEvent $event The event handler.
     *
     * @return void
     */
    public function addServiceLink(GenericEvent $event)
    {
        // check if this is for this handler
        if (!($event['modfunc'][1] == 'getlinks' && $event['type'] == 'admin' && $event['api'] == true)) {
            return;
        }

        // notify EVENT here to gather any system service links
        $args = array('modname' => $event->getArg('modname'));
        $localevent = new GenericEvent($event->getSubject(), $args);
        $this->eventManager->dispatch('module_dispatch.service_links', $localevent);
        $sublinks = $localevent->getData();

        if (!empty($sublinks)) {
            $event->data[] = array(
                    'url' => ModUtil::url($event['modname'], 'admin', 'moduleservices'),
                    'text' => __('Services'),
                    'class' => 'z-icon-es-gears',
                    'links' => $sublinks);
        }
    }

    /**
     * Listens for 'bootstrap.getconfig'
     *
     * @param GenericEvent $event Event.
     *
     * @return void
     */
    public function getConfigFile(GenericEvent $event)
    {
        if (is_readable('config/config.php')) {
            include 'config/config.php';
        }

        if (is_readable('config/personal_config.php')) {
            include 'config/personal_config.php';
        }

        if (is_readable('config/multisites_config.php')) {
            include 'config/multisites_config.php';
        }

        foreach ($GLOBALS['ZConfig'] as $config) {
            $event->getSubject()->getServiceManager()->loadArguments($config);
        }

        $event->stopPropagation();
    }

    /**
     * Perform some checks that might result in a die() upon failure.
     *
     * Listens on the CoreEvents::PREINIT event.
     *
     * @param GenericEvent $event Event.
     *
     * @return void
     */
    public function systemCheck(GenericEvent $event)
    {
        $die = false;

        if (get_magic_quotes_runtime()) {
            echo __('Error! Zikula does not support PHP magic_quotes_runtime - please disable this feature in php.ini.');
            $die = true;
        }

        if (ini_get('magic_quotes_gpc')) {
            echo __('Error! Zikula does not support PHP magic_quotes_gpc = On - please disable this feature in your php.ini file.');
            $die = true;
        }

        if (ini_get('register_globals')) {
            echo __('Error! Zikula does not support PHP register_globals = On - please disable this feature in your php.ini or .htaccess file.');
            $die = true;
        }

        // check PHP version, shouldn't be necessary, but....
        $x = explode('.', str_replace('-', '.', phpversion()));
        $phpVersion = "$x[0].$x[1].$x[2]";
        if (version_compare($phpVersion, Zikula\Core\Core::PHP_MINIMUM_VERSION, '>=') == false) {
            echo __f('Error! Zikula requires PHP version %1$s or greater. Your server seems to be using version %2$s.', array(Zikula\Core\Core::PHP_MINIMUM_VERSION, $phpVersion));
            $die = true;
        }

        // token_get_all needed for Smarty
        if (!function_exists('token_get_all')) {
            echo __("Error! PHP 'token_get_all()' is required but unavailable.");
            $die = true;
        }

        // mb_string is needed too
        if (!function_exists('mb_get_info')) {
            echo __("Error! PHP must have the mbstring extension loaded.");
            $die = true;
        }

        if (!function_exists('fsockopen')) {
            echo __("Error! The PHP function 'fsockopen()' is needed within the Zikula mailer module, but is not available.");
            $die = true;
        }

        if ($die) {
            echo __("Please configure your server to meet the Zikula system requirements.");
            exit;
        }

        if (System::isDevelopmentMode() || System::isInstalling()) {
            $temp = $this->serviceManager->getArgument('temp');
            if (!is_dir($temp) || !is_writable($temp)) {
                echo __f('The temporary directory "%s" and its subfolders must be writable.', $temp) . '<br />';
                die(__('Please ensure that the permissions are set correctly on your server.'));
            }

            $folders = array(
                    $temp,
                    "$temp/error_logs",
                    "$temp/view_compiled",
                    "$temp/view_cache",
                    "$temp/Theme_compiled",
                    "$temp/Theme_cache",
                    "$temp/Theme_Config",
                    "$temp/Theme_cache",
                    "$temp/purifierCache",
                    "$temp/idsTmp"
            );

            foreach ($folders as $folder) {
                if (!is_dir($folder)) {
                    mkdir($folder, $this->serviceManager->getArgument('system.chmod_dir'), true);
                }
                if (!is_writable($folder)) {
                    echo __f("System error! Folder '%s' was not found or is not writable.", $folder) . '<br />';
                    $die = true;
                }
            }
        }

        if ($die) {
            echo __('Please ensure that the permissions are set correctly for the mentioned folders.');
            exit;
        }
    }

    /**
     * Respond to theme.init events.
     *
     * Issues anti-clickjack headers.
     *
     * @link http://www.owasp.org/images/0/0e/OWASP_AppSec_Research_2010_Busting_Frame_Busting_by_Rydstedt.pdf
     * @link http://www.contextis.co.uk/resources/white-papers/clickjacking/Context-Clickjacking_white_paper.pdf
     *
     * @todo Reimplement in response/header objects in 1.4.0 - drak.
     *
     * @param Zikula $event
     *
     * @return void
     */
    public function clickJackProtection(GenericEvent $event)
    {
        header('X-Frames-Options: SAMEORIGIN');
        //header("X-Content-Security-Policy: frame-ancestors 'self'");
        header('X-XSS-Protection: 1');
    }

}