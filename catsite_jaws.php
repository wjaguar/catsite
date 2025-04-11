<?php

/**
 * catsite WordPress plugin.
 * Custom data tables handling and generic macro substitutions.
 *
 * @author  wjaguar <https://github.com/wjaguar>
 * @version 0.9.2
 * @package catsite
 */

class CatsiteJaws
{

	/**
	 * The component defaults.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private static $defaults = [
#		'table_init' => 'init.sql',
		'per_page' => 100, # Default cats per page
#		'tables' => null, # Table description block
		'table_prefix' => 'catsite_', # Goes after WP's prefix
		'primary_key' => 'id',
		'letter1_prefix' => '_1_', # For alphabet fields
#		'protected_codes' => [ '*' ],
		];

	/**
	 * The 'tables' default is the tables description block.
	 * It is organized like this:
	 * [
	 *	'table1' => [
	 *		'id', 'name', 'link' => 'table2',
	 *		],
	 *	'table2' => [ ...
	 *	'table3' => [ ' SQL' => '( SELECT foo FROM bar )', ...
	 *
	 * The fields that refer to other tables should have those tables' names
	 * as values.
	 * The ' SQL' key if present, defines a subquery, to serve instead of a
	 * generated (prefixed) table name. The prefix is interpolated into it
	 * in place of '{$prefix}' substrings, if any.
	 */

	/**
	 * Default output in case of formatting failure.
	 *
	 * @var   string
	 * @since 0.1.0
	 */
	const FORMAT_FAIL = 'FAIL';

	/**
	 * What column is used as primary key throughout.
	 *
	 * @var   string
	 * @since 0.1.0
	 */
	private $primary_key;

	/**
	 * The alphabet columns prefix.
	 *
	 * Is added before a text column name to get a corresponding one-char
	 * column which holds that column's upcased first letter.
	 * Empty string or null if no such columns in any of the tables.
	 *
	 * @var   string
	 * @since 0.1.0
	 */
	private $letter1_prefix;

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

# FIXME The ::rescan() method should load & run 'table_init' SQL if the first table is absent

	/**
	 * The options worker class instance.
	 *
	 * @var   \CatsiteOptions
	 * @since 0.1.0
	 */
	private $opts;

	/**
	 * The fields map.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private $fmap;

	/**
	 * The SQL request results, for use outside [from_table].
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private $results;

	/**
	 * The macros.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private $macros;

	/**
	 * The formats.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private $fmts;

	/**
	 * The textmaps.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private $tmaps;

	/**
	 * The variables.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private $hash = [];

	/**
	 * Create a new instance.
	 */
	public function __construct($opts = null)
	{
		global $wpdb;

		$this->opts = $opts = CatsiteOptions::update($opts, self::$defaults);

		/* Prepare the language URL prefix */
		$this->hash['/lang'] = $opts->def('lang_in_path') ?
			'/' . strtolower($opts->def('lang')) : '';

		/* Prepare the table settings */
		$this->primary_key = $opts->def('primary_key');
		$this->letter1_prefix = $opts->def('letter1_prefix');

		/* Assemble the fields map */
		$prefix = $wpdb->prefix . ($opts->def('table_prefix') ?? '');
		$this->fmap = [];
		$tables = $opts->def('tables');
		foreach ($tables as $tb => $arr)
		{
			$res = [ ' SQL' => "`$prefix$tb`" ];
			foreach ($arr as $key => $v)
			{
				if ($key === ' SQL')
					$res[$key] = str_replace('{$prefix}', $prefix, $v);
				elseif (is_string($key))
					$res[$key] = $tables[$v] ? $v : 1;
				else $res[$v] = 1;
			}
			$this->fmap[$tb] = $res;
		}

		$this->set_shortcodes();

		/* Init the WHERE condition to default */
		$this->set_where();
	}

	/**
	 * The SQL generators.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private $sqler;

	/**
	 * Full names of the shortcodes, for internal reference.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private $fullname;

	/**
	 * The shortcodes description block.
	 *
	 * A method can be mapped either to a single code, or to several codes
	 * with a helper method for each.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private static $codes = [
		# Normal shortcodes
		'macro' => 'macro',
		'tablemacro' => 'tablemacro',
		'expand' => 'expand',
		'vars' => 'vars',
		'format' => 'format',
		'unspace' => 'unspace',
		'textmap' => 'textmap',
		'remap' => 'remap',
		# SQL-and-interpolation shortcodes
		'interpolator' => [
			'alphabet' => 'alphabet_sql',
			'from_table' => 'table_sql',
			'totals' => 'sum_sql',
			'loop' => 'loop_arr', # Array instead of SQL
			'from_before' => 'once_arr', # Same
			],
		'set_where' => [
			'where_id' => 'id_sql_init',
			'where_letter1' => 'letter1_sql_init',
			'where_match' => 'match_sql_init',
			'where_key' => 'key_sql_init',
			'paged' => 'paged_sql_init', # Modifier
			],
		];

	/**
	 * Hook shortcodes to WordPress.
	 *
	 * @return void
	 * @since  0.1.0
	 */
	private function set_shortcodes()
	{
		$pf0 = $this->opts->def('shortcode_prefix') ?? '';
		if (strlen($pf0)) $pf0 .= '_';

		# Codes can be protected from abuse by adding a random prefix
		$ppf = $pf0 . 'p' . hash_hmac('md5', (string)rand(0, PHP_INT_MAX),
			(string)rand(0, PHP_INT_MAX));
		$protect = array_fill_keys($this->opts->def('protected_codes') ?? [], true);
		$all = !empty($protect['*']);
		$remap = [];

		# Register the shortcodes
		foreach (self::$codes as $func => $helpers)
		{
			$hdl = [ $this, $func ];
			if (!is_array($helpers))
				$helpers = [ $helpers => null ];
			foreach ($helpers as $code => $func)
			{
				$tag = $pf0 . $code;
				if ($all || !empty($protect[$code]))
				{
					$remap[] = $code;
					$tag = $ppf . $code;
				}
				if (isset($func)) # Remember the helper method
					$this->sqler[$tag] = [ $this, $func ];
				$this->fullname[$code] = $tag;
				add_shortcode($tag, $hdl);
			}
		}
		$this->sqler[''] = [ $this, 'id_sql_init' ]; # The default WHERE helper

		# If something needs protecting from regular users
		if ($remap)
		{
			rsort($remap, SORT_STRING); # So that longer keys go first
			foreach ($remap as &$v) $v = preg_quote($v, '/');
			unset($v); # Paranoia

			$this->prot_re = '/(?<=\[\/|\[)' . preg_quote($pf0, '/') .
				'(?=(?:' . implode('|', $remap) . ')\W)/s';
			$this->prot_str = $ppf;
			add_filter('catsite_protect_codes', [ $this, 'add_prot' ]);
		}
	}

	/**
	 * The protected shortcodes RE.
	 *
	 * @var   string
	 * @since 0.1.0
	 */
	private $prot_re;

	/**
	 * The protected shortcodes prefix.
	 *
	 * @var   string
	 * @since 0.1.0
	 */
	private $prot_str;

	/**
	 * Adds protected prefix to the shortcodes that match the RE.
	 *
	 * @param  string $str The original string.
	 * @return string The modified (or not) string.
	 * @since  0.1.0
	 */
	public function add_prot($str)
	{
		return implode($this->prot_str, preg_split($this->prot_re, $str, -1));
	}

	/**
	 * The regular expression to parse fields.
	 *
	 * @var   string
	 * @since 0.1.0
	 */
	private static $field_re =
		'/\$\{([^{}]+)\}|\$\{\+([^{}=]+)=\$\{([^-+?!\/][^{}]*)\}\}/';

	/**
	 * Build and make SQL request for the fields used in the content, then
	 * interpolate said fields.
	 * Generic parameter is: cache variable ("cache="), variable to write
	 * into ("into=").
	 *
	 * Syntax:
	 * ${field}, ${ref->field} - table values (HTMLified by default)
	 * ${=var} - variables (raw by default)
	 * ${+var} - setting a variable (to 1)
	 * ${-var} - unsetting a variable (to an empty string '')
	 * ${+var=value}, ${+var=${field}}, ${+var=${=var2}} - assignments
	 * ${?field}, ${?=var} - positive conditionals
	 * ${!field}, ${!=var} - negative conditionals
	 * ${/!} - else branch
	 * ${/} - endif
	 * ${field|%format}, ${=var|%format} - formatted fields/variables
	 * ${field|url}, ${=var|url}, ... - fields/variables piped through a
	 *	builtin function or several
	 *
	 * @param  array  $attrs   The attributes.
	 * @param  string $content The bracketed text, if any.
	 * @param  string $tag     The shortcode tag this is called with.
	 * @return string The text to substitute.
	 * @since  0.1.0
	 */
	public function interpolator($attrs = [], $content = null, $tag = '')
	{
		global $wpdb;

		$var = $attrs['into'] ?? null;

		# Reuse if possible
		$cslot = $attrs['cache'] ?? null;
		if (isset($cslot) && isset($this->hash[$cslot]))
		{
			$v = $this->hash[$cslot];
			# Output if no 'into='
			if (!isset($var)) return $v;
			# Put into variable instead
			$this->hash[$var] = $v;
			return '';
		}

		if (!isset($content)) return $content; # Nothing to do

		$parts = preg_split(self::$field_re, $content, -1, PREG_SPLIT_DELIM_CAPTURE);
		$n = count($parts);
		if ($n > 1)
		{
			$data = [ [ ] ];
			/* Collect the field names */
			$vars = $this->collect_fields($parts, $n);
			/* Write the SQL request and map names to columns */
			$sql = call_user_func_array($this->sqler[$tag], [ &$vars, $attrs ]);
#catsite_log("SQL:\n$sql\n");
			if (is_string($sql)) # SQL request
			{
				# Request
#$d = microtime(true);
				$this->results = $wpdb->get_results($sql, ARRAY_A);
#$d = microtime(true) - $d;
#$d = sprintf("%.8f", $d);
#catsite_log("Time spent:\n$d sec.\n");
				if ($wpdb->last_error)
					error_log('wpdb error: ' . $wpdb->last_error);
				$data = $this->results;
			}
			elseif (is_array($sql)) # Directly a result array
				$data = $this->results = $sql;
#$nn = 100;
#$d = microtime(true);
#for ($ii = 0; $ii < $nn; $ii++)
			$content = $this->batch_interpolate($data, $vars, $parts, $n);
#$d = microtime(true) - $d;
#$d = sprintf("%.8f", $d);
#error_log("\t{$nn}x batch_interpolate time: $d sec.\n", 3, CATSITE_TIME_LOG);
		}
		$content = do_shortcode($content);

		# Save if requested
		if (isset($cslot)) $this->hash[$cslot] = $content;

		# Output if no 'into='
		if (!isset($var)) return $content;
		# Put into variable instead
		$this->hash[$var] = $content;
		return '';
	}

