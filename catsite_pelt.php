<?php

/**
 * catsite WordPress plugin.
 * Theme interaction handling.
 *
 * @author  wjaguar <https://github.com/wjaguar>
 * @version 0.9.3
 * @package catsite
 */

class CatsitePelt
{

	/**
	 * Nonexistent, irregular URL to mark <a> for removal
	 * Has to survive esc_url() w/o changes
	 *
	 * @var   string
	 * @since 0.1.0
	 */
	const INSANE_URL = '////no/such/thing/here////';

	/**
	 * The component defaults.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private static $defaults = [
		];

	/**
	 * The action places.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private static $actions = [
		'init',
		'wp_head',
		'wp_body_open',
		'header_done',
		'get_footer',
		'wp_footer',
		'footer_done',
		];

	/**
	 * The theme(s)-specific settings block.
	 *
	 * The '@' theme selector can be a string, maybe '*', or an array of strings
	 *
	 * WARNING: In case something else uses the same trick (catching output
	 * between actions), here start and stop points MUST be outside their
	 * interception range. In case that is not possible, special handling
	 * will be needed in do_buffer(), to properly dismantle and then recreate
	 * and refill their buffer(s).
	 * 
	 * @var   array
	 * @since 0.1.0
	 */
	private static $tset = [
		[ '@' => '*', # WP itself
			'SITENAME' => [
				'fill' => 'SITENAME',
				'kind' => 'lang',
				'opt' => 'blogname',
				],
			'SITEDESC' => [
				'fill' => 'SITEDESC',
				'kind' => 'lang',
				'opt' => 'blogdescription',
				],
			'SITELOGO' => [
				'fill' => 'SITELOGO',
				'kind' => 'attach',
				'mod' => 'custom_logo',
				],
			'HEADER_IMAGE' => [
				'fill' => 'HEADER_IMAGE',
				'kind' => 'lang',
				'mod' => 'header_image',
				],
			'FAVICON' => [
				'fill' => 'FAVICON',
				'kind' => 'pair',
				],
		],
		[ '@' => [ 'flixita', 'flixify' ],
			# To insert a language chooser into the header
			'LANGBOX' => [
				'fill' => 'LANGBOX',
				'start' => 'wp_body_open',
				'stop' => 'header_done',
				're' => '`(?=<div\s[^>]+widget-right)
					(<div\s(?:[^<]+|<(?!/?div)|(?-1))+(?<here></div[^>]*>))`sx',
				# To eat all the spaces before </div>, use this instead:
				#	(<div\s(?:\s*[^<\s]+|\s*<(?!/?div)|\s*(?-1))+(?<here>\s*</div[^>]*>))`sx',
				'chain' => [
#					'LEFT_COLS' => 'col-lg-6 col-12',
					'RIGHT_COLS' => 'nopad col-lg-5 col-12',
					],
				],
			# To insert a language chooser into the header after the desktop menu
			'LANGBOX2D' => [
				'fill' => 'LANGBOX2',
				'start' => 'wp_body_open',
				'stop' => 'header_done',
				're' => '`<div\sclass="main-menu-right">(?<here>)`sx',
				],
			# To insert another language chooser into the header before the mobile menu
			'LANGBOX2M' => [
				'fill' => 'LANGBOX2',
				'start' => 'wp_body_open',
				'stop' => 'header_done',
				're' => '`(?<here>)<div\s+class="menu-collapse-wrap">`sx',
				],
			### Chained blocks must be kept after the originator block
			'LEFT_COLS' => [
				'start' => 'wp_body_open',
				'stop' => 'header_done',
				're' => '`(<div\s+class="(?<here>(?:\s*col[^\s"]*(?=[\s"]))+)[^>]+>\s*
					<div\s+class="widget-left)`sx',
				],
			'RIGHT_COLS' => [
				'start' => 'wp_body_open',
				'stop' => 'header_done',
				're' => '`(<div\s+class="(?<here>(?:\s*col[^\s"]*(?=[\s"]))+)[^>]+>\s*
					<div\s+class="widget-right)`sx',
				],
			# To disable initial scroll-up for pages
			'PAGE_STEADY' => [
				'fill' => 'PAGE_STEADY',
				'start' => 'wp_body_open',
				'stop' => 'header_done',
				'cut' => 1,
				're' => '`<section\s+id="flixita-page"[^<]+<div[^<]+
					<div[^>]+(?<here>wow\s+fadeInUp)`sx',
				],
			# To change the Bootstrap class that controls page width
			'PAGE_CONTAINER' => [
				'fill' => 'PAGE_CONTAINER',
				'start' => 'wp_body_open',
				'stop' => 'header_done',
				'cut' => 1,
				're' => '`<section\s+id="flixita-page"[^>]*>\s*
					<div\s+class="(?<here>[^"]*)"`sx',
				],
			# To change the Bootstrap class that controls logo width
			'LOGO_CONTAINER' => [
				'fill' => 'LOGO_CONTAINER',
				'start' => 'wp_body_open',
				'stop' => 'header_done',
				'cut' => 1,
				're' => '`<div\s+class="(?<here>[^"]*)"[^<]+
					<div\s+class="logo">`sx',
				],
			# To change the Bootstrap class that controls navbar width
			'NAVBAR_CONTAINER' => [
				'fill' => 'NAVBAR_CONTAINER',
				'start' => 'wp_body_open',
				'stop' => 'header_done',
				'cut' => 1,
				're' => '`<div\s+class="(?<here>[^"]*)"[^<]+
					<nav\s+class="navbar-area">`sx',
				],
			# To change the Bootstrap class that controls max footer blocks
			'FOOT2_BLOCKS' => [
				'fill' => 'FOOT2_BLOCKS',
				'start' => 'get_footer',
				'stop' => 'wp_footer',
				'cut' => 1,
				're' => '`<div[^>]+"footer-main[^<]+<div[^<]+
					<div[^>]+row-cols-lg-(?<here>4)`sx',
				],
			'TOP1_OFF' => [
				'fill' => 'TOP1_OFF',
				'kind' => 'unflag',
				'mod' => 'enable_hdr_info1',
				],
			'TOP1_URL' => [
				'fill' => 'TOP1_URL',
				'mod' => 'hdr_info1_link',
				'do_url' => 'DROP_A', # What block sees this
				],
			'TOP2_OFF' => [
				'fill' => 'TOP1_OFF',
				'kind' => 'unflag',
				'mod' => 'enable_hdr_info2',
				],
			'TOP3_OFF' => [
				'fill' => 'TOP1_OFF',
				'kind' => 'unflag',
				'mod' => 'enable_hdr_info3',
				],
			'TOPSEARCH_OFF' => [
				'fill' => 'TOPSEARCH_OFF',
				'kind' => 'unflag',
				'mod' => 'enable_nav_search',
				],
			'TOPBUTTON_OFF' => [
				'fill' => 'TOPBUTTON_OFF',
				'kind' => 'unflag',
				'mod' => 'enable_hdr_btn',
				],
			# To get rid of the footer's top row
			'FOOT1_OFF' => [
				'fill' => 'FOOT1_OFF',
				'kind' => 'unflag',
				'mod' => 'enable_top_footer',
				],
			'FOOT1:4_NAME' => [
				'fill' => 'FOOT1:4_NAME',
				'kind' => 'lang',
				'field' => [ 'FOOT1:', 3, 'title' ],
				],
			'TEXT1_NAME' => [
				'fill' => 'TEXT1_NAME',
				'kind' => 'lang',
				'field' => [ 'TEXTW:', 1, 'title' ],
				],
			'TEXT1_TEXT' => [
				'fill' => 'TEXT1_TEXT',
				'kind' => 'langb',
				'field' => [ 'TEXTW:', 1, 'text' ],
				],
			'TEXT2_NAME' => [
				'fill' => 'TEXT2_NAME',
				'kind' => 'lang',
				'field' => [ 'TEXTW:', 2, 'title' ],
				],
			'TEXT2_TEXT' => [
				'fill' => 'TEXT2_TEXT',
				'kind' => 'langb',
				'field' => [ 'TEXTW:', 2, 'text' ],
				],
			'CAT1_OFF' => [
				'fill' => 'CAT1_OFF',
				'kind' => 'unlist',
				'field' => [ 'CATW:', 1 ],
				],
			'FOOT2:2' => [
				'fill' => 'FOOT2:2',
				'field' => [ 'SIDEBARS:', 'footer-sidebar-2' ],
				'kind' => 'list',
				],
			'FOOT2:3_OFF' => [
				'fill' => 'FOOT2:3_OFF',
				'kind' => 'unlist',
				'field' => [ 'SIDEBARS:', 'footer-sidebar-3' ],
				],
			'FOOT2:4_OFF' => [
				'fill' => 'FOOT2:4_OFF',
				'kind' => 'unlist',
				'field' => [ 'SIDEBARS:', 'footer-sidebar-4' ],
				],
			# To get rid of the primary sidebar
			'PRIMARY_OFF' => [
				'fill' => 'PRIMARY_OFF',
				'kind' => 'unlist',
				'field' => [ 'SIDEBARS:', 'sidebar-primary' ],
				],
			# To get rid of the social iconbar
			'SOCIAL_OFF' => [
				'fill' => 'SOCIAL_OFF',
				'kind' => 'unflag',
				'mod' => 'enable_social_icon',
				],
			# To get rid of the Pinterest icon
			'SOC4_OFF' => [
				'fill' => 'SOC4_OFF',
				'kind' => 'unlist',
				'field' => [ 'SOCIAL:', 3 ],
				],
			### Array blocks: must be kept last
			'FOOT1:' => [
				'depth' => 2,
				'json' => 1,
				'mod' => 'footer_top_info',
#				'filt' => 'daddy_plus_flixita_footer_top_default', # This works too
				],
			'TEXTW:' => [
				'depth' => 2,
				'opt' => 'widget_text',
				],
			'CATW:' => [
				'depth' => 2,
				'opt' => 'widget_categories',
				],
			'SIDEBARS:' => [
				'depth' => 2,
				'opt' => 'sidebars_widgets',
				],
			'SOCIAL:' => [
				'depth' => 2,
				'json' => 1,
				'mod' => 'hdr_social_icons',
				],
		],
		# Service block: must be kept last, all the names are reserved
		[ '@' => '*',
			'DROP_A' => [ # Unwrap marked <a>'s in the header part
				'start' => 'wp_body_open',
				'stop' => 'header_done',
				'how' => 'do_drop_a',
				],
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
	 * The current theme.
	 *
	 * @var   string
	 * @since 0.1.0
	 */
	private $theme;

	/**
	 * The order of actions.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private $order;

	/**
	 * The blocks for the current theme.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private $blocks;

	/**
	 * What blocks end when.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private $done;

	/**
	 * What span ends when.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private $drop;

	/**
	 * What settings go into filters.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private $mods;

	/**
	 * Create a new instance.
	 */
	public function __construct($opts = null)
	{
		$this->opts = $opts = CatsiteOptions::update($opts, self::$defaults);
		$this->theme = $theme = get_stylesheet();

		$order = array_flip(self::$actions); # Action order
		$order[' < '] = 0; # Default start
		$order[' > '] = count(self::$actions) - 1; # Default end
		$this->order = $order;

		# Collect the blocks usable with the theme
		$loc = [];
		foreach (self::$tset as $s)
		{
			# Check if the block is relevant
			$where = $s['@'] ?? null;
			if (empty($where)) continue; # Paranoia
			if (is_array($where))
			{
				# Theme in array
				if (!in_array($theme, $where, true)) continue;
			}
			# Theme or '*' matches
			else if (strcmp($theme, $where) && strcmp('*', $where)) continue;

			# Collect the blocks
			foreach ($s as $id => $arr)
			{
				if ($id === '@') continue;
				# New values for keys overwrite the old
				$loc[$id] = array_merge($loc[$id] ?? [], $arr);
			}
		}

		# Request the values from whatever
		$loc = apply_filters('catsite_fill_values', $loc);

		# Assemble the arrays: starts-ends and mods
		$se = [];
		$ends = [];
		foreach ($loc as $id => &$arr)
		{
			# Skip what is left alone
			if (!isset($arr['value'])) continue;
			# Activate the chained mods
			foreach ($arr['chain'] ?? [] as $key => $v)
				$loc[$key]['value'] = $v;
			# Activate the <a> unwrapping logic if needed
			if (!empty($arr['do_url']) && empty($arr['value']))
			{
				# Enable the proper postprocessing phase
				$key = $arr['do_url'];
				if (!isset($loc[$key])) continue; # Bad block
				$loc[$key]['value'] = 1;
				# The value marks the tag for removal
				$arr['value'] = self::INSANE_URL;
			}
			# Protect the special shortcodes
			else $arr['value'] = apply_filters('catsite_protect_codes', $arr['value']);
			# Regex-targeted insert
			if (!empty($arr['re']) || !empty($arr['how']))
			{
				$start = $order[$arr['start'] ?? ' < '] ?? null;
				$stop = $order[$arr['stop'] ?? ' > '] ?? null;
				if (!isset($start) || !isset($stop) || ($start >= $stop))
					continue; # Bad block
				# Where the segment ceases to be needed
				if ($start < ($ends[$stop] ?? PHP_INT_MAX))
					$ends[$stop] = $start;

				$se[$start] = ($se[$start] ?? 0) + 1;
				$se[$stop] = ($se[$stop] ?? 0) - 1;
				$this->done[$stop][] = $id;
			}
			# On-action insert
			else if (!empty($arr['where']))
			{
				$stop = $order[$arr['where']] ?? null;
				if (!isset($stop)) continue; # Bad block
				$se[$stop] = $se[$stop] ?? 0;
				$this->done[$stop][] = $id;
			}
			# Field of some array
			else if (!empty($arr['field']))
			{
				$key = $arr['field'][0] ?? '';
				if (!isset($loc[$key])) continue; # Bad block
				# Tell the array to postprocess this
				$loc[$key]['value'][] = $id;
				# For removing the field
				if ($arr['kind'] === 'unlist') $arr['value'] = null;
			}
			# Theme mod, option, or filter
			else if (!empty($arr['mod']) || !empty($arr['opt']) || !empty($arr['filt']))
			{
				if (isset($arr['depth']) && ($arr['depth'] < 1)) continue; # Bad block
				$this->mods[] = $id; # This will go into option
			}
# !!! Add here any other kinds of substitutions
		}
		$this->blocks = $loc;
#catsite_vardump('Blocks', $loc);
#catsite_vardump('Mods', $this->mods);
#catsite_vardump('Done', $this->done);

		# Decide when we are done with what part of the stream
		krsort($ends, SORT_NUMERIC);
		$n = PHP_INT_MAX;
		foreach ($ends as $stop => $start)
		{
			if ($start >= $n) continue;
			$this->drop[$stop] = $n < $stop ? $n : $stop;
			$n = $start;
		}

		# Decide when we need buffering at all
		ksort($se, SORT_NUMERIC);
		$n = 0;
		foreach ($se as $i => $v)
		{
			$v += $n;
			if (($n <= 0) && ($v > 0))
				$this->drop[$i] = 'start'; # Start buffering
			else if (($n > 0) && ($v <= 0))
				$this->drop[$i] = 'stop'; # End buffering
			else if (($v > 0) && !isset($this->drop[$i]))
				$this->drop[$i] = 'keep'; # Continue buffering
			else if ($v <= 0)
				$this->drop[$i] = 'no'; # No buffering
			$n = $v;
		}

		# Set filters where we need them
		$this->set_filters($se);
	}

	/**
	 * Fire an action when theme header is done with, making sure to do it
	 * only once.
	 *
	 * @return void
	 * @since  0.9.1
	 */
	public function header_done_now()
	{
		if (current_action() !== 'get_footer')
			remove_action('get_footer', [ $this, 'header_done_now' ], -1);
		do_action('header_done');
	}

	/**
	 * Fire an action when 'wp_footer' is done with.
	 *
	 * @return void
	 * @since  0.1.0
	 */
	public function footer_done_now()
	{
		do_action('footer_done');
	}

	/**
	 * Hook plugin filters to WordPress.
	 *
	 * @param  array $se Capture start/end positions for actions.
	 * @return void
	 * @since  0.1.0
	 */
	private function set_filters($se)
	{
		# Setup buffering handlers
		$hdl = [ $this, 'do_buffer' ];
		foreach (self::$actions as $n => $a)
		{
			if (isset($se[$n])) add_action($a, $hdl);
		}
		# An extra action to reliably signal end of header area
		if (isset($se[$this->order['header_done']]))
		{
			add_action('loop_start', [ $this, 'header_done_now' ], -1);
			add_action('get_footer', [ $this, 'header_done_now' ], -1);
		}
		# An extra action for when the very end of footer need be edited
		if (isset($se[$this->order['footer_done']]))
			add_action('wp_footer', [ $this, 'footer_done_now' ], PHP_INT_MAX);

		# Setup theme mods / options / filters handlers
		$hdl = [ $this, 'mod_array' ];
		$mods = [];
		foreach ($this->mods as $id)
		{
			$arr = $this->blocks[$id];
			$mod = isset($arr['mod']) ? 'theme_mod_' . $arr['mod'] :
				(isset($arr['opt']) ? 'option_' . $arr['opt'] :
				$arr['filt']); # !!! Only these 3 for now
			add_filter($mod, $hdl, PHP_INT_MAX);
			$mods[$mod] = $id;
		}
		$this->mods = $mods; # To use in decoding
#catsite_vardump('Mods', $this->mods);

		# Favicons need a specialized handler
		if (isset($this->blocks['FAVICON']['value']))
		{
			$this->fav = $this->blocks['FAVICON']['value'];
			ksort($this->fav, SORT_NUMERIC);
			add_filter('get_site_icon_url', [ $this, 'get_favicon' ], PHP_INT_MAX, 3);
		}
	}

	/**
	 * Make a marker for HTML range.
	 *
	 * @param  int $n Location index
	 * @return string HTML comment to mark the point
	 * @since  0.1.0
	 */
	private static function part_marker($n)
	{
		return "<!-- catsite at #$n -->";
	}

	/**
	 * Do things with buffered HTML.
	 *
	 * @return void
	 * @since  0.1.0
	 */
	public function do_buffer()
	{
		$where = current_action();
		$idx = $this->order[$where];
		$drop = $this->drop[$idx];
		$has_buf = ($drop !== 'start') && ($drop !== 'no');
#catsite_log("Action '$where': #$idx, drop '$drop', has_buf '$has_buf'\n");

		# Process the blocks that end here
		$buf_taken = isset($this->done[$idx]) && $has_buf;
		$buf = $buf_taken ? ob_get_clean() : '';
		foreach ($this->done[$idx] ?? [] as $id)
		{
			$how = $this->blocks[$id]['how'] ?? 'do_block';
			$buf = $this->$how($buf, $id);
		}

		# Split off the done part
		if (!is_string($drop))
		{
			if ($drop < $idx)
			{
				$p = strpos($buf, self::part_marker($drop));
				if ($p !== false) # Found where to split
				{
					echo substr($buf, 0, $p);
					$buf = substr($buf, $p);
				}
			}
			else # Entire buffer is done with
			{
				echo $buf;
				$buf = '';
			}
		}

		# Emit the entire buffer and be done
		if (($drop === 'stop') || ($drop === 'no'))
		{
			echo $buf;
			$buf = '';
		}
		else # Continue with buffering
		{
			# (Re)start buffering
			if ($buf_taken || ($drop === 'start'))
			{
				ob_start();
				echo $buf;
				$buf = '';
			}
			echo self::part_marker($idx);
		}
	}

	/**
	 * Splice block value into buffer using targeting RE.
	 *
	 * @param  string $buf Buffer.
	 * @param  string $id  Block ID.
	 * @return string Modified (or not) buffer.
	 * @since  0.1.0
	 */
	public function do_block($buf, $id)
	{
#catsite_log("Block '$id' got input:\n---\n$buf\n---\n");
		$arr = $this->blocks[$id];

		# Find where to apply the RE
		$start = $this->order[$arr['start'] ?? ' < '];
		$m = self::part_marker($start);
		$p = strpos($buf, self::part_marker($start));
		$p = $p === false ? 0 : $p + strlen($m);

		# Apply it
		$where = null;
		$l = 0;
		if (preg_match($arr['re'], $buf, $where, PREG_OFFSET_CAPTURE, $p))
		{
			if (!empty($arr['cut'])) $l = strlen($where['here'][0]); # Replace
			$where = $where['here'][1];
		}
		else $where = null; # Paranoia
			
		# Insert what we want
		if (isset($where)) $buf = substr_replace($buf,
			do_shortcode($arr['value']), $where, $l); # Interpret shortcodes

		return $buf;
	}

	/**
	 * Unwrap the <a> tags marked for removal.
	 *
	 * @param  string $buf Buffer.
	 * @param  string $id  Block ID.
	 * @return string Modified (or not) buffer.
	 * @since  0.1.0
	 */
	public function do_drop_a($buf, $id)
	{
#catsite_log("Block '$id' got input:\n---\n$buf\n---\n");
		$arr = $this->blocks[$id];

		# Find where to apply the RE
		$start = $this->order[$arr['start'] ?? ' < '];
		$m = self::part_marker($start);
		$p = strpos($buf, self::part_marker($start));
		$before = '';
		if ($p !== false) # Split off the leading part
		{
			$p += strlen($m);
			$before = substr($buf, 0, $p);
			$buf = substr($buf, $p);
		}

		# Split the string cutting out the bad stuff
		$parts = preg_split('#<a\s[^>]+' . self::INSANE_URL . '[^>]*>(.*?)</a>#s',
			$buf, -1, PREG_SPLIT_DELIM_CAPTURE);
		unset($buf);

		# Merge it back
		array_unshift($parts, $before);
		return implode('', $parts);
	}

	/**
	 * Apply changes to fields of an array theme mod, option, or anything
	 * else filtered, or replace one entirely.
	 *
	 * @param  mixed $mod The original value (in whatever form).
	 * @return mixed Modified (or not) value.
	 * @since  0.1.0
	 */
	public function mod_array($mod)
	{
		$id = $this->mods[current_filter()];
		$arr = $this->blocks[$id];
#catsite_vardump('Block:', $arr);

		$w = $arr['value'];
		if (!isset($arr['depth'])) # A singular value
			return is_string($w) ? do_shortcode($w) : $w;

		$json = !empty($arr['json']);
		if ($json) $mod = json_decode($mod, true);
#catsite_vardump('In:', $mod);

		$n = $arr['depth'] + 1;
		foreach ($w as $id)
		{
			$r = $this->blocks[$id];
			$idx = $r['field'];

			if ($r['kind'] === 'unlist')
			{
				# !!! Depth limited to 2 for now
				if (isset($idx[2])) unset($mod[$idx[1]][$idx[2]]);
				else if (isset($idx[1])) unset($mod[$idx[1]]);
				else $mod = null;
				continue;
			}

			$d = &$mod;
# For unlimited depth:
#			for ($i = 1; ($i < $n) && isset($idx[$i]); $i++) $d = &$d[$idx[$i]];
			# !!! Depth limited to 2 for now
			if (isset($idx[1])) $d = &$d[$idx[1]];
			if (isset($idx[2])) $d = &$d[$idx[2]];

			$v = $r['value'];
			$d = is_string($v) ? do_shortcode($v) : $v;
		}
		unset($d); # Paranoia

#catsite_vardump('Out:', $mod);
		return $json ? json_encode($mod) : $mod;
	}

	/**
	 * Favicon URLs sorted by size.
	 *
	 * @var   array
	 * @since 0.9.3
	 */
	private $fav;

	/**
	 * Give one of favicon URLs: either matching by size, or absent that, for
	 * size 512, the largest (WP uses that size to check favicon presence).
	 *
	 * @param  string $url  The WP's favicon URL.
	 * @param  int    $size The requested size.
	 * @param  int    $blog The blog ID (ignored).
	 * @return string The icon URL, or empty string if no such thing.
	 * @since  0.9.3
	 */
	public function get_favicon($url, $size, $blog)
	{
		if (($size === 512) && $this->fav && !isset($this->fav[512]))
			return end($this->fav);
		return $this->fav[$size] ?? '';
	}

}
