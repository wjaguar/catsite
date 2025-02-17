<?php

/**
 * catsite WordPress plugin.
 * Page files handling.
 *
 * @author  wjaguar <https://github.com/wjaguar>
 * @version 0.9.0
 * @package catsite
 */

class CatsiteStock
{

	/**
	 * The component defaults.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private static $defaults = [
		"page_dir" => __DIR__,
		"page_ext" => '.page',
		"menu_ext" => '.menu',
		"fill_ext" => '.fill',
		"default_slug" => 'beep',
		"exact_slugs" => true,
		"section_prefix" => '@',
		];

	/**
	 * Page cache key prefix
	 *
	 * @var   string
	 * @since 0.1.0
	 */
	const CACHE_PREFIX = ' Parsed Page ';

	/**
	 * Bad paths list
	 *
	 * @var   string
	 * @since 0.1.0
	 */
	const BADPATHS = ' BAD PATHS ';

	/**
	 * Drafts list
	 *
	 * @var   string
	 * @since 0.1.0
	 */
	const DRAFTS = ' DRAFTS ';

	/**
	 * All of page file sections.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private static $sections = [
		'PAGE' => [
			'MAIN' => 'bool',
			'KEYED' => 'bool',
			'PAGED' => 'bool',
			'CSS' => 'block',
			'JS' => 'block',
			'INIT' => 'block',
			'TITLE' => 'lang',
			'TEXT' => 'langb',
			],
		'MENU' => [
			'PLACES' => 'list', # Choice of menu locations
			'ITEM:' => 'stack',
			'LEVEL' => 'uint',
			'TITLE' => 'lang',
			'LINK' => 'langlink',
			'ID' => 'slug', # Item slug candidate (optional)
			'CLASS' => 'string',
			],
		'FILL' => [
			'THEME:' => 'themegroup',
			],
		'FILL*' => [
			'CSS' => 'block',
			'JS' => 'block',
			],
		];

	/**
	 * Which section types can carry language tags.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	const taggable = [ 'lang' => 1, 'langb' => 1, 'langlink' => 1 ];

	/**
	 * Which section types can continue over comments.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	const mergeable = [ 'lang' => 1, 'langb' => 1, 'block' => 1 ];

	/**
	 * Convert a string into a slug candidate.
	 *
	 * @param  string	   $str  Filename or something.
	 * @param  \CatsiteOptions $opts The values for various things.
	 * @return string|null
	 * @since  0.1.0
	 */
	private static function slugify($str, $opts)
	{
		$slug = transliterator_transliterate($opts->def("transliterator"), $str);
		$slug = sanitize_title($slug);
		return !empty($slug) ? $slug : null;
	}

	/**
	 * Prepare a string to serve as menu link.
	 *
	 * @param  string	   $link Something pretending to be a link.
	 * @param  \CatsiteOptions $opts The values for various things.
	 * @return string
	 * @since  0.1.0
	 */
	private static $domains;
	private static function prepare_link($link, $opts)
	{
		$url = wp_parse_url($link);
		if (!$url) return '#'; # Bad URLs do nothing
		if (!empty($url['host']))
		{
			# Relativize, IGNORING port and protocol
			if (!isset(self::$domains)) # Init
			{
				$d0 = $opts->def("canonical_url");
				if (!empty($d0)) $d0 = wp_parse_url($d0, PHP_URL_HOST);
				$d1 = $opts->def("domain");
				if (!empty($d1)) $d1 = wp_parse_url($d1, PHP_URL_HOST);
				self::$domains = [ $d0 => $d0, $d1 => $d1 ];
			}
			if (isset(self::$domains[$url['host']])) unset($url['host']);
		}
		# Canonicalize
		$value = '';
		if (!empty($url['host']))
		{
			$value = ($url['scheme'] ?? 'https') . '://' . $url['host'];
			if (!empty($url['port'])) $value .= ':' . $url['port'];
		}
		$value .= $url['path'] ?? '/';
		if (!empty($url['query'])) $value .= '?' . $url['query'];
		if (!empty($url['fragment'])) $value .= '#' . $url['fragment'];
		return $value;
	}

