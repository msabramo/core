<?php
/**
 * Copyright Zikula Foundation 2009 - Zikula Application Framework
 *
 * This work is contributed to the Zikula Foundation under one or more
 * Contributor Agreements and licensed to You under the following license:
 *
 * @license GNU/LGPLv3 (or at your option, any later version).
 * @package Util
 *
 * Please see the NOTICE file distributed with this source code for further
 * information regarding copyright and licensing.
 */

use Symfony\Component\EventDispatcher\Event;

/**
 * EventUtil
 */
class EventUtil
{
    /**
     * Singleton instance of EventManager.
     *
     * @var \Zikula\Common\EventManager\EventManager
     */
    public static $eventManager;

    /**
     * Event handlers key for persistence.
     */
    const HANDLERS = '/EventHandlers';

    /**
     * Singleton constructor.
     */
    private function __construct()
    {

    }

    /**
     * Get EventManager instance.
     *
     * @param Zikula\Core\Core $core Core instance.
     *
     * @return \Zikula\Common\EventManager\EventManager
     */
    static public function getManager(Zikula\Core\Core $core = null)
    {
        if (self::$eventManager) {
            return self::$eventManager;
        }

        self::$eventManager = $core->getEventManager();

        return self::$eventManager;
    }

    /**
     * Notify event.
     *
     * @param Event $event Event.
     *
     * @return Event
     */
    static public function notify(Event $event)
    {
        return self::getManager()->notify($event);
    }

    /**
     * Dispatch event.
     *
     * @param string $name  Event name.
     * @param Event  $event Event.
     *
     * @return Event
     */
    static public function dispatch($name, Event $event = null)
    {
        if (!$event) {
            $event = new Event;
        }

        return self::getManager()->dispatch($name, $event);
    }

    /**
     * Attach listener.
     *
     * @param string       $name     Name of event.
     * @param array|string $handler  PHP Callable.
     * @param integer      $priority Higher get's executed first, default = 0.
     *
     * @return void
     */
    static public function attach($name, $handler, $priority=0)
    {
        self::getManager()->attach($name, $handler, $priority=0);
    }

    /**
     * Attach a service handler as an event listener.
     *
     * @param string         $name           Event name.
     * @param ServiceHandler $serviceHandler ServiceHandler (serviceID, Method)
     * @param string         $priority       Higher get's executed first, default = 0.
     */
    static public function attachListenerService($name, ServiceHandler $serviceHandler, $priority=0)
    {
        self::getManager()->attachListenerService($name, $serviceHandler, $priority);
    }

    /**
     * Detach listener.
     *
     * @param string       $name    Name of listener.
     * @param array|string $handler PHP callable.
     *
     * @return void
     */
    static public function detach($name, $handler)
    {
        self::getManager()->detach($name, $handler);
    }

    /**
     * Loader for custom handlers.
     *
     * @param string $dir Path to the folder holding the eventhandler classes.
     *
     * @return void
     */
    static public function attachCustomHandlers($dir)
    {
        self::$eventManager->getServiceManager()->getService('zikula')->attachHandlers($dir);
    }

    /**
     * Load and attach handlers for Zikula\Framework\AbstractEventHandler listeners.
     *
     * Loads event handlers that extend Zikula\Framework\AbstractEventHandler
     *
     * @param string $className The name of the class.
     *
     * @return void
     */
    public static function attachEventHandler($className)
    {
        $serviceManager = ServiceUtil::getManager();
        $serviceManager->getService('zikula')->attachEventHandler($className);
    }

    /**
     * Register a static persistent event for a module.
     *
     * @param string       $moduleName Module name.
     * @param string       $eventName  Event name.
     * @param string|array $callable   PHP static callable.
     * @param integer      $weight     Weight of handler, default = 10.
     *
     * @throws InvalidArgumentException If the callable given is not callable.
     *
     * @return void
     */
    public static function registerPersistentModuleHandler($moduleName, $eventName, $callable, $weight=10)
    {
        if (!is_callable($callable)) {
            if (is_array($callable)) {
                throw new InvalidArgumentException(sprintf('array(%s, %s) is not a valid PHP callable', $callable[0], $callable[1]));
            }

            throw new InvalidArgumentException(sprintf('%s is not a valid PHP callable', $callable));
        }

        if (is_array($callable) && is_object($callable[0])) {
            throw new InvalidArgumentException('Callable cannot be an instanciated class');
        }

        $handlers = ModUtil::getVar(self::HANDLERS, $moduleName, array());
        $handlers[] = array('eventname' => $eventName, 'callable' => $callable, 'weight' => $weight);
        ModUtil::setVar(self::HANDLERS, $moduleName, $handlers);
    }

