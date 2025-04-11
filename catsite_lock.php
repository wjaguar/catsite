<?php

/**
 * catsite WordPress plugin.
 * Access restriction handling.
 *
 * @author  wjaguar <https://github.com/wjaguar>
 * @version 0.9.2
 * @package catsite
 */

class CatsiteLock
{
	/**
	 * The access level.
	 *
	 * @var   string
	 * @since 0.1.0
	 */
	private $acc = '';

	/**
	 * The forbidden capabilities.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private $caps = [];

	/**
	 * The component defaults.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private static $defaults = [
		# Block higher privilege levels except for certain connections
		"site_entity" => null,
		"site_admin" => null,
		"admin_port" => null,
		"admin_host" => null,
		"admin_hostpart" => null,
		"admin_cap" => "manage_options",
		"admin_ssl" => true,
		# Disable various unneeded things
		"version" => false,
		"xmlrpc" => false,
		"rest" => false,
		"feed" => false,
		"archive" => false,
		"guess" => false,
		];

	/**
	 * Plugin activation tasks.
	 *
	 * @param  \CatsiteOptions $defaults The initial values for various things.
	 * @return void
	 * @since  0.1.0
	 */
	public static function activate($opts = null)
	{
		$opts = CatsiteOptions::update($opts, self::$defaults);
	}

	/**
	 * The options worker class instance.
	 *
	 * @var   \CatsiteOptions
	 * @since 0.1.0
	 */
	private $opts;

	/**
	 * Create a new instance.
	 */
	public function __construct($opts = null)
	{
		$this->opts = $opts = CatsiteOptions::update($opts, self::$defaults);
#catsite_vardump("opts", $this->opts);
		$opts = $opts->alldefs();

		$how = $this->host_header();
		$admin = (empty($opts["site_entity"]) ||
				($opts["site_entity"] === $how['entity'])) &&
			(empty($opts["site_admin"]) ||
				($opts["site_admin"] === $how['unit'])) &&
			(!($opts["admin_ssl"] ?? false) || $how['ssl']);

		if (!$admin); # Failed already
		# Require the (nonstandard) port to match
		elseif (!empty($opts["admin_port"]))
			$admin = $opts["admin_port"] === $how['port'];
		# Require the entire hostname to match
		elseif (!empty($opts["admin_host"]))
			$admin = $opts["admin_host"] === $how['host'];
		# Require a matching substring in the hostname
		elseif (!empty($opts["admin_hostpart"]))
		{
			$parts = explode(' ', $opts["admin_hostpart"]);
			$str = '^' . $how['host'] . '$';
			$admin = false;
			foreach ($parts as $part)
			{
				if (!strlen($part)) continue;
				if (strpos($str, $part) === false) continue;
				$admin = true;
				break;
			}
		}

		if ($admin) $this->acc = 'admin';
		else $this->caps[] = $opts["admin_cap"] ?? [];
#catsite_vardump("connection", $how);
#catsite_vardump("lock", $this);

		$this->set_filters($this->opts);
	}