	/**
	 * Collect DB field references from a presplit string.
	 *
	 * @param  array  $parts The presplit string.
	 * @param  int    $n     The count of $parts.
	 * @return array Field ref to empty string map.
	 * @since  0.1.0
	 */
	private function collect_fields($parts, $n)
	{
		$vars = [];
		for ($i = 1; $i < $n; $i += 2)
		{
			$key = $parts[$i];

			# Handle assignments
			if ($key === '') continue; # The source var is 2 fields ahead

			# Handle prefixes
			switch ($key[0])
			{
			case '/': # Endif marker
			case '+': case '-': # Variable set/unset
			case '=': # Variable extraction
				continue 2; 
			case '?': case '!': # Used as conditional
				if (($key[1] ?? '') === '=') continue 2; # Variable
				$key = substr($key, 1);
				break;
			}

			$v = strstr($key, '|', true); # Drop the transforms
			if ($v === false) $v = $key;

			$vars[$v] = ''; # Map to nothing by default
		}
		return $vars;
	}

	/**
	 * Repeatedly interpolate batches of prepared values into a presplit string.
	 *
	 * @param  array  $data  The array of arrays of DB field values.
	 * @param  array  $vars  The text to field name map.
	 * @param  array  $parts The presplit string.
	 * @param  int    $n     The count of $parts.
	 * @return string The resulting text.
	 * @since  0.1.0
	 */
	private function batch_interpolate($data, $vars, $parts, $n)
	{
		$this->compiled = $this->precompile($vars); # Reinit

		$first = array_key_first($data);
		$last = array_key_last($data);
		$cnt = count($data);
		# Builtin index variables
		$this->hash['_min'] = $first;
		$this->hash['_max'] = $last;
		$this->hash['_count'] = $cnt;

		$all = [ [ ] ];
		foreach ($data as $idx => $arr)
		{
			# Builtin index variables
			$this->hash['_idx'] = $idx;
			$this->hash['_first'] = $idx === $first ? 1 : 0;
			$this->hash['_last'] = $idx === $last ? 1 : 0;

			$res = $this->interpolate($arr, $vars, $parts, $n);
			$all[] = $res;
		}
#catsite_vardump("Compiled:", $this->compiled);

		return implode('', call_user_func_array('array_merge', $all));
	}

	/**
	 * Interpolate prepared values into a presplit string.
	 *
	 * @param  array  $arr   The DB field values.
	 * @param  array  $vars  The text to field name map.
	 * @param  array  $parts The presplit string.
	 * @param  int    $n     The count of $parts.
	 * @return array An array of copied and substituted parts.
	 * @since  0.1.0
	 */
	private function interpolate($arr, $vars, $parts, $n)
	{
		$res = [];
		$depth = 0;
		for ($i = 0; $i < $n; $i++)
		{
			if ($depth) # Failed conditional
			{
				if (!($i & 1)) continue; # Plaintext
				$key = $parts[$i];
				switch ($key[0] ?? '')
				{
				case '': # Assignment
					$i += 2; # Skip its extra fields
					continue 2;
				case '/': # Endif/else
					--$depth;
					if (!$depth || (($key[1] ?? '') !== '!'))
						continue 2;
					# Fallthrough for nested else
				case '?': case '!': # Nested conditional
					++$depth;
				}
				continue;
			}
			# Normal flow
			$key = $parts[$i];
			if (!($i & 1)) # Plaintext
			{
				$res[] = $key;
				continue;
			}

			# Handle prefixes
			$dest = null;
			$flag = '';
			switch ($key[0] ?? '')
			{
			case '': # Assignment
				$dest = $parts[$i + 1]; # Destination
				$i += 2;
				$key = $parts[$i]; # Source
				break;
			case '/': # Endif/else
				if (($key[1] ?? '') === '!') $depth = 1; # Else
				continue 2;
			case '+': # Set a variable
			case '-': # Unset a variable
				$set = $key[0] === '+';
				$l = strpos($key, '=', 1);
				$nm = $l === false ? substr($key, 1) : substr($key, 1, $l - 1);
				$p = strstr($nm, '|', true);
				if ($p !== false) # Processing, not assignment
				{
					$nm = $p;
					if ($set) # Self-modification
					{
						$dest = $nm;
						$key[0] = '=';
						break;
					}
				}
				# Assign a constant
				$this->hash[$nm] = !$set ? '' : # Unset
					($l === false ? 1 : substr($key, $l + 1)); # Set
				continue 2;
			case '?': # Positive condition
			case '!': # Negative condition
				$flag = $key[0];
				$key = substr($key, 1);
			# default: # A variable or a field
			}
			# Expecting multiple rows, need to minimize parsing overhead
			$r = $this->compiled[$key] ?? $this->compile_var($key, $vars);
			$v = $this->do_var($r, $flag, $arr);

			if ($flag) # Conditional
			{
				if ($v xor ($flag === '!')) $depth = 1; # Failed
			}
			elseif (isset($dest)) $this->hash[$dest] = $v; # Assignment
			else $res[] = $v; # Emit the value
		}
		return $res;
	}

	/**
	 * The converted EMS codes.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private $ems;

	/**
	 * Commands for template value processing.
	 *
	 * @var   int
	 * @since 0.1.0
	 */
	const cNothing = 0;
	const cConst = 1;
	const cVar = 2;
	const cGetField = 3;
	const cGetVar = 4;
	const cStr = 5;
	const cEmptyStop = 6;
	const cNullReplace = 7;
	const cEmptyReplace = 8;
	const cFullReplace = 9;
	const cFormat = 10;
	const cUrlenc = 11;
	const cHtm = 12;
	const cEvery = 13;
	const cIs = 14;
	const cAdd = 15;
	const cMax = 16;
	const cRemap = 17;
	const cMatch = 18;
	const cEMS = 19;
	const cStore = 20;
	const cPercent = 21;
	const cLink = 22;

	/**
	 * Execute a compiled value processing sequence.
	 *
	 * @param  array  $r    The sequence.
	 * @param  string $cond Empty if normal use, non-empty if a condition.
	 * @param  array  $arr  The DB field values.
	 * @return mixed The result value.
	 * @since  0.1.0
	 */
	private function do_var($r, $cond, $arr)
	{
		$i = 0;
		while (isset($r[$i])) switch ($r[$i++])
		{
		case self::cConst:
			$w = $r[$i++]; break;
		case self::cVar:
			$w = $this->hash[$r[$i++]] ?? null; break;
		case self::cGetField:
			# String DB values get escaped, variables not
			$v = $arr[$r[$i++]] ?? null; $var = false; $need_esc = is_string($v); break;
		case self::cGetVar:
			$v = $this->hash[$r[$i++]] ?? null; $var = true; $need_esc = false; break;
		case self::cStr:
			$v = $r[$i++]; $var = true; $need_esc = false; break;
		case self::cEmptyStop:
			if (!empty($v)) break;
			$v = '';
			$need_esc = false;
			$var = true; # Empty string is variable-like
			break 2;
		case self::cFullReplace:
			$need_esc = false;
			$var = true; # Either result is variable-like
			if (empty($v))
			{
				$v = '';
				break 2;
			}
			$v = $w ?? '';
			break;
		case self::cEmptyReplace:
			if ((string)$v !== '') break;
			$v = $w ?? '';
			$need_esc = false;
			$var = true; # The replacement is variable-like
			break;
		case self::cNullReplace:
			if (isset($v)) break;
			$v = $w ?? '';
			$need_esc = false;
			$var = true; # The replacement is variable-like
			break;
		case self::cFormat:
			$v = !isset($v) ? '' : ($need_esc ? esc_html($v) : $v);
			$v = $this->do_format($w, $v);
			$need_esc = false;
			$var = true; # The result is variable-like
			break;
		case self::cUrlenc:
			# Encode NULL as an empty string
			$v = rawurlencode($v ?? '');
			$need_esc = false;
			$var = true; # The result is variable-like
			break;
		case self::cHtm:
			$v = esc_html($v ?? '');
			$need_esc = false;
			$var = true; # The result is variable-like
			break;
		case self::cEvery:
			$w = intval($w ?? 0);
			$v = intval($v);
			$v = !$v || !$w || ($v % $w);
			$v = $v ? 0 : 1;
			$need_esc = false;
			$var = true; # The flag is variable-like
			break;
		case self::cIs:
			$w = intval($w ?? 0);
			$v = intval($v);
			$v = $v !== $w;
			$v = $v ? 0 : 1;
			$need_esc = false;
			$var = true; # The flag is variable-like
			break;
		case self::cAdd:
			$w = intval($w ?? 0);
			$v = intval($v) + $w;
			$need_esc = false;
			$var = true; # The integer is variable-like
			break;
		case self::cMax:
			$w = intval($w ?? 0);
			$v = intval($v);
			if ($v > $w) $v = $w;
			$need_esc = false;
			$var = true; # The integer is variable-like
			break;
		case self::cRemap:
			$v = (string)$v;
			$var = $this->tmaps[$r[$i++]][$v] ?? null;
			if (isset($var))
			{
				$v = $var;
				$need_esc = false;
			}
			$var = true; # The result is variable-like
			break;
		case self::cMatch:
			$v = ($r[$i] !== '') && preg_match($r[$i], (string)$v) ? 1 : 0;
			$i++;
			$need_esc = false;
			$var = true; # The flag is variable-like
			break;
		case self::cEMS:
			$v = $this->ems[$v] ??
				($this->ems[$v] = $this->emscolor($v));
			$need_esc = false; # No special chars in there
			$var = true; # The decoded string is variable-like
			break;
		case self::cStore:
			$dest = $r[$i];
			break 2; # Stop right now
		case self::cPercent:
			$v = intval($v) / $r[$i++];
			$v = sprintf($r[$i++], $v);
			$need_esc = true; # In case locale put ' as decimal point
			$var = true; # The formatted string is variable-like
			break;
		case self::cLink:
			$v = esc_url($v);
			$need_esc = false; # Escaping done
			$var = true; # Variable-like now
			break;
		}

		# Condition
		if ($cond) $v = $var ? empty($v) : !isset($v);
		# Text
		else
		{
			$v = !isset($v) ? '' : ($need_esc ? esc_html($v) : $v);
			# Format the output, if requested
			$form = $this->hash['%'] ?? '';
			if (($form !== '') && is_string($form))
				$v = $this->do_format($form, $v);
		}
		if (!isset($dest)) return $v;
		# The final part of self::cStore
		$this->hash[$dest] = $v;
		return '';
	}

