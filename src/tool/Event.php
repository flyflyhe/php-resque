<?php
namespace he\queue\tool;

class Event
{
	/**
	 * @var array Array containing all registered callbacks, indexked by event name.
	 */
	private static $events = array();

    /**
     * Raise a given event with the supplied data.
     *
     * @param string $event Name of event to be raised.
     * @param mixed $data Optional, any data that should be passed to each callback.
     * @return true
     */
	public static function trigger(string $event, $data = null): bool
    {
		if (!is_array($data)) {
			$data = array($data);
		}

		if (empty(self::$events[$event])) {
			return true;
		}
		
		foreach (self::$events[$event] as $callback) {
			if (!is_callable($callback)) {
				continue;
			}
			call_user_func_array($callback, $data);
		}
		
		return true;
	}

    /**
     * Listen in on a given event to have a specified callback fired.
     *
     * @param string $event Name of event to listen on.
     * @param mixed $callback Any callback callable by call_user_func_array.
     * @return true
     */
	public static function listen(string $event, $callback): bool
    {
		if (!isset(self::$events[$event])) {
			self::$events[$event] = array();
		}
		
		self::$events[$event][] = $callback;
		return true;
	}

    /**
     * Stop a given callback from listening on a specific event.
     *
     * @param string $event Name of event.
     * @param mixed $callback The callback as defined when listen() was called.
     * @return true
     */
	public static function stopListening(string $event, $callback): bool
    {
		if (!isset(self::$events[$event])) {
			return true;
		}
		
		$key = array_search($callback, self::$events[$event]);
		if ($key !== false) {
			unset(self::$events[$event][$key]);
		}
		
		return true;
	}
	
	/**
	 * Call all registered listeners.
	 */
	public static function clearListeners()
	{
		self::$events = array();
	}
}
