<?php
/*
 * Plugin Name:		catsite
 * Plugin URI:		https://github.com/wjaguar/catsite
 * Description:		This plugin provides component parts for a cat site
 * Version:		0.9.3
 * Author:		wjaguar
 * Author URI:		https://github.com/wjaguar
 * License:		GPLv3 or later
 * License URI:		https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:		catsite
 * Domain Path:		/languages
 * Requires at least:	6.5
 * Requires PHP:	7.0
*/

/* Requires the 'intl' PHP extension */

if (!defined('WPINC')) die; # Not for direct calling

/**
 * The plugin file name.
 *
 * For things that require the base plugin file name, relative to plugin dir.
 * Cannot use __FILE__ because symlinks happen.
 *
 * @var   string
 * @since 0.1.0
 */
define('CATSITE_PLUGIN', 'catsite/catsite.php');

/*
 * Debugging helpers.
 */
#define('CATSITE_TIME_LOG', '/tmp/times.log');
define('CATSITE_LOG', '/tmp/hooks.log');
function catsite_arrdump($name, $array)
{
	$v = "$name:\n";
	foreach ($array as $parm => $value)  $v = "$v$parm = '$value'\n";
	error_log($v, 3, CATSITE_LOG);
}
function catsite_arrlist($pre, $array)
{
	$v = $pre . implode(" :: ", $array) . "\n";
	error_log($v, 3, CATSITE_LOG);
}
function catsite_log($text)
{
	error_log($text, 3, CATSITE_LOG);
}
function catsite_vardump($name, $var)
{
#	ob_start();
#	var_dump($var);
#	$v = ob_get_contents();
#	ob_end_clean();
	$v = var_export($var, true);
	error_log("$name:\n$v\n", 3, CATSITE_LOG);
}
if (defined('CATSITE_TIME_LOG')) add_action('shutdown',
	function()
	{
		$d = microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];
		if ($_SERVER['REQUEST_URI'] !== '/favicon.ico') # If want pure page timings
			error_log("Generation time: $d sec.\n", 3, CATSITE_TIME_LOG);
	} );

/**
 * Recursive glob.
 *
 * @param  string $path    The initial directory.
 * @param  string $pattern The mask of what files to search for.
 * @param  int    $flags   The glob() flags to use for the files.
 * @return array The found filenames, relative to the initial directory.
 * @since  0.1.0
 */
function catsite_rglob($path = '.', $pattern = '*', $flags = 0)
{
	$found = [ [] ];
	$tried = [];
	$wanted = [ [ rtrim($path, '/\\') . '/' ] ];
	while ($wanted)
	{
		$path = array_shift($wanted[0]);
		if (!$wanted[0]) array_shift($wanted);
		$real = realpath($path);
		if (isset($tried[$real])) continue;
		$tried[$real] = 1;
		$dirs = glob($path . '*', GLOB_MARK | GLOB_ONLYDIR | GLOB_NOSORT);
		if ($dirs) array_unshift($wanted, $dirs);
		$files = glob($path . $pattern, $flags);
		if ($files) $found[] = $files;
	}
	return array_merge(...$found);
}

/**
 * Urldecode a prefixed string, fallback to default value on prefix mismatch.
 *
 * @param  string $str     The string.
 * @param  string $prefix  The prefix.
 * @param  string $default The default.
 * @return string The decoded part after the prefix, or the default string.
 * @since  0.1.0
 */
function catsite_urldecode($str, $prefix, $default)
{
	# The default prefix is nothing
	if (!isset($prefix)) $prefix = '';
	$l = strlen($prefix);
	# If no string, or prefix mismatch, return the default
	if (!isset($str) || strncmp($str, $prefix, $l)) return $default;
	$str = substr($str, $l);
	# '%00' in path cannot go through Apache, so do not distinguish NULL
	# from an empty string for now (may replace the prefix if really needed)
	return rawurldecode($str);
}

/*
 * To avoid requiring PHP 7.3
 */
