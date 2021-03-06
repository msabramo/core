<?php
/**
 * Copyright 2009 Zikula Foundation
 *
 * This work is contributed to the Zikula Foundation under one or more
 * Contributor Agreements and licensed to You under the following license:
 *
 * @license GNU/LGPLv3 (or at your option, any later version).
 * @package Zikula
 * @subpackage Zikula_EventManager
 *
 * Please see the NOTICE file distributed with this source code for further
 * information regarding copyright and licensing.
 */

namespace Zikula\Common\EventManager;
use Zikula\Common\ServiceManager\ServiceManager;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * EventManager.
 *
 * Manages event handlers and invokes them for notified events.
 */
class EventManager extends EventDispatcher implements EventManagerInterface
{
    /**
     * Attach an event handler to the stack.
     *
     * @param string  $name     Name of handler.
     * @param mixed   $handler  Callable handler or instance of ServiceHandler.
     * @param integer $priority Priotity to control notification order, (default = 0).
     *
     * @deprecated Use addListener
     *
     * @throws \InvalidArgumentException If Handler is not callable or an instance of ServiceHandler.
     *
     * @return void
     */
    public function attach($name, $handler, $priority=0)
    {
        $this->addListener($name, $handler, $priority);
    }

    /**
     * Removed a handler from the stack.
     *
     * @param string   $name    Handler name.
     * @param callable $handler Callable handler.
     *
     * @deprecated Use removeListener
     *
     * @return void
     */
    public function detach($name, $handler)
    {
        $this->removeListener($name, $handler);
    }

    /**
     * Notify all handlers for given event name but stop if signalled.
     *
     * @param Event $event Event.
     *
     * @return Event
     */
    public function notify(Event $event)
    {
        return $this->dispatch($event->getName(), $event);
    }

    /**
     * Flush handlers.
     *
     * Clears all handlers.
     *
     * @return void
     */
    public function flushHandlers()
    {
        foreach ($this->getListeners() as $eventName => $listener) {
            $this->removeListener($eventName, $listener);
        }
    }
}