	/**
	 * Load and parse menu or page file.
	 *
	 * @param  string	   $name Filename (full path).
	 * @param  \CatsiteOptions $opts The values for various things.
	 * @param  array	   $sec  Sections and their types
	 * @param  bool		   $init If called in the setup phase.
	 * @return array|false
	 * @since  0.1.0
	 */
	private static function parse_file($name, $opts, $sec, $init = false)
	{
		# Use cache when possible
		if (!$init)
		{
			$cache_key = self::CACHE_PREFIX . $name;
			$cache = $opts->def($cache_key);
			if (isset($cache)) return $cache;
			$opts->redef($cache_key, []); # To fail only once
		}

		# Read in
		$text = file_get_contents($name);
		if ($text === false)
		{
			if ($init) $opts->error("Could not load the file '$name'");
			return false;
		}

		# Split into parts
		$sec0 = $opts->def('section_prefix');
		$parts = preg_split('/^' . $sec0 . '(#|[A-Z0-9_\.:*]+)\s?/m',
			$text, -1, PREG_SPLIT_DELIM_CAPTURE);
		unset($text);
		if (($parts === false) || (count($parts) < 2)) $parts = [];

		# Process the parts
		$now = '';
		$top = [];
		$res = [];
		$where = null;
		$theme = '*';
		$ltags = [];
		$lang = '.' . $opts->def('lang');
		$def = '.' . $opts->def('default_lang');
		$n = count($parts);
		for ($i = 1; $i < $n; $i += 2) $keys[$parts[$i]] = 1;
		for ($i = 1; $i < $n; $i += 2)
		{
			$key = $skey = $parts[$i];
			$tag = null;
			# Allow only known keys through
			# Unknown keys can be comments ('#' is reserved for it)
			$kind = $sec[$key] ?? null;
			if (!isset($kind))
			{
				$skey = strstr($key, '.', true);
				$kind = $sec[$skey] ?? '';
				# Only some can haz tag
				if (!isset(self::taggable[$kind])) continue;
				$tag = substr($key, strlen($skey));
			}

			# Mismatched theme-specific stuff is skipped
			if (empty($theme) && ($kind !== 'themegroup')) continue;

			$v = $parts[$i + 1] ?? '';
			# Where it makes sense, merge the text after their
			# trailing comments' end marker (if any) with the block
			if (isset(self::mergeable[$kind])) while (($parts[$i + 2] ?? '') === '#')
			{
				$value = explode('#' . $sec0, $parts[$i + 3] ?? '', 2);
				if (isset($value[1])) $v .= $value[1];
				$i += 2;
			}
			$v = trim($v);

			$value = null;
			switch ($kind)
			{
			case 'stack': # Multiple sets of fields
				if (empty($now)) $top = $res;
				else if ($res)
				{
					if (!isset($top[$now])) $top[$now] = [];
					$top[$now][] = $res;
				}
				$now = $key;
				$res = [];
				continue 2;
			case 'bool':
				if ($init) $value = in_array(strtolower($v),
					[ 1, 'yes', 'true' ], true);
				break;
			case 'uint':
				if ($init) $value = max(0, intval($v));
				break;
			case 'slug':
				if ($init) $value = self::slugify($v, $opts);
				break;
			case 'string':
				if ($init) $value = $v;
				break;
			case 'themegroup': # Filter on theme
				$where = $where ?? get_stylesheet() ?? '';
				$theme = preg_split('/\s+/', $v) ?: [];
				$theme = in_array($where, $theme, true) ? $where :
					(in_array('*', $theme, true) ? '*' : '');
				if ($init) $value = $theme;
				break;
			case 'langb':
				# '*'-tagged blocks are for all languages
				# No tag, empty tag, '.*whatever' mean the same
				if (($tag[1] ?? '*') === '*') $key = $skey . ($tag = '.*');
				$a = $ltags[$skey] ?? [];
				if (!$a) # Need init
				{
					$a['.*'] = true;
					$a[$lang] = true;
					# If no such thing in current lang, allow default lang
					if (!isset($keys[$skey . $lang])) $a[$def] = true;
					$ltags[$skey] = $a;
				}
				# Join relevant blocks together
				if ($a[$tag]) $res[$skey] = ($res[$skey] ?? '') . $v . "\n";
				$value = 1; # Just to keep the tagged key
				break;
			case 'lang':
			case 'langlink':
				# No tag, empty tag mean the default language
				if (!isset($tag[1])) $key = $skey . ($tag = $def);
				$a = $ltags[$skey] ?? [];
				if (!$a) # Need init
				{
					$a[$lang] = true;
					# If no such thing in current lang, allow default lang
					if (!isset($keys[$skey . $lang])) $a[$def] = true;
					$ltags[$skey] = $a;
				}
				# Current language overwrites the value
				if ($a[$tag])
				{
					if ($kind === 'langlink') $v = self::prepare_link($v, $opts);
					$res[$skey] = $v;
				}
				$value = 1; # Just to keep the tagged key
				break;
			case 'block':
				$value = ($res[$key] ?? '') . $v . "\n";
				break;
			case 'flag':
				$value = 1;
				break;
			case 'unflag':
				$value = 0;
				break;
			case 'list':
				$value = preg_split('/\s+/', $v) ?: [];
				break;
			case 'unlist':
				$value = 1; # Merely a flag
				break;
			}
			if (!$init) $value = $value ?? $v;
			$res[$key] = $value;
		}
		if ($now && $res)
		{
			if (!isset($top[$now])) $top[$now] = [];
			$top[$now][] = $res;
		}
		if ($top) $res = $top;
		if (!$res)
		{
			if ($init && !isset($where))
				$opts->error("Could not parse the file '$name'");
			return false;
		}

		if (!$init) $opts->redef($cache_key, $res); # Remember

		return $res;
	}

	/**
	 * Try and match menu items from database and from file:
	 * first, order & link, then link only, then everything
	 * remaining for reuse.
	 *
	 * @param  array $items Array of objects decorated by wp_setup_nav_menu_item().
	 * @param  array $parts Array of menuitem descriptions.
	 * @return array The matches: menu_order => ID.
	 * @since  0.1.0
	 */
	private static function match_menu_items($items, $parts)
	{
		$matched = []; # order => item
		$cnt = min(count($parts), count($items));
		for ($idx = 1; $idx <= $cnt; $idx++)
		{
			$item = $items[$idx - 1];
			# Same link in same place is best match
			if (($item->menu_order == $idx) &&
				($item->url === ($parts[$idx - 1]['LINK'] ?? '/')))
				$matched[$idx] = $item->db_id;
		}
		$stay = array_flip($matched); # item => order
		$by_link = [];
		foreach ($items as $item)
		{
			if (empty($stay[$item->db_id]))
				$by_link[$item->url][] = $item->db_id;
		}
		$cnt = count($parts);
		for ($idx = 1; $idx <= $cnt; $idx++)
		{
			if (isset($matched[$idx])) continue;
			$link = $parts[$idx - 1]['LINK'] ?? '/';
			# Same link in different place is a worse match
			if ($by_link[$link] ?? false)
				$matched[$idx] = array_shift($by_link[$link]);
		}
		$stay = array_flip($matched); # item => order
		$the_rest = [];
		foreach ($items as $item)
		{
			if (empty($stay[$item->db_id]))
				$the_rest[] = $item->db_id;
		}
		for ($idx = 1; $idx <= $cnt; $idx++)
		{
			if (isset($matched[$idx])) continue;
			if (!$the_rest) break; # No more
			$matched[$idx] = array_shift($the_rest);
		}
		return $matched;
	}