if (!function_exists('array_key_first'))
{
	function array_key_first($arr)
	{
        	foreach ($arr as $key => $v) return $key;
		return null;
	}
}
if (!function_exists('array_key_last'))
{
	function array_key_last($arr)
	{
		if (!$arr) return null;
		return key(array_slice($arr, -1, 1, true));
	}
}

/**
 * Convert a wildcard mask to a PCRE regular expression.
 *
 * @param  string $mask The wildcard mask.
 * @return string The equivalent RE, or NULL if the mask is invalid and cannot
 *		  match anything.
 * @since  0.1.0
 */
function catsite_mask2re($mask)
{
	$pclasses = [ # The named character classes known to PCRE
		'alnum' => 1, 'alpha' => 1, 'ascii' => 1, 'blank' => 1,
		'cntrl' => 1, 'digit' => 1, 'graph' => 1, 'lower' => 1,
		'print' => 1, 'punct' => 1, 'space' => 1, 'upper' => 1,
		'word' => 1, 'xdigit' => 1,
		'^alnum' => 1, '^alpha' => 1, '^ascii' => 1, '^blank' => 1,
		'^cntrl' => 1, '^digit' => 1, '^graph' => 1, '^lower' => 1,
		'^print' => 1, '^punct' => 1, '^space' => 1, '^upper' => 1,
		'^word' => 1, '^xdigit' => 1,
		];
	$anychar = '.';
	$l = strlen($mask);
	$re = $c = '';
	$i = 0;
	while (true)
	{
		$chunk = $c;
		$q = $star = 0;
		while (true) # Outside a class
		{
			$p = -1;
			if ($i >= $l) break; # Normal exit
			$c = $mask[$i++];
			$p = strpos(' \\[?*', $c, 1);
			switch ($p)
			{
			case 1: # '\' - special
				if ($i >= $l) return null; # Fail horribly
				$c = $mask[$i++];
				break;
			case 2: # '[' - special outside a class IF can start a class
				if ($i + 1 >= $l) break;
				break 2;
			case 3: # '?' - special outside a class
				$q++;
			case 4: # '*' - special outside a class
				$star++; # Count '?' + '*'
				continue 2;
			# Anything else - not special outside a class
			}
			if ($star) break; # The span is broken
			$chunk .= $c;
		}
		# A literal span
		if ($chunk !== '') $re .= preg_quote($chunk, '/');
		# A group of wildcards
		if ($star)
		{
			$re .= $anychar;
			$star -= $q;
			$re .= $q > 1 ? ('{' . $q . ($star ? ',}' : '}')) :
				($q ? ($star ? '+' : '') : '*');
		}
		if ($p < 0) return '/^' . $re . '$/u'; # Done
		if ($p < 2) continue; # Still outside a class

		$class_at = $i; # Maybe restart point
		$first = $i + 1;
		$pf = $range = '';
		$between = ' '; # For the first range
		while ($i < $l) # Inside a class
		{
			$c = $mask[$i++];
			$p = strpos(' ]!^\\-[', $c, 1);
			switch ($p)
			{
			case 1: # ']' - special IF standalone & not first
				if ($i <= $first) break;
				# Go finalize & emit
				$class_at = 0;
				break 2;
			case 2: # '!' - special IF absolutely first
			case 3: # '^' - special IF absolutely first
				if ($i > $class_at + 1) break;
				$first = $i + 1;
				$pf = '^';
				continue 2;
			case 4: # '\' - special
				if ($i >= $l) return null; # Fail horribly
				$c = $mask[$i++];
				break;
			case 5: # '-' - special IF in between single exprs
				if ($i >= $l) break 2; # Fail
				if ($mask[$i] === ']') break;
				if (strlen(mb_substr($between, 0, 1)) >= strlen($between))
					break; # Need 2+ _chars_ between
				$between = '';
				$range = $c;
				continue 2;
			case 6: # '[' - may start a construct
				if ($i >= $l) break 2; # Fail
				$c2 = $mask[$i];
				$p = strpos(' .=:', $c2, 1);
				if (!$p) break; # No construct starts with this
				$p2 = strpos($mask, $c2 . ']', $i + 1);
				if (!$p2) break 2; # Fail if not properly ended
				$tag = substr($mask, $i + 1, $p2 - $i - 1);
				# Classes cannot be part of a range
				if ($p > 1)
				{
					if ($range) $pf .= '\\-';
					$range = $between = '';
				}
				$i = $p2 + 2; # Skip the whole construct
				switch ($p)
				{
				case 1: # Collating element - NO proper support for now
				case 2: # Equivalence class - same thing
					# Fail if 2+ chars
					$c = mb_substr($tag, 0, 1);
					if (strlen($c) < strlen($tag)) break 3;
					# Emit the single char
					break 2;
				case 3: # POSIX character class
					# Fail if not known to PCRE
					if (!isset($pclasses[$tag])) break 3;
					# Emit a known one
					$pf .= '[:' . $tag . ':]';
					$between = ' ';
					continue 3;
				}
			# Everything else goes as is
			}
			# Add range char if needed
			$pf .= $range;
			$range = '';
			# Quote what may need it
			$p2 = strpos(' \\-^][/', $c, 1);
			if ($p2) $pf .= '\\';
			$pf .= $c; # Quoted for RE
			$between .= $c; # Raw for mb
		}
		if ($class_at) # Invalid class gets reparsed as literal
		{
			$re .= '\\[';
			$i = $class_at;
		}
		else $re .= '[' . $pf . ']'; # Valid class gets added
		$c = '';
	}
}

