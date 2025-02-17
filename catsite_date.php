<?php

/**
 * catsite WordPress plugin.
 * DB date's formatter, GNU strftime() workalike.
 * https://www.gnu.org/software/libc/manual/html_node/Formatting-Calendar-Time.html
 *
 * @author  wjaguar <https://github.com/wjaguar>
 * @version 0.9.0
 * @package catsite
 */

class CatsiteDate
{

	/**
	 * Commands for date value processing.
	 *
	 * @var   int
	 * @since 0.1.0
	 */
	const cCalc = 0;
	const cChar = 1;
	const cLoc = 2;
	const cFormat = 3;

	const cFetch = 4;
	const cUpcase = 5;
	const cPad = 6;
	const cEmit = 7;


	const cWd0 = 8;
	const cMonth0 = 9;
	const cCentury = 10;
	const cDay = 11;

	const cMonth = 12;
	const cY = 13;
	const cYd100 = 14;
	const cYd = 15;

	const cNday = 16;
	const cWd1 = 17;
	const cWeek = 18;
	const cIso = 19;

	const cWeekM = 20;
	const cY100 = 21;

	/**
	 * The compiled value processing sequences.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private static $scripts = null;

	/**
	 * Format a DB date.
	 *
	 * @param  string $form The format string.
	 * @param  string $v The DB date (YYYY-MM-DD).
	 * @return string The result string.
	 * @since  0.1.0
	 */
	public static function format_date($form, $v)
	{
		if (!isset($v)) return ''; # To simplify NULL handling

		$sc = self::$scripts[$form] ?? self::compile_date_format($form);

		return self::do_date_format($sc, $v);
	}

	/**
	 * Compile and store a processing sequence to format a date.
	 *
	 * @param  string $form The format string.
	 * @return array The compiled sequence.
	 * @since  0.1.0
	 */
	private static function compile_date_format($form)
	{
		$r = [];
		$calc = false;

		# Time-related formats are ignored: cHIklMpPrRsSTXzZ
		# The modifiers are checked, but only 'Ob' and 'OB' do anything
		$re = '/%((?>[_#^0-]?))(\d*)' .
			'(E(?=[CxyY])|O(?=[demuUVwWy])|)([%aAbBCdDeFgGhjmntuUVwWxyY])/';

		$parts = preg_split($re, $form, -1, PREG_SPLIT_DELIM_CAPTURE);
		# Emit the leading plaintext span if any
		if (strlen($parts[0])) $r = [ self::cEmit, $parts[0] ];
		# Compile the format directives that follow
		$n = count($parts);
		for ($i = 1; $i < $n; $i += 5)
		{
			$s = $pad = $loc = '';
			$l = 2; # Default
			$mod = $parts[$i + 2];
			switch ($parts[$i + 3])
			{
			case '%': $s = "%"; $l = 1; break;
			case 'a': $s = self::cWd0; $loc = 'abday'; break;
			case 'A': $s = self::cWd0; $loc = 'day'; break;
			case 'h':
			case 'b':
				$s = self::cMonth0;
				$loc = $mod === 'O' ? 'ab_alt_mon' : 'abmon';
				break;
			case 'B':
				$s = self::cMonth0;
				$loc = $mod === 'O' ? 'alt_mon' : 'mon';
				break;
			case 'C': $s = self::cCentury; $l = 1; break;
			case 'd': $s = self::cDay; $pad = '0'; break;
			case 'D':
				$r[] = self::cFormat;
				$r[] = '%m/%d/%y';
				$l = 1;
				$s = null;
				break;
			case 'e': $s = self::cDay; $pad = ' '; break;
			case 'F':
				$r[] = self::cFormat;
				$r[] = '%Y-%m-%d';
				$l = 1;
				$s = null;
				break;
			case 'g': $s = self::cYd100; $pad = '0'; break;
			case 'G': $s = self::cYd; $l = 1; break;
			case 'j': $s = self::cNday; $pad = '0'; $l = 3; break;
			case 'm': $s = self::cMonth; $pad = '0'; break;
			case 'n': $s = "\n"; $l = 1; break;
			case 't': $s = "\t"; $l = 1; break;
			case 'u': $s = self::cWd1; $l = 1; break;
			case 'U': $s = self::cWeek; $pad = '0'; break;
			case 'V': $s = self::cIso; $pad = '0'; break;
			case 'w': $s = self::cWd0; $l = 1; break;
			case 'W': $s = self::cWeekM; $pad = '0'; break;
			case 'x':
				$r[] = self::cLoc;
				$r[] = 'd_fmt';
				$r[] = '%d.%m.%Y'; # Default format
				$r[] = self::cFormat;
				$r[] = self::cLoc;
				$s = null;
				break;
			case 'y': $s = self::cY100; $pad = '0'; break;
			case 'Y': $s = self::cY; $l = 1; break;
			}
			$upcase = $parts[$i] === '^';
			# Locale-dependent string
			if (!empty($loc))
			{
				$r[] = self::cLoc;
				$r[] = $loc;
				$r[] = []; # Default empty array
				$r[] = $s;
				$calc = true;
				$r[] = self::cFetch;
				if ($parts[$i] === '#') $upcase = true; # Only for these
				$l = 1;
			}
			else if (is_string($s)) # Character constant
			{
				$r[] = self::cChar;
				$r[] = $s;
			}
			else if (isset($s)) # Some numeric value
			{
				$r[] = $s;
				$calc = true;
			}
			# Uppercase
			if ($upcase) $r[] = self::cUpcase;
			# Padding flags
			switch ($parts[$i])
			{
			case '_': $pad = ' '; break;
			case '-': $pad = ''; break;
			case '0': $pad = '0'; break;
			}
			# Field width *IN BYTES!*
			$l2 = intval($parts[$i + 1]);
			if ($l < $l2) $l = $l2;
			# Add padding
			if ($pad !== '')
			{
				$r[] = self::cPad;
				$r[] = $pad;
				$r[] = $l;
			}
			# Emit the result, then the plaintext span that follows
			$r[] = self::cEmit;
			$r[] = $parts[$i + 4];
		}
		# Precalculate the date parts IF they get used
		if ($calc) array_unshift($r, self::cCalc);

		self::$scripts[$form] = $r;
		return $r;
	}