	/**
	 * Gets post essentials.
	 *
	 * @param  int $id The post ID.
	 * @return array Post essentials: ID, slug, parent / empty on failure
	 * @since  0.1.0
	 */
	public static function locate_post($id)
	{
		$post = get_post($id);
		if (!isset($post)) return [];
		return [
			'ID' => $id,
			'post_name' => $post->post_name,
			'post_parent' => $post->post_parent,
			];
	}

 	/**
	 * Makes post a path node.
	 *
	 * @param  array           $where Post essentials: ID, slug, parent
	 * @param  string          $path  The path.
	 * @param  \CatsiteOptions $opts  The values for various things.
	 * @return int The ID of the post, or 0 on failure.
	 * @since  0.1.0
	 */
	private static function make_node($where, $path, $opts)
	{
		if (!$where) return 0; # Nothing there

		$part = substr($path, 0, -1); # Lose the trailing slash
		$p = strrpos($part, '/', -1);
		if ($p !== false) $part = substr($part, $p + 1);

		$title = htmlspecialchars(wp_check_invalid_utf8($part, true),
			ENT_NOQUOTES, $opts->def("charset"));
		$how = [ # Path item parameters
			'meta_input' => [ 'catsite_file' => $path ],
			'post_title' => $title,
			'post_content' => "[@catsite hidden]",
			'post_type' => 'page',
			'post_status' => 'publish',
			'comment_status' => 'closed',
			'ping_status' => 'closed',
			];
		# Turn the post into the node
		return wp_insert_post($where + $how);
	}

 	/**
	 * Ensures existence of a post, and of the chain of pages for its path.
	 *
	 * @param  string          $name  The filename and path.
	 * @param  \array          $have  Array of path => ID mappings.
	 * @param  \CatsiteOptions $opts  The values for various things.
	 * @return int The ID of the post, or 0 on failure.
	 * @since  0.1.0
	 */
	private static function ensure_post($name, &$have, $opts)
	{
		$page_ext = $opts->def("page_ext");

		# Strip off the extension
		$path = substr($name, 0, -strlen($page_ext));

		# If a path item already exists, use it
		$item = $path . '/';
		$id = $have[$item] ?? 0;
		if ($id)
		{
			$where = self::locate_post($id);
			if ($where) return $where; # The post is good, return its info

			# Something deleted the post
			$opts->warn("Something deleted the path node for '$item'");
			# All (ex-)direct children of a deleted post are now wrong
			# due to reparenting, and must be rewritten if staying in use
			$opts->push(self::BADPATHS, $item);
			$have[$item] = -1; # Mark it invalid
		}

		# Go up the path till something exists
		$steps = [];
		while (true)
		{
			$p = strrpos($path, '/', -1);
			if ($p === false)
			{
				$steps[] = $path;
				$path = '';
			}
			else
			{
				$steps[] = substr($path, $p + 1);
				$path = substr($path, 0, $p);
			}
			$parent = 0;
			if ($path === '') break;
			$item = $path . '/';
			$parent = $have[$item] ?? 0;
			if ($parent <= 0) # No valid path item
			{
				# Use a page, if there, as path node
				$parent = $have[$path . $page_ext] ?? 0;
				if ($parent > 0) $have[$item] = $parent;
			}
			if ($parent > 0) break;
		}

		# Build the path up step by step
		$blank = [ # Blank post parameters
			'post_title'  => '-',
			'post_type' => 'page',
			'post_status' => 'auto-draft',
			];
		$item = $path !== '' ? $path . '/' : '';
		while ($steps)
		{
			$part = array_pop($steps);
			$item .= $part . '/';
			$what = "file '$name'" . ($steps ? ", dir '$part'" : '');

			$slug = self::slugify($part, $opts);
			$slug2 = $slug ?? $opts->def("default_slug");
			$slug2 = wp_unique_post_slug($slug2, 0, 'publish', 'post', $parent);
			if ($slug2 !== $slug) # Something isn't good
			{
				if (empty($slug)) # Unusable filename/dirname
					$opts->warn("The $what had to go by the default slug '$slug2'");
 				else if (!$opts->def("exact_slugs")) # Can haz tail
					$opts->warn("The $what had to go by the slug '$slug2'");
 				else # Fail on conflict
		 		{
					$opts->error("The $what could not get the slug '$slug'");
 					return false;
 				}
				$slug = $slug2;
			}

			# Try reusing first
			$id = $opts->tail(self::DRAFTS);
			if (empty($id))
			{
				# Add a blank post
				$id = wp_insert_post($blank, false, false);
				if (!$id)
				{
					$opts->error("Could not create a post for the $what");
					return false;
				}
				$opts->push(self::DRAFTS, $id); # Remember, in case it lingers
			}
			$where = [
				'ID' => $id,
				'post_name' => $slug,
				'post_parent' => $parent,
			];
			if (!$steps) return $where; # All done - return the location info

			# Another brick in the path - fill it up
			$id = self::make_node($where, $item, $opts);
			if (!$id)
			{
				$opts->error("Could not setup the path node for the $what");
				return false;
			}
			$have[$item] = $id;
			$opts->set('pages_added', $have); # Remember
			$parent = $id;
		}

		return false; # Paranoia
	}