/*
 * Loading the helper classes.
 */
require_once __DIR__ . '/catsite_options.php';
require_once __DIR__ . '/catsite_date.php';

/*
 * Loading the worker classes.
 */
require_once __DIR__ . '/catsite_domains.php';
#require_once __DIR__ . '/catsite_domains_settings.php';
require_once __DIR__ . '/catsite_lang.php';
#require_once __DIR__ . '/catsite_lang_settings.php';
require_once __DIR__ . '/catsite_lock.php';
#require_once __DIR__ . '/catsite_lock_settings.php';
require_once __DIR__ . '/catsite_stock.php';
#require_once __DIR__ . '/catsite_stock_settings.php';
require_once __DIR__ . '/catsite_pelt.php';
#require_once __DIR__ . '/catsite_pelt_settings.php';
require_once __DIR__ . '/catsite_jaws.php';
#require_once __DIR__ . '/catsite_jaws_settings.php';

class Catsite
{
	/**
	 * The plugin version.
	 *
	 * @var   string
	 * @since 0.1.0
	 */
	const VERSION = '0.9.0';

	/**
	 * The plugin instance.
	 *
	 * @var   \Catsite
	 * @since 0.1.0
	 */
	private static $instance;

	/**
	 * The plugin defaults.
	 *
	 * @return array The key-value pairs of site's default settings
	 * @since 0.1.0
	 */
	private static function defaults()
	{
		# Get the $defaults array from a separate site-specific file
		include __DIR__ . "/catsite_defaults.php";

		$init = [ 'charset' => get_option('blog_charset') ];

		return $defaults + $init;
	}

	/**
	 * Plugin activation tasks.
	 *
	 * @return void
	 * @since  0.1.0
	 */
	public static function activate()
	{
		$opts = CatsiteOptions::update(null, self::defaults());
		$opts->add('errors', null); # For passing errors to screens

		/* Do the activation-time tasks for the worker classes */
		$opts->squeal();
		CatsiteDomains::activate($opts);
		CatsiteLang::activate($opts);
		CatsiteLock::activate($opts); # Merely to collect the options
		CatsiteStock::activate($opts);
		CatsitePelt::activate($opts); # Merely to collect the options
		CatsiteJaws::activate($opts); # Merely to collect the options
# !!! Add here any other classes that need activating, even only for options
		$opts->set('errors', $opts->errors() ?: null);
	}

	/**
	 * Modify plugin loading order to load this plugin before any other,
	 * to ensure all plugins can use the multi-domain.
	 *
	 * @return void
	 * @since  0.1.0
	 */
	public static function put_first($plugins)
	{
		if (empty($plugins)) return $plugins;
		/* The array of active plugins holds relative plugin paths */
		$path = CATSITE_PLUGIN;
		$key = array_search($path, $plugins);
		if ($key)
		{
			array_splice($plugins, $key, 1);
			array_unshift($plugins, $path);
		}
		return $plugins;
	}