	/**
	 * The compiled value processing sequences.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private $compiled;

	/**
	 * The converted wildcard masks.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private $masks;

	/**
	 * Template text to command codes correspondence.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	const xforms = [
		'?'	 => self::cEmptyStop,
		'??'	 => self::cNullReplace,
		'()?'	 => self::cEmptyReplace,
		'? '	 => self::cFullReplace,
		'%'	 => self::cFormat,
		'url'	 => self::cUrlenc,
		'htm'	 => self::cHtm,
		'html'	 => self::cHtm,
		'every ' => self::cEvery,
		'is '	 => self::cIs,
		'+'	 => self::cAdd,
		'max '	 => self::cMax,
		'remap ' => self::cRemap,
		'remap'  => self::cRemap,
		'match ' => self::cMatch,
		'emscolor' => self::cEMS,
		'into '	 => self::cStore,
		'percent' => self::cPercent,
		'link' => self::cLink,
		];

	/**
	 * The prefix commands - those that allow for a parameter.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	const pfx = [
		'%', '??', '()?', '? ', 'every ', 'is ', 'remap ', 'match ', '+',
		'max ', 'into ', 'percent',
		];

	/**
	 * Compile and store a processing sequence to transform and/or format a
	 * value for intended use.
	 *
	 * @param  string $v    The value.
	 * @param  array  $vars The text to field name map.
	 * @return array The compiled sequence.
	 * @since  0.1.0
	 */
	private function compile_var($v, $vars)
	{
		$r = [];
		$pipe = explode('|', $v);

		# The initial access
		$key = array_shift($pipe);
		if ($key[0] !== '=')
		{
			$r[] = self::cGetField;
			$r[] = $vars[$key] ?? null;
		}
		elseif (($key[1] ?? '') !== '=')
		{
			$r[] = self::cGetVar;
			$r[] = substr($key, 1);
		}
		else
		{
			$r[] = self::cStr;
			$r[] = substr($key, 2);
		}

# !!! Better remake this into a switch on opcodes, too
		# The further processing
		foreach ($pipe as $cmd)
		{
			$lc = strtolower($cmd);
			# Test all the prefixes
			foreach (self::pfx as $p)
			{
				$l = strlen($p);
				if (!strncmp($lc, $p, $l)) break;
				$p = null;
			}
			$s = self::xforms[$p ?? $lc] ?? self::cNothing;

			switch ($s)
			{
			case self::cNothing: # No such command
				# On first invalid transform, just stop
				break 2;
			case self::cNullReplace: # '??' - NULL replacement
			case self::cEmptyReplace: # '()?' - Empty string replacement
			case self::cFullReplace: # '? ' - Stop/replacement
				$c = substr($cmd, $l);
				if ($c[0] === '=') # Variable
				{
					$r[] = self::cVar;
					$r[] = substr($c, 1);
				}
				else # String
				{
					$r[] = self::cConst;
					$r[] = $c;
				}
				$r[] = $s;
				break;
			case self::cFormat:	# '%' - Format
				if (strlen($cmd) < 2) continue 2; # Skip if no format
				$r[] = self::cConst;
				$r[] = $cmd;
				$r[] = self::cFormat;
				break;
			case self::cEvery:	# 'every ' - Grouping flag
			case self::cIs:		# 'is ' - Numeric equality test
			case self::cAdd:	# '+' - Addition
			case self::cMax:	# 'max ' - Max limit
				$c = trim(substr($cmd, $l));
				if ($c[0] === '=') # Variable
				{
					$r[] = self::cVar;
					$r[] = substr($c, 1);
				}
				else # Number
				{
					$r[] = self::cConst;
					$r[] = intval($c);
				}
				# Fallthrough
			case self::cEmptyStop:	# '?' - Stop condition
			case self::cUrlenc:	# 'url' - Urlencode
			case self::cHtm:	# 'htm'/'html' - Htmlify
			case self::cEMS:	# 'emscolor' - Decode EMS cat color code (to English)
			case self::cLink:	# 'link' - Prepare an URL to be used as href
				$r[] = $s;
				break;
			case self::cRemap: # 'remap '/'remap' - Text remapping (given/default table)
				$r[] = self::cRemap;
				$r[] = isset($p) ? trim(substr($cmd, $l)) : '';
				break;
			case self::cMatch: # 'match ' - Wildcard match test
				$c = substr($cmd, $l);
				$r[] = self::cMatch;
				$r[] = $this->masks[$c] ??
					($this->masks[$c] = catsite_mask2re($c) ?? '');
				break;
			case self::cStore: # 'into ' - Postfix assignment
				$r[] = self::cStore;
				$r[] = substr($cmd, $l);
				# After the assignment, ignore everything else
				break 2;
			case self::cPercent: # 'percent' - Integer as a fractional percentage
				# A power of 10 may follow; anything else gets reduced to that
				$c = strlen((string)abs(intval(trim(substr($cmd, $l))))) - 1;
				$r[] = self::cPercent;
				if ($c > 0) # Fractional percentage
				{
					$r[] = 10 ** $c;
					$r[] = "%.{$c}f%%";
				}
				else # Integer percentage
				{
					$r[] = 1;
					$r[] = "%d%%";
				}
				break;
			# !!! Add here whatever else is useful
			}
		}

		$this->compiled[$v] = $r;
		return $r;
	}

	/**
	 * Compile and store all plain field accesses, for a bit of speedup
	 * when interpolating a large number of different fields.
	 *
	 * @param  array  $vars The text to field name map.
	 * @return array The array of all compiled sequences.
	 * @since  0.1.0
	 */
	private function precompile($vars)
	{
		$res = [];
		$rf = [ self::cGetField, null ]; # Field
		foreach ($vars as $key => $v)
		{
			$rf[1] = $v;
			$res[$key] = $rf;
		}
		return $res;
	}

	/**
	 * Parse a format string and cache the result.
	 *
	 * @param  string $form The format string.
	 * @return array The setup for doing this formatting.
	 * @since  0.1.0
	 */
	private function get_format($form)
	{
		if (!strncmp($form, '%(', 2)) # Want a formatter
		{
			if (preg_match('/^%\(([^)]*)\)(.*)$/s', $form, $matches))
			switch ($matches[1])
			{
			# sprintf()
			case '': $res = [
				'what' => 'sprintf',
				'how' => $matches[2],
				];
				break;
			# POSIX date
			case 'date': $res = [
				'what' => 'CatsiteDate::format_date',
				'how' => $matches[2],
				];
				break;
			# Image width & height
			case 'wh': $res = [
				'what' => [ $this, 'format_wh' ],
				'how' => preg_split("/%([%wh])/", $matches[2], -1,
					PREG_SPLIT_DELIM_CAPTURE),
				];
				break;
			}
			# Default to a failure reporter
			$res = $res ?? [
				'what' => static function () { return self::FORMAT_FAIL; },
				'how' => '',
				];
		}
		# Default is a normal sprintf()
		else $res = [
			'what' => 'sprintf',
			'how' => $form,
			];
		# Cache and return the result
		$this->fmts[$form] = $res;
		return $res;
	}

	/**
	 * Format a value.
	 *
	 * @param  string $form The format string.
	 * @param  mixed  $v    The value.
	 * @return string The formatted value.
	 * @since  0.1.0
	 */
	private function do_format($form, $v)
	{
		$fmt = $this->fmts[$form] ?? $this->get_format($form);
		try
		{
			return call_user_func($fmt['what'], $fmt['how'], $v);
		}
		catch (Exception $ex)
		{
			return self::FORMAT_FAIL;
		}
	}

	/**
	 * The width & height for image URLs.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private $wh;

	/**
	 * Format width & height values (%w and %h) of a local image file.
	 * A '%%' sequence is replaced by a single '%'.
	 *
	 * @param  array  $form The presplit format string.
	 * @param  mixed  $v    The local URL.
	 * @return string The formatted values.
	 * @since  0.1.0
	 */
	private function format_wh($format, $url)
	{
		$wh = $this->wh[$url] ?? null;
		if (!isset($wh)) # Not yet processed, do it now
		{
			$parts = wp_parse_url($url);
			if (($parts !== false) && !isset($parts['host']))
			{
				$dir = $_SERVER['DOCUMENT_ROOT'];
				# Dynamically sized images are not handled
				$res = getimagesize($dir . rawurldecode($parts['path']));
				if ($res !== false) $wh = [ 'w' => $res[0], 'h' => $res[1] ];
			}
			if (!isset($wh)) $wh = [ 'w' => '', 'h' => '' ]; # Default
			$wh['%'] = '%';
			$this->wh[$url] = $wh; # Remember
		}
		$parts = $format;
		$l = count($parts);
		for ($i = 1; $i < $l; $i += 2)
			$parts[$i] = $wh[$parts[$i]];
		return implode('', $parts);
	}

	/**
	 * Prepare a macro.
	 * Parameters are:
	 * : start delimiter;
	 * : end delimiter;
	 * : placeholders to be replaced;
	 * : "var=name" to take the macro's body from that variable.
	 * Everything but the start delimiter is optional.
	 * Due to the way shortcodes are parsed, all [macro]...[/macro] pairs
	 * must precede all the unpaired [macro var=...] codes.
	 * Due to WP paranoia, a delimiter cannot contain '<' characters without
	 * having a '>' character paired to each one ("<->" is allowed, but "<-"
	 * or "<<->>" get eaten).
	 *
	 * @param  array  $attrs   The attributes.
	 * @param  string $content The bracketed text, if any.
	 * @param  string $tag     The shortcode tag this is called with.
	 * @return string The text to substitute.
	 * @since  0.1.0
	 */
	public function macro($attrs = [], $content = null, $tag = '')
	{
		# The marker(s)
		$start = $stop = null;
		if (strlen($attrs[0] ?? '')) $start = $attrs[0];
		if (!isset($start)) return ''; # Fail
		if (strlen($attrs[1] ?? '')) $stop = $attrs[1];
		# Substitution placeholders
		$slots = [];
		for ($i = 2; isset($attrs[$i]); $i++)
			$slots[] = $attrs[$i];
		# Prepare the regex for substitution targets
		$split = null;
		if ($slots)
		{
			$arr = [];
			foreach ($slots as $v)
				if (strlen($v)) $arr[] = preg_quote($v, '/');
			if ($arr) $split = '/(' . implode('|', $arr) . ')/';
		}
		# The replacement
		$var = $attrs['var'] ?? null;
		$body = null;
		if (!isset($var)) $body = !isset($split) ? [ $content ] :
			preg_split($split, $content, -1, PREG_SPLIT_DELIM_CAPTURE);
		# Add to the collection
		$this->macros[$start] = [
			'start' => $start,
			'stop' => $stop,
			'body' => $body,
			'var' => $var,
			'split' => $split,
			'slots' => $slots,
			];

#catsite_vardump('Macros', $this->macros);
		return ''; # No text produced here
	}