 	/**
	 * Validate a list of posts.
	 *
	 * @param  array  $what Array with post ID values.
	 * @param  string $how  How to return the results.
	 * @return array Whatever is requested by $how.
	 * @since  0.1.0
	 */
	private static function check_posts($what, $how)
	{
		$want = [];
		foreach ($what as $id) $want[$id] = 1;
		$there = get_pages( [
			'sort_column' => 'ID',
			'hierarchical' => false,
			'include' => array_keys($want),
			] ) ?: [];
		# An array or hash of what does not exist
		if (($how === 'bad') || ($how === 'badhash'))
		{
			foreach ($there as $post) unset($want[$post->ID]);
			return $how === 'bad' ? array_keys($want) : $want;
		}
		# An array (the default) or hash of what exists
		$want = [];
		if ($how === 'goodhash')
			foreach ($there as $post) $want[$post->ID] = 1;
		else
			foreach ($there as $post) $want[] = $post->ID;
		return $want;
	}

	/**
	 * Plugin activation tasks.
	 *
	 * @param  array $defaults The initial values for various things.
	 * @return void
	 * @since  0.1.0
	 */
	public static function activate($opts = null)
	{
		global $wp_rewrite;
#		$test_rewrite = false;

		$opts = CatsiteOptions::update($opts, self::$defaults);

		/* Add the required class options to WordPress */
		$opts->add('pages_directory', $opts->def("page_dir"));
		$opts->add('pages_added', []);
		$opts->add('paged', []);
		$opts->add('fill_info', []);

		/* Set up proper permalinks */
		if (!got_url_rewrite())
		{
			$opts->error("Catsite requires working rewrite engine");
			return;
		}
		$link = get_option('permalink_structure');
		$wantlink = '/%postname%/';
		if ($link !== $wantlink)
		{
			$wp_rewrite->set_permalink_structure($wantlink);
			$wp_rewrite->flush_rules(true);
#			$test_rewrite = true; # Wait for a page to try it on
		}

		/* Scan the files */
		self::rescan($opts);
	}

