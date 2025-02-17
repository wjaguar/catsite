<?php

/**
 * catsite WordPress plugin.
 * Multiple domains handling, path parsing and reassembly.
 *
 * @author  wjaguar <https://github.com/wjaguar>
 * @version 0.9.0
 * @package catsite
 */

class CatsiteDomains
{
	/**
	 * The current domain, possibly including the (nondefault) port.
	 *
	 * @var   string
	 * @since 0.1.0
	 */
	private $domain;

	/**
	 * The canonical domain[:port], in case it need be distinguished.
	 *
	 * @var   string
	 * @since 0.1.0
	 */
	private $canon_domain;

	/**
	 * The component defaults.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private static $defaults = [
		"canonical_url" => null,
		];

	/**
	 * Plugin activation tasks.
	 *
	 * @param  array $defaults The initial values for various things.
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

		$this->canon_domain = $opts->def("canonical_url");
		$this->domain = $this->host_header();
		$opts->redef("domain", $this->domain);

		$this->prepare_path();

		$this->set_filters();
	}

	/**
	 * Get the 'Host' HTTP header value.
	 *
	 * For compatibility with proxies, try the 'X-Host' first.
	 * Return 'null' in case both headers are empty.
	 * For the protocol part, use the recommendation from is_ssl() docs.
	 *
	 * @return string|null The HTTP 'Host' header value.
	 * @since  0.1.0
	 */
	private function host_header()
	{
#catsite_arrdump("_SERVER", $_SERVER);
		$h = $_SERVER['HTTP_X_HOST'] ?? $_SERVER['HTTP_HOST'];
		if (empty($h)) return null;
		$ssl = is_ssl() || ($_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https');
		return ($ssl ? 'https://' : 'http://') . $h;
	}

	/**
	 * Prepare the requested path for the other classes to parse.
	 *
	 * Expects correct paths, leaving any correcting, normalizing, etc. to
	 * the redirect_canonical().
	 *
	 * @return void
	 * @since  0.1.0
	 */
	private function prepare_path()
	{
#catsite_log("URI: '" . $_SERVER['REQUEST_URI'] . "'\n");
		# Cut off the vars
		list($s) = explode('?', $_SERVER['REQUEST_URI'] ?? '', 2);
		# Trim the outer slashes and split on the inner
		$s = explode('/', trim($s, '/'));
		# Package it
		$s = [
			'tail' => $s,
			'before' => [],
			'after' => [],
			'home' => '',
			];
		$this->opts->redef('PATH', $s);
	}

	/**
	 * Hook plugin filters to WordPress.
	 *
	 * @return void
	 * @since  0.1.0
	 */
	private function set_filters()
	{
		/* Generic domain replacement */
		$fix = [ $this, 'fix_url' ];
		add_filter('content_url', $fix);
		add_filter('option_siteurl', $fix);
		add_filter('option_home', $fix);
		add_filter('plugins_url', $fix);
		add_filter('wp_get_attachment_url', $fix);
		add_filter('get_the_guid', $fix);

		/* Specialized filters */
		add_filter('upload_dir', [ $this, 'fix_upload_dir' ]);
		add_filter('allowed_http_origins', [ $this, 'add_origin' ]);

		/* Replace the canonical URL */
		add_filter('get_canonical_url', [ $this, 'set_canonical' ]);

		/* Hide extra parts from parse_request() */
		add_filter('option_rewrite_rules', [ $this, 'hide_vars' ]);
		/* Redirection filter, to keep the extra parts */
		add_filter('redirect_canonical', [ $this, 'page_redirect' ], 10, 2);
	}

	/**
	 * Replaces the domain in the given URL with the current one.
	 *
	 * @param  string $url The URL to fix.
	 * @return string The modified URL.
	 * @since  0.1.0
	 */
	public function fix_url($url)
	{
		$res = preg_replace('`https?://[^/]+`', $this->domain, $url);
#catsite_log("Was: $url\nNow: $res\n\n");
		return $res;
	}

	/**
	 * Replace the domain in 'upload_dir' filter used by wp_upload_dir().
	 *
	 * @param  array $uploads The array with 'url', 'baseurl' and other things.
	 * @return array The array with modified values.
	 * @since  0.1.0
	 */
	public function fix_upload_dir($uploads)
	{
		$uploads['url'] = $this->fix_url($uploads['url']);
		$uploads['baseurl'] = $this->fix_url($uploads['baseurl']);
		return $uploads;
	}

	/**
	 * Add the current domain to allowed origins (to prevent CORS issues).
	 *
	 * @param  array $origins The default list of allowed origins.
	 * @return array The updated list of allowed origins.
	 * @since  0.1.0
	 */
	public function add_origin($origins)
	{
		$host = preg_replace('`https?://`', '', $this->domain);
		$origins[] = 'https://' . $host;
		$origins[] = 'http://' . $host;
		return array_values(array_unique($origins));
	}

	/**
	 * Replace the canonical domain if it is set.
	 *
	 * @param  string $url The canonical URL.
	 * @return string The modified URL.
	 * @since  0.1.0
	 */
	public function set_canonical($url)
	{
		if (!empty($this->canon_domain))
			$url = preg_replace('`https?://[^/]+`', $this->canon_domain, $url);
		return $url;
	}

	/**
	 * Add to rewrite rules a RE that ignores the prefix & suffix variables.
	 *
	 * @param  array $rules The rules array.
	 * @return array The modified (or not) rules.
	 * @since  0.1.0
	 */
	public function hide_vars($rules)
	{
		$path = $this->opts->def('PATH');
		if (empty($path['FAIL']) && !isset($path['tail']))
		{
			# Default is the frontpage
			$res = [];
			$req = 'page_id=' . $path['page'];
			# Take the page path if it exists
			$v = $path['path'];
			if ($v)
			{
				$res[] = '(' . implode('/', $v) . ')';
				$req = 'pagename=$matches[1]';
			}
			# Add the prefix if any
			$v = $path['before'];
			if (($v[0] ?? null) === $path['home']) array_shift($v); # RE won't see it
			if ($v) array_unshift($res, implode('/', $v));
			# Add the suffix if any
			$v = $path['after'];
			if ($v) $res[] = implode('/', $v);
			# Join it all together
			$res = implode('/', $res) . '/?$';
			$req = 'index.php?' . $req;
#catsite_log("res='$res' req='$req'\n");
#catsite_vardump('PATH', $path);
			# Stuff it into the array
			$rules = [ $res => $req ] + $rules;
		}
#catsite_vardump('rules', $rules);
		return $rules;
	}

	/**
	 * Stops the canonical redirect of the frontpage from erasing the extra parts.
	 *
	 * @param  string $redir The redirect url.
	 * @param  string $orig  The requested url.
	 * @return string|false The modified url, or false to stop the redirect.
	 * @since  0.1.0
	 */
	public function page_redirect($redir, $orig)
	{
		if (($redir === false) || !is_page() || is_feed()) return $redir; # Let it

#catsite_log("id '" . get_queried_object_id() . "' front '" . get_option('page_on_front') . "'\n");
#catsite_log("orig '$orig' redir '$redir'\n");
#catsite_vardump("PATH", $this->opts->def('PATH'));

		if (get_queried_object_id() == get_option('page_on_front'))
		{
			$path = $this->opts->def('PATH');

			# The prefix if any
			$v = $path['before'];
			if (($v[0] ?? null) === $path['home']) array_shift($v); # In home URL
			# Collect the parts
			$v = array_merge([ '' ], $v, $path['after']);
			# Assemble the path
			$path = implode('/', $v) . '/';

			# Find the query part
			$query = wp_parse_url($redir, PHP_URL_QUERY);
			$query = !empty($query) ? '?' . $query : '';

			# Add all together
			$res = get_home_url(null, $path) . $query;
#catsite_log("path '$path' res '$res'\n");

			return $res !== $orig ? $res : false;
		}

		return $redir;
	}


}