	/**
	 * Get the single plugin instance.
	 *
	 * @return \Catsite The plugin instance.
	 * @since  0.1.0
	 */
	public static function instance()
	{
		if (!isset(self::$instance)) self::$instance = new self();
		return self::$instance;
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
	private function __construct()
	{
		$this->opts = $opts = CatsiteOptions::update(null, self::defaults());
		# !!! No current_user_can() yet, at this point
		$opts->shutup(); # Or maybe allow errors when on admin pages?

		/* Init the worker classes */
		$opts->redef("Domains", new CatsiteDomains($opts));
		$opts->redef("Lang", new CatsiteLang($opts));
		$opts->redef("Lock", new CatsiteLock($opts));
		$opts->redef("Stock", new CatsiteStock($opts));
		$opts->redef("Pelt", new CatsitePelt($opts));
		$opts->redef("Jaws", new CatsiteJaws($opts));
# !!! Add here any other worker classes
		if (is_admin())
		{
			$this->set_filters(); # For admin stuff only
# !!! Add here any worker-configuration classes
		}
	}

	/**
	 * Hook plugin filters to WordPress.
	 *
	 * @return void
	 * @since  0.1.0
	 */
	private function set_filters()
	{
		add_filter('plugin_action_links_' . CATSITE_PLUGIN,
			[ $this, 'add_action_links' ]); # !!! No need of other params (yet?)
		add_action('admin_post_' . 'catsite_rescan',
			[ $this, 'maybe_rescan' ]);
		add_action('admin_notices',
			[ $this, 'show_notices' ], PHP_INT_MAX); # As near bottom as we can
# !!! Everything else admin-related gets added here

	}

	/**
	 * Add extra plugin action links ("Rescan" for now).
	 *
	 * @return void
	 * @since  0.1.0
	 */
	public function add_action_links($actions)
	{
		$rescan = wp_nonce_url(
			add_query_arg([ 'action' => 'catsite_rescan' ], admin_url('admin-post.php')),
			'catsite_rescan');

		$actions['rescan'] = '<a href="' . $rescan .
			'" id="rescan-catsite" aria-label="Rescan catsite">Rescan</a>';
		return $actions;
	}

	/**
	 * Do the rescan action if allowed.
	 *
	 * @return void
	 * @since  0.1.0
	 */
	public function maybe_rescan()
	{
		if (!wp_verify_nonce($_REQUEST['_wpnonce'], 'catsite_rescan') ||
			!current_user_can('manage_options'))
			wp_die("<center>Locked</center>");
		else
		{
			$opts = $this->opts;

			# Do the rescan and remember the errors
			$opts->squeal();
			$opts->def("Stock")::rescan($opts);
# !!! Do other classes' rescan action if needed
			$opts->set('errors', $opts->errors() ?: null);

			# Return to the originating page
			$r = wp_get_referer();
			if ($r) wp_safe_redirect($r);
		}
	}

	/**
	 * Show outstanding notices if any.
	 *
	 * @return void
	 * @since  0.1.0
	 */
	public function show_notices()
	{
		$err = $this->opts->get('errors');
		if (empty($err)) return; # Nothing to show

		$where = get_current_screen();
		$where = isset($where) ? $where->id : ''; # Paranoia
		if ($where === 'plugins') # Only on this screen for now
		{
			foreach ($err as $e)
			{
				wp_admin_notice($e[0], [ 'additional_classes' => [ $e[1] ] ]);
			}
			$this->opts->set('errors', null); # Show only once for now
		}
	}	


}

/*
 * Register the activation method.
 */
register_activation_hook(WP_PLUGIN_DIR . '/' . CATSITE_PLUGIN, [ Catsite::class, 'activate' ]);

/*
 * Control the plugin loading order.
 */
add_filter('pre_update_option_active_plugins', [ Catsite::class, 'put_first' ], 10, 1);

/*
 * Initialize.
 */
$catsite = Catsite::instance();

# Debug dump
#catsite_arrlist('Observing: ', get_option('active_plugins'));