	/**
	 * (Re)scan the relevant files and anchor them into DB.
	 *
	 * @param  array $opts The values for various things.
	 * @return void
	 * @since  0.1.0
	 */
	public static function rescan($opts)
	{
		$opts = CatsiteOptions::update($opts,
			# Simple conversion
			[ "transliterator" => Transliterator::create('Any-Latin; Latin-ASCII') ]);

		$page_ext = $opts->def("page_ext");
		$lx = strlen($page_ext);

		/* Prepare for files */
		$dir = trailingslashit($opts->get('pages_directory'));
		$ldir = strlen($dir);

		$have = ($opts->get('pages_added') ?? false) ?: [];

		$prefix = $opts->def('section_prefix');

		/* Block WP's autosaving revisions */
		add_filter('wp_revisions_to_keep', '__return_zero', PHP_INT_MAX);

		/* Clear the page anchors and path items */
		if (false) # ### For when I change something
		{
			$pages = [];
			foreach ($have as $name => $id)
			{
				if (substr($name, -$lx) === $page_ext)
					wp_delete_post($id, true);
				else if (substr($name, -1) === '/')
					wp_delete_post($id, true);
				else $pages[$name] = $id;
			}
			$have = $pages;
			$opts->set('pages_added', $have); # Remember
		}

		/* Collect the page files */
		$files = catsite_rglob($dir, '*' . $page_ext, GLOB_MARK);

		/* Clean up and depth-sort them */
		$pages = [];
		foreach ($files as $name)
		{
			# Skip dirs and unreadable things
			if (!is_file($name)) continue;
			# Relativize & sanitize
			$name = str_replace('\\', '/', substr($name, $ldir));
			# Prepare sort key
			$parts = explode('/', $name);
			$parts[] = $name;
			$pages[] = $parts;
		}
		sort($pages); # Arrays are sorted by length then by values in order
		$files = array_map(function($v) { return $v[count($v) - 1]; }, $pages);

		/* Check */
		$badtimes = 0;
REDO:		$pages = [];
		$paged = [];
		$keyed = [];
		foreach ($files as $name)
		{
			# Parse the file
			$parts = self::parse_file($dir . $name, $opts,
				self::$sections['PAGE'], true);
			if (!$parts) continue; # Failed

			# See if we have the page already
			if (isset($have[$name]))
				$id = $pages[$name] = $have[$name];
			# Otherwise, attempt to create it
			else
			{
				# Get us a post with ID, slug & parent
				$where = self::ensure_post($name, $have, $opts);
				if (!$where) continue; # Error

				# Real page parameters
				$id = $where['ID'];
				$how = [
					'meta_input' => [ 'catsite_file' => $name ],
					'post_title' => "[@catsite title $id]",
					'post_content' => "[@catsite content $id]",
					'post_type' => 'page',
					'post_status' => 'publish',
					'comment_status' => 'closed',
					'ping_status' => 'closed',
					];

				# Turn the post into the page
				$id = wp_insert_post($where + $how);
				if (!$id)
				{
					$opts->error("Could not setup the page for the file '$name'");
					continue;
				}
				$opts->drop(self::DRAFTS, $id); # A draft no more (if ever)
				$pages[$name] = $have[$name] = $id;
				$opts->set('pages_added', $have); # Remember
			}

			# Reapply the page's options

			if ($parts['MAIN'] ?? false) # Make it the front page
			{
				update_option('show_on_front', 'page');
				update_option('page_on_front', $id);
			}
			# Allow/disallow it accept page number
			if ($parts['PAGED'] ?? false) $paged[$id] = true;
			# Allow/disallow it accept search key
			if ($parts['KEYED'] ?? false) $keyed[$id] = true;

			# Trace the page's path
			$path = $name;
			while (true)
			{
				$p = strrpos($path, '/', -2);
				if ($p === false) break; # Not in subdir
				$path = substr($path, 0, $p + 1);
				if ($pages[$path] ?? false) continue; # Already traced
				if ($have[$path] ?? false)
				{
					$pages[$path] = $have[$path];

					# Check if this is an ex-page
					$key = substr($path, 0, $p) . $page_ext;
					$id = $have[$key] ?? 0;
					# Due to depth-sorting, all existing pages
					# on this level are already in $pages[]
					if ($id && !($pages[$key] ?? false))
					{
						# Rewrite it into a path node
						$id = self::make_node(self::locate_post($id), $path, $opts);
						# Failure means damaged tree at least
						if (!$id) $opts->push(self::BADPATHS, $path);
						# No ex-page there now
						else unset($have[$key]);
					}
				}
				# Triggering this means the saved option itself is damaged
				else $opts->push(self::BADPATHS, $path);
			}
		}

		# Prune the broken paths if something fishy is happening
		$badpaths = $opts->def(self::BADPATHS) ?? [];
		$opts->redef(self::BADPATHS, []);
		if ($badpaths)
		{
			if ($badtimes++) # Drop the towel if it keeps happening
				wp_die("<center>Something keeps trashing the DB!</center>");

			# Check the actual presence of the pages in use
			$want = self::check_posts($pages, 'badhash');

			# Collect the paths that lost their posts
			$bad = array_fill_keys($badpaths, 1);
			foreach ($pages as $name => $id)
			{
				if ($want[$id]) $bad[$name] = 1;
			}

			# Prune the damaged branches
			$there = sort(array_keys($pages));
			$lost = '///'; # Impossible name
			$l = 3; # Its length
			foreach ($there as $name)
			{
				if (strncmp($name, $lost, $l) === 0); # Lost with the dir
				else if ($bad[$name]) $l = strlen($lost = $name); # New lost dir
				else continue; # Leave be
				unset($pages[$name]); # Prune
			}
		}

		# Collect the stale posts
		$stale = $opts->def(self::DRAFTS) ?? []; # Lingering drafts are stale
		foreach ($have as $name => $id)
		{
			if (($pages[$name] ?? null) === $id) continue; # Is alive
			if ((substr($name, -$lx) === $page_ext) || # Is a page
				(substr($name, -1) === '/')) # Is a node
			{
				$stale[] = $id; # To delete
				unset($have[$name]); # To forget
			}
		}

		# Go reattach stuff to broken links
		if ($badtimes)
		{
			# Some/all stale posts may have been deleted, validate them
			$opts->redef(self::DRAFTS,
				array_reverse(self::check_posts($stale, 'good')));
			goto REDO;
		}

		# Delete the stale posts
		foreach ($stale as $id) wp_delete_post($id, true);
		$opts->redef(self::DRAFTS, []);

		# Prepare page slugs array, by querying the DB
		$ids = array_values(array_unique($have));
		$there = get_pages( [
			'sort_column' => 'ID',
			'hierarchical' => false,
			'include' => $ids,
			] ) ?: [];
		$names = [];
		foreach ($there as $post)
		{
			$names[$post->post_name][$post->post_parent] = $post->ID;
		}
#catsite_vardump('names', $names);

		# Remember the new state
		$opts->set('pages_added', $have);
		$opts->set('paged', [ $paged, $keyed, $names ]);

		/* Collect the menu files - only in the base dir */
		$files = glob($dir . '*' . $opts->def("menu_ext"), GLOB_MARK);
#catsite_arrlist("files: ", $files);

		/* Check */
		$lx = strlen($opts->def("menu_ext"));
		$locs = get_nav_menu_locations();
		$reg = get_registered_nav_menus();
		foreach ($files as $name)
		{
			# Skip dirs and unreadable things
			if (!is_file($name)) continue;
			# Relativize
			$name = substr($name, $ldir);

			# Parse the file
			$parts = self::parse_file($dir . $name, $opts,
				self::$sections['MENU'], true);
			if (!$parts) continue; # Failed

			# See if we have the menu already
			if (isset($have[$name]))
				$id = $pages[$name] = $have[$name];
			# Otherwise, attempt to create it
			else
			{
				$slug = self::slugify(substr($name, 0, -$lx), $opts);
				$id = wp_update_nav_menu_object(0, [ 'menu-name' => $slug ]);
				if (is_wp_error($id))
				{
					$opts->error($id);
					$opts->error("Could not create the menu '$slug' for the file '$name'");
					continue; # Failed
				}
				add_term_meta($id, 'catsite_file', $name);
				$pages[$name] = $have[$name] = $id;
				$opts->set('pages_added', $have); # Remember
			}
			# See what (if anything) the menu has inside
			$items = wp_get_nav_menu_items($id);
			if ($items === false) # Paranoia
			{
				$opts->error("Total failure getting menu items for the file '$name'");
				continue; # Failed
			}
#catsite_vardump("Items", $items);
			# Match items to menu indices
			$matched = self::match_menu_items($items, $parts['ITEM:'] ?? []);
#catsite_vardump("Matched", $matched);

			wp_defer_term_counting(true); # Stop per-item updating

			# Rewrite items
			$stay = []; # item => order
			$levels = [ [ -1, 0 ] ];
			$cnt = count($parts['ITEM:'] ?? []);
			for ($idx = 1; $idx <= $cnt; $idx++)
			{
				# Init item, find its parent
				$part = $parts['ITEM:'][$idx - 1];
				$lvl = $part['LEVEL'] ?? 0; # uint
				while ($levels[0][0] >= $lvl) array_shift($levels);

# TODO In principle, internal links w/o fragment part can be mapped to pages here,
# through the $pages[] array, given that all pages have been processed by this point

				# Item parameters
				$how = [
					'menu-item-position' => $idx,
					'menu-item-parent-id' => $levels[0][1],
					'menu-item-title' => "[@catsite menu $id item $idx]",
					'menu-item-url' => $part['LINK'] ?? '/',
					'menu-item-classes' => $part['CLASS'] ?? null,
					'menu-item-status' => 'publish',
					'menu-item-type' => 'custom', # Paranoia
					];
				$res = wp_update_nav_menu_item($id, $matched[$idx] ?? 0, $how);
				if (is_wp_error($res))
				{
					$opts->error($res);
					$opts->error("Could not save menu item #$idx from the file '$name'");
					continue; # Failed
				}
				# Tag with filename
				add_post_meta($res, 'catsite_file', $name, true);
				# Remember for possible children
				array_unshift($levels, [ $lvl, $res ]);
				# Remember where the part went
				$stay[$res] = $idx;
			}
			# Delete the unused items
			foreach ($items as $item)
			{
				if (!isset($stay[$item->ID]))
					wp_delete_post($item->ID, true);
			}

			wp_defer_term_counting(false); # Do the update

			# Attach the menu to preferred location
			$places = $parts['PLACES'] ?? [];
			foreach ($places as $where)
			{
				# Next if no such slot
				if (!array_key_exists($where, $reg)) continue;
				# Done if already there
				if (($locs[$where] ?? null) == $id) break;
				# Next if the slot is occupied by another our menu
				if (!empty($locs[$where]) && in_array($pages, $locs[$where]))
					continue;
				# Place in there and be done
				$locs[$where] = $id;
				break;
			}
			if (!$places || ($locs[$where] != $id))
				$opts->warn("Could not find where to attach the menu from the file '$name'");
		}
		set_theme_mod('nav_menu_locations', $locs);

		/* Collect the fill files - only in the base dir */
		$files = glob($dir . '*' . $opts->def("fill_ext"), GLOB_MARK);
#catsite_arrlist("files: ", $files);

		/* Check */
		$group1 = [];
		$group2 = [];
		foreach ($files as $name)
		{
			# Skip dirs and unreadable things
			if (!is_file($name)) continue;
			# Relativize
			$name = substr($name, $ldir);

			# Parse the file
			$parts = self::parse_file($dir . $name, $opts,
				self::$sections['FILL'], true);
			if (!$parts) continue; # Failed or mismatched
			$v = $parts['THEME:'] ?? '*'; # Paranoia
			if ($v === '*') $group1[] = $name; # Generic
			else $group2[] = $name; # Specific
		}
		$opts->set('fill_info', array_merge($group1, $group2));
	}

