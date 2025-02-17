<?php

/**
 * catsite WordPress plugin.
 * Language selection handling.
 *
 * @author  wjaguar <https://github.com/wjaguar>
 * @version 0.9.0
 * @package catsite
 */

class CatsiteLang
{

	/**
	 * The component defaults.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private static $defaults = [
		"default_lang" => 'EN',
		"lang" => 'EN',
		"langs" => [ 'EN', 'LV', 'RU' ],
		"redirect" => 0, # -1 = remove default lang / 1 = always add lang
		];

	/**
	 * The locales for Wordpress.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private static $locales = [
		'EN' => 'en_US',
		'LV' => 'lv',
		'RU' => 'ru_RU',
		];

	/**
	 * The locales for OS.
	 * Only the differences need be listed.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private static $syslocales = [
		'LV' => 'lv_LV',
		];

	/**
	 * The language names.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private static $langs = [
		'EN' => [
			'EN' => 'English',
			'LV' => 'Latvian',
			'RU' => 'Russian',
			],
		'LV' => [
			'EN' => 'Angļu',
			'LV' => 'Latviešu',
			'RU' => 'Krievu',
			],
		'RU' => [
			'EN' => 'Английский',
			'LV' => 'Латышский',
			'RU' => 'Русский',
			],
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
		global $wp;

		$this->opts = $opts = CatsiteOptions::update($opts, self::$defaults);
		$this->set_filters();

		# Queue the CSS
		$opts->cat('CSS', $this->css);

		# WP::parse_request() happens too late, do its job here & now
		$lang = $this->lang_from_path($opts);
		$this->opts->redef('lang_in_path', !empty($lang)); # Remember
		if (!empty($lang)) # Explicit
			$lang = strtoupper($lang);
		else if ($this->opts->def('redirect') < 0) # Enforced default
			$lang = $this->opts->def('default_lang');
# TODO If 'redirect' > 0, may guess $lang from elsewhere: 'Accept-Language' header,
# or 'Referer' header's path, or session vars
		if (isset($lang)) $this->opts->redef('lang', $lang);

		$this->set_shortcodes();
	}

	/**
	 * Hook plugin filters to WordPress.
	 *
	 * @return void
	 * @since  0.1.0
	 */
	private function set_filters()
	{
		/* Redirector */
		add_action('template_redirect', [ $this, 'lang_redirect' ]);
		/* Paths modifier */
		add_filter('home_url', [ $this, 'lang_path' ], -1); # No multisite support for now
		/* Locale setters */
		add_filter('locale', [ $this, 'set_locale' ], PHP_INT_MAX);
		add_filter('determine_locale', [ $this, 'maybe_set_locale' ], PHP_INT_MAX);
		/* Theme translation loader */
		add_filter('override_load_textdomain', [ $this, 'load_xlate' ], 10, 4);
		/* Saved locale data loader */
		add_filter('catsite_fetch_locale', [ $this, 'load_locale' ], 10, 2);
	}

	/**
	 * Hook shortcodes to WordPress.
	 *
	 * @return void
	 * @since  0.1.0
	 */
	private function set_shortcodes()
	{
		$pf = $this->opts->def('shortcode_prefix') ?? '';
		if (strlen($pf)) $pf .= '_';

		$hdl = [ $this, 'make_langbox' ];
		add_shortcode($pf . 'langbox', $hdl);
		add_shortcode($pf . 'langmenu', $hdl);

		add_shortcode($pf . 'lang', [ $this, 'want_lang']);
	}

	/**
	 * The CSS for language switchers.
	 *
	 * @var   string
	 * @since 0.1.0
	 */
	private $css = <<<'ENDCSS'
.langmenu { position: relative; }
.langmenu ul {
	display: none;
	position: absolute;
	list-style-type: none;
	padding-left: 0;
        min-width: 100%;
        z-index: 5;
	text-align: center;
	}
.langmenu:hover ul, .langmenu:focus-within ul,
.langmenu ul:hover, .langmenu ul:focus-within {
	display: block;
	}
ENDCSS;