	/**
	 * The C locale LC_TIME category, because the PHP standard library is useless.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	const c_lc_time = [
		'abday'	=> [ "Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat" ],
		'day'	=> [ "Sunday", "Monday", "Tuesday", "Wednesday", "Thursday",
				"Friday", "Saturday" ],
		'abmon'	=> [ "Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep",
				"Oct", "Nov", "Dec" ],
		'mon'	=> [ "January", "February", "March", "April", "May", "June", "July",
				"August", "September", "October", "November", "December" ],
		'am_pm'	=> [ "AM", "PM" ],
		'd_t_fmt'	=> "%a %b %e %H:%M:%S %Y",
		'd_fmt'		=> "%m/%d/%y",
		't_fmt'		=> "%H:%M:%S",
		't_fmt_ampm'	=> "%I:%M:%S %p",
		'era'		=> null,
		'era_year'	=> "",
		'era_d_fmt'	=> "",
		'alt_digits'	=> null,
		'era_d_t_fmt'	=> "",
		'era_t_fmt'	=> "",
		'time-era-num-entries'	=> 0,
		'time-era-entries'	=> "",
		'week-ndays'	=> 7,
		'week-1stday'	=> 19971130,
		'week-1stweek'	=> 4,
		'first_weekday'	=> 1,
		'first_workday'	=> 2,
		'cal_direction'	=> 1,
		'timezone'	=> "",
		'date_fmt'	=> "%a %b %e %H:%M:%S %Z %Y",
		'time-codeset'	=> "ANSI_X3.4-1968",
		'alt_mon'	=> [ "January", "February", "March", "April", "May",
					"June", "July", "August", "September", "October",
					"November", "December" ],
		'ab_alt_mon'	=> [ "Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul",
					"Aug", "Sep", "Oct", "Nov", "Dec"],
	];

	/**
	 * The selected locale's LC_TIME category.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	private static $locale;

	/**
	 * Request the locale from whatever.
	 *
	 * @return void
	 * @since  0.1.0
	 */
	public static function set_locale()
	{
		self::$locale = apply_filters('catsite_fetch_locale', self::c_lc_time, 'LC_TIME');
	}