	/**
	 * Page ID to file mapping.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private $pages;

	/**
	 * Menu ID to file mapping.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private $menus;

	/**
	 * Path item IDs without files.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private $nodes;

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

		$page = $this->vars_from_path($opts);

		# Make reverse tables
		# Those for pages (posts) and menus (taxonomy terms) need be
		# separate to preclude ID clash
		# That for nodes (page-less directory items) is separate to allow
		# easy recognition of them
		$menu_ext = $opts->def("menu_ext");
		$lx = strlen($menu_ext);
		$files = ($opts->get('pages_added') ?? false) ?: [];
		$this->pages = $this->menus = $this->nodes = [];
		$nodes = [];
		foreach ($files as $name => $id)
		{
			if (substr($name, -1) === '/')
				$nodes[$id] = $name;
			else if (substr($name, -$lx) === $menu_ext)
				$this->menus[$id] = $name;
			else $this->pages[$id] = $name;
		}
		foreach ($nodes as $id => $name)
		{
			if (!isset($this->pages[$id]))
				$this->nodes[$id] = $name;
		}
#catsite_vardump("stock", $this);

		$this->set_filters();
	}

	/**
	 * Extracts the page ID and variables from the 'PATH' option.
	 *
	 * @param  \CatsiteOptions $opts The values for various things.
	 * @return string Lang value or null.
	 * @since  0.1.0
	 */
	private function vars_from_path($opts)
	{
		list($paged, $keyed, $pages) = array_pad($opts->get('paged') ?? [], 3, null);

		# Find where page hierarchy stops
		$path = $opts->def('PATH');
		$tail = $path['tail'];
		$prev = $page = 0;
		foreach ($tail as $idx => $s)
		{
			# Do it like get_page_by_path() does
			$parts = explode('%2F', str_replace('%20', ' ',
				rawurlencode(urldecode($s))));
			$page = $prev;
			foreach ($parts as $slug)
			{
				$page = $pages[$slug][$page] ?? null;
				if (!isset($page)) break 2; # A variable or a miss
			}
			$prev = $page; # Accept
		}
		# An empty hierarchy means the root page
		if (!$prev) $prev = get_option('page_on_front');

		# If something is left, try taking it as variables
		$vars = [];
		if (!isset($page))
		{
			$vars = array_splice($tail, $idx);

			# Try accepting last part as number
			if ($paged[$prev] ?? false)
			{
				$last = array_pop($vars);
				$v = strspn($last, '0123456789');
				if ($v === strlen($last)) $opts->redef('_page', $v2 = $last);
				else $vars[] = $last;
			}
			# Try accepting everything else as (urlencoded) string
			if ($vars && ($keyed[$prev] ?? false))
			{
				$opts->redef('_key', $v1 = implode('/', $vars));
				$vars = [];
			}
		}
		# If nothing left now, remember the successful parse
		if (!$vars)
		{
			if (isset($v1)) $vars[] = $v1;
			if (isset($v2)) $vars[] = $v2;
			$path = [
				'page' => $prev,
				'path' => $tail,
				'tail' => null,
				'after' => $vars,
				] + $path;
		}
		# Otherwise, mark failure
		else
		{
			$path['FAIL'] = true;
			$prev = 0;
		}
#catsite_vardump('opts', $opts);
#catsite_vardump('PATH', $path);
#catsite_log("page=$prev\n");

		# Return the results
		$opts->redef('PATH', $path);
		return $prev;
	}