	/**
	 * Get the relevant HTTP header values.
	 *
	 * For compatibility with proxies, try the 'X-Host' first.
	 * For the protocol part, use the recommendation from is_ssl() docs.
	 *
	 * @return array The values from HTTP headers.
	 * @since  0.1.0
	 */
	private function host_header()
	{
#catsite_arrdump("_SERVER", $_SERVER);
		$h = $_SERVER['HTTP_X_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '';
		$ssl = is_ssl() || ($_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https');
		$port = $ssl ? 443 : 80;
		$res = [];
		if (preg_match('/:(\d+)$/', $h, $res)) $port = (int)$res[1];
		$res = [
			"entity" => $_SERVER['SSL_CLIENT_S_DN_O'] ?? '',
			"unit" => $_SERVER['SSL_CLIENT_S_DN_OU'] ?? '',
			"port" => $port,
			"ssl" => $ssl,
			"host" => $h,
			];
		return $res;
	}

	/**
	 * Hook plugin filters to WordPress.
	 *
	 * @param  \CatsiteOptions $opts The values for various things.
	 * @return void
	 * @since  0.1.0
	 */
	private function set_filters($opts)
	{
		/* The login filter */
		add_filter('authenticate', [ $this, 'drop_em' ], PHP_INT_MAX, 3);
		/* The cookie action */
		add_action('auth_cookie_valid', [ $this, 'stop_em' ], -1, 2);
		/* Stop advertising version */
		if (!($opts->def("version") ?? false))
		{
			$hdl = [ $this, 'cut_em' ];
			add_filter('script_loader_src', $hdl);
			add_filter('style_loader_src', $hdl);
		}
		/* Stop guessing pages */
		if (!($opts->def("guess") ?? false))
			add_filter('do_redirect_guess_404_permalink', '__return_false');
		/* The XML-RPC disable */
		if (!($opts->def("xmlrpc") ?? false))
		{
			add_filter('xmlrpc_enabled', '__return_false');
			add_filter('xmlrpc_methods', '__return_empty_array', PHP_INT_MAX);
		}
		/* The REST fence */
		if (!($opts->def("rest") ?? false))
			add_filter('rest_authentication_errors', [ $this, 'auth_em' ], PHP_INT_MAX);
		/* Disable feeds and suchlike */
		add_filter('pre_handle_404', [ $this, 'starve_em' ], 10, 2);
		/* Remove various things from <head> */
		add_action('wp_loaded', [ $this, 'hide_em' ]);
		/* Remove page indices from <body> */
		add_filter('body_class', [ $this, 'trash_em' ], 10, 2);
	}

	/**
	 * Drops the user object if their caps are disallowed for this setup.
	 *
	 * @param  \WP_User|\WP_Error|null $user     The user object or error.
	 * @param  string		   $username The username.
	 * @param  string		   $password The password in plaintext.
	 * @return \WP_User|\WP_Error|null The user object or error.
	 * @since  0.1.0
	 */
	public function drop_em($user, $username, $password)
	{
		if ($user instanceof WP_User)
		{
			foreach ($this->caps as $cap)
			{
				if (!$user->has_cap($cap)) continue;
#catsite_log("!!! DROPPED\n");
				return null;
			}
		}
		return $user;
	}

	/**
	 * Logout & raise an error if the user's caps are disallowed for this setup.
	 *
	 * The filter is one-shot, because wp_die() triggers it again.
	 * The logout is necessary, because otherwise the user stays locked out
	 * of the insecure site view.
	 *
	 * @param  array $cookie Authentication cookie components.
	 * @param  \WP_User $user The user object.
	 * @since  0.1.0
	 */
	private $count = 0;
	public function stop_em($cookie, $user)
	{
		if ($this->count) return;
		if ($user instanceof WP_User)
		{
			foreach ($this->caps as $cap)
			{
				if (!$user->has_cap($cap)) continue;
				$this->count++;
#catsite_log("!!! STOPPED {$this->count}\n");
				wp_clear_auth_cookie();
				wp_die("<center>Locked</center>");
			}
		}
	}

	/**
	 * Return error if the REST request isn't on behalf of a logged-in user.
	 *
	 * @param  \WP_Error|true|null $errors Auth error, acceptance, or ignorance.
	 * @return \WP_Error|true|null The old value, or a new WP_Error.
	 * @since  0.1.0
	 */
	public function auth_em($errors)
	{
		if (is_wp_error($errors)) return $errors; # Already failed
		if (is_user_logged_in()) return $errors; # Leave be
#catsite_log("!!! ARRESTED\n");
		return new WP_Error('rest_not_logged_in',
			'You are not currently logged in.',
			[ 'status' => 401 ]);
	}

	/**
	 * Cut version parameter out of file URLs.
	 *
	 * @param  string $src The URL.
	 * @return string The modified (or not) URL.
	 * @since  0.1.0
	 */
	public function cut_em($src)
	{
		return strpos($src, 'ver=') ? remove_query_arg('ver', $src) : $src;
	}

	/**
	 * Remove links insertion calls.
	 *
	 * @return void
	 * @since  0.1.0
	 */
	public function hide_em()
	{
		$opts = $this->opts;
		/* Get rid of post index */
		remove_action('wp_head', 'wp_shortlink_wp_head', 10);
		remove_action('template_redirect', 'wp_shortlink_header', 11);
		/* Stop advertising version */
		if (!($opts->def("version") ?? false))
			remove_action('wp_head', 'wp_generator');
		/* The XML-RPC disable */
		if (!($opts->def("xmlrpc") ?? false))
			remove_action('wp_head', 'rsd_link');
		/* The REST fence */
		if (!($opts->def("rest") ?? false))
		{
			remove_action('wp_head', 'wp_oembed_add_discovery_links');
			remove_action('wp_head', 'rest_output_link_wp_head', 10);
			remove_action('template_redirect', 'rest_output_link_header', 11);
		}
		/* The feeds disable */
		if (!($opts->def("feed") ?? false))
		{
			remove_action('wp_head', 'feed_links', 2);
			remove_action('wp_head', 'feed_links_extra', 3);
		}
	}

	/**
	 * Report feeds as missing.
	 *
	 * @param  bool     $skip  If something else wants to skip the rest of handle_404().
	 * @param  WP_Query $query The query object with inputs and results.
	 * @return bool Whether to skip the rest of handle_404().
	 * @since  0.1.0
	 */

	public function starve_em($skip, $query)
	{
		global $wp;

		if ($skip) return $skip; # Not to handle here
		while (true)
		{
			$opts = $this->opts;
			# Feeds are off by default
			if ($query->is_feed() &&
				!($opts->def("feed") ?? false)) break;
			# Various archives are off by default
			# (including taxonomies, categories, and tags)
			if ($query->is_archive() &&
				!($opts->def("archive") ?? false)) break;
			# Builtin search cannot display *.page properly
			if ($query->is_search()) break;
			return $skip; # Not to handle here
		}
		$wp->set_query_var('error', '404'); # THE decisive marker
		$query->is_feed = false; # Also necessary
		$query->set_404();
		status_header(404);
		nocache_headers();
		return true;
	}

	/**
	 * Remove the classes with page indices in them.
	 *
	 * @param  array $classes   The full array of classes.
	 * @param  array $css_class The array of extra classes.
	 * @return array The modified array of classes
	 * @since  0.1.0
	 */

	public function trash_em($classes, $css_class)
	{
		$s1 = 'page-id-';
		$s2 = 'parent-pageid-';
		$l1 = strlen($s1);
		$l2 = strlen($s2);
		foreach ($classes as $key => $v)
		{
			if (!strncmp($v, $s1, $l1) || !strncmp($v, $s2, $l2))
				$classes[$key] = '';
		}
		return $classes;
	}


}