	/**
	 * Produce HTML for the shortcodes.
	 * Parameters are: this=[show]|stub|hide, empty=... (same choices)
	 *
	 * @param  array  $attrs   The attributes.
	 * @param  string $content The bracketed text, if any.
	 * @param  string $tag     The shortcode tag this is called with.
	 * @return string The text to substitute.
	 * @since  0.1.0
	 */
	public function make_langbox($attrs = [], $content = null, $tag = '')
	{
		$opts = $this->opts;

		# Get current & base path
		$lang = $opts->def('lang');
		$p = $_SERVER['REQUEST_URI'] ?? ''; # Current path
		$have_lang = strpos("$p/", strtolower("$lang/")) == 1;
		$pb = $have_lang ? substr($p, strlen($lang) + 1) : $p; # Base path

		$def = $opts->def('default_lang');
		# Default language may need be unmarked
		$clr = $opts->def('redirect') < 0 ? $def : null;

		# Present languages
		$have = [];
		foreach ($opts->def('have_langs') ?? [] as $l) $have[$l] = true;
		$have[$def] = true; # Default language is default

		# Assemble the lang array
		$th = $attrs['this'] ?? '';
		$em = $attrs['empty'] ?? '';;
		$res = [];
		foreach ($opts->def('langs') as $l)
		{
			$class = '';
			$stub = 0; # No href if >0
			if ($l === $lang) # Current
			{
				if ($th === 'hide') continue; # Skip
				$class .= 'active ';
				$stub += $th === 'stub';
			}
			if (empty($have[$l])) # Empty
			{
				if ($em === 'hide') continue; # Skip
				$class .= 'empty ';
				$stub += $em === 'stub';
			}
			if (!empty($class)) $res[$l]['class'] = $class;
			if (!$stub) $res[$l]['url'] =
				$l === $lang ? $p : # Original
				($l === $clr ? (($pb[0] ?? null) !== '/' ? "/$pb" : $pb) : # Unmarked
				'/' . strtolower($l) . $pb); # Regular
		}
#catsite_vardump("Res", $res);

		# Produce the links
		$arr = [];
		foreach ($res as $l => $r)
		{
			$a = '<a title="' . self::$langs[$lang][$l] . '" ';
			if (isset($r['class'])) $a .= 'class="' . $r['class'] . '" ';
			if (isset($r['url'])) $a .= 'href="' . esc_url($r['url']) . '" ';
			$a .= ">$l</a>";
			$arr[] = $a;
		}

		# Produce the final HTML
		$t = '';
		if ($tag === 'langbox')
		{
			$t = '<span class="langbox">' . implode('&nbsp;', $arr) . '</span>';
		}
		else if ($tag === 'langmenu')
		{
			$t = '<div class="langmenu"><button>' . $lang . "</button>\n" .
				"<ul>\n <li>" . implode("</li>\n <li>", $arr) . "</li>\n</ul></div>";
		}
#catsite_log($t);
		return $t;
	}

	/**
	 * Skip the part if the language is different.
	 * Parameters are: the language code.
	 *
	 * @param  array  $attrs   The attributes.
	 * @param  string $content The bracketed text, if any.
	 * @param  string $tag     The shortcode tag this is called with.
	 * @return string The text to substitute.
	 * @since  0.1.0
	 */
	public function want_lang($attrs = [], $content = null, $tag = '')
	{
		$lang = strtoupper($attrs[0] ?? $this->opts->def('default_lang'));
		if ($lang !== $this->opts->def('lang')) return '';
		return do_shortcode($content);
	}

	/**
	 * Extracts lang setting from the 'PATH' option.
	 *
	 * @param  \CatsiteOptions $opts The values for various things.
	 * @return string Lang value or null.
	 * @since  0.1.0
	 */
	private function lang_from_path($opts)
	{
		$path = $opts->def('PATH');
		$tag = $path['tail'][0] ?? null;
		if (!empty($tag) && in_array(strtoupper($tag), $opts->def('langs')))
		{
			$path['home'] = $tag; # As it will be in home_url()
			$path['before'][] = $tag;
			array_shift($path['tail']);
			$opts->redef('PATH', $path);
			return $tag;
		}
		return null;
	}

	/**
	 * Redirects to the lang-matching url when appropriate.
	 *
	 * @return void
	 * @since  0.1.0
	 */
	public function lang_redirect()
	{
		# Limit the scope similarly to redirect_canonical()
		$req = strtoupper($_SERVER['REQUEST_METHOD'] ?? '');
		if (($req !== 'GET') && ($req !== 'HEAD')) return;
		if (is_admin() || is_favicon()) return;

		# Additionally limit to pages ### for now
		if (!is_page() || is_feed()) return;

		$lang = $this->opts->def('lang');
		$redir = $this->opts->def('redirect');
		$path = $_SERVER['REQUEST_URI'] ?? '';

		$have_lang = strpos("$path/", strtolower("$lang/")) == 1;
		$def_lang = $lang === $this->opts->def('default_lang');

		# When marked, redirect default to unmarked if 'redirect' < 0
		if ($have_lang) $redir = $def_lang && ($redir < 0) ? -1 : 0;
		# When unmarked, leave default unmarked if 'redirect' <= 0
		else $redir = $def_lang && ($redir <= 0) ? 0 : 1;

		if (!$redir) return; # Do nothing
		if ($redir < 0) $path = substr($path, strlen($lang) + 1); # Cut
		if (($path[0] ?? null) !== '/') $path = "/$path";
		if ($redir > 0) $path = strtolower("/$lang") . $path; # Paste

		# Do a temporary redirect to a relative URI
		wp_redirect($path);
		exit;
	}