	/**
	 * Hook plugin filters to WordPress.
	 *
	 * @return void
	 * @since  0.1.0
	 */
	private function set_filters()
	{
		/* The title filters */
		add_filter('the_title', [ $this, 'do_title_replace_i' ], -1, 2);
		add_filter('single_post_title', [ $this, 'do_title_replace_p' ], -1, 2);
		/* The content filter */
		add_filter('the_content', [ $this, 'do_replace' ], -1);
		/* The menu filter */
		add_filter('wp_get_nav_menu_items', [ $this, 'do_menu_replace' ], -1, 3);
		# And whatever other places can hit the insertion markers

		/* The page-catching filter */
		add_filter('pre_handle_404', [ $this, 'get_page' ], 10, 2);

		/* The *.fill data export filter */
		add_filter('catsite_fill_values', [ $this, 'fill_values' ]);

		/* The CSS and JS emitter */
		add_action('wp_head', [ $this, 'add_css_js' ], PHP_INT_MAX);
	}

	/**
	 * Redirects path nodes to their page child.
	 * Loads page's CSS and languages.
	 *
	 * @param  bool     $skip  If something else wants to skip the rest of handle_404().
	 * @param  WP_Query $query The query object with inputs and results.
	 * @return bool Whether to skip the rest of handle_404().
	 * @since  0.1.0
	 */

	public function get_page($skip, $query)
	{
		global $wp;

#catsite_vardump("WP", $wp);
#catsite_vardump("query", $query);
#catsite_vardump("stock", $this);
		if (!$this->opts || $skip || !$query->is_page())
			return $skip; # Not to handle here

		$id = $query->get_queried_object_id();
#catsite_log("want $id\n");

		if (!empty($this->nodes[$id])) # Redirect the node
		{
			$where = $this->nodes[$id];
			$l = strlen($where);
			$dest = '';
			foreach ($this->pages as $p => $name)
			{
				if (strncmp($name, $where, $l) === 0)
				{
					# Take the first page found
					$dest = get_page_link($p);
					break;
				}
			}
			# Paranoia: if something ate all the pages, go to homepage
			if (empty($dest)) $dest = get_home_url();

			wp_redirect($dest);
			exit;
		}

		if (!empty($this->pages[$id]))
		{
			$opts = $this->opts;
			$dir = trailingslashit($opts->get('pages_directory'));
			$vars = self::parse_file($dir . $this->pages[$id],
				$opts, self::$sections['PAGE']);

			# Queue page's CSS and JS
			if (!empty($vars['CSS']))
				$opts->redef('page_CSS', $vars['CSS'] . "\n");
			if (!empty($vars['JS']))
				$opts->redef('page_JS', $vars['JS'] . "\n");

			# See which languages the page has
			$def = $opts->def('default_lang');
			$langs = [];
			foreach ($opts->def('langs') as $lang)
			{
				if (($lang === $def) || isset($vars["TITLE.$lang"]) ||
					isset($vars["TEXT.$lang"])) $langs[] = $lang;
			}
			$opts->redef('have_langs', $langs);
		}
		return $skip; # No influence on this
	}

	/**
	 * Adds the site's CSS and JS to the page header.
	 *
	 * @return void
	 * @since  0.1.0
	 */
	public function add_css_js()
	{
		$opts = $this->opts;

		$css = strip_tags(				# Mistakes happen
			($opts->def('CSS') ?? '') .		# Generic first
			($opts->def('page_CSS') ?? '') .	# Page second
			($opts->def('fill_CSS') ?? ''));	# Fill last
		if (!empty($css))
		{
			$id = 'id="catsite-css"';
			$type = 'type="text/css"';
			if (current_theme_supports('html5', 'style')) $type = '';
			echo "<style $type $id>\n$css\n</style>\n";
		}

		$js = 	($opts->def('JS') ?? '') .	# Generic first
			($opts->def('page_JS') ?? '') .	# Page second
			($opts->def('fill_JS') ?? '');	# Fill last
		if (!empty($js))
		{
			wp_print_inline_script_tag($js, [ 'id' => "catsite-js" ]);
		}
	}