	/**
	 * Day one of a regular year's months.
	 *
	 * @var   array
	 * @since 0.1.0
	 */
	const day1 = [ 0, 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 ];

	/**
	 * Execute a compiled date formatting sequence.
	 *
	 * @param  array  $r The sequence.
	 * @param  string $v The DB date (YYYY-MM-DD).
	 * @return string The result string.
	 * @since  0.1.0
	 */
	private static function do_date_format($r, $v)
	{
		$res = '';
		$s = '';
		$i = 0;
		while (isset($r[$i]))
		{
		$uu = $r[$i++];
		switch ($uu)
		{
		case self::cCalc:
			$z = explode('-', $v, 3);
			$year = $z[0] ?? 0;
			$month = $z[1] ?? 0;
			$day = $z[2] ?? 0;

			# The day of the week for Jan 1 (Monday is 0)
			$ym1 = $year - 1;
			$wd0 = (5 * ($ym1 % 4) + 4 * ($ym1 % 100) + 6 * ($ym1 % 400)) % 7;

			# Has a leap day
			$leap = !($year % 4) ^ !($year % 100) ^ !($year % 400);

			# Day number (from 1)
			$nday = (self::day1[$month] ?? 0) + (int)($leap && ($month > 2)) + $day;

			# Weekday (Monday is 0)
			$wd = ($wd0 + $nday - 1) % 7;

			# ISO week
			$dy = 0;
			$iso = intdiv(($wd0 + 3) % 7 + 3 + $nday, 7);
			if (!$iso) $dy = -1; # Week 0 is previous year
			else if (($iso >= 53) && # Week 53 may be next year
				($wd < 3) && ($nday + (3 - $wd) > 365 + (int)$leap)) $dy = 1;
			break;
		case self::cChar:
			$s = $r[$i++]; break;
		case self::cLoc:
			if (!isset(self::$locale)) self::set_locale();

			$loc = $r[$i++];
			$def = $r[$i++];
			$loc = self::$locale[$loc] ?? $def;
			break;
		case self::cFormat:
			$form = $r[$i++];
			if ($form === self::cLoc) $form = $loc;
			$sc = self::$scripts[$form] ?? self::compile_date_format($form);
			$s = self::do_date_format($sc, $v);
			break;
		case self::cFetch:
			$s = $loc[$s] ?? '???'; break;
		case self::cUpcase:
			$s = mb_strtoupper((string)$s, 'UTF-8'); break;
		case self::cPad:
			$pad = $r[$i++];
			$l = $r[$i++];
			$s = str_pad((string)$s, $l, $pad, STR_PAD_LEFT);
			break;
		case self::cEmit:
			# Joining strings this way is a little bit faster, than
			# all at once with implode()
			$res .= (string)$s . $r[$i++];
			break;
		case self::cWd0:
			$s = ($wd + 1) % 7; break;
		case self::cMonth0:
			$s = $month - 1; break;
		case self::cCentury:
			$s = intdiv($year, 100); break;
		case self::cDay:
			$s = $day; break;
		case self::cMonth:
			$s = $month; break;
		case self::cY:
			$s = $year; break;
		case self::cYd100:
			$s = ($year + $dy) % 100; break;
		case self::cYd:
			$s = $year + $dy; break;
		case self::cNday:
			$s = $nday; break;
		case self::cWd1:
			$s = $wd + 1; break;
		case self::cWeek:
			$s = intdiv($wd0 + $nday, 7); break;
		case self::cIso:
			if (!$iso) # Recalculate the ISO week in the previous year
			{
				$s = $year - 1;
				$s = 365 + (int)(!($s % 4) ^ !($s % 100) ^ !($s % 400)); # Length
				$iso = intdiv(($wd0 - $s + 364 + 7 + 3) % 7 + 3 + $s + $nday, 7);
			}
			else if ($dy > 0) $iso = 1; # The first ISO week of the next year
			$s = $iso;
			break;
		case self::cWeekM:
			$s = intdiv(($wd0 + 6) % 7 + $nday, 7); break;
		case self::cY100:
			$s = $year % 100; break;
		}
		}
		return $res;
	}

}
