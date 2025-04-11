<?php

/**
 * catsite WordPress plugin.
 * Data exchange module.
 *
 * @author  wjaguar <https://github.com/wjaguar>
 * @version 0.9.2
 * @package catsite
 */

class CatsiteOptions
{

	/**
	 * Default and nondefault settings.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private $defs = [];

	/**
	 * Cached WP options.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private $opt = [];

	/**
	 * Stored WP error messages.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private $err = [];

	/**
	 * Plugin's WP options prefix.
	 *
	 * @var   string
	 * @since 0.1.0
	 */
	private $prefix = '';

	/**
	 * Update an existing object with new settings, or create one anew.
	 *
	 * @param  \CatsiteOptions|null $obj   The existing settings object or NULL.
	 * @param  array		$array The new settings.
	 * @return \CatsiteOptions A new/updated settings object.
	 * @since  0.1.0
	 */
	public static function update($obj, $array)
	{
		if (!($obj instanceof self)) $obj = new self();
		$obj->defs = $obj->defs + (array)$array;
		$obj->prefix = isset($obj->defs['option_root']) ?
			$obj->defs['option_root'] . '_' : '';
		return $obj;
	}

	/**
	 * Get a setting value.
	 *
	 * @param  string $key The setting's key.
	 * @return mixed The setting's value, or NULL if none.
	 * @since  0.1.0
	 */
	public function def($key)
	{
		return $this->defs[$key] ?? null;
	}

	/**
	 * Get multiple settings' values.
	 *
	 * @param  array $keys The settings' keys.
	 * @return array The settings' values (or NULLs) in the same order.
	 * @since  0.1.0
	 */
	public function mdef($keys)
	{
		foreach ($keys as $key)
			$res[] = $this->defs[$key] ?? null;
		return $res;
	}

	/**
	 * Get all the settings.
	 *
	 * @return array A copy of the settings hash.
	 * @since  0.9.2
	 */
	public function alldefs()
	{
		return $this->defs ?? [];
	}

	/**
	 * Set a setting value.
	 *
	 * @param  string $key   The setting's key.
	 * @param  mixed  $value The value to set it to.
	 * @return void
	 * @since  0.1.0
	 */
	public function redef($key, $value)
	{
		$this->defs[$key] = $value;
	}

	/**
	 * Set multiple settings' values.
	 *
	 * @param  array $keyval The settings' key-value pairs.
	 * @return void
	 * @since  0.1.0
	 */
	public function mredef($keyval)
	{
		foreach ($keyval as $key => $value)
			$this->defs[$key] = $value;
	}

	/**
	 * Append a string to a setting's value.
	 *
	 * @param  string $key   The setting's key.
	 * @param  string $value The string to append.
	 * @return void
	 * @since  0.1.0
	 */
	public function cat($key, $value)
	{
		if (!isset($this->defs[$key])) $this->defs[$key] = (string)$value;
		else $this->defs[$key] .= (string)$value;
	}

	/**
	 * Push a value into a setting, converting it to array if needed.
	 *
	 * @param  string $key   The setting's key.
	 * @param  mixed  $value The value to push.
	 * @return void
	 * @since  0.1.0
	 */
	public function push($key, $value)
	{
		$v = $this->defs[$key] ?? null;
		if (!is_array($v)) $this->defs[$key] = isset($v) ? [ $v ] : [];
		$this->defs[$key][] = $value;
	}

	/**
	 * Remove the specified value from the setting's array top.
	 * If the value there does not match, do nothing.
	 *
	 * @param  string $key   The setting's key.
	 * @param  mixed  $value The value to remove (must support '===').
	 * @return void
	 * @since  0.1.0
	 */
	public function drop($key, $value)
	{
		$v = $this->defs[$key] ?? null;
		if (is_array($v) && $v && (array_pop($v) === $value))
			array_pop($this->defs[$key]);
	}

	/**
	 * Get the value from the setting's array top.
	 *
	 * @param  string $key   The setting's key.
	 * @return mixed The value (NULL if none).
	 * @since  0.1.0
	 */
	public function tail($key)
	{
		$v = $this->defs[$key] ?? null;
		return is_array($v) ? ($v ? $v[count($v) - 1] : null) : $v;
	}

	/**
	 * Add/update a WP option with a given value.
	 *
	 * @param  string $key   The option name.
	 * @param  mixed  $value The value to set it to.
	 * @param  bool   $reset Whether to set the option if it already exists
	 *			  (false to leave alone)
	 * @return bool False if add and update both failed, true otherwise.
	 * @since  0.1.0
	 */
	public function add($key, $value, $reset = false)
	{
		$key = $this->prefix . $key;
		if (!add_option($key, $value))
		{
			if (!$reset) return true;
			if (!update_option($key, $value)) return false;
		}
		$this->opt[$key] = $value;
		return true;
	}

	/**
	 * Update an existing WP option with a given value.
	 *
	 * @param  string $key   The option name.
	 * @param  mixed  $value The value to set it to.
	 * @return bool False if the option stays unchanged, true otherwise.
	 * @since  0.1.0
	 */
	public function set($key, $value)
	{
		$key = $this->prefix . $key;
		# !!! If value merely stays the same, we get false here too
		# Still, better to reread it later, than cover up a real error
		if (!update_option($key, $value)) return false;
		$this->opt[$key] = $value;
		return true;
	}

	/**
	 * Get a WP option value (from internal cache if possible).
	 *
	 * @param  string $key   The option name.
	 * @param  mixed  $value The default value for it.
	 * @return mixed The option value.
	 * @since  0.1.0
	 */
	public function get($key, $value = null)
	{
		$key = $this->prefix . $key;
		if (!isset($this->opt[$key]) && !array_key_exists($key, $this->opt))
			$this->opt[$key] = get_option($key, $value);
		return $this->opt[$key];
	}

	/**
	 * Store a WP error/warning message.
	 *
	 * @param  string|WP_Error $err  The error message or error object.
	 * @param  string          $type What it is.
	 * @return void
	 * @since  0.1.0
	 */
	private function adderr($err, $type)
	{
		$err = is_wp_error($err) ? $err->get_error_message() : esc_html($err);
		$this->err[] = [ $err, $type ];
	}

	/**
	 * Store a WP error message.
	 *
	 * @param  string|WP_Error $err  The error message or error object.
	 * @param  string          $type What it is: 'error' by default.
	 * @return void
	 * @since  0.1.0
	 */
	public function error($message, $type = 'error')
	{
		if (isset($this->err)) $this->adderr($message, $type);
	}

	/**
	 * Store a WP warning message.
	 *
	 * @param  string|WP_Error $err  The error message or error object.
	 * @param  string          $type What it is: 'warning' by default.
	 * @return void
	 * @since  0.1.0
	 */
	public function warn($message, $type = 'warning')
	{
		if (isset($this->err)) $this->adderr($message, $type);
	}

	/**
	 * Get the stored WP errors and warnings.
	 *
	 * @return array The [ message, error/warning/whatever ] pairs.
	 * @since  0.1.0
	 */
	public function errors()
	{
		return $this->err ?? [];
	}

	/**
	 * Drop the stored WP errors and stop storing any more.
	 *
	 * @return void
	 * @since  0.1.0
	 */
	public function shutup()
	{
		$this->err = null;
	}

	/**
	 * Drop the stored WP errors and start storing them again.
	 *
	 * @return void
	 * @since  0.1.0
	 */
	public function squeal()
	{
		$this->err = [];
	}


}