	/**
	 * Replaces 'title' or 'content' markers with the corresponding content.
	 *
	 * @param  string $text The text to process.
	 * @param  int    $what The post ID, or null in case of 'content'.
	 * @return string The rewritten (or not) text.
	 * @since  0.1.0
	 */
	public function do_replace($text, $what = null)
	{
		$cmd = isset($what) ? 'title' : 'content';
#catsite_log("ID $what, text:\n$text\n---\n");
		if (strpos($text, '[@catsite ') === false) return $text; # Nothing to do
		$parts = preg_split('/\[@catsite \s*' . $cmd . '\s+(\d+)\s*\]/',
			$text, -1, PREG_SPLIT_DELIM_CAPTURE);
		$n = count($parts);
		if ($n < 2) return $text; # False alarm

		/* Disable WP's machinations on this page */
		if ($cmd === 'content')
		{
			$id = current_filter();
			remove_filter($id, 'wpautop');
			remove_filter($id, 'shortcode_unautop');
			remove_filter($id, 'wptexturize');
		}

		$vars = false;
		if (!isset($what)) $what = (int)$parts[1];
		if (!empty($what) && !empty($this->pages[$what]))
		{
			$dir = trailingslashit($this->opts->get('pages_directory'));
			$vars = self::parse_file($dir . $this->pages[$what],
				$this->opts, self::$sections['PAGE']);
		}
		if (!$vars) $vars = [];
#catsite_vardump("File ", $vars);

		# Nonexistent field or file produce empty string
		$key = $cmd === 'title' ? 'TITLE' :
			($cmd === 'content' ? 'TEXT' : null);
		$text = isset($key) ? ($vars['INIT'] ?? '') . ($vars[$key] ?? '') : '';
		# Protect the special shortcodes
		$text = apply_filters('catsite_protect_codes', $text);
		for ($i = 1; $i < $n; $i += 2) $parts[$i] = $text;
#catsite_vardump("Parts ", $parts);

		$text = implode('', $parts);
		if ($vars && ($cmd === 'title')) return do_shortcode($text);
		return $text; # Shortcodes in content are left to WP
	}

	/**
	 * Replaces 'title' markers with the corresponding content.
	 *
	 * @param  string $text The text to process.
	 * @param  int    $what The post ID (may be null).
	 * @return string The rewritten (or not) text.
	 * @since  0.1.0
	 */
	public function do_title_replace_i($text, $what)
	{
		# When displaying menu items, we see their titles and IDs here,
		# but in their case, do_menu_replace() already handled things
		# To avoid wasting time, let only known pages past this point
		if (empty($what) || empty($this->pages[$what])) return $text;
		return $this->do_replace($text, $what);
	}

	/**
	 * Replaces 'title' markers with the corresponding content.
	 *
	 * @param  string   $text The text to process.
	 * @param  \WP_Post $what The post it is from.
	 * @return string The rewritten (or not) text.
	 * @since  0.1.0
	 */
	public function do_title_replace_p($text, $what)
	{
		# To avoid wasting time, let only known pages past this point
		if (empty($this->pages[$what->ID])) return $text;
		return $this->do_replace($text, $what->ID);
	}

	/**
	 * Replaces 'menu N item' markers with the corresponding content.
	 *
	 * @param  array   $items Array of objects decorated by wp_setup_nav_menu_item().
	 * @param  WP_Term $menu  The menu object.
	 * @param  array   $args  The arguments sent to get_posts() to get the items.
	 * @return array   The modified (or not) items.
	 * @since  0.1.0
	 */
	public function do_menu_replace($items, $menu, $args)
	{
		$what = $menu->term_id;
		if ($what && isset($this->menus[$what]))
		{
			$dir = trailingslashit($this->opts->get('pages_directory'));
			$vars = self::parse_file($dir . $this->menus[$what],
				$this->opts, self::$sections['MENU']);
			if (!$vars) $vars = [];
			$parts = $vars['ITEM:'] ?? [];

			foreach ($items as &$item)
			{
				if (strpos($item->title, '[@catsite ') === false)
					continue; # Nothing to do
				$r = preg_split('/\[@catsite \s*menu\s+\d+\s+item\s+(\d+)\s*\]/',
					$item->title, -1, PREG_SPLIT_DELIM_CAPTURE);
				$n = count($r);
				if ($n < 2) continue; # False alarm (wrong command)

				for ($i = 1; $i < $n; $i += 2)
				{
					$part = $parts[(int)$r[$i] - 1] ?? [];
					# Protect the special shortcodes
					$r[$i] = apply_filters('catsite_protect_codes',
						$part['TITLE'] ?? '');
				}
				$item->title = do_shortcode(implode('', $r));
				# Links need updating in case they depend on language
				if (isset($part['LINK'])) $item->url = $part['LINK'];
			}
			unset($item); # Paranoia
		}

		return $items;
	}

	/**
	 * Adds values from *.fill files to the items.
	 *
	 * @param  array   $where Array of arrays keyed by value IDs.
	 * @return array   The modified (or not) array.
	 * @since  0.1.0
	 */
	public function fill_values($where)
	{
		$opts = $this->opts;

		# Prepare sections map
		$sec = [];
		foreach ($where as $arr)
		{
			if (!empty($arr['fill']))
				$sec[$arr['fill']] = $arr['kind'] ?? '';
		}
		# Predefined things are kept unmodifiable
		$sec = self::$sections['FILL'] + self::$sections['FILL*'] + $sec;
#catsite_vardump("Sec", $sec);

		# Read values from files
		$dir = trailingslashit($opts->get('pages_directory'));
		$files = $opts->get('fill_info') ?? [];
		$res = [];
		foreach ($files as $name)
		{
			$parts = self::parse_file($dir . $name, $opts, $sec);
			# Properly merge the fields
			foreach ($parts as $key => $v)
			{
				if (!isset($sec[$key])) continue; # No use
				if ($sec[$key] === 'block') # Merge
					$v = ($res[$key] ?? '') . $v . "\n";
				$res[$key] = $v; # Remember
			}
		}
		unset($parts);
#catsite_vardump("Res", $res);

		# Insert values where they belong
		foreach ($where as &$arr)
		{
			$from = $arr['fill'] ?? '';
			if (!empty($from) && isset($res[$from]))
				$arr['value'] = $res[$from];
		}
		unset($arr);
#catsite_vardump("Where", $where);

		# Queue the CSS and JS
		if (!empty($res['CSS']))
			$opts->redef('fill_CSS', $res['CSS'] . "\n");
		if (!empty($res['JS']))
			$opts->redef('fill_JS', $res['JS'] . "\n");
#catsite_vardump("Opts", $opts);

		return $where;
	}


}