	/**
	 * Inserts the current lang into the URL if needed.
	 *
	 * @param  string $url The url.
	 * @return string Modified (or not) url.
	 * @since  0.1.0
	 */
	public function lang_path($url)
	{
#catsite_log("Got $url\n");
		if ($this->opts->def('lang_in_path') ?? false) # Explicit
		{
			$p = strpos($url, '://');
			$p = $p === false ? 0 : $p + 3;
			$p = strpos($url, '/', $p);
			if ($p === false) $p = strlen($url);
			$lang = '/' . strtolower($this->opts->def('lang'));
			$url = substr_replace($url, $lang, $p, 0);
#catsite_log("Made $url\n");
		}
		return $url;
	}

	/**
	 * Sets WP locale according to the current lang.
	 *
	 * @param  string $locale The default locale.
	 * @return string The locale to use.
	 * @since  0.1.0
	 */
	public function set_locale($locale)
	{
		if (!is_admin() && isset($this->opts)) # Paranoia
		{
#catsite_log($this->opts->def('lang') . " => " . self::$locales[$this->opts->def('lang')] . "\n");
			return self::$locales[$this->opts->def('lang')] ?? $locale;
		}
		return $locale;
	}

	/**
	 * Sets locale according to the current lang if explicitly set in URL.
	 *
	 * @param  string $locale The default locale.
	 * @return string The locale to use.
	 * @since  0.1.0
	 */
	public function maybe_set_locale($locale)
	{
		if ($this->opts->def('lang_in_path') ?? false) # Explicit
		{
			return $this->set_locale($locale);
		}
		return $locale;
	}

	/**
	 * Loads translation file from the plugin directory if possible.
	 *
	 * @param bool   $override If something before overrides it already.
	 * @param string $domain   The text domain.
	 * @param string $mofile   The default filename (with a .mo extension).
	 * @param string $locale   The default locale, or null.
	 * @return bool Whether something got loaded.
	 * @since  0.1.0
	 */
	public function load_xlate($override, $domain, $mofile, $locale)
	{
		static $lock = 0; # !!! Not for parallel execution
		if ($lock) return $override; # Stop recursion

#catsite_log("domain $domain file $mofile locale $locale\n");
#catsite_log("lang " . $this->opts->def('lang') . "\n");
		if ($override) return $override; # Someting already done the deed
		if (empty($locale)) $locale = $this->set_locale(null);
		if (empty($locale)) return $override; # Not our concern

		# Attempt to load a PHP-formatted translation from our dir
		$dir = trailingslashit($this->opts->get('pages_directory'));
		$maybe = "$dir$domain-$locale";
#catsite_log("maybe '$maybe.l10n.php'\n");
		if (is_file("$maybe.l10n.php"))
		{
			$lock++;
			$override = load_textdomain($domain, "$maybe.mo"); # Rename happens inside
			$lock--;
		}

		return $override;
	}

	/**
	 * Loads locale category from a file in the plugin directory if possible.
	 *
	 * @param array  $locale The (default) locale category data.
	 * @param string $what   The locale category name.
	 * @return array The new (or not) data.
	 * @since  0.1.0
	 */
	public function load_locale($locale, $what)
	{
		# Attempt to load a name=value file from our dir
		$dir = trailingslashit($this->opts->get('pages_directory'));
		$lang = $this->opts->def('lang');
		# Try the system locale first, the WP locale next
		$arr = [ self::$syslocales[$lang] ?? null,
			self::$locales[$lang] ?? null ];
		foreach ($arr as $loc)
		{
			if (!isset($loc)) continue;
			$text = file_get_contents("$dir$what.$loc.list");
			if ($text === false) continue; # Could not read
			$locale = $this->parse_locale($text, $what) ?: $locale;
			break;
		}
		return $locale;
	}

	/**
	 * The array-values in locale categories.
	 * See glibc: locale/categories.def
	 * At the moment, only LC_TIME is described here.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private static $locarrays = [
		'LC_TIME' => [ 'abday', 'day', 'abmon', 'mon', 'am_pm', 'era',
			'alt_digits', 'time-era-entries', 'alt_mon', 'ab_alt_mon' ],
		];

	/**
	 * Parses locale category data into a proper PHP array.
	 *
	 * @param string $text The name=value textfile from 'locale -k LC_***'.
	 * @param string $what The locale category name.
	 * @return array The data, if any.
	 * @since  0.1.0
	 */
	private function parse_locale($text, $what)
	{
		/* Parse the input */
		$re = '/(?<=\n|^)[^\S\n]*([^\s=]+)[^\S\n]*=' .
			'(?:[^\S\n]*(?:"([^\n]*)"|(?=\S)([^\n]*\S))|)[^\S\n]*\n/s';
		if (!preg_match_all($re, $text, $match, PREG_SET_ORDER))
			return []; # Failure

		/* Map the array fields */
		$map = [];
		foreach (self::$locarrays[$what] ?? [] as $v) $map[$v] = 1;

		/* Assemble the result */
		$res = [];
		foreach ($match as $arr)
		{
			$key = $arr[1];
			$v = $arr[3] ?? $arr[2] ?? null; # Using RE to eat double quotes
			if (isset($v) && !empty($map[$key]))
				$v = explode(';', $v);
			$res[$key] = $v;
		}
		return $res;
	}


}
