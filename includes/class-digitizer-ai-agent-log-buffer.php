<?php
/**
 * Agent Log module - what this request changed, gathered before it is written.
 *
 * A single REST call that updates a post fires save_post once and the meta
 * hooks once per key. Writing a row per hook would turn one edit into nine
 * rows and make the log something you reassemble by eye rather than read. So
 * changes accumulate here, keyed by object, and the request writes once.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Digitizer_AI_Agent_Log_Buffer {

	const DEFAULT_MAX_AGE_DAYS = 30;
	const DEFAULT_MAX_ROWS     = 20000;

	/** @var array */
	private static $pending = array();

	/**
	 * How strongly an action describes what happened to an object, when more
	 * than one reached the buffer in one request. An update is a fact about
	 * an object's contents, the weakest claim. Activation, deactivation and
	 * switching are facts about its state - the same class of fact as
	 * creation and deletion, which are facts about its existence - so all
	 * five outrank a mere update. An action this table does not know about
	 * ranks 0, below even "updated", so it can never silently outrank one
	 * the table does know. Rank breaks ties in favour of the later action:
	 * see record(), where equal rank means two claims of the same strength
	 * and the second one is the one that is still true at the end.
	 */
	private static $rank = array(
		'updated'     => 1,
		'activated'   => 2,
		'deactivated' => 2,
		'switched'    => 2,
		'created'     => 3,
		'deleted'     => 4,
	);

	/**
	 * Note that something changed.
	 *
	 * @param string $type    post|term|attachment|user|plugin|theme|option.
	 * @param string $subtype Post type, taxonomy, plugin file - '' when none.
	 * @param int    $id      Object id, 0 for objects without one.
	 * @param string $action  created|updated|deleted|activated|deactivated|switched.
	 * @param string $name    Human-readable name.
	 * @param array  $fields  Field names touched. Never values.
	 * @return void
	 */
	public static function record( $type, $subtype, $id, $action, $name = '', $fields = array() ) {
		// Which site the change happened on. One CLI or cron request can walk
		// a whole network with switch_to_blog(), and the log table is per site
		// - so the entry has to carry the site it belongs to, or flush() has
		// nothing left to route it by. On a single site this is core's
		// $blog_id global (wp-includes/load.php:1481), which is 1 and costs
		// nothing to read; it is in the key there too, harmlessly, because a
		// single site never produces a second value for it.
		$blog = (int) get_current_blog_id();

		// Objects with a real id are keyed on it. Plugins, themes and options
		// pass id 0, so keying on id alone would collapse every plugin
		// activated in one request onto the same "plugin:0" entry - key
		// those on whatever actually identifies the object instead. The site
		// leads the key because post 5 on one site and post 5 on another are
		// two different objects, and merging them would write one row that
		// describes neither.
		$key = $blog . ':' . $type . ':' . ( (int) $id > 0
			? (int) $id
			: ( '' !== (string) $subtype ? (string) $subtype : (string) $name ) );

		if ( ! isset( self::$pending[ $key ] ) ) {
			self::$pending[ $key ] = array(
				'blog_id'        => $blog,
				'object_type'    => (string) $type,
				'object_subtype' => (string) $subtype,
				'object_id'      => (int) $id,
				'object_name'    => (string) $name,
				'action'         => (string) $action,
				'fields'         => array(),
			);
		} else {
			$held = self::$pending[ $key ]['action'];
			$new  = (string) $action;
			$a    = isset( self::$rank[ $held ] ) ? self::$rank[ $held ] : 0;
			$b    = isset( self::$rank[ $new ] ) ? self::$rank[ $new ] : 0;
			// Greater *or equal*: two actions of the same rank are two state
			// changes, and the later one is the state the request left the
			// object in. A plugin activated and then deactivated in one
			// request is deactivated, whatever order the hooks fired in, and
			// a row saying "activated" would be the log contradicting the
			// site. Ranks that differ are unaffected, so a create still
			// outranks the update that followed it.
			if ( $b >= $a ) {
				self::$pending[ $key ]['action'] = $new;
			}
			if ( '' !== (string) $name ) {
				self::$pending[ $key ]['object_name'] = (string) $name;
			}
		}

		foreach ( (array) $fields as $field ) {
			if ( is_scalar( $field ) && '' !== (string) $field ) {
				self::$pending[ $key ]['fields'][ (string) $field ] = true;
			}
		}
	}

	/**
	 * @return array
	 */
	public static function pending() {
		return self::$pending;
	}

	/**
	 * @return void
	 */
	public static function reset() {
		self::$pending = array();
	}

	/**
	 * The rows this request should write.
	 *
	 * Each row carries the 'blog_id' its entry was recorded on. That is
	 * routing information, not a column: the table is per site, so the row
	 * needs no site column and Digitizer_AI_Agent_Log_Store::insert() - which builds its data
	 * from columns() and ignores anything else in the row - never writes it.
	 * Digitizer_AI_Agent_Log_Hooks::flush() groups on it to reach the right table.
	 *
	 * @param string $channel Channel name.
	 * @param string $app     Application name, '' when unknown.
	 * @param int    $user_id Acting user, 0 under cron.
	 * @param int    $now     Current timestamp.
	 * @return array
	 */
	public static function rows( $channel, $app, $user_id, $now ) {
		$rows = array();
		foreach ( self::$pending as $entry ) {
			if ( self::is_option_echo( $entry ) ) {
				continue;
			}
			$entry['logged_at'] = gmdate( 'Y-m-d H:i:s', (int) $now );
			$entry['channel']   = (string) $channel;
			$entry['app']       = (string) $app;
			$entry['user_id']   = (int) $user_id;
			$entry['fields']    = array_keys( $entry['fields'] );

			$rows[]             = $entry;
		}
		return $rows;
	}

	/**
	 * The site-identity options another plugin copies into an object of its
	 * own, and the object it copies them into.
	 *
	 * Elementor mirrors the site name and tagline into the active kit's
	 * settings (elementor/core/kits/manager.php, on update_option_blogname
	 * and update_option_blogdescription), so an agent that renames the site
	 * also, through Elementor, updates the kit's _elementor_page_settings
	 * meta. That is a real write and the listener sees it as one - but it is
	 * Elementor's reaction, not the agent's action, and a row for it says
	 * nothing the option row does not already say. Worse, core fires
	 * update_option_{$option} before updated_option, so the reaction reaches
	 * the buffer ahead of its cause and the log reads effect-then-cause.
	 *
	 * @var array
	 */
	private static $echoed_options = array( 'blogname', 'blogdescription' );

	/**
	 * Whether an entry is only another plugin's copy of an option change
	 * that this same request also recorded.
	 *
	 * Narrow on purpose. The entry has to be an update of an elementor_library
	 * post whose only touched field is _elementor_page_settings, and one of
	 * the options that Elementor mirrors has to be pending for the same site.
	 * An agent editing Site Settings through Elementor's own API touches the
	 * same meta but records no option, so it is kept; a kit save that also
	 * changed the post itself carries more than one field, so it is kept
	 * too. Decided here at flush rather than in the listener because the
	 * option row arrives after the kit row, and only the finished buffer
	 * knows whether it arrived at all.
	 *
	 * @param array $entry A pending entry.
	 * @return bool
	 */
	private static function is_option_echo( $entry ) {
		if ( 'post' !== $entry['object_type'] || 'elementor_library' !== $entry['object_subtype'] || 'updated' !== $entry['action'] ) {
			return false;
		}
		if ( array( '_elementor_page_settings' ) !== array_keys( $entry['fields'] ) ) {
			return false;
		}
		foreach ( self::$echoed_options as $option ) {
			$key = $entry['blog_id'] . ':option:' . $option;
			if ( isset( self::$pending[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return int Days to keep, 0 or less to keep forever.
	 */
	public static function max_age_days() {
		/**
		 * Filter how many days of agent activity are kept.
		 *
		 * @param int $days Days, 0 or less to disable the age bound.
		 */
		return (int) apply_filters( 'digitizer_ai_agent_log_max_age_days', self::DEFAULT_MAX_AGE_DAYS );
	}

	/**
	 * @return int Rows to keep, 0 or less for no limit.
	 */
	public static function max_rows() {
		/**
		 * Filter how many rows of agent activity are kept.
		 *
		 * @param int $rows Rows, 0 or less to disable the row bound.
		 */
		return (int) apply_filters( 'digitizer_ai_agent_log_max_rows', self::DEFAULT_MAX_ROWS );
	}
}