	/**
	 * Prepare table parts in variables.
	 * Parameters are:
	 * : table name ("name=" or the first unnamed one, default 'table');
	 * : cell header line marker ("header=" or the next unnamed, default 'H: ');
	 * : cell content line marker ("value=" or the next, default 'V: ');
	 * : cell separator line marker ("next=" or the next, default '---');
	 * : stacked mode flag ("stack=", default false);
	 * : row break class ("break=", default empty);
	 * : cell class prefix ("class=", default 'c').
	 * Outside stacked mode, the assembled table header goes into the
	 * "{$name}_thead" variable, and an assembled row into the "{$name}_tr".
	 * In stacked mode, all the rows go into the "{$name}_rows" variable.
	 * When assembling rows outside stacked mode, if row break class isn't
	 * empty, an extra header cell with that class is added before each
	 * regular cell (empty in the header row, cell header text otherwise).
	 * Their purpose is to serve as row breaks or headers in responsive mode,
	 * when needed, and to remain hidden otherwise.
	 * In stacked mode, instead, a row is a header cell and a regular one.
	 *
	 * @param  array  $attrs   The attributes.
	 * @param  string $content The bracketed text, if any.
	 * @param  string $tag     The shortcode tag this is called with.
	 * @return string The text to substitute.
	 * @since  0.1.0
	 */
	public function tablemacro($attrs = [], $content = '', $tag = '')
	{
		$attrs = $this->fill_named($attrs, [ 'name', 'header', 'value', 'next' ]);
		$name = $attrs['name'] ?? 'table';
		$header = $attrs['header'] ?? 'H: ';
		$value = $attrs['value'] ?? 'V: ';
		$next = $attrs['next'] ?? '---';

		$stack = !empty($attrs['stack']);

		$break = $attrs['break'] ?? '';
		$class = $attrs['class'] ?? 'c';
		# Let's not use numbers as classes
		if ($break && is_numeric($break)) $break = 'break';

		$lheader = strlen($header);
		$lvalue = strlen($value);
		$lnext = strlen($next);

		$lines = explode("\n", $content);
		# Remove technical empty lines from both ends
		$line = array_pop($lines);
		if (!empty($line)) array_push($lines, $line);
		if ($lines && empty($lines[0])) array_shift($lines);
		# Add a break for the final cell
		array_push($lines, $next);

		# Now parse the lines
		$cells = [];
		$arr = [];
		$field = '';
		foreach ($lines as $line)
		{
			if (!strncmp($line, $header, $lheader))
			{
				$field = 'header';
				$arr[$field] = substr($line, $lheader);
			}
			elseif (!strncmp($line, $value, $lvalue))
			{
				unset($arr['']);
				$field = 'value';
				$arr[$field] = substr($line, $lvalue);
			}
			elseif (!strncmp($line, $next, $lnext))
			{
				if ($arr)
				{
					if (!isset($arr['value']))
						$arr['value'] = $arr[''] ?? '';
					unset($arr['']);
					array_push($cells, $arr);
				}
				$arr = [];
				$field = '';
			}
			elseif (isset($arr[$field])) $arr[$field] .= " " . $line;
			else $arr[$field] = $line;
		}
#catsite_vardump('Cells', $cells);

		# Now build the rows
		$head = $body = '<tr>';
		$rows = '';
		$l = count($cells);
		$i = 0;
		foreach ($cells as $arr)
		{
			$i += 1;
			$h = '<th class="' . $class . $i . '">' .
				($arr['header'] ?? '') . '</th>';
			$c = '<td class="' . $class . $i . '">' .
				($arr['value'] ?? '') . '</td>';
			if ($stack) # Stacked mode
			{
				$rows .= '<tr class="' . $class . $i . '">' . $h . $c . '</tr>';
				continue;
			}
			# Regular mode
			if ($break) # Insert cell headers/breakpoints if requested
			{
				$head .= '<th class="' . $break . ' ' . $class . $i . '"></th>';
				$body .= '<th class="' . $break . ' ' . $class . $i . '">' .
					($arr['header'] ?? '') . '</th>';
			}
			$head .= $h;
			$body .= $c;
		}
		$head .= "</tr>\n";
		$body .= "</tr>\n";
		$rows .= "\n";
#catsite_log("Head: '$head'\nBody: '$body'\nRows: '$rows'\n");

		# Now spit them out into variables
		if ($stack)
			$this->hash[$name . '_rows'] = $rows;
		else
		{
			$this->hash[$name . '_thead'] = "<thead>\n$head</thead>\n";
			$this->hash[$name . '_tr'] = $body;
		}

		return ''; # No text produced here
	}