    /**
     * Unregister a static persistent event handler for a module.
     *
     * @param string       $moduleName Module name.
     * @param string       $eventName  Event name.
     * @param string|array $callable   PHP static callable.
     * @param integer      $weight     Weight.
     *
     * @return void
     */
    public static function unregisterPersistentModuleHandler($moduleName, $eventName, $callable, $weight=10)
    {
        $handlers = ModUtil::getVar(self::HANDLERS, $moduleName, false);
        if (!$handlers) {
            return;
        }
        $filteredHandlers = array();
        foreach ($handlers as $handler) {
            if ($handler !== array('eventname' => $eventName, 'callable' => $callable, 'weight' => $weight)) {
                $filteredHandlers[] = $handler;
            }
        }
        ModUtil::setVar(self::HANDLERS, $moduleName, $filteredHandlers);
    }

    /**
     * Register a Zikula\Framework\AbstractEventHandler as a persistent handler.
     *
     * @param string $moduleName Module name.
     * @param string $className  Class name (subclass of Zikula\Framework\AbstractEventHandler).
     *
     * @throws InvalidArgumentException If class is not available or not a subclass of Zikula\Framework\AbstractEventHandler.
     *
     * @return void
     */
    public static function registerPersistentEventHandlerClass($moduleName, $className)
    {
        if (!class_exists($className)) {
            throw new InvalidArgumentException(sprintf('Class %s does not exist or cannot be found', $className));
        }

        $reflection = new ReflectionClass($className);
        if (!$reflection->isSubclassOf('Zikula\Framework\AbstractEventHandler')) {
            throw new InvalidArgumentException(sprintf('%s is not a subclass of Zikula\Framework\AbstractEventHandler', $className));
        }

        $handlers = ModUtil::getVar(self::HANDLERS, $moduleName, array());
        $handlers[] = array('classname' => $className);
        ModUtil::setVar(self::HANDLERS, $moduleName, $handlers);
    }

    /**
     * Unregister a Zikula\Framework\AbstractEventHandler event handler.
     *
     * @param string $moduleName Module name.
     * @param string $className  Class name (subclass of Zikula\Framework\AbstractEventHandler).
     *
     * @return void
     */
    public static function unregisterPersistentStaticHandler($moduleName, $className)
    {
        $handlers = ModUtil::getVar(self::HANDLERS, $moduleName, false);
        if (!$handlers) {
            return;
        }
        $filteredHandlers = array();
        foreach ($handlers as $handler) {
            if ($handler !== array('classname' => $className)) {
                $filteredHandlers[] = $handler;
            }
        }
        ModUtil::setVar(self::HANDLERS, $moduleName, $filteredHandlers);
    }

    /**
     * Unregister all persisten event handlers for a given module.
     *
     * @param string $moduleName Module name.
     *
     * @return void
     */
    public static function unregisterPersistentModuleHandlers($moduleName)
    {
        ModUtil::delVar(self::HANDLERS, $moduleName);
    }

    /**
     * Load all persistent events handlers into EventManager.
     *
     * This loads persistent events registered by modules and module plugins.
     *
     * @internal
     *
     * @return void
     */
    public static function loadPersistentEvents()
    {
        $handlerGroup = ModUtil::getVar(self::HANDLERS);
        if (!$handlerGroup) {
            return;
        }
        foreach ($handlerGroup as $module => $handlers) {
            if (!$handlers) {
                continue;
            }
            foreach ($handlers as $handler) {
                if (ModUtil::available($module)) {
                    try {
                        if (isset($handler['classname'])) {
                            self::attachEventHandler($handler['classname']);
                        } else {
                            self::attach($handler['eventname'], $handler['callable'], $handler['weight']);
                        }
                    } catch (InvalidArgumentException $e) {
                        LogUtil::log(sprintf("Event handler could not be attached because %s", $e->getMessage()), Zikula_AbstractErrorHandler::ERR);
                    }
                }
            }
        }
    }
}