	/**
	 * Expand macros.
	 * Unnamed parameters are: start delimiters of the macros to be expanded,
	 * or names of the variables to be inserted if no such macro exists.
	 * Optional named parameters are: "passes=N" to do the expansion more
	 * than once, "within=1" to take (some of) the used macro definitions
	 * from within the bracketed text (BEFORE expanding anything).
	 * If nothing is bracketed, will expand all the start delimiters given.
	 *
	 * @param  array  $attrs   The attributes.
	 * @param  string $content The bracketed text, if any.
	 * @param  string $tag     The shortcode tag this is called with.
	 * @return string The text to substitute.
	 * @since  0.1.0
	 */
	public function expand($attrs = [], $content = null, $tag = '')
	{
		# Passes
		$passes = $attrs['passes'] ?? 1;

		# Markers
		$starts = [];
		for ($i = 0; isset($attrs[$i]); $i++)
			if (strlen($attrs[$i])) $starts[] = $attrs[$i];
		if ($starts)
		{
			if (!isset($content)) $content = implode('', $starts);
			foreach ($starts as &$v)
			{
				$active[$v] = 1;
				$v = preg_quote($v, '/');
			}
			unset($v); # Play it safe
			$starts = '/(' . implode('|', $starts) . ')/';

			# If macro definitions are expected to be inside the
			# block, find them, compile them, and excise them
			if (!empty($attrs['within']))
			{
				$macro = $this->fullname['macro'];
				$re = '/' . get_shortcode_regex([ $macro ]) . '/';
				$n = preg_match_all($re, $content, $matches,
					PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
				$rep = [];
				if (!empty($n)) foreach ($matches as $mm)
				{
					# Let escaped macros be
					if (($mm[1][0] === '[') && ($mm[6][0] === '['))
						continue;
					$ma = shortcode_parse_atts($mm[3][0]);
					# Skip the macros we are not applying
					if (!isset($active[$ma[0] ?? null])) continue;

					# Load the found macro
					# !!! 'pre_do_shortcode_tag' and 'do_shortcode_tag'
					# are not run, because PREG_OFFSET_CAPTURE
					$v = $this->macro($ma, $mm[5][1] < 0 ? null : $mm[5][0], $macro);
					# What goes where
					$rep[] = [ $mm[1][0] . $v . $mm[6][0],
						$mm[0][1], strlen($mm[0][0]) ];
				}
				# Replace the compiled macros' text
				foreach(array_reverse($rep) as $mm)
					$content = substr_replace($content, $mm[0], $mm[1], $mm[2]);
			}
		}
		else $passes = 0; # Nothing to do

# FIXME Will have to drag in the variables when/if catsite_stock get support for them

		# Processing
		while ($passes-- > 0)
		{
			$parts = preg_split($starts, $content, -1,
				PREG_SPLIT_DELIM_CAPTURE);
			$n = count($parts);
			$res = [];
			for ($i = 0; $i < $n; $i++)
			{
				$key = $parts[$i];
				if (!($i & 1)) # Plaintext
				{
					$res[] = $key;
					continue;
				}
				$macro = $this->macros[$key] ?? null;
				if (!isset($macro)) # Variable
				{
					$res[] = $this->hash[$key] ?? '';
					continue;
				}
				$v = '';
				if (isset($macro['stop'])) # Can do substitution
				{
					$arr = explode($macro['stop'], $parts[$i + 1] ?? '', 2);
					if (!isset($arr[1])) # Ignore unclosed macros
					{
						$res[] = $key;
						continue;
					}
					$parts[$i + 1] = $arr[1]; # The outside part
					$v = $arr[0]; # The replacement list
				}
				$res[] = $this->expand_macro($macro, $v);
			}
			$content = implode('', $res);
		}
		return do_shortcode($content);
	}

	/**
	 * Expand the given macro.
	 *
	 * @param  array  $macro The macro description.
	 * @param  string $str   The string with substitution values.
	 * @return string The text to substitute.
	 * @since  0.1.0
	 */
	private function expand_macro($macro, $str)
	{
		# Prepare the replacement
		$parts = $macro['body'] ?? null;
		if (!isset($parts))
		{
			# Take the macro body from a variable
			$v = $this->hash[$macro['var']] ?? '';
			if (!strlen($v)) return ''; # Empty
			$parts = !isset($macro['split']) ? [ $v ] :
				preg_split($macro['split'], $v, -1, PREG_SPLIT_DELIM_CAPTURE);
		}
		$n = count($parts);
		if ($n < 2) return $parts[0]; # No substitutions to do
		$map = [];

		# Parse the substitutions string
		$dest = $macro['slots'];
		$re = '/"([^"]*)"(?!\S)|\'([^\']*)\'(?!\S)|(\S+)(?!\S)/s';
		$match = [];
		if ($dest && preg_match_all($re, $str, $match, PREG_SET_ORDER))
		{
			# Assemble the substitutions map
			foreach ($dest as $i => $key)
			{
				if (!isset($match[$i])) break;
				$map[$key] = strlen($match[$i][1]) ?
					# Double quotes interpret backslashes
					stripcslashes($match[$i][1]) :
					# Single quotes and barewords do not
					$match[$i][2] . $match[$i][3];
			}
		}

		# Do the substitution
		for ($i = 1; $i < $n; $i += 2)
			$parts[$i] = $map[$parts[$i]] ?? '';

		# Return the result
		return implode('', $parts);
	}

	/**
	 * Set variables.
	 * All attributes go into the variables hash.
	 * Bracketed text, UNEXPANDED, goes into the first bareword name.
	 * Due to the way shortcodes are parsed, all [vars]...[/vars] pairs
	 * must precede all the unpaired [vars var=...] codes.
	 *
	 * @param  array  $attrs   The attributes.
	 * @param  string $content The bracketed text, if any.
	 * @param  string $tag     The shortcode tag this is called with.
	 * @return string The text to substitute.
	 * @since  0.1.0
	 */
	public function vars($attrs = [], $content = null, $tag = '')
	{
		# Set one variable from bracketed text, NOT expanding shortcodes
		if (isset($attrs[0]) && isset($content))
			$this->hash[$attrs[0]] = $content;
		unset($attrs[0]);
		# Set other variables from name=value pairs
		$this->hash = $attrs + $this->hash;
		return '';
	}

	/**
	 * Fill a format string (bracketed, or first parameter) with values.
	 * In case of failure, the FORMAT_FAIL string constant is returned.
	 *
	 * @param  array  $attrs   The attributes.
	 * @param  string $content The bracketed text, if any.
	 * @param  string $tag     The shortcode tag this is called with.
	 * @return string The text to substitute.
	 * @since  0.1.0
	 */
	public function format($attrs = [], $content = null, $tag = '')
	{
		if (isset($content)) array_unshift($attrs, $content);
		$fmt = $attrs[0] ?? '';
		$fmt = $this->fmts[$fmt] ?? $this->get_format($fmt);
		$attrs[0] = $fmt['how'];
		try
		{
			return call_user_func_array($fmt['what'], $attrs);
		}
		catch (Exception $ex)
		{
			return self::FORMAT_FAIL;
		}
	}

	/**
	 * Build a SQL request for the fields listed.
	 * Parameters are: primary table ("table=" or the first unnamed one).
	 *
	 * @param  \array $vars  The fields in use.
	 * @param  array  $attrs The attributes.
	 * @return string The SQL request, or NULL if no need of it.
	 * @since  0.1.0
	 */
	private function table_sql(&$vars, $attrs = [])
	{
		/* Which table will be the root */
		$table = $attrs['table'] ?? $attrs[0] ?? null;
		if (!isset($this->fmap[$table]))
			$table = array_key_first($this->fmap); # Default

		/* Analyze the requested parts */
		$req = $this->scan_vars($vars, $table);
		if (!is_array($req) || (count($req) < 2)) return null; # Nothing hit the table

		/* Add whichever fields the WHERE condition needs */
		$wvars = $this->get_where_vars();
		$req = $this->scan_vars($wvars, $table, $req);

		# Build the SQL request

		# Produce the parts
		list($ids, $tables) = $this->join_tables($req);
#catsite_vardump("vars", $vars);
#catsite_vardump("ids", $ids);
#catsite_vardump("tables", $tables);

		# Assemble them together
		$rvars = [];
		foreach ($ids as $key => $id)
			if (isset($vars[$key])) $rvars[] = "$id AS `$vars[$key]`";
		return "SELECT " . implode(', ', $rvars) . implode('', $tables) .
			$this->get_where($ids) . ';';
	}

	/**
	 * Check a list of fields against table definitions.
	 *
	 * @param  \array $vars  The fields in use.
	 * @param  string $table The root table's name.
	 * @param  mixed  $req   A map of what else to request.
	 * @return mixed The updated (or not) request map.
	 * @since  0.1.0
	 */
	private function scan_vars(&$vars, $table, $req = 1)
	{
		foreach ($vars as $key => &$v)
		{
			# Check validity
			$r = explode('->', $key);
			$t = $table;
			foreach ($r as $field)
			{
				$p = $this->fmap[$t][$field] ?? null;
				if (!isset($p)) # Not a field
				{
					# Maybe an explicit tableref: field@table
					$p = strstr($field, '@', true);
					if (!isset($this->fmap[$t][$p]))
						continue 2; # Error: no valid field
					$p = substr($field, strlen($p) + 1);
					if (!isset($this->fmap[$p]))
						continue 2; # Error: no valid table
					$this->fmap[$t][$field] = $p; # Remember
				}
				$t = $p;
			}
			$v = implode('__', $r); # Map to field alias

			# Find out what to request
			$t = $table;
			$map = &$req;
			foreach ($r as $field)
			{
				if (!is_array($map)) $map = [ ' TABLE' => $t ];
				if (!isset($map[$field])) $map[$field] = 1;
				if ($this->fmap[$t][$field] === 1) break; # Value field
				# Reference field
				$map = &$map[$field];
				$t = $this->fmap[$t][$field];
			}
		}
		return $req;
	}

	/**
	 * Produce components of a SQL request.
	 *
	 * @param  \array $req The request map from ::scan_vars().
	 * @return array The var->id array, and the tables array (FROM & JOIN).
	 * @since  0.1.0
	 */
	private function join_tables($req)
	{
		$primary = $this->primary_key;
		$tidbase = '__t';
		$tidcount = 0;
		$newtid = $req[' TID'] = $tidbase . '0';
		$req[' BASE'] = '';
		$que = [ $req ];
		$ids = [];

		$sql = $this->fmap[$req[' TABLE']][' SQL'];
		$tables = [ " FROM $sql `$newtid`" ];
		foreach ($que as &$arr)
		{
			$tid = $arr[' TID'];
			$base = $arr[' BASE'];
			foreach ($arr as $field => $v)
			{
				if ($field[0] === ' ') continue; # Internal var
				$key = $base . $field;
				# Reduce explicit tablerefs to their fields
				$ident = strstr($field, '@', true);
				if ($ident === false) $ident = $field;
				$ident = "`$tid`.`$ident`";
				$ids[$key] = $ident; # Use in WHERE and anywhere
				if (!is_array($v)) continue; # Plain value
				$newtid = $v[' TID'] = $tidbase . ++$tidcount;
				$v[' BASE'] = $key . '->';
				$que[] = $v; # Queue for processing

				$sql = $this->fmap[$v[' TABLE']][' SQL'];
				$tables[] = " LEFT JOIN $sql `$newtid` ON $ident = `$newtid`.`$primary`";
			}
		}
		return [ $ids, $tables ];
	}

	/**
	 * Build a SQL request for: all the first letters of a field, to be
	 * interpolated as '_a'; per-letter counts as '_c'; per-letter equality
	 * with a test value as '_e' (all renameable).
	 * Parameters are: the table ("table=" or the first unnamed one), the
	 * base field ("field=" or the next unnamed), name to use in place of
	 * '_a' ("_a=" or the next unnamed), same for '_c' ("_c=" or the next
	 * unnamed), same for '_e' ("_e=" or the next). The test value is either
	 * the "test=" parameter, or the first letter of the "_key" setting.
	 *
	 * @param  \array $vars  The fields in use.
	 * @param  array  $attrs The attributes.
	 * @return string The SQL request, or NULL if no need of it.
	 * @since  0.1.0
	 */
	private function alphabet_sql(&$vars, $attrs = [])
	{
		$attrs = $this->fill_named($attrs, [ 'table', 'field', '_a', '_c', '_e' ]);

		/* Which table it will be */
		$table = $attrs['table'] ?? null;
		if (!isset($this->fmap[$table]))
			$table = array_key_first($this->fmap); # Default

		/* Process the field names */
		$field = $attrs['field'] ?? null;
		if (!isset($this->fmap[$table][$field])) $field = null; # No such thing
		$map = [];
		if (isset($field)) foreach (['_a', '_c', '_e'] as $key)
		{
			$v = $attrs[$key] ?? $key;
			if (!isset($vars[$v])) continue;
			$vars[$v] = $key; # The SQL aliases are fixed
			$map[$key] = $v; # To remember what we need
		}
		if (!$map) return null; # Nothing hit the table

		# Build the SQL request

		# Do we have a dedicated alphabet column?
		$pf = $this->letter1_prefix;
		$alph = !empty($pf) && isset($this->fmap[$table][$pf . $field]);

		# If a comparison is wanted, abuse a WHERE helper to generate it
		if (isset($map['_e']))
		{
			$prep = $this->letter1_sql_init(
				[ 'field' => $field, 'value' => $attrs['test'] ?? null ], '');
			$kk = [ $this->primary_key, $field ];
			if ($alph) $kk[] = $pf . $field;
			foreach ($kk as $key) $ids[$key] = "`$key`";
			$res = isset($prep) ? $this->letter1_sql($prep, $ids) : null;
		}

		# Produce the parts
		$order = "ORDER BY `_a`";
		$group = '';
		# If no alphabet column, use brute force
		$rvars = [ ($alph ? "`$pf$field`" : "LEFT(`$field`, 1)") . " AS `_a`" ];
		if (isset($map['_c'])) $rvars[] = "COUNT(*) AS `_c`";
		if (isset($map['_e'])) $rvars[] = ($res['WHERE'] ?? 'FALSE') . " AS `_e`";
		# If asked for nothing but the alphabet, can use DISTINCT
		# (never use with comparisons, they throw it into the slow path)
		if (!isset($rvars[1]))
			$req = "SELECT DISTINCT";
		# Otherwise, use grouping
		else
		{
			$req = "SELECT";
			$group = "GROUP BY `_a`";
		}

		# Assemble them together
		$sql = $this->fmap[$table][' SQL'];
		return $req . ' ' . implode(', ', $rvars) .
			" FROM $sql $group ORDER BY `_a` ASC;";
	}

	/**
	 * Build a SQL request for count or sum of the fields listed. By default
	 * '_c' is 'COUNT(*)', table field 'X' is 'SUM(X)', '_c:' prefixed table
	 * field '_c:X' is 'COUNT(X)'.
	 * Parameters are: the table ("table=" or the first unnamed one), the
	 * mode for unprefixed fields ("mode=" or the next unnamed) (either
	 * 'count' or 'sum'), the name/prefix to use in place of '_c' ("_c=" or
	 * the next unnamed).
	 *
	 * @param  \array $vars  The fields in use.
	 * @param  array  $attrs The attributes.
	 * @return string The SQL request, or NULL if no need of it.
	 * @since  0.1.0
	 */
	private function sum_sql(&$vars, $attrs = [])
	{
		$attrs = $this->fill_named($attrs, [ 'table', 'mode', '_c' ]);

		/* Which table it will be */
		$table = $attrs['table'] ?? null;
		if (!isset($this->fmap[$table]))
			$table = array_key_first($this->fmap); # Default

		/* Preprocess the prefixed field names into real ones */
		$cc = $attrs['_c'] ?? '_c';
		$pfx = $cc . ':';
		$l = strlen($pfx);
		$wrk = [];
		$xtra = [];
		foreach ($vars as $key => $v)
		{
			if ($key === $cc)
				$xtra[$key] = ''; # COUNT(*) is special
			elseif (!strncmp($key, $pfx, $l)) # Prefixed
			{
				if ($key === $pfx) continue; # Bad: no field name
				$vv = substr($key, $l);
				$wrk[$vv] = $v;
				$xtra[$key] = $vv; # COUNT($vv)
			}
			else $wrk[$key] = $v; # Regular
		}

		/* Analyze the requested parts */
		$req = $this->scan_vars($wrk, $table);
		if (!isset($vars[$cc]) && (!is_array($req) || (count($req) < 2)))
			return null; # Nothing hit the table

		/* Add whichever fields the WHERE condition needs */
		$wvars = $this->get_where_vars();
		$req = $this->scan_vars($wvars, $table, $req);

		# Build the SQL request

		# Produce the parts
		list($ids, $tables) = $this->join_tables($req);

		# Build the requests and export the columns
		$rvars = [];
		# The full count
		if (isset($xtra[$cc]))
		{
			$vars[$cc] = '_c'; # The SQL aliases are fixed
			$rvars[] = "COUNT(*) AS `_c`";
		}
		# The prefixed counts
		foreach ($xtra as $key => $key2)
			if (($key2 !== '') && isset($ids[$key2]))
			{
				$v = '_c_' . $wrk[$key2]; # The SQL prefixes are fixed
				$vars[$key] = $v;
				$rvars[] = "COUNT($ids[$key2]) AS `$v`";
			}
		# The default totals
		$fn = ($attrs['mode'] ?? '') === 'count' ? 'COUNT' : 'SUM';
		foreach ($ids as $key => $id)
			if (isset($vars[$key]) && !isset($xtra[$key]))
			{
				$v = $wrk[$key];
				$vars[$key] = $v;
				$rvars[] = "$fn($id) AS `$v`";
			}
#catsite_vardump('rvars:', $rvars);
#catsite_vardump('vars:', $vars);

		# Get the WHERE condition
		$res = !isset($this->is_where) ? [] :
			(call_user_func($this->is_where['sql'], $this->is_where, $ids) ?? []);
		$res = $res['WHERE'] ?? 'FALSE'; # To get nothing on failure

		# Assemble them together
		return "SELECT " . implode(', ', $rvars) . implode('', $tables) . " WHERE $res;";
	}

	/**
	 * Build a result array (from the current request's first row repeated,
	 * or from repeated nothing if no such row exists) for a set of indices.
	 * Parameters are: start index ("from=" or the first unnamed), end index
	 * (inclusive!) ("to=" or the 2nd unnamed), step ("step=" or the 3rd
	 * unnamed).
	 * In absence of ALL parameters, the variables '_from', '_to', and
	 * '_step' will be used.
	 *
	 * @param  \array $vars  The fields in use.
	 * @param  array  $attrs The attributes.
	 * @return array An array with specified indices.
	 * @since  0.1.0
	 */
	private function loop_arr(&$vars, $attrs = [])
	{
		$nn = [ 'from', 'to', 'step' ];
		$attrs = $this->fill_named($attrs, $nn, $nn);
		if (!isset($attrs['from']))
		{
			$from = $this->hash['_from'] ?? 1;
			$to = $this->hash['_to'] ?? 1;
			$step = $this->hash['_step'] ?? 1;
		}
		else
		{
			$from = $attrs['from'] ?? 1;
			$to = $attrs['to'] ?? 1;
			$step = $attrs['step'] ?? 1;
		}
		$from = intval($from);
		$to = intval($to);
		$step = intval($step) ?: 1;

		# Do nothing if wrong direction
		$dir = $step < 0 ? -1 : 1;
		if (($to - $from) * $dir < 0) return [];

		$l = intdiv($to - $from, $step) + 1;
		# Do not violate the page limit
		$lmax = $this->opts->def('per_page') ?? null;
		if (isset($lmax) && ($l > $lmax)) $l = $lmax;

		# Generate
		$res = $var = [];
		# Repeat the current request's first row if possible
		if (isset($this->results)) foreach ($this->results as $var) break;
		# Give field accessors a chance
		if ($var) foreach ($vars as $key => &$v)
			$v = str_replace('->', '__', $key);
		# Setup the array
		for ($i = 0; $i < $l; $i++) $res[$from + $step * $i] = $var;

		# Set variables
		$this->hash['_from'] = $from;
		$this->hash['_to'] = $to;
		$this->hash['_step'] = $step;

		return $res;
	}

	/**
	 * Build a oneshot result array (take the current request's first row,
	 * or nothing if no such row exists) using 0 for the index.
	 * No parameters.
	 *
	 * @param  \array $vars  The fields in use.
	 * @param  array  $attrs The attributes.
	 * @return array A oneshot array.
	 * @since  0.1.0
	 */
	private function once_arr(&$vars, $attrs = [])
	{
		# Generate
		$res = $var = [];
		# Use the current request's first row if possible
		if (isset($this->results)) foreach ($this->results as $var) break;
		# Give field accessors a chance
		if ($var) foreach ($vars as $key => &$v)
			$v = str_replace('->', '__', $key);
		# Setup the array
		$res[] = $var;

		# Set variables
		$this->hash['_from'] = $this->hash['_to'] = 0;
		$this->hash['_step'] = 1;

		return $res;
	}

	/**
	 * Fill string keys, if absent, from numeric keys in order, and renumber
	 * the numeric keys not used up.
	 *
	 * @param  array  $attrs   The original array.
	 * @param  array  $names   The string keys to fill.
	 * @return array The modified (or not) array.
	 * @since  0.1.0
	 */
	private function fill_named($attrs, $names, $vars = [])
	{
		$i = 0;
		foreach ($names as $key)
		{
			if (isset($attrs[$key]) || array_key_exists($key, $attrs))
				continue;
			if (isset($attrs[$i])) $attrs[$key] = $attrs[$i];
			unset($attrs[$i++]);
		}
		# Expand variables to their values
		foreach ($vars as $key)
		{
			if (!isset($attrs[$key])) continue;
			$v = $attrs[$key];
			# Should be a regular variable accessor
			if (strncmp($v, '${=', 3) ||
				!preg_match(self::$field_re, $v, $m) ||
				($m[1] === '')) continue;
			$v = $m[1];
			$r = $this->compiled[$v] ?? $this->compile_var($v, []);
			$attrs[$key] = $this->do_var($r, '', []);
		}
		return array_merge($attrs);
	}

	/**
	 * Remove whitespace around the '<>' markers, and the markers themselves
	 * from the text, *after* all the shortcodes inside have run.
	 * The marker is chosen for its being impossible in valid HTML.
	 *
	 * @param  array  $attrs   The attributes.
	 * @param  string $content The bracketed text, if any.
	 * @param  string $tag     The shortcode tag this is called with.
	 * @return string The modified (or not) text.
	 * @since  0.1.0
	 */
	public function unspace($attrs = [], $content = null, $tag = '')
	{
		if (isset($content))
			$content = preg_replace('/\s*<>\s*/', '', do_shortcode($content));
		return $content;
	}

	/**
	 * The WHERE condition (singular, for now).
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private $is_where;

	/**
	 * Set the WHERE condition for the [from_table]'s that follow.
	 *
	 * @param  array  $attrs   The attributes.
	 * @param  string $content The bracketed text, if any.
	 * @param  string $tag     The shortcode tag this is called with.
	 * @return string The modified (or not) text.
	 * @since  0.1.0
	 */
	public function set_where($attrs = [], $content = '', $tag = '')
	{
		if (!empty($content)) $content = do_shortcode($content);
		$res = call_user_func($this->sqler[$tag], $attrs, $content);
		$this->is_where = $res;
		# Set variables to exported values
		$this->hash = ($res['export'] ?? []) + $this->hash;
		return '';
	}

	/**
	 * Get an integer index value as specified by parameters.
	 * Parameters are: the value ("value=" or the first unnamed), the default
	 * source ("from=", defaults to "page" which means the URL part in
	 * '_page'; other allowed value is "key" which means the URL part in
	 * '_key', after removing its default '_' prefix).
	 * The value parameter is used if present, otherwise the default source
	 * is used, defaulting to 1 if absent.
	 *
	 * @param  array  $data  The shortcode attributes.
	 * @return int The value.
	 * @since  0.1.0
	 */
	private function get_idx($data)
	{
		$idx = $data['value'] ?? null;
		if (!isset($idx)) switch ($data['from'] ?? 'page')
		{
		case 'key':
			$idx = catsite_urldecode($this->opts->def('_key'), '_', '1'); break;
		case 'page':
			$idx = $this->opts->def('_page'); break;
		}
		return intval($idx ?? 1);
	}

	/**
	 * The WHERE conditions have the following 3 pseudo-methods.
	 *
	 * '*_init' - parse the input settings and prepare (ref in $sqler).
	 * @param  array  $data  The shortcode attributes.
	 * @param  string $data2 The bracketed contents (expanded).
	 * @return array The parsed settings for further calls, or NULL if failed.
	 *
	 * '*_vars' - list the fields used by the condition (ref in 'vars').
	 * @param  array  $data  The parsed settings from the init stage.
	 * @return array An array with field names as keys, '' as values.
	 *
	 * '*' - generate components of the condition (ref in 'sql' or 'paged').
	 * @param  array  $data  The parsed settings from the init stage.
	 * @param  array  $data2 An array mapping field names to their SQL.
	 * @return array An array with 'WHERE', 'ORDER BY', or with 'LIMIT', 'OFFSET'.
	 */

	/**
	 * Initialize the 'id = integer' WHERE condition.
	 * Parameters are: the value ("value=" or the first unnamed), the default
	 * source ("from="); see get_idx() for details.
	 *
	 * @param  array  $data  The shortcode attributes.
	 * @param  string $data2 The bracketed contents (expanded).
	 * @return array The parsed settings for further calls, or NULL if failed.
	 * @since  0.1.0
	 */
	private function id_sql_init($data, $data2)
	{
		$data = $this->fill_named($data, [ 'value' ]);
		$idx = $this->get_idx($data);

		# If anyone needs it
		$export = [ '_id' => $idx ];

		return [
			'value' => $idx,
			'export' => $export,

			'vars' => [ $this, 'id_sql_vars' ],
			'sql' => [ $this, 'id_sql' ],
			];
	}

	/**
	 * List the fields used by the 'id = integer' WHERE condition.
	 *
	 * @param  array  $data  The parsed settings from the init stage.
	 * @return array An array with field names as keys, '' as values.
	 * @since  0.1.0
	 */
	private function id_sql_vars($data)
	{
		return [ $this->primary_key => '' ];
	}

	/**
	 * Generate components of the 'id = integer' WHERE condition.
	 *
	 * @param  string $mode  'sql' - generate components of the condition.
	 * @param  array  $data  The parsed settings from the init stage.
	 * @param  array  $data2 An array mapping field names to their SQL.
	 * @return array An array with 'WHERE'.
	 * @since  0.1.0
	 */
	private function id_sql($data, $data2)
	{
		$primary = $data2[$this->primary_key] ?? null;
		if (!isset($primary)) return null; # Fail
		$idx = $data['value'];
		return [ 'WHERE' => "$primary = $idx" ];
	}

	/**
	 * Initialize the 'letter1_* = char' WHERE condition.
	 * Parameters are: the base column ("field=" or the first unnamed), the
	 * value ("value=" or the next unnamed, or the URL part in '_key',
	 * defaults to 'A').
	 *
	 * @param  array  $data  The shortcode attributes.
	 * @param  string $data2 The bracketed contents (expanded).
	 * @return array The parsed settings for further calls, or NULL if failed.
	 * @since  0.1.0
	 */
	private function letter1_sql_init($data, $data2)
	{
		$data = $this->fill_named($data, [ 'field', 'value' ]);

		# Field, for anything to be done at all
		$field = $data['field'];
		if (!isset($field)) return null; # Fail

		# First-char field, for it to be done faster
		$pf = $this->letter1_prefix ?? '';
		if (strlen($pf))
		{
			$p = strrpos($field, '->');
			$p = $p === false ? 0 : $p + 2;
			$pf = substr_replace($field, $pf, $p, 0);
		}

		# Letter, for comparison (empty string substitutes for NULL)
		$str = $data['value'] ?? catsite_urldecode($this->opts->def('_key'), '_', 'A');
		# !!! This runs after 'plugins_loaded', so
		# mb_internal_encoding() is already set by WP
		$str = mb_substr($str, 0, 1);

		# If anyone needs it
		$export = [
			'_letter1' => esc_html($str),
			'_letter1url' => rawurlencode($str),
			];

		return [
			'field' => $field,
			'field1' => $pf,
			'value' => $str,
			'export' => $export,

			'vars' => [ $this, 'letter1_sql_vars' ],
			'sql' => [ $this, 'letter1_sql' ],
			];
	}

	/**
	 * List the fields used by the 'letter1_* = char' WHERE condition.
	 *
	 * @param  array  $data  The parsed settings from the init stage.
	 * @return array An array with field names as keys, '' as values.
	 * @since  0.1.0
	 */
	private function letter1_sql_vars($data)
	{
		$res = [
			$this->primary_key => '', # For stable sorting
			$data['field'] => '', # For sorting at least
			];
		$f1 = $data['field1'];
		if (strlen($f1)) $res[$f1] = ''; # For request, if possible
		return $res;
	}

	/**
	 * Generate components of the 'letter1_* = char' WHERE condition.
	 *
	 * @param  array  $data  The parsed settings from the init stage.
	 * @param  array  $data2 An array mapping field names to their SQL.
	 * @return array An array with 'WHERE', 'ORDER BY'.
	 * @since  0.1.0
	 */
	private function letter1_sql($data, $data2)
	{
		global $wpdb;

		$primary = $data2[$this->primary_key] ?? null;
		$field = $data2[$data['field']] ?? null;
		if (!isset($primary) || !isset($field)) return null; # Fail

		$f1 = $data2[$data['field1']] ?? null;
		$v = $data['value'];
		$res = [];
		if ($v === '') # Comparing with NULL
		{
			$res['WHERE'] = ($f1 ?? $field) . " IS NULL";
		}
		elseif (isset($f1)) # Using alphabet field
		{
			$v = esc_sql($v);
			$res['WHERE'] = "$f1 = '$v'";
		}
		else # Using LIKE on full field
		{
			$v = esc_sql($wpdb->esc_like($v) . '%');
			$res['WHERE'] = "$field LIKE '$v'";
		}

		# Sort by full field, then by ID
		$res['ORDER BY'] = "$field, $primary ASC";

		# Done
		return $res;
	}

	/**
	 * Initialize the 'x = this.x' WHERE condition.
	 * The fields to be matched are listed in the shortcode's body.
	 * Parameters are: the sort column ("sort=" or the first unnamed),
	 * the acceptability of NULLs ("nulls=1" if yes).
	 *
	 * @param  array  $data  The shortcode attributes.
	 * @param  string $data2 The bracketed contents (expanded).
	 * @return array The parsed settings for further calls, or NULL if failed.
	 * @since  0.1.0
	 */
	private function match_sql_init($data, $data2)
	{
		# Fields, for anything to be done at all
		$parts = preg_split("/\s+/", $data2, -1, PREG_SPLIT_NO_EMPTY);
		if (empty($parts)) return null; # Fail

		$sort = $data['sort'] ?? $data[0] ?? null;
		$nulls = !empty($data['nulls']);

		# Fields' values at this location
		$arr = $this->results[$this->hash['_idx'] ?? 0] ?? [];
		$pp = $parts;
		$pp[] = $this->primary_key; # This ID
		foreach ($pp as $key)
		{
			$v = str_replace('->', '__', $key);
			$v = $arr[$v] ?? null;
			if (!isset($v) && !$nulls) return null; # Fail
			$vv[$key] = $v;
		}

		return [
			'fields' => $parts,
			'values' => $vv,
			'sort' => $sort,

			'vars' => [ $this, 'match_sql_vars' ],
			'sql' => [ $this, 'match_sql' ],
			];
	}

	/**
	 * List the fields used by the 'x = this.x' WHERE condition.
	 *
	 * @param  array  $data  The parsed settings from the init stage.
	 * @return array An array with field names as keys, '' as values.
	 * @since  0.1.0
	 */
	private function match_sql_vars($data)
	{
		$res = [ $this->primary_key => '' ]; # For exclusion & stable sorting
		if (isset($data['sort'])) $res[$data['sort']] = ''; # For sorting
		foreach ($data['fields'] as $key) $res[$key] = ''; # For comparisons
		return $res;
	}

	/**
	 * Generate components of the 'x = this.x' WHERE condition.
	 *
	 * @param  array  $data  The parsed settings from the init stage.
	 * @param  array  $data2 An array mapping field names to their SQL.
	 * @return array An array with 'WHERE', 'ORDER BY'.
	 * @since  0.1.0
	 */
	private function match_sql($data, $data2)
	{
		$fields = $data['fields'];
		$values = $data['values'];

		$cmp = [];
		foreach ($fields as $key)
		{
			$field = $data2[$key] ?? null;
			if (!isset($field)) return null; # Fail
			$v = $values[$key];
			if (isset($v))
			{ 
				$v = esc_sql($v);
				$cmp[] = "$field = '$v'";
			}
			else $cmp[] = "$field IS NULL";
		}
		# Exclude the match source
		$primary = $data2[$this->primary_key] ?? null;
		if (!isset($primary)) return null; # Fail
		if (isset($values[$this->primary_key]))
		{
			$v = esc_sql($values[$this->primary_key]); # Paranoia
			$cmp[] = "$primary <> '$v'";
		}
		$res = [ 'WHERE' => implode(' AND ', $cmp) ];

		# Sort by specified field, then by ID
		$ord = [];
		$sort = $data['sort'] ?? null;
		if (isset($sort))
		{
			if (!isset($data2[$sort])) return null; # Fail
			$ord[] = $data2[$sort];
		}
		$ord[] = $primary;
		$res['ORDER BY'] = implode(', ', $ord) . ' ASC';

		# Done
		return $res;
	}

	/**
	 * Initialize the 'field = integer' WHERE condition.
	 * Parameters are: the base column ("field=" or the first unnamed), the
	 * value ("value=" or the next unnamed), the sort column ("sort=" or the
	 * next unnamed), the default source ("from="); see get_idx() for details.
	 *
	 * @param  array  $data  The shortcode attributes.
	 * @param  string $data2 The bracketed contents (expanded).
	 * @return array The parsed settings for further calls, or NULL if failed.
	 * @since  0.1.0
	 */
	private function key_sql_init($data, $data2)
	{
		$data = $this->fill_named($data, [ 'field', 'value', 'sort' ]);

		# Field, for anything to be done at all
		$field = $data['field'];
		if (!isset($field)) return null; # Fail

		$sort = $data['sort'] ?? null;

		$idx = $this->get_idx($data);

		# If anyone needs it
		$export = [ '_key' => $idx ];

		return [
			'field' => $field,
			'value' => $idx,
			'sort' => $sort,
			'export' => $export,

			'vars' => [ $this, 'key_sql_vars' ],
			'sql' => [ $this, 'key_sql' ],
			];
	}

	/**
	 * List the fields used by the 'field = integer' WHERE condition.
	 *
	 * @param  array  $data  The parsed settings from the init stage.
	 * @return array An array with field names as keys, '' as values.
	 * @since  0.1.0
	 */
	private function key_sql_vars($data)
	{
		$res = [ $this->primary_key => '', # For stable sorting
			$data['field'] => '' ];
		if (isset($data['sort'])) $res[$data['sort']] = ''; # For sorting
		return $res;
	}

	/**
	 * Generate components of the 'field = integer' WHERE condition.
	 *
	 * @param  array  $data  The parsed settings from the init stage.
	 * @param  array  $data2 An array mapping field names to their SQL.
	 * @return array An array with 'WHERE', 'ORDER BY'.
	 * @since  0.1.0
	 */
	private function key_sql($data, $data2)
	{
		$primary = $data2[$this->primary_key] ?? null;
		$field = $data2[$data['field']] ?? null;
		if (!isset($primary) || !isset($field)) return null; # Fail

		$idx = $data['value'];
		$res = [ 'WHERE' => "$field = $idx" ];

		# Sort by specified field, then by ID
		$ord = [];
		$sort = $data['sort'] ?? null;
		if (isset($sort))
		{
			if (!isset($data2[$sort])) return null; # Fail
			$ord[] = $data2[$sort];
		}
		$ord[] = $primary;
		$res['ORDER BY'] = implode(', ', $ord) . ' ASC';

		# Done
		return $res;
	}

	/**
	 * Initialize paging for the current WHERE condition.
	 * Parameters are: the page step (records per page, zero or negative
	 * means all of them) ("step=" or the first unnamed, defaults to the
	 * 'per_page' option), the page number ("page=" or the next unnamed, or
	 * the URL part in '_page', defaults to 1).
	 *
	 * @param  array  $data  The shortcode attributes.
	 * @param  string $data2 The bracketed contents (expanded).
	 * @return array The parsed settings for further calls, or NULL if failed.
	 * @since  0.1.0
	 */
	private function paged_sql_init($data, $data2)
	{
		$data = $this->fill_named($data, [ 'step', 'page' ]);

		# Page step, for LIMIT (0 means no limit)
		$step = $data['step'] ?? $this->opts->def('per_page');
		$step = empty($step) ? 0 : intval($step);
		if ($step < 0) $step = 0;

		# Page number, for OFFSET
		$page = 1;
		$ofs = 0;
		if ($step > 0)
		{
			$page_max = intdiv(PHP_INT_MAX, $step);
			$page = intval($data['page'] ??
				$this->opts->def('_page') ?? 1);
			if ($page < 1) $page = 1;
			if ($page > $page_max) $page = $page_max;
			$ofs = $step * ($page - 1);
		}

		$res = $this->is_where; # Guaranteed not empty
		$export = $res['export'] ?? [];

		# If anyone needs it
		$export['_limit'] = $step;
		$export['_page'] = $page;
		$export['_offset'] = $ofs;

		$res['limit'] = $step;
		$res['page'] = $page;
		$res['export'] = $export;
		$res['paged'] = [ $this, 'paged_sql' ];

		return $res;
	}

	/**
	 * Generate paging components of the current WHERE condition.
	 *
	 * @param  array  $data  The parsed settings from the init stage.
	 * @param  array  $data2 An array mapping field names to their SQL.
	 * @return array An array with 'LIMIT', 'OFFSET'.
	 * @since  0.1.0
	 */
	private function paged_sql($data, $data2)
	{
		$res = [];
		# Paginate if necessary
		$n = $data['limit'];
		if ($n)
		{
			$res['LIMIT'] = "$n";
			$n *= $data['page'] - 1;
			if ($n) $res['OFFSET'] = "$n";
		}
		return $res;
	}

	/**
	 * List the fields used by the WHERE condition expression.
	 *
	 * @return array Hash where fields are keys, and '' are values.
	 * @since  0.1.0
	 */
	private function get_where_vars()
	{
		return !isset($this->is_where) ? [] :
			call_user_func($this->is_where['vars'], $this->is_where);
	}

	/**
	 * Compile the WHERE condition expression.
	 *
	 * @param  array $wvars Hash mapping fields to proper IDs.
	 * @return string The expression.
	 * @since  0.1.0
	 */
	private function get_where($wvars)
	{
		foreach ($wvars as $key => $v)
			if ($v === '') unset($wvars[$key]); # For simpler tests

		$res = !isset($this->is_where) ? null :
			call_user_func($this->is_where['sql'], $this->is_where, $wvars);
		if (!isset($res)) return ' WHERE FALSE'; # To get nothing on failure

		# Paging
		if (isset($this->is_where['paged']))
			$res = call_user_func($this->is_where['paged'], $this->is_where, $wvars) + $res;

		$sql = [ '' ];
		foreach([ 'WHERE', 'ORDER BY', 'LIMIT', 'OFFSET' ] as $key)
		{
			if (!isset($res[$key])) continue;
			$sql[] = $key;
			$sql[] = $res[$key];
		}
		return implode(' ', $sql);
	}

	/**
	 * Create or add to a text map (for translation or anything else).
	 * Parameters are: map name ("name=" or the first unnamed, defaults to
	 * an empty string), item separator ("sep1=" or the next unnamed,
	 * defaults to ' => ' ), pair separator ("sep2=" or the next, defaults
	 * to a newline).
	 * Due to WP paranoia, a separator cannot contain '<' characters without
	 * having a '>' character paired to each one ("<->" is allowed, but "<-"
	 * or "<<->>" get eaten).
	 * An absent name is perfectly valid and defaults to an empty string.
	 * Bracketed text, UNEXPANDED, is split on sep2, each part split again
	 * on their first sep1, any parts without sep1 in them dropped, and the
	 * pairs become indices and their values in the map array.
	 *
	 * @param  array  $attrs   The attributes.
	 * @param  string $content The bracketed text.
	 * @param  string $tag     The shortcode tag this is called with.
	 * @return string The text to substitute.
	 * @since  0.1.0
	 */
	public function textmap($attrs = [], $content = '', $tag = '')
	{
		$attrs = $this->fill_named($attrs, [ 'name', 'sep1', 'sep2' ]);
		$name = $attrs['name'] ?? '';
		if (!isset($this->tmaps[$name])) $this->tmaps[$name] = [];
		$map = &$this->tmaps[$name];
		$sep1 = $attrs['sep1'] ?? ' => ';
		$sep2 = $attrs['sep2'] ?? "\n";
		$l = strlen($sep1);
		$rows = explode($sep2, $content);
		foreach ($rows as $v)
		{
			$b = strstr($v, $sep1, true);
			if ($b === false) continue;
			$map[$b] = substr($v, strlen($b) + $l);
		}
		return '';
	}

	/**
	 * Use a textmap to replace bracketed parts of text. Bracketed parts not
	 * in the textmap are left unchanged, with their brackets removed.
	 * Unpaired brackets are left alone. If several opening brackets precede
	 * a closing one, it is the last one that is paired to it.
	 * Parameters are: map name ("name=" or the first unnamed, defaults to
	 * an empty string), left bracket ("left=" or the next unnamed, defaults
	 * to '_(' ), right bracket ("right=" or the next, defaults to ')' ).
	 * Due to WP paranoia, a bracket cannot contain '<' characters without
	 * having a '>' character paired to each one ("<->" is allowed, but "<-"
	 * or "<<->>" get eaten).
	 * An absent name is perfectly valid and defaults to an empty string.
	 *
	 * @param  array  $attrs   The attributes.
	 * @param  string $content The bracketed text.
	 * @param  string $tag     The shortcode tag this is called with.
	 * @return string The text to substitute.
	 * @since  0.1.0
	 */
	public function remap($attrs = [], $content = null, $tag = '')
	{
		if (!isset($content)) return $content; # Nothing to do
		$attrs = $this->fill_named($attrs, [ 'name', 'left', 'right' ]);
		$name = $attrs['name'] ?? '';
		$map = $this->tmaps[$name] ?? [];
		$left = $attrs['left'] ?? '_(';
		$right = $attrs['right'] ?? ')';
		$re = '/(' . preg_quote($left, '/') . '|' . preg_quote($right, '/') . ')/';
		$parts = preg_split($re, $content, -1, PREG_SPLIT_DELIM_CAPTURE);
		$n = count($parts);
		for ($i = 0; $i < $n; $i++)
		{
			$key = $parts[$i];
			if (($i & 1) && ($n > $i + 2) &&
				($key === $left) && ($parts[$i + 2] === $right))
			{
				$key = $parts[$i + 1];
				$key = $map[$key] ?? $key;
				$i += 2;
			}
			$res[] = $key;
		}
		$content = implode('', $res);
		return do_shortcode($content);
	}

	/**
	 * Data for converting EMS codes.
	 *
	 * @var array
	 * @since  0.1.0
	 */
	const emsparts = [
		'01' => [ 'white', 'van' ],
		'02' => [ 'white', 'harlequin' ],
		'03' => [ 'white', 'bicolor' ],
		'09' => [ 'white', 'with white' ],
		'11' => [ 'tips', 'shaded' ],
		'12' => [ 'tips', 'tipped' ],
		'21' => [ 'tabby', 'tabby' ],
		'22' => [ 'tabby', 'classic tabby' ],
		'23' => [ 'tabby', 'mackerel tabby' ],
		'24' => [ 'tabby', 'spotted tabby' ],
		'33' => [ 'point', 'point' ],
		'61' => [ 'eyes', 'blue' ],
		'62' => [ 'eyes', 'orange' ],
		'63' => [ 'eyes', 'odd' ],
		'64' => [ 'eyes', 'green' ],
		's' => [ 'metal', 'silver', 'smoke', 'smoke' ],
		'y' => [ 'metal', 'golden' ],
		'u' => [ 'sun', 'sunshine' ],
		'a' => [ 'base', 'blue' ],
		'c' => [ 'base', 'lilac' ],
		'd' => [ 'base', 'red' ],
		'e' => [ 'base', 'cream' ],
		'f' => [ 'base', 'black', 'tortie', 'tortie', 'seal', 'seal' ],
		'g' => [ 'base', 'blue', 'tortie', 'tortie' ],
		'n' => [ 'base', 'black', 'seal', 'seal' ],
		'w' => [ 'base', 'white' ],
	];
	const colororder = [
		'base', 'sun', 'metal', 'tips', 'tortie', 'tabby', 'point', 'white', 'eyes',
		];

	/**
	 * Convert an EMS cat color code to English text.
	 *
	 * @param string $code The code.
	 * @return string The text ('???' if unable to convert).
	 * @since  0.1.0
	 */
	private function emscolor($code)
	{
		# Split the code into tokens
		$cp = preg_split('/\s+/', $code);
		$cc = str_split(array_shift($cp));
		# Turn the tokens into flags
		$h = [];
		foreach (array_merge($cc, $cp) as $key)
		{
			$a = self::emsparts[$key] ?? null;
			if (!isset($a)) return '???'; # Unsupported token in EMS code
			for ($i = 0; isset($a[$i]); $i += 2)
				$h[$a[$i]] = $a[$i + 1];
		}
		# Only tabbies can be silver, others are smokey
		if (!($h['tabby'] ?? $h['tips'] ?? false))
			$h['metal'] = $h['smoke'] ?? $h['metal'] ?? null;
		# In colorpoints the black is seal
		if ($h['point'] ?? false)
			$h['base'] = $h['seal'] ?? $h['base'];
		# Eye color gets a proper phrase
		if ($h['eyes'] ?? false)
			$h['eyes'] = "with $h[eyes] eyes";
		# Assemble the string
		$a = [];
		foreach (self::colororder as $v)
			if (isset($h[$v])) $a[] = $h[$v];
		return implode(' ', $a);
	}


}
