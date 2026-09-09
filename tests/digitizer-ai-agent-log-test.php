<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-digitizer-ai-agent-log-channel.php';

// The site every assertion runs on unless it says otherwise. get_current_blog_id()
// is stubbed further down this file (function declarations are hoisted, so it is
// callable from here); core answers 1 on a single site, so that is what it holds
// while nothing is switching. The per-site option sets the switch stubs swap
// between start out empty.
$GLOBALS['aial_stub_current_blog']  = 1;
$GLOBALS['aial_stub_blog_options']  = array();
$GLOBALS['aial_stub_switch_stack']  = array();
$GLOBALS['aial_stub_switch_calls']  = 0;
$GLOBALS['aial_stub_restore_calls'] = 0;

/* ---- channel detection ---- */

// A browser request is the case the whole module turns on: nothing is
// recorded, so this must be '' rather than a channel nobody named.
$GLOBALS['aial_stub_doing_cron']   = false;
$GLOBALS['aial_stub_rest_request'] = false;
aial_test_eq( Digitizer_AI_Agent_Log_Channel::current(), '', 'a browser request is not a channel' );

$GLOBALS['aial_stub_rest_request'] = true;
aial_test_eq( Digitizer_AI_Agent_Log_Channel::current(), 'rest', 'a REST request is' );

// ...but not every REST request. The block editor saves posts over the REST
// API, authenticated by the ordinary logged-in cookie, so REST_REQUEST alone
// would make every Gutenberg save on every site read as an automation and
// bury the log in human editing.
//
// The signal is core's own $wp_rest_auth_cookie, which
// rest_cookie_collect_status() (wp-includes/rest-api.php:1185) sets to true
// from the auth_cookie_valid action, and to the failure name from each of the
// four failure actions (default-filters.php:338-342). For a REST request that
// action is reached through wp_validate_logged_in_cookie()
// (wp-includes/user.php:598, on determine_current_user) and fires only at the
// very end of wp_validate_auth_cookie() (pluggable.php:931).
$GLOBALS['aial_stub_app_password_uuid'] = null;
$GLOBALS['aial_stub_app_passwords']     = array();
$GLOBALS['wp_rest_auth_cookie']        = true;
aial_test_eq( Digitizer_AI_Agent_Log_Channel::current(), '', 'a cookie-authenticated REST request - a person saving in the block editor - is not an automation' );

// An application password is unambiguously not a browser, so it wins even in
// the one case where both could be true of the same request.
$GLOBALS['aial_stub_app_password_uuid'] = 'uuid-1';
$GLOBALS['aial_stub_app_passwords']     = array( 'uuid-1' => array( 'name' => 'ContentEngine' ) );
aial_test_eq( Digitizer_AI_Agent_Log_Channel::current(), 'rest', 'while a REST write authenticated by an application password is still rest, cookie or no cookie' );
$GLOBALS['aial_stub_app_password_uuid'] = null;
$GLOBALS['aial_stub_app_passwords']     = array();

// A cookie that was sent and rejected is not a session either: core puts the
// failure name ('expired', 'bad_hash', ...) in the same global, and only the
// identity true means a session was established.
$GLOBALS['wp_rest_auth_cookie'] = 'expired';
aial_test_eq( Digitizer_AI_Agent_Log_Channel::current(), 'rest', 'a cookie that was sent and rejected is no browser session, so the request is still rest' );

// The cookie check belongs to REST alone. A cron run carrying a valid cookie
// - an admin-triggered wp-cron.php spawn does exactly that - is still cron.
$GLOBALS['wp_rest_auth_cookie'] = true;
$GLOBALS['aial_stub_doing_cron'] = true;
aial_test_eq( Digitizer_AI_Agent_Log_Channel::current(), 'cron', 'a cron run is decided before the cookie is ever looked at' );
$GLOBALS['aial_stub_doing_cron'] = false;
unset( $GLOBALS['wp_rest_auth_cookie'] );

// A REST request with no authentication at all never validated a cookie, so
// the global is unset. That falls on the recorded side on purpose: no browser
// session was established, a permission callback would normally reject such a
// write, and one that lands anyway is exactly the anomalous non-browser change
// the log exists to surface.
aial_test_eq( Digitizer_AI_Agent_Log_Channel::current(), 'rest', 'and an unauthenticated REST write, which no browser session backs, stays on the recorded side' );

// Contexts nest, and the outermost one is the true origin. Cron that runs
// code setting REST_REQUEST is still cron.
$GLOBALS['aial_stub_doing_cron'] = true;
aial_test_eq( Digitizer_AI_Agent_Log_Channel::current(), 'cron', 'cron outranks REST when both are true' );

$GLOBALS['aial_stub_doing_cron']   = false;
$GLOBALS['aial_stub_rest_request'] = false;

/* ---- reads are never recorded ---- */

$_SERVER['REQUEST_METHOD'] = 'GET';
aial_test_ok( Digitizer_AI_Agent_Log_Channel::is_read_request(), 'GET is a read' );
$_SERVER['REQUEST_METHOD'] = 'HEAD';
aial_test_ok( Digitizer_AI_Agent_Log_Channel::is_read_request(), 'so is HEAD' );
$_SERVER['REQUEST_METHOD'] = 'POST';
aial_test_ok( ! Digitizer_AI_Agent_Log_Channel::is_read_request(), 'POST is not' );
$_SERVER['REQUEST_METHOD'] = 'DELETE';
aial_test_ok( ! Digitizer_AI_Agent_Log_Channel::is_read_request(), 'nor is DELETE' );
// An absent method is the CLI and cron case: no HTTP verb at all, and those
// are writes worth recording, so it must not read as a read.
unset( $_SERVER['REQUEST_METHOD'] );
aial_test_ok( ! Digitizer_AI_Agent_Log_Channel::is_read_request(), 'and a request with no method at all is not a read' );

/* ---- the channels that can be named before plugins_loaded ---- */

// Most hosts disable WP-Cron and have a system cron fetch wp-cron.php, which
// is a GET. wp-cron.php defines DOING_CRON (line 42) before it requires
// wp-load.php, so wp_doing_cron() is already true at plugins_loaded and the
// verb must not be allowed to hide the channel.
$_SERVER['REQUEST_METHOD']      = 'GET';
$GLOBALS['aial_stub_doing_cron'] = true;
aial_test_ok( Digitizer_AI_Agent_Log_Channel::is_early_channel(), 'a GET-triggered cron run is a channel we can already name' );

$GLOBALS['aial_stub_doing_cron'] = false;
aial_test_ok( ! Digitizer_AI_Agent_Log_Channel::is_early_channel(), 'a plain browser request is not' );

// REST is deliberately outside this list: core defines REST_REQUEST in
// rest_api_loaded() on 'parse_request' (wp-includes/rest-api.php line 478),
// long after plugins_loaded, so it cannot be named that early. The stub
// standing in for it being true here proves the list is not just "any
// channel at all".
$GLOBALS['aial_stub_rest_request'] = true;
aial_test_ok( ! Digitizer_AI_Agent_Log_Channel::is_early_channel(), 'and REST is not, because REST_REQUEST does not exist yet at plugins_loaded' );
$GLOBALS['aial_stub_rest_request'] = false;
unset( $_SERVER['REQUEST_METHOD'] );

/* ---- the app name is never guessed ---- */

// A UUID that resolves to a record: the name comes back, and a User-Agent
// header present at the same time proves it was ignored rather than used.
$_SERVER['HTTP_USER_AGENT']            = 'ContentEngine/1.0';
$GLOBALS['aial_stub_app_password_uuid'] = 'uuid-1';
$GLOBALS['aial_stub_app_passwords']     = array(
	'uuid-1' => array( 'name' => 'ContentEngine' ),
);
aial_test_eq( Digitizer_AI_Agent_Log_Channel::app_name(), 'ContentEngine', 'a resolved application password returns its name, not the User-Agent' );

// A UUID that authenticated the request but no longer resolves to a record
// (e.g. deleted between authentication and shutdown).
$GLOBALS['aial_stub_app_password_uuid'] = 'uuid-missing';
$GLOBALS['aial_stub_app_passwords']     = array();
aial_test_eq( Digitizer_AI_Agent_Log_Channel::app_name(), '', 'a UUID with no matching record has no app name' );

// No application password authenticated this request at all.
$GLOBALS['aial_stub_app_password_uuid'] = null;
aial_test_eq( Digitizer_AI_Agent_Log_Channel::app_name(), '', 'an unidentified caller has no app name, and the User-Agent is not one' );

unset( $_SERVER['HTTP_USER_AGENT'] );
$GLOBALS['aial_stub_app_password_uuid'] = null;
$GLOBALS['aial_stub_app_passwords']     = array();

require_once dirname( __DIR__ ) . '/includes/class-digitizer-ai-agent-log-store.php';

/* ---- the store builds what it says it builds ---- */

class AIAL_Test_Writer {
	public $prefix   = 'wp_';
	public $inserted = array();
	public $queries  = array();
	public $rows     = array();
	public $var      = 0;
	public function insert( $table, $data, $formats ) {
		$this->inserted[] = array( 'table' => $table, 'data' => $data, 'formats' => $formats );
		return 1;
	}
	public function query( $sql ) {
		$this->queries[] = $sql;
		return 1;
	}
	public function get_results( $sql ) {
		$this->queries[] = $sql;
		return $this->rows;
	}
	public function get_var( $sql ) {
		$this->queries[] = $sql;
		return $this->var;
	}
	// Core's own implementation (wp-includes/class-wpdb.php:1786): the LIKE
	// metacharacters and the backslash, escaped with a backslash.
	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}
	public function get_charset_collate() {
		return '';
	}
	// Deliberately naive, and deliberately NOT a no-op: a prepare() that
	// returned its first argument unchanged would let a test pass while the
	// real query dropped every parameter.
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $arg ) {
			$sql = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . $arg . "'", $sql, 1 );
		}
		return $sql;
	}
}

$writer = new AIAL_Test_Writer();
Digitizer_AI_Agent_Log_Store::set_writer( $writer );

aial_test_eq( Digitizer_AI_Agent_Log_Store::table(), 'wp_digitizer_ai_agent_log', 'the table is named from the writer prefix' );

Digitizer_AI_Agent_Log_Store::insert(
	array(
		'logged_at'      => '2026-08-25 10:00:00',
		'channel'        => 'rest',
		'app'            => 'ContentEngine',
		'user_id'        => 5,
		'action'         => 'updated',
		'object_type'    => 'post',
		'object_subtype' => 'page',
		'object_id'      => 812,
		'object_name'    => 'About us',
		'fields'         => array( 'post_content', 'rank_math_title' ),
	)
);
aial_test_eq( count( $writer->inserted ), 1, 'an insert reaches the writer' );
aial_test_eq( $writer->inserted[0]['table'], 'wp_digitizer_ai_agent_log', 'against the right table' );
aial_test_eq( $writer->inserted[0]['data']['fields'], '["post_content","rank_math_title"]', 'with the field names JSON-encoded, and no values anywhere' );
aial_test_eq( count( $writer->inserted[0]['formats'] ), count( $writer->inserted[0]['data'] ), 'and a format for every column, so none is passed unescaped' );

// A row missing everything still writes something legal rather than a fatal.
$writer->inserted = array();
Digitizer_AI_Agent_Log_Store::insert( array() );
aial_test_eq( $writer->inserted[0]['data']['channel'], '', 'a row with nothing in it defaults rather than fails' );
aial_test_eq( $writer->inserted[0]['data']['object_id'], 0, 'with numeric columns defaulting to zero' );
aial_test_eq( $writer->inserted[0]['data']['fields'], '[]', 'and no fields encoding as an empty array, not null' );

/* ---- values too long for their column are cut, not dropped ---- */

// Under MySQL strict mode - the default on 5.7 and 8.0 - an overlong value
// errors the whole INSERT, and flush() ignores the return value, so the
// change vanishes from the log with no trace. Plugin basenames routinely
// exceed object_subtype's 60 characters.
$writer->inserted = array();
Digitizer_AI_Agent_Log_Store::insert(
	array(
		'channel'        => str_repeat( 'c', 40 ),
		'app'            => str_repeat( 'a', 200 ),
		'action'         => str_repeat( 'x', 80 ),
		'object_subtype' => 'contact-form-7-extension-for-mailchimp/contact-form-7-extension-for-mailchimp.php',
		'object_name'    => str_repeat( 'n', 400 ),
	)
);
$too_long = $writer->inserted[0]['data'];
aial_test_eq( strlen( $too_long['object_subtype'] ), 60, 'an 81-character plugin basename is cut to object_subtype(60)' );
aial_test_eq( strlen( $too_long['object_name'] ), 191, 'a long title is cut to object_name(191)' );
aial_test_eq( strlen( $too_long['app'] ), 100, 'a long app name is cut to app(100)' );
aial_test_eq( strlen( $too_long['action'] ), 40, 'a long action is cut to action(40)' );
aial_test_eq( strlen( $too_long['channel'] ), 20, 'and a long channel to channel(20)' );

// And a value that fits is written whole, so the bound is a bound rather
// than an unconditional trim.
$writer->inserted = array();
Digitizer_AI_Agent_Log_Store::insert( array( 'object_name' => 'About us', 'object_subtype' => 'page' ) );
aial_test_eq( $writer->inserted[0]['data']['object_name'], 'About us', 'a value inside its column is untouched' );
aial_test_eq( $writer->inserted[0]['data']['object_subtype'], 'page', 'and so is a short subtype' );

// substr() on multi-byte text cuts mid-character and writes invalid UTF-8,
// which is worse than the overlong value it was fixing.
$writer->inserted = array();
Digitizer_AI_Agent_Log_Store::insert( array( 'object_name' => str_repeat( 'ת', 300 ) ) );
$mb_name = $writer->inserted[0]['data']['object_name'];
aial_test_eq( mb_strlen( $mb_name, 'UTF-8' ), 191, 'a multi-byte name is cut to 191 characters, not 191 bytes' );
aial_test_ok( mb_check_encoding( $mb_name, 'UTF-8' ), 'and what is written is still valid UTF-8, not a half character' );
// object_name's bound is 191, an odd number of characters over two-byte
// Hebrew, so byte 191 lands in the middle of the 96th character. substr()
// would stop there and write a lone lead byte; there is no substr() fallback
// any more, because inside WordPress mb_substr() always exists - core
// polyfills it in wp-includes/compat.php, line 256.
aial_test_eq( strlen( $mb_name ), 382, 'a 191-character Hebrew name is 382 bytes, so the cut was made in characters and not in bytes' );
aial_test_eq( $mb_name, str_repeat( 'ת', 191 ), 'and what survives is the first 191 characters, whole' );

// A Hebrew title that fits is written exactly as it came, so the multi-byte
// path is a bound and not a mangling.
$writer->inserted = array();
Digitizer_AI_Agent_Log_Store::insert( array( 'object_name' => 'שלום עולם' ) );
aial_test_eq( $writer->inserted[0]['data']['object_name'], 'שלום עולם', 'a short Hebrew title is untouched' );

/* ---- a field list too long for TEXT is shortened, not left to lose the row ---- */

// The same failure the per-column bounds fixed, on the one column that has no
// character width: fields is TEXT, 65,535 bytes, and one import touching a few
// hundred long meta keys on a single object passes that. Strict mode then
// errors the INSERT and flush() ignores the return value, so the log loses the
// fact that the object changed at all.
$writer->inserted = array();
$many             = array();
for ( $i = 0; $i < 2000; $i++ ) {
	$many[] = '_a_fairly_long_plugin_meta_key_name_number_' . $i;
}
Digitizer_AI_Agent_Log_Store::insert( array( 'fields' => $many ) );
$encoded = $writer->inserted[0]['data']['fields'];
aial_test_ok( strlen( wp_json_encode( $many ) ) > Digitizer_AI_Agent_Log_Store::MAX_FIELDS_BYTES, 'the unbounded encoding of 2000 long meta keys really does exceed the budget' );
aial_test_ok( strlen( $encoded ) <= Digitizer_AI_Agent_Log_Store::MAX_FIELDS_BYTES, 'what is written is inside the budget' );
$decoded = json_decode( $encoded, true );
aial_test_ok( is_array( $decoded ), 'and still decodes to an array, which is what the endpoint and the screen both require' );
aial_test_ok( count( $decoded ) > 500, 'keeping as many names as fit rather than giving up on the list' );
aial_test_ok( count( $decoded ) < count( $many ), 'but not all of them, since all of them would not fit' );
aial_test_eq( $decoded[0], $many[0], 'the names kept are the leading ones, in order' );
aial_test_eq( $decoded[ count( $decoded ) - 1 ], $many[ count( $decoded ) - 1 ], 'up to wherever the budget ran out' );

// A list that fits is written whole, so the budget is a bound and not a trim.
$writer->inserted = array();
Digitizer_AI_Agent_Log_Store::insert( array( 'fields' => array( 'post_content', 'rank_math_title' ) ) );
aial_test_eq( $writer->inserted[0]['data']['fields'], '["post_content","rank_math_title"]', 'a field list that fits is written exactly as it came' );

// Bytes, not characters. A Hebrew meta key costs two bytes a character in the
// column and one in mb_strlen(), and on a real site more still: core's
// wp_json_encode() defaults its flags to 0 and escapes non-ASCII to \uXXXX,
// six bytes a character. (The stub in tests/bootstrap.php passes
// JSON_UNESCAPED_UNICODE, so what is measured here is the two-byte case - the
// gentler of the two, which means the real column has more headroom than this
// test proves, not less.) Either way, counting characters, or counting the
// names before they were encoded, leaves the overflow in place.
$writer->inserted = array();
$hebrew           = array();
for ( $i = 0; $i < 4000; $i++ ) {
	$hebrew[] = str_repeat( 'ת', 20 ) . $i;
}
Digitizer_AI_Agent_Log_Store::insert( array( 'fields' => $hebrew ) );
$encoded = $writer->inserted[0]['data']['fields'];
$decoded = json_decode( $encoded, true );
aial_test_ok( strlen( $encoded ) <= Digitizer_AI_Agent_Log_Store::MAX_FIELDS_BYTES, 'a list of non-ASCII names is bounded by its encoded bytes' );
aial_test_ok( is_array( $decoded ) && count( $decoded ) > 0, 'and decodes to a non-empty array of names' );
aial_test_ok( count( $decoded ) < count( $hebrew ), 'with the tail dropped, because the escaped bytes ran out before the names did' );
aial_test_ok( mb_check_encoding( $decoded[ count( $decoded ) - 1 ], 'UTF-8' ), 'and the last name kept is a whole name, not a cut one' );
aial_test_eq( $decoded[0], $hebrew[0], 'the first Hebrew name survives intact' );
// What was written fills the byte budget but is nowhere near it in
// characters, which is the whole difference: a bound that counted characters
// would have gone on adding names until the column had long since overflowed.
aial_test_ok( strlen( $encoded ) > Digitizer_AI_Agent_Log_Store::MAX_FIELDS_BYTES - 200, 'the bytes written fill the budget' );
// Once wp_json_encode() escapes non-ASCII to \uXXXX, the encoded string
// itself is all ASCII, so its own mb_strlen() is no longer a useful
// contrast - it tracks strlen() almost exactly. The contrast that matters is
// against the *names before encoding*: a bound that counted their characters
// instead of the encoded bytes would have believed it had far more room than
// it did, and kept adding names well past where the real, escaped budget ran
// out.
$kept_chars = array_sum( array_map( function ( $name ) { return mb_strlen( $name, 'UTF-8' ); }, $decoded ) );
aial_test_ok( $kept_chars < Digitizer_AI_Agent_Log_Store::MAX_FIELDS_BYTES - 10000, 'while the character count of the names actually kept is far short of the byte budget, which is the room a character-counting bound would have kept filling' );

/* ---- query arguments ---- */

$q = Digitizer_AI_Agent_Log_Store::query_args( array( 'channel' => 'rest', 'object_type' => 'post', 'object_id' => 812, 'per_page' => 20, 'page' => 2 ) );
aial_test_ok( false !== strpos( $q['where'], 'channel = %s' ), 'a channel filter is a placeholder, never interpolated' );
aial_test_ok( false !== strpos( $q['where'], 'object_id = %d' ), 'and so is an id' );
aial_test_eq( $q['params'], array( 'rest', 'post', 812 ), 'with the values carried separately, in the order the placeholders appear' );
aial_test_eq( $q['limit'], 20, 'per_page becomes the limit' );
aial_test_eq( $q['offset'], 20, 'and page 2 of 20 starts at 20' );

// The enums are closed. A value outside them is dropped, not passed through.
$q = Digitizer_AI_Agent_Log_Store::query_args( array( 'channel' => 'ftp; DROP TABLE wp_posts' ) );
aial_test_eq( $q['params'], array(), 'a channel outside the enum contributes no parameter' );
aial_test_eq( $q['where'], '', 'and no clause' );

$q = Digitizer_AI_Agent_Log_Store::query_args( array( 'per_page' => 5000 ) );
aial_test_eq( $q['limit'], 100, 'per_page is capped at 100' );
$q = Digitizer_AI_Agent_Log_Store::query_args( array( 'per_page' => 0, 'page' => 0 ) );
aial_test_eq( $q['limit'], 20, 'a nonsense per_page falls back to the default' );
aial_test_eq( $q['offset'], 0, 'and a nonsense page starts at the beginning' );

/* ---- retention ---- */

$now = 1756108800; // 2026-08-25 08:00:00 UTC

$plan = Digitizer_AI_Agent_Log_Store::prune_plan( 30, 20000, $now );
aial_test_eq( count( $plan ), 2, 'both bounds produce work when both are set' );

$plan = Digitizer_AI_Agent_Log_Store::prune_plan( 0, 20000, $now );
aial_test_eq( count( $plan ), 1, 'an age bound of zero disables that bound only' );
aial_test_eq( $plan[0]['kind'], 'rows', 'leaving the row bound in force' );

$plan = Digitizer_AI_Agent_Log_Store::prune_plan( 30, -5, $now );
aial_test_eq( count( $plan ), 1, 'a negative row bound disables that bound the same way a zero does' );
aial_test_eq( $plan[0]['kind'], 'age', 'leaving the age bound in force' );

aial_test_eq( Digitizer_AI_Agent_Log_Store::prune_plan( 0, 0, $now ), array(), 'and both disabled means no work at all, rather than a delete with no bound' );

$plan = Digitizer_AI_Agent_Log_Store::prune_plan( 30, 0, $now );
aial_test_eq( $plan[0]['cutoff'], gmdate( 'Y-m-d H:i:s', $now - ( 30 * 86400 ) ), 'the age cutoff is 30 days before now, in UTC' );

require_once dirname( __DIR__ ) . '/includes/class-digitizer-ai-agent-log-buffer.php';

/* ---- one row per object per request ---- */

Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Buffer::record( 'post', 'page', 812, 'updated', 'About us', array( 'post_content' ) );
Digitizer_AI_Agent_Log_Buffer::record( 'post', 'page', 812, 'updated', 'About us', array( 'rank_math_title' ) );
Digitizer_AI_Agent_Log_Buffer::record( 'post', 'page', 812, 'updated', 'About us', array( '_elementor_data', 'post_content' ) );

$rows = Digitizer_AI_Agent_Log_Buffer::rows( 'rest', 'ContentEngine', 5, 1756108800 );
aial_test_eq( count( $rows ), 1, 'three writes to one post are one row' );
aial_test_eq( count( $rows[0]['fields'] ), 3, 'carrying every distinct field name' );
aial_test_ok( in_array( 'post_content', $rows[0]['fields'], true ), 'including one named twice' );
aial_test_eq( count( array_unique( $rows[0]['fields'] ) ), 3, 'and named once each, not twice' );
aial_test_eq( $rows[0]['logged_at'], gmdate( 'Y-m-d H:i:s', 1756108800 ), 'stamped in UTC from the clock it was handed' );
aial_test_eq( $rows[0]['channel'], 'rest', 'carrying the channel' );
aial_test_eq( $rows[0]['app'], 'ContentEngine', 'and the app' );

// Two objects are two rows, however interleaved the writes were.
Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Buffer::record( 'post', 'page', 812, 'updated', 'About us', array( 'post_title' ) );
Digitizer_AI_Agent_Log_Buffer::record( 'term', 'category', 4, 'updated', 'News', array( 'name' ) );
Digitizer_AI_Agent_Log_Buffer::record( 'post', 'page', 812, 'updated', 'About us', array( 'post_excerpt' ) );
aial_test_eq( count( Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 ) ), 2, 'two objects are two rows' );

// A delete outranks an update: the object is gone, and saying it was
// "updated" because an update came first in the same request is a lie the
// log would tell forever.
Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Buffer::record( 'post', 'page', 812, 'updated', 'About us', array( 'post_title' ) );
Digitizer_AI_Agent_Log_Buffer::record( 'post', 'page', 812, 'deleted', 'About us' );
$rows = Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 );
aial_test_eq( $rows[0]['action'], 'deleted', 'a delete in the same request wins over an update' );

// And a create outranks an update for the same reason, in the other
// direction: the request that made the object is the one worth recording.
Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Buffer::record( 'post', 'page', 813, 'created', 'New page' );
Digitizer_AI_Agent_Log_Buffer::record( 'post', 'page', 813, 'updated', 'New page', array( 'post_content' ) );
$rows = Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 );
aial_test_eq( $rows[0]['action'], 'created', 'a create earlier in the request wins over the update that followed it' );
aial_test_eq( count( $rows[0]['fields'] ), 1, 'while still collecting the fields the update touched' );

Digitizer_AI_Agent_Log_Buffer::reset();
aial_test_eq( Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 ), array(), 'a request that changed nothing writes nothing' );

/* ---- objects without an id key on what identifies them, not on 0 ---- */

// Two plugins activated in one request are two objects, not one - keying on
// id 0 for both would collapse the second's name onto the first's row.
Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Buffer::record( 'plugin', 'akismet/akismet.php', 0, 'activated', 'Akismet' );
Digitizer_AI_Agent_Log_Buffer::record( 'plugin', 'hello-dolly/hello.php', 0, 'activated', 'Hello Dolly' );
$rows  = Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 );
$names = array_column( $rows, 'object_name' );
aial_test_eq( count( $rows ), 2, 'two plugins activated in one request are two rows' );
aial_test_ok( in_array( 'Akismet', $names, true ) && in_array( 'Hello Dolly', $names, true ), 'each carrying its own name' );

// Two watched options updated in one request are likewise two rows.
Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Buffer::record( 'option', '', 0, 'updated', 'siteurl' );
Digitizer_AI_Agent_Log_Buffer::record( 'option', '', 0, 'updated', 'blogname' );
$rows  = Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 );
$names = array_column( $rows, 'object_name' );
aial_test_eq( count( $rows ), 2, 'two options updated in one request are two rows' );
aial_test_ok( in_array( 'siteurl', $names, true ) && in_array( 'blogname', $names, true ), 'one per option name' );

/* ---- another plugin's copy of an option change is not a second row ---- */

// Elementor mirrors blogname and blogdescription into the active kit
// (core/kits/manager.php, on update_option_blogname), and that hook fires
// before updated_option - so the kit's meta write reaches the buffer ahead
// of the option row it was caused by. One agent action, one row.
Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Buffer::record( 'post', 'elementor_library', 5, 'updated', 'Default Kit', array( '_elementor_page_settings' ) );
Digitizer_AI_Agent_Log_Buffer::record( 'option', '', 0, 'updated', 'blogname', array( 'blogname' ) );
$rows = Digitizer_AI_Agent_Log_Buffer::rows( 'cli', '', 0, 1756108800 );
aial_test_eq( count( $rows ), 1, 'a kit resave that only mirrors a renamed site is folded into the option row' );
aial_test_eq( $rows[0]['object_name'], 'blogname', 'and the option row is the one kept' );

Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Buffer::record( 'post', 'elementor_library', 5, 'updated', 'Default Kit', array( '_elementor_page_settings' ) );
Digitizer_AI_Agent_Log_Buffer::record( 'option', '', 0, 'updated', 'blogdescription', array( 'blogdescription' ) );
aial_test_eq( count( Digitizer_AI_Agent_Log_Buffer::rows( 'cli', '', 0, 1756108800 ) ), 1, 'the tagline mirrors the same way' );

// Without the option in the same request the same meta write is an agent
// editing Site Settings through Elementor - a real action, kept.
Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Buffer::record( 'post', 'elementor_library', 5, 'updated', 'Default Kit', array( '_elementor_page_settings' ) );
aial_test_eq( count( Digitizer_AI_Agent_Log_Buffer::rows( 'rest', 'Studio', 5, 1756108800 ) ), 1, 'a kit settings write on its own is an action and stays' );

// A kit save that changed more than the mirrored settings is more than an
// echo, so it stays beside the option row.
Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Buffer::record( 'post', 'elementor_library', 5, 'updated', 'Default Kit', array( '_elementor_page_settings', 'post_title' ) );
Digitizer_AI_Agent_Log_Buffer::record( 'option', '', 0, 'updated', 'blogname', array( 'blogname' ) );
aial_test_eq( count( Digitizer_AI_Agent_Log_Buffer::rows( 'cli', '', 0, 1756108800 ) ), 2, 'a kit save that also changed the post is kept' );

// Only the two options Elementor mirrors count. Any other option beside a
// kit write is a coincidence of the same request, not its cause.
Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Buffer::record( 'post', 'elementor_library', 5, 'updated', 'Default Kit', array( '_elementor_page_settings' ) );
Digitizer_AI_Agent_Log_Buffer::record( 'option', '', 0, 'updated', 'siteurl', array( 'siteurl' ) );
aial_test_eq( count( Digitizer_AI_Agent_Log_Buffer::rows( 'cli', '', 0, 1756108800 ) ), 2, 'an unrelated option beside a kit write folds nothing' );

// And the same post type with the same field is not a kit echo when the
// post was created or deleted, whatever else the request did.
Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Buffer::record( 'post', 'elementor_library', 9, 'created', 'New Kit', array( '_elementor_page_settings' ) );
Digitizer_AI_Agent_Log_Buffer::record( 'option', '', 0, 'updated', 'blogname', array( 'blogname' ) );
aial_test_eq( count( Digitizer_AI_Agent_Log_Buffer::rows( 'cli', '', 0, 1756108800 ) ), 2, 'a kit created in the same request is not an echo' );

// The option has to be pending for the same site. A network run that
// renamed site 1 and touched site 2's kit did two different things.
Digitizer_AI_Agent_Log_Buffer::reset();
$GLOBALS['aial_stub_current_blog'] = 2;
Digitizer_AI_Agent_Log_Buffer::record( 'post', 'elementor_library', 5, 'updated', 'Default Kit', array( '_elementor_page_settings' ) );
$GLOBALS['aial_stub_current_blog'] = 1;
Digitizer_AI_Agent_Log_Buffer::record( 'option', '', 0, 'updated', 'blogname', array( 'blogname' ) );
aial_test_eq( count( Digitizer_AI_Agent_Log_Buffer::rows( 'cli', '', 0, 1756108800 ) ), 2, 'an option renamed on another site folds nothing here' );

/* ---- state-change actions outrank a later update, same as create/delete ---- */

Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Buffer::record( 'plugin', 'akismet/akismet.php', 0, 'activated', 'Akismet' );
Digitizer_AI_Agent_Log_Buffer::record( 'plugin', 'akismet/akismet.php', 0, 'updated', 'Akismet' );
$rows = Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 );
aial_test_eq( $rows[0]['action'], 'activated', 'activated recorded before updated on the same key keeps activated' );

Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Buffer::record( 'theme', '', 0, 'switched', 'Twenty Twenty-Five' );
Digitizer_AI_Agent_Log_Buffer::record( 'theme', '', 0, 'updated', 'Twenty Twenty-Five' );
$rows = Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 );
aial_test_eq( $rows[0]['action'], 'switched', 'switched is not overwritten by a later updated' );

Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Buffer::record( 'plugin', 'akismet/akismet.php', 0, 'deactivated', 'Akismet' );
Digitizer_AI_Agent_Log_Buffer::record( 'plugin', 'akismet/akismet.php', 0, 'updated', 'Akismet' );
$rows = Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 );
aial_test_eq( $rows[0]['action'], 'deactivated', 'deactivated is not overwritten by a later updated' );

/* ---- between two state changes of equal rank, the later one is the truth ---- */

// activated, deactivated and switched all rank the same, so rank alone cannot
// separate them: what separates them is that one of them happened last. A
// plugin activated and then deactivated in one request is deactivated at the
// end of it, and a row saying "activated" is the log contradicting the site.
Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Buffer::record( 'plugin', 'akismet/akismet.php', 0, 'activated', 'Akismet' );
Digitizer_AI_Agent_Log_Buffer::record( 'plugin', 'akismet/akismet.php', 0, 'deactivated', 'Akismet' );
$rows = Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 );
aial_test_eq( $rows[0]['action'], 'deactivated', 'activated then deactivated in one request keeps deactivated' );

Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Buffer::record( 'plugin', 'akismet/akismet.php', 0, 'deactivated', 'Akismet' );
Digitizer_AI_Agent_Log_Buffer::record( 'plugin', 'akismet/akismet.php', 0, 'activated', 'Akismet' );
$rows = Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 );
aial_test_eq( $rows[0]['action'], 'activated', 'and deactivated then activated keeps activated, the other way round' );

// Two switches in one request do not meet in the buffer at all: switch_theme
// carries the theme being switched *to*, and a theme has no id and no
// subtype, so it keys on that name. Two names are two keys, and the tie-break
// never sees them - each row names the theme it is about, which is not a lie,
// just two facts instead of one.
Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Buffer::record( 'theme', '', 0, 'switched', 'Twenty Twenty-Four' );
Digitizer_AI_Agent_Log_Buffer::record( 'theme', '', 0, 'switched', 'Twenty Twenty-Five' );
$rows  = Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 );
$names = array_column( $rows, 'object_name' );
aial_test_eq( count( $rows ), 2, 'two switches to different themes key on the theme name, so they are two rows' );
aial_test_eq( $names, array( 'Twenty Twenty-Four', 'Twenty Twenty-Five' ), 'each naming the theme it switched to, in the order they happened' );

// Where two equal-ranked records DO meet on one key, the later one is kept -
// a theme switched and then reported switched again is still switched, and a
// 'switched' arriving after an 'activated' on the same key replaces it rather
// than being ignored for tying.
Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Buffer::record( 'theme', '', 0, 'activated', 'Twenty Twenty-Five' );
Digitizer_AI_Agent_Log_Buffer::record( 'theme', '', 0, 'switched', 'Twenty Twenty-Five' );
$rows = Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 );
aial_test_eq( count( $rows ), 1, 'two records for one theme name are one row' );
aial_test_eq( $rows[0]['action'], 'switched', 'and the later of two equal-ranked actions is the one kept' );

// An object created and destroyed inside one request is deleted: it does not
// exist now, and "created" would send a reader looking for something that
// isn't there. The creation is not lost so much as subsumed - a row for an
// object that no longer exists is a row about a deletion.
Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Buffer::record( 'post', 'page', 814, 'created', 'Draft' );
Digitizer_AI_Agent_Log_Buffer::record( 'post', 'page', 814, 'deleted', 'Draft' );
$rows = Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 );
aial_test_eq( $rows[0]['action'], 'deleted', 'created then deleted in one request records the deletion' );

// Ties are broken by order, not by ignoring rank: a weaker action arriving
// later still loses.
Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Buffer::record( 'plugin', 'akismet/akismet.php', 0, 'deleted', 'Akismet' );
Digitizer_AI_Agent_Log_Buffer::record( 'plugin', 'akismet/akismet.php', 0, 'deactivated', 'Akismet' );
$rows = Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 );
aial_test_eq( $rows[0]['action'], 'deleted', 'a lower-ranked action arriving later still does not win' );

/* ---- retention settings ---- */

$GLOBALS['aial_stub_filters'] = array();
aial_test_eq( Digitizer_AI_Agent_Log_Buffer::max_age_days(), 30, 'the age bound defaults to thirty days' );
aial_test_eq( Digitizer_AI_Agent_Log_Buffer::max_rows(), 20000, 'and the row bound to twenty thousand' );

add_filter( 'digitizer_ai_agent_log_max_age_days', function () { return 7; } );
aial_test_eq( Digitizer_AI_Agent_Log_Buffer::max_age_days(), 7, 'both are filterable' );
add_filter( 'digitizer_ai_agent_log_max_rows', function () { return 0; } );
aial_test_eq( Digitizer_AI_Agent_Log_Buffer::max_rows(), 0, 'including down to zero, which disables that bound' );
$GLOBALS['aial_stub_filters'] = array();

require_once dirname( __DIR__ ) . '/includes/class-digitizer-ai-agent-log-hooks.php';

/* ---- which post columns changed ---- */

$before = (object) array( 'post_title' => 'Old', 'post_content' => 'Body', 'post_status' => 'draft', 'post_modified' => '2026-01-01 00:00:00' );
$after  = (object) array( 'post_title' => 'New', 'post_content' => 'Body', 'post_status' => 'publish', 'post_modified' => '2026-08-25 00:00:00' );

$diff = Digitizer_AI_Agent_Log_Hooks::post_field_diff( $after, $before );
aial_test_ok( in_array( 'post_title', $diff, true ), 'a changed title is reported' );
aial_test_ok( in_array( 'post_status', $diff, true ), 'and a changed status' );
aial_test_ok( ! in_array( 'post_content', $diff, true ), 'an unchanged column is not' );
// post_modified changes on every save by definition. Reporting it would put
// a field in every single row that means nothing.
aial_test_ok( ! in_array( 'post_modified', $diff, true ), 'and neither is post_modified, which changes on every save' );

// A create has no "before". Every column would look changed, which is true
// and useless - the action already says the object is new.
aial_test_eq( Digitizer_AI_Agent_Log_Hooks::post_field_diff( $after, null ), array(), 'a create reports no field diff at all' );

/* ---- the option allowlist ---- */

$GLOBALS['aial_stub_filters'] = array();
$watched = Digitizer_AI_Agent_Log_Hooks::watched_options();
aial_test_ok( in_array( 'siteurl', $watched, true ), 'siteurl is watched' );
aial_test_ok( in_array( 'active_plugins', $watched, true ), 'and so is active_plugins' );
// The tagline sits beside the site name: both are site identity, and both
// are what Elementor mirrors into its kit - the fold in Buffer::rows() needs
// the cause on the record for the tagline case just as for the name.
aial_test_ok( in_array( 'blogdescription', $watched, true ), 'and the tagline, beside the site name' );
// updated_option fires for every transient. Without an allowlist the table
// fills with noise in a day and buries the writes worth seeing.
aial_test_ok( ! in_array( '_transient_doing_cron', $watched, true ), 'a transient is not' );

add_filter( 'digitizer_ai_agent_log_watched_options', function ( $list ) { $list[] = 'my_option'; return $list; } );
aial_test_ok( in_array( 'my_option', Digitizer_AI_Agent_Log_Hooks::watched_options(), true ), 'and a site may add one by filter' );
// A filter returning something that is not a list must not disarm the
// allowlist into "watch everything".
add_filter( 'digitizer_ai_agent_log_watched_options', function () { return 'nonsense'; } );
aial_test_ok( in_array( 'siteurl', Digitizer_AI_Agent_Log_Hooks::watched_options(), true ), 'while a filter returning nonsense leaves the defaults standing' );
$GLOBALS['aial_stub_filters'] = array();

/* ---- pruning execution ---- */

$writer = new AIAL_Test_Writer();
Digitizer_AI_Agent_Log_Store::set_writer( $writer );
$writer->var = 900;
Digitizer_AI_Agent_Log_Store::prune( 30, 20000, 1756108800 );
aial_test_eq( count( $writer->queries ), 3, 'both bounds run: one delete by age, one lookup and one delete by id' );
aial_test_ok( false !== strpos( $writer->queries[0], 'logged_at <' ), 'the age bound deletes by date' );
// The offset is the cap itself: OFFSET 20000 is the 20001st-newest row, the
// first one that must go. An off-by-one here silently keeps or drops a row
// on every prune, and the query count alone would not notice.
aial_test_ok( false !== strpos( $writer->queries[1], 'OFFSET 20000' ), 'the lookup finds the id at exactly the cap, not one either side of it' );
aial_test_ok( false !== strpos( $writer->queries[2], 'id <= 900' ), 'and the row bound deletes below the id at the cap' );

// Nothing below the cap means nothing to delete, and no DELETE issued.
$writer->queries = array();
$writer->var     = null;
Digitizer_AI_Agent_Log_Store::prune( 0, 20000, 1756108800 );
aial_test_eq( count( $writer->queries ), 1, 'a table under the row cap issues the lookup and no delete' );

$writer->queries = array();
Digitizer_AI_Agent_Log_Store::prune( 0, 0, 1756108800 );
aial_test_eq( $writer->queries, array(), 'and both bounds disabled runs no query at all, rather than an unbounded delete' );

/* ---- hook callbacks, smoke-tested through the buffer ---- */
// The brief only required tests for the two pure statics and Store::prune().
// The design spec's Testing section additionally names "meta names
// accumulated across several hook fires in one request collapsing to one
// row" as required coverage, and nothing proves the record() call sites pass
// their arguments in the right positions - which is exactly what the
// buffer's id-less keying (rewritten a task ago) depends on getting right.

// 1. on_post_saved() on an update.
Digitizer_AI_Agent_Log_Buffer::reset();
$post_before = (object) array(
	'post_title'    => 'Old Title',
	'post_content'  => 'Body',
	'post_status'   => 'publish',
	'post_modified' => '2026-01-01 00:00:00',
);
$post_after = (object) array(
	'post_type'     => 'post',
	'post_title'    => 'New Title',
	'post_content'  => 'Body',
	'post_status'   => 'publish',
	'post_modified' => '2026-08-25 00:00:00',
);
Digitizer_AI_Agent_Log_Hooks::on_post_saved( 601, $post_after, true, $post_before );
$rows = Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 );
aial_test_eq( count( $rows ), 1, 'on_post_saved() writes one row' );
aial_test_eq( $rows[0]['object_type'], 'post', 'as an object_type of post' );
aial_test_eq( $rows[0]['object_subtype'], 'post', 'the post type as subtype' );
aial_test_eq( $rows[0]['object_id'], 601, 'the post id' );
aial_test_eq( $rows[0]['action'], 'updated', 'action updated for $update = true' );
aial_test_eq( $rows[0]['object_name'], 'New Title', 'the title as object_name' );
aial_test_eq( $rows[0]['fields'], array( 'post_title' ), 'and only the column that actually changed' );

// 2. The accumulation case the spec names by name: a save plus three meta
// writes on the same post collapse to one row carrying all four field names.
Digitizer_AI_Agent_Log_Buffer::reset();
$GLOBALS['aial_stub_posts'][602] = array(
	'post_type'   => 'post',
	'post_title'  => 'Accumulated',
	'post_status' => 'publish',
);
$before = (object) array(
	'post_title'    => 'Was Accumulated',
	'post_content'  => 'Body',
	'post_status'   => 'publish',
	'post_modified' => '2026-01-01 00:00:00',
);
$after = (object) array(
	'post_type'     => 'post',
	'post_title'    => 'Accumulated',
	'post_content'  => 'Body',
	'post_status'   => 'publish',
	'post_modified' => '2026-08-25 00:00:00',
);
Digitizer_AI_Agent_Log_Hooks::on_post_saved( 602, $after, true, $before );
Digitizer_AI_Agent_Log_Hooks::on_post_meta( 101, 602, 'rank_math_title' );
Digitizer_AI_Agent_Log_Hooks::on_post_meta( 102, 602, '_elementor_data' );
Digitizer_AI_Agent_Log_Hooks::on_post_meta( 103, 602, 'custom_field' );
$rows = Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 );
aial_test_eq( count( $rows ), 1, 'a save plus three meta writes on one post collapse to one row' );
aial_test_eq( count( $rows[0]['fields'] ), 4, 'carrying all four field names' );
foreach ( array( 'post_title', 'rank_math_title', '_elementor_data', 'custom_field' ) as $expected_field ) {
	aial_test_ok( in_array( $expected_field, $rows[0]['fields'], true ), "including {$expected_field}" );
}

// 3. on_post_saved() is silent on a revision, and on an autosave - and test 1
// above already proves the guard is not simply an always-return.
Digitizer_AI_Agent_Log_Buffer::reset();
$GLOBALS['aial_stub_posts'][603] = array( 'post_type' => 'revision', 'post_parent' => 602 );
Digitizer_AI_Agent_Log_Hooks::on_post_saved( 603, (object) array( 'post_type' => 'revision' ), false, null );
aial_test_eq( Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 ), array(), 'a revision records nothing' );

Digitizer_AI_Agent_Log_Buffer::reset();
$GLOBALS['aial_stub_autosaves'] = array( 604 );
Digitizer_AI_Agent_Log_Hooks::on_post_saved( 604, (object) array( 'post_type' => 'post' ), false, null );
aial_test_eq( Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 ), array(), 'and an autosave records nothing either' );
$GLOBALS['aial_stub_autosaves'] = array();

// 4. on_post_deleted() records deleted, and an attachment is object_type
// attachment rather than post - through both on_post_saved and on_post_deleted.
Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Hooks::on_post_deleted( 605, (object) array( 'post_type' => 'post', 'post_title' => 'Gone' ) );
$rows = Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 );
aial_test_eq( $rows[0]['action'], 'deleted', 'on_post_deleted() records deleted' );
aial_test_eq( $rows[0]['object_type'], 'post', 'a plain post stays object_type post' );

Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Hooks::on_post_deleted( 606, (object) array( 'post_type' => 'attachment', 'post_title' => 'Image' ) );
$rows = Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 );
aial_test_eq( $rows[0]['object_type'], 'attachment', 'a deleted attachment is object_type attachment' );

// 4b. on_post_deleted() is silent on a revision or an autosave too - the same
// guard on_post_saved() uses. wp_delete_post_revision() (wp-includes/revision.php)
// prunes the oldest revisions past WP_POST_REVISIONS through wp_delete_post(),
// which fires 'before_delete_post' just like a real delete, so without this
// guard every routine automated update would also log spurious "deleted" rows.
Digitizer_AI_Agent_Log_Buffer::reset();
$GLOBALS['aial_stub_posts'][608] = array( 'post_type' => 'revision', 'post_parent' => 602 );
Digitizer_AI_Agent_Log_Hooks::on_post_deleted( 608, (object) array( 'post_type' => 'revision' ) );
aial_test_eq( Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 ), array(), 'deleting a revision records nothing' );

Digitizer_AI_Agent_Log_Buffer::reset();
$GLOBALS['aial_stub_autosaves'] = array( 609 );
Digitizer_AI_Agent_Log_Hooks::on_post_deleted( 609, (object) array( 'post_type' => 'post' ) );
aial_test_eq( Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 ), array(), 'deleting an autosave records nothing' );
$GLOBALS['aial_stub_autosaves'] = array();

// 4c. on_post_meta() is the sibling on_post_deleted() had before this fix and
// on_post_meta() did not: WP 6.4+ copies registered post meta onto each
// revision through the ordinary metadata APIs (wp_save_revisioned_meta_fields()
// calling _wp_copy_post_meta(), wp-includes/revision.php), and removes it the
// same way when a revision is pruned (wp_delete_post_revision() ->
// wp_delete_post() -> delete_metadata_by_mid(), wp-includes/meta.php). Both
// fire added_post_meta / deleted_post_meta with the revision's own id, which
// on_post_meta() resolves with get_post() into the revision object - so an
// unguarded callback logs a spurious "updated" row for every automated save
// that touches revisioned meta.
Digitizer_AI_Agent_Log_Buffer::reset();
$GLOBALS['aial_stub_posts'][610] = array( 'post_type' => 'revision', 'post_parent' => 602 );
Digitizer_AI_Agent_Log_Hooks::on_post_meta( 201, 610, 'rank_math_title' );
aial_test_eq( Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 ), array(), 'added_post_meta on a revision id records nothing' );

Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Hooks::on_post_meta( 202, 610, 'rank_math_title' );
aial_test_eq( Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 ), array(), 'deleted_post_meta on a revision id records nothing either' );

Digitizer_AI_Agent_Log_Buffer::reset();
// A real post row, so that without the guard get_post() would resolve it and
// on_post_meta() would proceed to record - the only thing standing in the way
// is the autosave check itself.
$GLOBALS['aial_stub_posts'][611] = array( 'post_type' => 'post', 'post_title' => 'Autosave Target' );
$GLOBALS['aial_stub_autosaves'] = array( 611 );
Digitizer_AI_Agent_Log_Hooks::on_post_meta( 203, 611, 'rank_math_title' );
aial_test_eq( Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 ), array(), 'and an autosave id records nothing through the meta callback' );
$GLOBALS['aial_stub_autosaves'] = array();

// A real post must still be reported: the guard must not have swallowed
// on_post_meta() outright.
Digitizer_AI_Agent_Log_Buffer::reset();
$GLOBALS['aial_stub_posts'][612] = array( 'post_type' => 'post', 'post_title' => 'Still Reported' );
Digitizer_AI_Agent_Log_Hooks::on_post_meta( 204, 612, 'rank_math_title' );
$rows = Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 );
aial_test_eq( count( $rows ), 1, 'a meta write on a real post still records' );
aial_test_eq( $rows[0]['fields'], array( 'rank_math_title' ), 'with the meta key' );

Digitizer_AI_Agent_Log_Buffer::reset();
$attachment_after = (object) array(
	'post_type'     => 'attachment',
	'post_title'    => 'New Image',
	'post_content'  => '',
	'post_status'   => 'inherit',
	'post_modified' => '2026-08-25 00:00:00',
);
Digitizer_AI_Agent_Log_Hooks::on_post_saved( 607, $attachment_after, false, null );
$rows = Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 );
aial_test_eq( $rows[0]['object_type'], 'attachment', 'a saved attachment is object_type attachment too' );
aial_test_eq( $rows[0]['action'], 'created', 'and action created for $update = false' );

// 5. Term hooks: edited records term with the taxonomy and the term's current
// name; deleted takes its name from the object it is handed, since the term
// is already gone by the time delete_term fires.
Digitizer_AI_Agent_Log_Buffer::reset();
$GLOBALS['aial_stub_terms'][30] = array( 'name' => 'News' );
Digitizer_AI_Agent_Log_Hooks::on_term_edited( 30, 300, 'category' );
$rows = Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 );
aial_test_eq( $rows[0]['object_type'], 'term', 'on_term_edited() records object_type term' );
aial_test_eq( $rows[0]['object_subtype'], 'category', 'the taxonomy as subtype' );
aial_test_eq( $rows[0]['object_name'], 'News', "the term's current name" );
aial_test_eq( $rows[0]['action'], 'updated', 'action updated' );

Digitizer_AI_Agent_Log_Buffer::reset();
$deleted_term = (object) array( 'name' => 'Old Category' );
Digitizer_AI_Agent_Log_Hooks::on_term_deleted( 31, 301, 'category', $deleted_term );
$rows = Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 );
aial_test_eq( $rows[0]['action'], 'deleted', 'on_term_deleted() records deleted' );
aial_test_eq( $rows[0]['object_name'], 'Old Category', 'taking the name from the deleted-term object, not a lookup' );

// 6. User hooks.
Digitizer_AI_Agent_Log_Buffer::reset();
$GLOBALS['aial_stub_users'][70] = array( 'user_login' => 'agent-seven' );
Digitizer_AI_Agent_Log_Hooks::on_user_role( 70, 'editor' );
$rows = Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 );
aial_test_eq( $rows[0]['object_subtype'], 'editor', 'on_user_role() records the role as subtype' );
aial_test_eq( $rows[0]['fields'], array( 'role' ), "and 'role' in fields" );
aial_test_eq( $rows[0]['object_name'], 'agent-seven', "the user's login" );

Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Hooks::on_user_created( 71 );
$rows = Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 );
aial_test_eq( $rows[0]['action'], 'created', 'on_user_created() records created' );

Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Hooks::on_user_updated( 71 );
$rows = Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 );
aial_test_eq( $rows[0]['action'], 'updated', 'on_user_updated() records updated' );

Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Hooks::on_user_deleted( 71 );
$rows = Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 );
aial_test_eq( $rows[0]['action'], 'deleted', 'on_user_deleted() records deleted' );

// 7. The id-less keying, proven through the real call sites: two plugins
// activated in one request are two rows with different names, and so are two
// watched options updated in one request. This is the exact thing that broke
// silently a task ago.
Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Hooks::on_plugin_activated( 'akismet/akismet.php' );
Digitizer_AI_Agent_Log_Hooks::on_plugin_activated( 'hello-dolly/hello.php' );
$rows  = Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 );
$names = array_column( $rows, 'object_name' );
aial_test_eq( count( $rows ), 2, 'two plugins activated through the real hooks are two rows' );
aial_test_ok( in_array( 'akismet/akismet.php', $names, true ) && in_array( 'hello-dolly/hello.php', $names, true ), 'each carrying its own file as its name' );

Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Hooks::on_option_updated( 'siteurl' );
Digitizer_AI_Agent_Log_Hooks::on_option_updated( 'blogname' );
$rows  = Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 );
$names = array_column( $rows, 'object_name' );
aial_test_eq( count( $rows ), 2, 'two watched options updated through the real hook are two rows' );
aial_test_ok( in_array( 'siteurl', $names, true ) && in_array( 'blogname', $names, true ), 'each carrying its own option name' );

// An unwatched option updated through the real hook records nothing at all.
Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Hooks::on_option_updated( '_transient_doing_cron' );
aial_test_eq( Digitizer_AI_Agent_Log_Buffer::rows( 'rest', '', 5, 1756108800 ), array(), 'an option outside the allowlist records nothing' );

// 8. init() registers nothing for a read, and something for a write. It does
// NOT gate on the channel: init() runs on 'plugins_loaded', and core does not
// define REST_REQUEST until rest_api_loaded() on 'parse_request', so a channel
// gate here would read '' for every REST request and hook nothing at all. A
// browser request therefore does register listeners, and flush() - which runs
// at shutdown, when the channel is knowable - is what writes nothing for it.
$GLOBALS['aial_stub_filters']      = array();
$GLOBALS['aial_stub_doing_cron']   = false;
$GLOBALS['aial_stub_rest_request'] = false;
$_SERVER['REQUEST_METHOD']        = 'POST';
Digitizer_AI_Agent_Log_Hooks::init();
aial_test_ok( ! empty( $GLOBALS['aial_stub_filters'] ), 'init() registers on a write whose channel is not yet knowable, rather than losing every REST request to a gate that runs too early' );

$GLOBALS['aial_stub_filters']      = array();
$GLOBALS['aial_stub_rest_request'] = true;
$_SERVER['REQUEST_METHOD']        = 'GET';
Digitizer_AI_Agent_Log_Hooks::init();
aial_test_eq( $GLOBALS['aial_stub_filters'], array(), 'init() on a real channel that is only a read also registers nothing' );

$GLOBALS['aial_stub_filters'] = array();
$_SERVER['REQUEST_METHOD']   = 'POST';
Digitizer_AI_Agent_Log_Hooks::init();
aial_test_ok( ! empty( $GLOBALS['aial_stub_filters'] ), 'init() on a real write registers something' );

// A cron run reached over GET - an external scheduler fetching wp-cron.php,
// which is what most hosts do - must still register. It is a write channel
// whose name is knowable at plugins_loaded, and bailing out on the verb would
// leave every change that cron run makes unrecorded.
$GLOBALS['aial_stub_filters']    = array();
$GLOBALS['aial_stub_doing_cron'] = true;
$_SERVER['REQUEST_METHOD']      = 'GET';
Digitizer_AI_Agent_Log_Hooks::init();
aial_test_ok( ! empty( $GLOBALS['aial_stub_filters'] ), 'init() registers for a cron run fetched over GET, rather than reading it as a poll and recording nothing all run' );
aial_test_ok( isset( $GLOBALS['aial_stub_filters']['shutdown'] ), 'including the shutdown flush, without which nothing buffered is ever written' );

// And the same request without cron registers nothing, so the line above is
// the channel doing the work and not the verb quietly passing.
$GLOBALS['aial_stub_filters']    = array();
$GLOBALS['aial_stub_doing_cron'] = false;
Digitizer_AI_Agent_Log_Hooks::init();
aial_test_eq( $GLOBALS['aial_stub_filters'], array(), 'while the same GET off any early channel still registers nothing' );

$GLOBALS['aial_stub_filters']      = array();
$GLOBALS['aial_stub_rest_request'] = false;
unset( $_SERVER['REQUEST_METHOD'] );

// WP-CLI and XML-RPC announce themselves with constants, which cannot be
// undefined once set - so they run in a child process rather than changing
// the channel every assertion below would see. See the fixture's header.
$aial_child = dirname( __DIR__ ) . '/tests/early-channel-child.php';
$aial_cases = array();
foreach (
	array(
		'cli'           => array( 'cli' ),
		'xmlrpc'        => array( 'xmlrpc' ),
		'none'          => array( 'none' ),
		// The same two channels, this time on a request that also carries a
		// valid logged-in cookie: the browser-session check current() applies
		// to REST must not reach them.
		'cli-cookie'    => array( 'cli', 'cookie' ),
		'xmlrpc-cookie' => array( 'xmlrpc', 'cookie' ),
	) as $aial_name => $aial_argv
) {
	$aial_cmd = 'php ' . escapeshellarg( $aial_child );
	foreach ( $aial_argv as $aial_arg ) {
		$aial_cmd .= ' ' . escapeshellarg( $aial_arg );
	}
	$aial_out = trim( (string) shell_exec( $aial_cmd ) );
	$aial_cases[ $aial_name ] = array( 'raw' => $aial_out, 'early' => null, 'hooks' => null, 'channel' => null );
	if ( preg_match( '/^early=(\d+) hooks=(\d+) channel=(\S+)$/', $aial_out, $aial_m ) ) {
		$aial_cases[ $aial_name ]['early']   = (int) $aial_m[1];
		$aial_cases[ $aial_name ]['hooks']   = (int) $aial_m[2];
		$aial_cases[ $aial_name ]['channel'] = ( '-' === $aial_m[3] ) ? '' : $aial_m[3];
	}
}
aial_test_eq( $aial_cases['cli']['early'], 1, 'a WP-CLI run is a channel we can name before plugins_loaded' );
aial_test_ok( $aial_cases['cli']['hooks'] > 0, 'so it registers listeners even though it has no HTTP verb of its own' );
aial_test_eq( $aial_cases['xmlrpc']['early'], 1, 'so is an XML-RPC request, whose constant is likewise set before wp-load' );
aial_test_ok( $aial_cases['xmlrpc']['hooks'] > 0, 'and it registers listeners too' );
// The control: the same child, the same GET, no constant. If this registered
// anything the four above would prove nothing.
aial_test_eq( $aial_cases['none']['early'], 0, 'while the same child with neither constant names no channel' );
aial_test_eq( $aial_cases['none']['hooks'], 0, 'and registers nothing at all, which is what makes the cases above mean something' );

// And the browser-session check that keeps a Gutenberg save out of the log is
// a REST check and nothing wider: a WP-CLI or XML-RPC request that happens to
// carry a valid logged-in cookie is still the channel it announced itself as.
aial_test_eq( $aial_cases['cli']['channel'], 'cli', 'a WP-CLI run names its own channel' );
aial_test_eq( $aial_cases['cli-cookie']['channel'], 'cli', 'and still does with a valid logged-in cookie on the request' );
aial_test_eq( $aial_cases['xmlrpc']['channel'], 'xmlrpc', 'an XML-RPC request names its own too' );
aial_test_eq( $aial_cases['xmlrpc-cookie']['channel'], 'xmlrpc', 'and is likewise untouched by the cookie check' );

/* ---- flush(): the only path that writes ---- */

// flush() asks each site whether it records before writing that site's rows,
// and asks it with the schema stamp - which answers both "is the plugin active
// here" and "is the table really there" with one read of that site's options.

/**
 * Put the current stub site in the state a site that records is in: the schema
 * stamp that says install_table() has confirmed the table is really there.
 */
function aial_stub_recording( $on = true ) {
	if ( $on ) {
		update_option( 'digitizer_ai_agent_log_schema', Digitizer_AI_Agent_Log_Store::SCHEMA_VERSION );
	} else {
		update_option( 'digitizer_ai_agent_log_schema', '' );
	}
}

// init() cannot gate on the channel, because core defines REST_REQUEST on
// 'parse_request', long after the 'plugins_loaded' that reaches init(). So
// the whole boundary lives here, at shutdown, and this is what proves it.
$writer = new AIAL_Test_Writer();
Digitizer_AI_Agent_Log_Store::set_writer( $writer );
$GLOBALS['aial_stub_options']           = array();
aial_stub_recording();
$GLOBALS['aial_stub_doing_cron']        = false;
$GLOBALS['aial_stub_rest_request']      = false;
$GLOBALS['aial_stub_app_password_uuid'] = null;
$GLOBALS['aial_stub_app_passwords']     = array();

// A browser request writes nothing - and leaves nothing buffered, so a later
// request in the same process cannot inherit its changes and file them under
// the wrong channel.
Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Hooks::on_option_updated( 'siteurl' );
Digitizer_AI_Agent_Log_Hooks::flush();
aial_test_eq( $writer->inserted, array(), 'flush() on a browser request writes no row' );
aial_test_eq( Digitizer_AI_Agent_Log_Buffer::pending(), array(), 'and empties the buffer rather than leaving it for the next request' );
aial_test_eq( $writer->queries, array(), 'and prunes nothing' );

// A recognised channel with nothing buffered touches neither the database nor
// the throttle: a poll that changed nothing must not push the prune stamp
// forward and starve the prune that a real write would have done.
$GLOBALS['aial_stub_rest_request'] = true;
Digitizer_AI_Agent_Log_Buffer::reset();
$writer->inserted = array();
$writer->queries  = array();
$GLOBALS['aial_stub_app_password_lookups'] = 0;
Digitizer_AI_Agent_Log_Hooks::flush();
aial_test_eq( $writer->inserted, array(), 'a recognised channel with an empty buffer writes no row' );
aial_test_eq( $writer->queries, array(), 'and issues no query at all' );
aial_test_ok( ! isset( $GLOBALS['aial_stub_options']['digitizer_ai_agent_log_last_prune'] ), 'and does not stamp the prune throttle' );
// Resolving the app name reads user meta. A request that changed nothing must
// decide that before paying for it.
aial_test_eq( $GLOBALS['aial_stub_app_password_lookups'], 0, 'and never looks the application password up at all' );

// A recognised channel with real changes: one row per object, each carrying
// the channel read at shutdown and the application password's name.
$GLOBALS['aial_stub_app_password_uuid'] = 'uuid-1';
$GLOBALS['aial_stub_app_passwords']     = array( 'uuid-1' => array( 'name' => 'ContentEngine' ) );
Digitizer_AI_Agent_Log_Buffer::reset();
$writer->inserted = array();
$writer->queries  = array();
Digitizer_AI_Agent_Log_Hooks::on_option_updated( 'siteurl' );
Digitizer_AI_Agent_Log_Hooks::on_plugin_activated( 'akismet/akismet.php' );
Digitizer_AI_Agent_Log_Hooks::flush();
aial_test_eq( count( $writer->inserted ), 2, 'a REST request that changed two objects writes two rows' );
$flushed_channels = array_unique( array_column( array_column( $writer->inserted, 'data' ), 'channel' ) );
aial_test_eq( $flushed_channels, array( 'rest' ), 'every row carrying the channel, read at shutdown when the constant exists' );
$flushed_apps = array_unique( array_column( array_column( $writer->inserted, 'data' ), 'app' ) );
aial_test_eq( $flushed_apps, array( 'ContentEngine' ), 'and the name of the application password that authenticated it' );
aial_test_eq( Digitizer_AI_Agent_Log_Buffer::pending(), array(), 'and the buffer is empty afterwards' );
aial_test_ok( ! empty( $writer->queries ), 'a first flush prunes, since the throttle has never been stamped' );
aial_test_ok( isset( $GLOBALS['aial_stub_options']['digitizer_ai_agent_log_last_prune'] ), 'stamping the throttle as it goes' );

// A second flush within the hour writes its rows but does not prune again.
$writer->inserted = array();
$writer->queries  = array();
Digitizer_AI_Agent_Log_Hooks::on_option_updated( 'blogname' );
Digitizer_AI_Agent_Log_Hooks::flush();
aial_test_eq( count( $writer->inserted ), 1, 'a second flush within the hour still writes its row' );
aial_test_eq( $writer->queries, array(), 'but issues no prune query' );

// Once the throttle has expired, the next flush prunes again.
$GLOBALS['aial_stub_options']['digitizer_ai_agent_log_last_prune'] = time() - HOUR_IN_SECONDS - 1;
$writer->inserted = array();
$writer->queries  = array();
Digitizer_AI_Agent_Log_Hooks::on_option_updated( 'blogname' );
Digitizer_AI_Agent_Log_Hooks::flush();
aial_test_ok( ! empty( $writer->queries ), 'and a flush after the throttle has expired prunes again' );

/* ---- one request, several sites ---- */

// A CLI or cron run that walks a network with switch_to_blog() records changes
// on several sites and reaches shutdown back where it started. The log table is
// per site, so the buffer has to remember which site each change was made on
// and flush() has to write each group in that site's context.

// Production has one $wpdb serving as both the global and the store's writer,
// which is what makes the prefix - and so the table name - follow a switch.
// The double has to be the same object in both roles or the switch would be
// invisible to Digitizer_AI_Agent_Log_Store::table().
$writer = new AIAL_Test_Writer();
Digitizer_AI_Agent_Log_Store::set_writer( $writer );
$GLOBALS['wpdb'] = $writer;

$GLOBALS['aial_stub_rest_request']  = true;
$GLOBALS['aial_stub_multisite']     = true;
$GLOBALS['aial_stub_blog_options']  = array();
$GLOBALS['aial_stub_switch_stack']  = array();
$GLOBALS['aial_stub_switch_calls']  = 0;
$GLOBALS['aial_stub_restore_calls'] = 0;
$GLOBALS['aial_stub_options']       = array();
Digitizer_AI_Agent_Log_Buffer::reset();
aial_stub_enter_blog( 2 );
aial_stub_recording();

// The same post id on two sites. Keyed on the object alone these would merge
// into one row describing neither.
Digitizer_AI_Agent_Log_Buffer::record( 'post', 'post', 5, 'updated', 'Home on site two', array( 'post_title' ) );
switch_to_blog( 7 );
aial_stub_recording();
Digitizer_AI_Agent_Log_Buffer::record( 'post', 'post', 5, 'updated', 'Home on site seven', array( 'post_content' ) );
restore_current_blog();

$GLOBALS['aial_stub_switch_calls']  = 0;
$GLOBALS['aial_stub_restore_calls'] = 0;
$writer->inserted                  = array();
$writer->queries                   = array();
Digitizer_AI_Agent_Log_Hooks::flush();

aial_test_eq( count( $writer->inserted ), 2, 'post 5 on site two and post 5 on site seven are two rows, not one merged row' );
aial_test_eq(
	array_column( $writer->inserted, 'table' ),
	array( 'wp_2_digitizer_ai_agent_log', 'wp_7_digitizer_ai_agent_log' ),
	'each row written to its own site table rather than all of them to the site the request ends on'
);
aial_test_eq(
	array_column( array_column( $writer->inserted, 'data' ), 'object_name' ),
	array( 'Home on site two', 'Home on site seven' ),
	'and each carrying the name it was recorded with, so the two entries never merged'
);
aial_test_eq( $GLOBALS['aial_stub_switch_calls'], 1, 'the site the request is already on is written without switching to it' );
aial_test_eq( $GLOBALS['aial_stub_restore_calls'], 1, 'and the one switch that was needed is paired with a restore' );
aial_test_eq( $GLOBALS['aial_stub_current_blog'], 2, 'leaving the request on the site it started on' );
aial_test_eq( $writer->prefix, 'wp_2_', 'with the prefix - and so the table every other plugin writes to - back where it was' );

// Pruning follows the switch, because both halves of it are per site: the
// table it trims and the throttle stamp that decides whether to trim at all.
aial_test_ok(
	(bool) array_filter( $writer->queries, function ( $sql ) { return false !== strpos( $sql, 'wp_7_digitizer_ai_agent_log' ); } ),
	'the prune trims the switched-to site table too, rather than trimming the originating site twice'
);
aial_test_ok(
	(bool) array_filter( $writer->queries, function ( $sql ) { return false !== strpos( $sql, 'wp_2_digitizer_ai_agent_log' ); } ),
	'and the originating site table as well'
);
aial_test_ok( isset( $GLOBALS['aial_stub_options']['digitizer_ai_agent_log_last_prune'] ), 'the throttle stamp lands on the originating site' );
aial_test_ok( isset( $GLOBALS['aial_stub_blog_options'][7]['digitizer_ai_agent_log_last_prune'] ), 'and a separate one on the site that was switched to, so neither starves the other' );

/* ---- and each site decides for itself whether it records ---- */

// DPT_Plugin::load_modules() decides enablement once, for the site the request
// started on. Now that flush() writes into other sites' contexts, that one
// answer is the wrong one for every other group: a run that switches into a
// site where the operator turned Agent Log off would record there anyway, and
// into a table that was never created because install_table() only ever runs
// on the starting site. So each group asks the site it is about, and skips
// itself when the answer is no.

// Site 7 has the module switched off. Its rows are dropped; site 2's are not,
// which is what makes this a per-site decision rather than an off switch.
$GLOBALS['aial_stub_multisite']    = true;
$GLOBALS['aial_stub_blog_options'] = array();
$GLOBALS['aial_stub_switch_stack'] = array();
Digitizer_AI_Agent_Log_Buffer::reset();
aial_stub_enter_blog( 2 );
aial_stub_recording();
Digitizer_AI_Agent_Log_Buffer::record( 'post', 'post', 5, 'updated', 'Home on site two' );
switch_to_blog( 7 );
aial_stub_recording( false );
Digitizer_AI_Agent_Log_Buffer::record( 'post', 'post', 5, 'updated', 'Home on site seven' );
restore_current_blog();
$writer->inserted = array();
$writer->queries  = array();
Digitizer_AI_Agent_Log_Hooks::flush();

aial_test_eq(
	array_column( $writer->inserted, 'table' ),
	array( 'wp_2_digitizer_ai_agent_log' ),
	'a site with the module switched off receives no rows, while the site that has it on still does'
);
aial_test_ok(
	! array_filter( $writer->queries, function ( $sql ) { return false !== strpos( $sql, 'wp_7_digitizer_ai_agent_log' ); } ),
	'and nothing prunes there either, since a disabled site may have no table to prune'
);

// The other half of the question, and the one that turns a dropped row into a
// database error rather than a wrong row: the module is on for site 7, but
// install_table() never ran there - it runs from init(), on the starting site
// alone - so the schema stamp that only appears once the table is confirmed
// present is missing.
$GLOBALS['aial_stub_blog_options'] = array();
$GLOBALS['aial_stub_switch_stack'] = array();
Digitizer_AI_Agent_Log_Buffer::reset();
aial_stub_enter_blog( 2 );
aial_stub_recording();
Digitizer_AI_Agent_Log_Buffer::record( 'post', 'post', 5, 'updated', 'Home on site two' );
switch_to_blog( 7 );
aial_stub_recording();
update_option( 'digitizer_ai_agent_log_schema', '' );
Digitizer_AI_Agent_Log_Buffer::record( 'post', 'post', 5, 'updated', 'Home on site seven' );
restore_current_blog();
$writer->inserted = array();
$writer->queries  = array();
Digitizer_AI_Agent_Log_Hooks::flush();

aial_test_eq(
	array_column( $writer->inserted, 'table' ),
	array( 'wp_2_digitizer_ai_agent_log' ),
	'a site whose table was never installed receives no rows either, rather than inserting into a table that is not there'
);

// A single site takes none of that path: no switch, no restore, and every row
// in the one table it has.
$GLOBALS['aial_stub_multisite']     = false;
$GLOBALS['aial_stub_switch_calls']  = 0;
$GLOBALS['aial_stub_restore_calls'] = 0;
$GLOBALS['aial_stub_options']       = array();
Digitizer_AI_Agent_Log_Buffer::reset();
aial_stub_enter_blog( 1 );
aial_stub_recording();
$writer->inserted = array();
$writer->queries  = array();
Digitizer_AI_Agent_Log_Buffer::record( 'post', 'post', 5, 'updated', 'Home', array( 'post_title' ) );
Digitizer_AI_Agent_Log_Buffer::record( 'post', 'post', 9, 'updated', 'About', array( 'post_title' ) );
Digitizer_AI_Agent_Log_Hooks::flush();

aial_test_eq(
	array_column( $writer->inserted, 'table' ),
	array( 'wp_digitizer_ai_agent_log', 'wp_digitizer_ai_agent_log' ),
	'a single site writes both rows to its one table'
);
aial_test_eq( $GLOBALS['aial_stub_switch_calls'], 0, 'switching no blogs, because there are none to switch to' );
aial_test_eq( $GLOBALS['aial_stub_restore_calls'], 0, 'and restoring none either' );
aial_test_eq( $GLOBALS['aial_stub_current_blog'], 1, 'and staying on the site it was already on' );

// A write that throws mid-flush still leaves the site context as it found it.
// A switch left on the stack would hand the rest of shutdown, and every other
// plugin on it, the wrong site.
class AIAL_Test_Throwing_Writer extends AIAL_Test_Writer {
	public $fail_table = '';
	public function insert( $table, $data, $formats ) {
		if ( '' !== $this->fail_table && $table === $this->fail_table ) {
			throw new RuntimeException( 'the database went away' );
		}
		return parent::insert( $table, $data, $formats );
	}
}

$thrower             = new AIAL_Test_Throwing_Writer();
$thrower->fail_table = 'wp_7_digitizer_ai_agent_log';
Digitizer_AI_Agent_Log_Store::set_writer( $thrower );
$GLOBALS['wpdb'] = $thrower;

$GLOBALS['aial_stub_multisite']     = true;
$GLOBALS['aial_stub_switch_stack']  = array();
$GLOBALS['aial_stub_switch_calls']  = 0;
$GLOBALS['aial_stub_restore_calls'] = 0;
$GLOBALS['aial_stub_options']       = array();
Digitizer_AI_Agent_Log_Buffer::reset();
aial_stub_enter_blog( 2 );
aial_stub_recording();
switch_to_blog( 7 );
aial_stub_recording();
Digitizer_AI_Agent_Log_Buffer::record( 'post', 'post', 5, 'updated', 'Home on site seven' );
restore_current_blog();
$GLOBALS['aial_stub_switch_calls']  = 0;
$GLOBALS['aial_stub_restore_calls'] = 0;

$aial_threw = false;
try {
	Digitizer_AI_Agent_Log_Hooks::flush();
} catch ( RuntimeException $e ) {
	$aial_threw = true;
}

aial_test_ok( $aial_threw, 'a failing write is not swallowed' );
aial_test_eq( $GLOBALS['aial_stub_restore_calls'], 1, 'and the switch it happened inside is restored anyway' );
aial_test_eq( $GLOBALS['aial_stub_current_blog'], 2, 'leaving the request back on its own site' );
aial_test_eq( $GLOBALS['aial_stub_switch_stack'], array(), 'with nothing left on the switched stack' );
aial_test_eq( $thrower->prefix, 'wp_2_', 'and the prefix restored with it' );

$GLOBALS['aial_stub_multisite']     = false;
$GLOBALS['aial_stub_blog_options']  = array();
$GLOBALS['aial_stub_switch_stack']  = array();
$GLOBALS['aial_stub_switch_calls']  = 0;
$GLOBALS['aial_stub_restore_calls'] = 0;
aial_stub_enter_blog( 1 );
$writer = new AIAL_Test_Writer();
Digitizer_AI_Agent_Log_Store::set_writer( $writer );
$GLOBALS['wpdb'] = $writer;

/* ---- the flush runs last on shutdown ---- */

// Deferred writes from another plugin's own shutdown callback are an ordinary
// pattern, and the change listeners stay hooked for the whole of shutdown. A
// flush at the default priority runs before those callbacks, empties the
// buffer, and the change they make is then buffered with nothing left to write
// it. Asserting only that something is hooked would pass at any priority.
$GLOBALS['aial_stub_filters']    = array();
$GLOBALS['aial_stub_doing_cron'] = true;
$_SERVER['REQUEST_METHOD']      = 'GET';
Digitizer_AI_Agent_Log_Hooks::init();
aial_test_eq(
	aial_stub_filter_priority( 'shutdown', array( 'Digitizer_AI_Agent_Log_Hooks', 'flush' ) ),
	9999,
	'the flush is hooked late enough to see a change another plugin defers to its own shutdown callback'
);
$GLOBALS['aial_stub_filters']    = array();
$GLOBALS['aial_stub_doing_cron'] = false;
unset( $_SERVER['REQUEST_METHOD'] );

Digitizer_AI_Agent_Log_Buffer::reset();
$GLOBALS['aial_stub_rest_request']      = false;
$GLOBALS['aial_stub_app_password_uuid'] = null;
$GLOBALS['aial_stub_app_passwords']     = array();
$GLOBALS['aial_stub_options']           = array();

require_once dirname( __DIR__ ) . '/includes/class-digitizer-ai-agent-log-rest.php';

/* ---- the endpoint's argument schema ---- */

$args = Digitizer_AI_Agent_Log_Rest::args();
aial_test_eq( $args['per_page']['default'], 20, 'per_page defaults to twenty' );
aial_test_eq( $args['per_page']['maximum'], 100, 'and is capped at a hundred in the schema, not only in the store' );
aial_test_eq( $args['channel']['enum'], array( 'rest', 'cron', 'cli', 'xmlrpc' ), 'the channel is an enum the core validator can reject against' );
aial_test_ok( isset( $args['object_type']['enum'] ), 'and so is the object type' );

/* ---- who may read it ---- */

// The log names who changed what. edit_posts is not enough to see that.
$GLOBALS['aial_stub_denied_caps'] = array( 'manage_options' );
aial_test_ok( ! Digitizer_AI_Agent_Log_Rest::may_read(), 'a user without manage_options may not read the log' );
$GLOBALS['aial_stub_denied_caps'] = array();
aial_test_ok( Digitizer_AI_Agent_Log_Rest::may_read(), 'and one with it may' );

/* ---- there is no way to erase it over the API ---- */

$GLOBALS['aial_stub_rest_routes'] = array();
Digitizer_AI_Agent_Log_Rest::init();
$key = 'digitizer-ai-agent-log/v1/activity';
aial_test_ok( isset( $GLOBALS['aial_stub_rest_routes'][ $key ] ), 'the route is registered under digitizer-ai-agent-log/v1/activity' );
$methods = array();
foreach ( $GLOBALS['aial_stub_rest_routes'][ $key ] as $registered ) {
	$methods[] = isset( $registered['methods'] ) ? $registered['methods'] : '';
}
aial_test_eq( $methods, array( 'GET' ), 'for GET and nothing else - a log erasable over the API is a log an attacker erases' );

/* ---- handle(): the endpoint's actual output shape ---- */

$writer = new AIAL_Test_Writer();
Digitizer_AI_Agent_Log_Store::set_writer( $writer );

// A normal row, a row whose fields column is malformed JSON, and a row
// whose fields column is null - the two ways a column that is only ever
// written by this module's own insert() could still arrive broken.
$row_normal = (object) array(
	'id'             => 5,
	'logged_at'      => '2026-08-25 10:00:00',
	'channel'        => 'rest',
	'app'            => 'seo-bot',
	'user_id'        => '812', // a numeric string on purpose: handle() must cast it.
	'action'         => 'updated',
	'object_type'    => 'post',
	'object_subtype' => 'page',
	'object_id'      => '20',
	'object_name'    => 'Homepage',
	'fields'         => wp_json_encode( array( 'post_content', 'rank_math_title' ) ),
);
$row_malformed = (object) array(
	'id'             => 6,
	'logged_at'      => '2026-08-25 10:05:00',
	'channel'        => 'cron',
	'app'            => '',
	'user_id'        => 0,
	'action'         => 'created',
	'object_type'    => 'option',
	'object_subtype' => '',
	'object_id'      => 0,
	'object_name'    => 'siteurl',
	'fields'         => 'not json at all',
);
$row_null_fields = (object) array(
	'id'             => 7,
	'logged_at'      => '2026-08-25 10:06:00',
	'channel'        => 'cron',
	'app'            => '',
	'user_id'        => 0,
	'action'         => 'created',
	'object_type'    => 'option',
	'object_subtype' => '',
	'object_id'      => 0,
	'object_name'    => 'blogname',
	'fields'         => null,
);

$writer->rows = array( $row_normal, $row_malformed, $row_null_fields );
$writer->var  = 45; // deliberately not a multiple of the 20-per-page default.

$response = Digitizer_AI_Agent_Log_Rest::handle( new WP_REST_Request( array() ) );
$data     = $response->get_data();

aial_test_eq( $data[0]['fields'], array( 'post_content', 'rank_math_title' ), 'fields comes back as the decoded array of names, not the JSON string' );
aial_test_eq( $data[1]['fields'], array(), 'malformed JSON in the fields column comes back as an empty array' );
aial_test_eq( $data[2]['fields'], array(), 'and a null fields column comes back as an empty array too, not null or false' );

aial_test_eq( $data[0]['id'], 5, 'id is an int' );
aial_test_eq( $data[0]['object_id'], 20, 'so is a numeric-string object_id, cast rather than passed through' );
aial_test_eq( $data[0]['user_id'], 812, 'and a numeric-string user_id casts to an int too' );
aial_test_eq( $data[0]['channel'], 'rest', 'while channel stays a string' );
aial_test_eq( $data[0]['object_name'], 'Homepage', 'and object_name carries the value untouched' );

$headers = $response->get_headers();
aial_test_eq( $headers['X-WP-Total'], '45', 'X-WP-Total carries the store total' );
aial_test_eq( $headers['X-WP-TotalPages'], '3', '45 rows at 20 per page is 3 pages, rounded up rather than floored' );

/* ---- only the parameters actually present reach the store ---- */

$writer->queries = array();
Digitizer_AI_Agent_Log_Rest::handle( new WP_REST_Request( array() ) );
$no_param_sql = implode( ' ', $writer->queries );
aial_test_ok( false === strpos( $no_param_sql, 'WHERE' ), 'a request with no parameters builds no WHERE clause' );

$writer->queries = array();
Digitizer_AI_Agent_Log_Rest::handle( new WP_REST_Request( array( 'channel' => 'cron' ) ) );
$channel_param_sql = implode( ' ', $writer->queries );
aial_test_ok( false !== strpos( $channel_param_sql, "WHERE channel = 'cron'" ), 'and one carrying a channel filters the store query by it' );

/* ---- the schema guard skips dbDelta once the version is current ---- */

// dbDelta runs once per schema version, not on every page load.
$GLOBALS['aial_stub_options'] = array( 'digitizer_ai_agent_log_schema' => Digitizer_AI_Agent_Log_Store::SCHEMA_VERSION );
$GLOBALS['aial_stub_dbdelta_calls'] = 0;
Digitizer_AI_Agent_Log_Store::install_table();
aial_test_eq( $GLOBALS['aial_stub_dbdelta_calls'], 0, 'a table already at this schema version is not rebuilt' );

/* ---- and the stamp is only written once the table is really there ---- */

// dbDelta() lives in wp-admin/includes/upgrade.php, which does not exist
// here; the store requires that file only when the function is missing, so
// this stub stands in for it. It records rather than creates - which is the
// point: dbDelta returns its list of intended changes whether or not the
// queries ran, so the store cannot learn anything from its return value and
// has to go and look for the table.
function dbDelta( $queries = '', $execute = true ) {
	$GLOBALS['aial_stub_dbdelta_calls']++;
	$GLOBALS['aial_stub_dbdelta_sql'] = $queries;
	return array();
}

$writer = new AIAL_Test_Writer();
Digitizer_AI_Agent_Log_Store::set_writer( $writer );

// The table is there afterwards: SHOW TABLES answers with its name.
$GLOBALS['aial_stub_options']       = array();
$GLOBALS['aial_stub_dbdelta_calls'] = 0;
$writer->queries                   = array();
$writer->var                       = 'wp_digitizer_ai_agent_log';
Digitizer_AI_Agent_Log_Store::install_table();
aial_test_eq( $GLOBALS['aial_stub_dbdelta_calls'], 1, 'an unstamped schema version runs dbDelta' );
aial_test_eq( get_option( 'digitizer_ai_agent_log_schema', 'unstamped' ), Digitizer_AI_Agent_Log_Store::SCHEMA_VERSION, 'and stamps the version once the table is confirmed present' );

// _ is a single-character wildcard in LIKE and every table prefix has one, so
// an unescaped name would match wpXdigitizerXaiXagentXlog and report a table
// this plugin never created as its own.
aial_test_ok(
	in_array( "SHOW TABLES LIKE 'wp\_digitizer\_ai\_agent\_log'", $writer->queries, true ),
	'the existence check escapes the underscores rather than leaving them as LIKE wildcards'
);

// The creation failed - a transient database error, a user without CREATE
// rights - so SHOW TABLES finds nothing.
$GLOBALS['aial_stub_options']       = array();
$GLOBALS['aial_stub_dbdelta_calls'] = 0;
$writer->var                       = null;
Digitizer_AI_Agent_Log_Store::install_table();
aial_test_eq( $GLOBALS['aial_stub_dbdelta_calls'], 1, 'a failed creation still only ran dbDelta once' );
aial_test_eq( get_option( 'digitizer_ai_agent_log_schema', 'unstamped' ), 'unstamped', 'but leaves the version unstamped, because a stamp would claim a table that is not there' );

// Which is what makes the next request try again: were the stamp written,
// the version guard would return before dbDelta and the enabled log would
// stay empty forever with nothing to say why.
Digitizer_AI_Agent_Log_Store::install_table();
aial_test_eq( $GLOBALS['aial_stub_dbdelta_calls'], 2, 'so the next request retries instead of returning at the version guard' );

// And it stamps as soon as a retry succeeds.
$writer->var = 'wp_digitizer_ai_agent_log';
Digitizer_AI_Agent_Log_Store::install_table();
aial_test_eq( get_option( 'digitizer_ai_agent_log_schema', 'unstamped' ), Digitizer_AI_Agent_Log_Store::SCHEMA_VERSION, 'and stamps the version on the retry that finally works' );

/* ---- the screen ---- */

require_once dirname( __DIR__ ) . '/includes/class-digitizer-ai-agent-log-admin.php';

// The slice of the admin API render_page() touches, and nothing more.
function esc_html_e( $text, $domain = null ) { echo esc_html( $text ); }
function esc_attr_e( $text, $domain = null ) { echo esc_attr( $text ); }
function selected( $selected, $current = true, $display = true ) {
	$out = ( (string) $selected === (string) $current ) ? " selected='selected'" : '';
	if ( $display ) {
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	return $out;
}
function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
	if ( $display ) {
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="nonce" />';
	}
}

// wp_date() rendered in a fixed, deliberately non-UTC zone. Core's own
// implementation formats the timestamp in the given timezone
// (wp-includes/functions.php:243); date_i18n() with an explicit timestamp does
// not - it treats the value as already carrying an offset - and that is the
// difference this stub has to keep visible. A stub that ignored the zone, or a
// test under UTC, could not tell the two apart.
$GLOBALS['aial_stub_timezone'] = 'Asia/Jerusalem';
function wp_date( $format, $timestamp = null, $timezone = null ) {
	$dt = new DateTimeImmutable( '@' . (int) $timestamp );
	return $dt->setTimezone( new DateTimeZone( $GLOBALS['aial_stub_timezone'] ) )->format( (string) $format );
}

// wp_timezone() (wp-includes/functions.php:152-154) wraps wp_timezone_string(),
// which returns either the site's named zone or a "+HH:MM" string built from
// gmt_offset (lines 124-141) - DateTimeZone accepts both, so this one stub
// covers a named zone and a raw offset alike; the tests below exercise it as
// both.
function wp_timezone() {
	return new DateTimeZone( $GLOBALS['aial_stub_timezone'] );
}

$GLOBALS['aial_stub_denied_caps'] = array();
$GLOBALS['aial_stub_options']     = array(
	'date_format' => 'Y-m-d',
	'time_format' => 'H:i',
);

$writer = new AIAL_Test_Writer();
Digitizer_AI_Agent_Log_Store::set_writer( $writer );

/**
 * Render the screen for a given query string and hand back the HTML and the
 * SQL it built.
 */
function aial_render( $get ) {
	global $writer;
	$_GET            = $get;
	$writer->queries = array();
	ob_start();
	$admin = new Digitizer_AI_Agent_Log_Admin();
	$admin->render_page();
	$html = (string) ob_get_clean();
	return array( $html, implode( ' ', $writer->queries ) );
}

// The 'When' column. The row is stored in UTC, as every logged_at is, and the
// screen belongs to a person reading it in the site's timezone.
$writer->rows = array(
	(object) array(
		'id'             => 1,
		'logged_at'      => '2026-08-25 12:00:00',
		'channel'        => 'rest',
		'app'            => 'ContentEngine',
		'user_id'        => 1,
		'action'         => 'updated',
		'object_type'    => 'post',
		'object_subtype' => 'page',
		'object_id'      => 20,
		'object_name'    => 'Homepage',
		'fields'         => wp_json_encode( array( 'post_title' ) ),
	),
);

list( $aial_html ) = aial_render( array() );

// Jerusalem is UTC+3 in August. date_i18n() with an explicit timestamp reads
// it as a value that already carries an offset and hands back 12:00 wearing a
// local label; wp_date() renders the moment, which is 15:00 there.
aial_test_ok( false !== strpos( $aial_html, '2026-08-25 15:00' ), 'a row stored at 12:00 UTC shows as the local 15:00, not the UTC wall time' );
aial_test_ok( false === strpos( $aial_html, '2026-08-25 12:00' ), 'and the UTC wall time is not what appears on the screen' );

// The same row under a zone behind UTC, so a fix that merely added three
// hours somewhere would not pass either.
$GLOBALS['aial_stub_timezone'] = 'America/New_York';
list( $aial_html )          = aial_render( array() );
aial_test_ok( false !== strpos( $aial_html, '2026-08-25 08:00' ), 'and under a zone behind UTC it shows as 08:00, so the offset is read from the site rather than assumed' );
$GLOBALS['aial_stub_timezone'] = 'Asia/Jerusalem';

/* ---- the date range filters the spec promised ---- */

// With only the newest hundred rows on the screen, an administrator with an
// incident window and no date controls cannot narrow to it at all.
//
// The boxes are picked against the *locally rendered* date - Jerusalem is
// still the active zone here, the same one the 'When' column above was just
// proven to render in - so the bound the store receives is not the literal
// UTC value of the typed string. It is that local day's boundary, converted
// to UTC: Jerusalem is UTC+3 in August, so local 2026-08-01 00:00:00 is
// 2026-07-31 21:00:00 UTC, and local 2026-08-25 23:59:59 is 2026-08-25
// 20:59:59 UTC. A store that received the boxes' text unconverted - the
// previous round's bug - would produce '2026-08-01 00:00:00' and
// '2026-08-25 23:59:59' instead, so this assertion fails without the fix.
list( $aial_html, $aial_sql ) = aial_render( array( 'after' => '2026-08-01', 'before' => '2026-08-25' ) );

aial_test_ok( false !== strpos( $aial_sql, "logged_at >= '2026-07-31 21:00:00'" ), 'the From date reaches the store as a lower bound on logged_at, converted from the local day to UTC' );
// Inclusive at the top end: someone who types the same date in both boxes
// means that day, not an empty range from its midnight to its midnight.
aial_test_ok( false !== strpos( $aial_sql, "logged_at <= '2026-08-25 20:59:59'" ), 'and the To date as an upper bound covering the whole of that local day, not its opening second, likewise converted to UTC' );
aial_test_ok( false !== strpos( $aial_html, 'name="after" value="2026-08-01"' ), 'the From box keeps what was submitted after the reload' );
aial_test_ok( false !== strpos( $aial_html, 'name="before" value="2026-08-25"' ), 'and so does the To box' );

// A single day, both ends the same: the range has to contain the day itself,
// again in the site's timezone - local 2026-08-25 00:00:00 is 2026-08-24
// 21:00:00 UTC.
list( , $aial_sql ) = aial_render( array( 'after' => '2026-08-25', 'before' => '2026-08-25' ) );
aial_test_ok(
	false !== strpos( $aial_sql, "logged_at >= '2026-08-24 21:00:00'" ) && false !== strpos( $aial_sql, "logged_at <= '2026-08-25 20:59:59'" ),
	'one day in both boxes is that whole local day, rather than a range that can hold nothing'
);

// The finding itself, proved against the bounds the running code just
// produced (extracted from $aial_sql above, not recomputed by hand): a row
// stored at 22:30 UTC on the 24th displays as the 25th in Jerusalem (UTC+3
// puts it at 01:30 local on the 25th). Filtering for the 25th in both boxes
// must include it. This is the one case a test run under UTC cannot catch,
// because under UTC the local and UTC days coincide and the bug is invisible.
preg_match( "/logged_at >= '([^']+)'/", $aial_sql, $aial_lower_m );
preg_match( "/logged_at <= '([^']+)'/", $aial_sql, $aial_upper_m );
$aial_lower_bound = $aial_lower_m[1];
$aial_upper_bound = $aial_upper_m[1];

$aial_p2_row = '2026-08-24 22:30:00';
aial_test_ok(
	$aial_p2_row >= $aial_lower_bound && $aial_p2_row <= $aial_upper_bound,
	'a row whose UTC day is the 24th but whose local Jerusalem day is the 25th falls inside the bounds the store actually received for a "25th in both boxes" filter'
);

// The mirror image: a row that displays on the 26th in Jerusalem - stored at
// 21:30 UTC on the 25th, which is 00:30 local on the 26th - must fall
// outside the same filter's upper bound, again checked against the bound the
// running code actually sent.
$aial_next_day_row = '2026-08-25 21:30:00';
aial_test_ok(
	$aial_next_day_row > $aial_upper_bound,
	'a row that displays on the local day after the one selected falls outside the upper bound the store actually received for that selection'
);

// A date that is not a date. strtotime() in the store would happily read
// "next tuesday", and rolls 2026-13-45 forward into 2027 rather than
// rejecting it - so the format is checked before it ever gets there.
// It is dropped rather than kept, too: reflecting it back into the box would
// leave the screen showing a filter that is not being applied.
list( , $aial_sql ) = aial_render( array( 'after' => 'next tuesday' ) );
aial_test_ok( false === strpos( $aial_sql, 'logged_at' ), 'a relative phrase strtotime() would happily parse narrows nothing rather than inventing a range' );

foreach ( array( '2026-13-45', 'next tuesday', '25/08/2026', '2026-08-25 12:00:00', 'nonsense' ) as $aial_bad ) {
	list( $aial_html ) = aial_render( array( 'after' => $aial_bad ) );
	aial_test_ok( false === strpos( $aial_html, 'value="' . esc_attr( $aial_bad ) . '"' ), 'and a malformed date is not shown back in the box as a filter that is not in force (' . $aial_bad . ')' );
}

// Absent parameters build no bound at all.
list( , $aial_sql ) = aial_render( array() );
aial_test_ok( false === strpos( $aial_sql, 'logged_at' ), 'and a screen loaded with no dates bounds nothing' );

// An array-valued parameter must not reach a string function: the scalar
// guard comes first, exactly as it does for the two enum filters, or PHP 8
// raises a TypeError and the screen is a fatal instead of a list.
list( , $aial_sql ) = aial_render( array( 'after' => array( '2026-08-01' ), 'before' => array() ) );
aial_test_ok( false === strpos( $aial_sql, 'logged_at' ), 'an array-valued date parameter is dropped rather than handed to a string function' );

// A site with no named timezone, only a raw gmt_offset - wp_timezone_string()
// (wp-includes/functions.php:124-141) formats that into a "+HH:MM" string
// rather than returning a zone name, and DateTimeZone accepts that string
// exactly as it accepts 'Asia/Jerusalem'. +02:00 puts local midnight on the
// 25th at 2026-08-24 22:00:00 UTC, and local 23:59:59 on the 25th at
// 2026-08-25 21:59:59 UTC.
$GLOBALS['aial_stub_timezone'] = '+02:00';
list( , $aial_sql )         = aial_render( array( 'after' => '2026-08-25', 'before' => '2026-08-25' ) );
aial_test_ok(
	false !== strpos( $aial_sql, "logged_at >= '2026-08-24 22:00:00'" ) && false !== strpos( $aial_sql, "logged_at <= '2026-08-25 21:59:59'" ),
	'a site configured with a raw UTC offset instead of a named timezone gets the same local-day-to-UTC conversion'
);
$GLOBALS['aial_stub_timezone'] = 'Asia/Jerusalem';

// The controls are on the screen and inside the filter form, so submitting
// them keeps the channel and object type the administrator already chose.
list( $aial_html ) = aial_render( array( 'channel' => 'cron' ) );
aial_test_ok( false !== strpos( $aial_html, 'name="after"' ), 'the From control is rendered' );
aial_test_ok( false !== strpos( $aial_html, 'name="before"' ), 'and the To control with it' );
aial_test_ok( substr_count( $aial_html, '<form method="get">' ) === 1 && strpos( $aial_html, 'name="after"' ) > strpos( $aial_html, '<form method="get">' ), 'both inside the one filter form, so a date submits alongside the other filters' );

$_GET                        = array();
$GLOBALS['aial_stub_options'] = array();

/* ---- uninstalling removes the log from every site of a network ---- */

// The uninstaller is a plain script, so the pieces of WordPress it reaches
// for are stubbed here and it is required directly. WordPress runs it once
// per network, not once per site, which is the whole point of the loop.
class AIAL_Test_Uninstall_DB {
	public $prefix  = 'wp_';
	public $queries = array();
	public function query( $sql ) {
		$this->queries[] = $sql;
		return 1;
	}
}

$GLOBALS['aial_stub_sites']         = array();
$GLOBALS['aial_stub_site_args']     = array();
$GLOBALS['aial_stub_switch_stack']  = array();
$GLOBALS['aial_stub_switch_calls']  = 0;
$GLOBALS['aial_stub_restore_calls'] = 0;
$GLOBALS['aial_stub_deleted']       = array();
$GLOBALS['aial_stub_current_blog']  = 1;

function get_sites( $args = array() ) {
	$GLOBALS['aial_stub_site_args'] = $args;
	return $GLOBALS['aial_stub_sites'];
}
function get_current_blog_id() {
	return $GLOBALS['aial_stub_current_blog'];
}
// Core swaps $wpdb->prefix for the switched site's, which is what makes the
// table name and the options resolve per site: switch_to_blog() calls
// wpdb::set_blog_id() (wp-includes/ms-blogs.php:534), which reassigns
// $wpdb->prefix from the new site's blog id (wp-includes/class-wpdb.php:1051).
// The store reads that same property through its writer, so a double whose
// prefix stayed put would let a test pass while production wrote every row to
// one table - which is the bug this is here to catch. It follows that a test
// that means to exercise the switch must make $GLOBALS['wpdb'] the very object
// it gave Digitizer_AI_Agent_Log_Store::set_writer(), exactly as production has one $wpdb
// serving both roles.
//
// Options are per site too, so the switch swaps the whole option set for that
// site's, the way a real options table would.
function aial_stub_blog_prefix( $blog_id ) {
	return ( 1 === (int) $blog_id ) ? 'wp_' : 'wp_' . (int) $blog_id . '_';
}
function aial_stub_enter_blog( $blog_id ) {
	$GLOBALS['aial_stub_current_blog'] = $blog_id;
	if ( isset( $GLOBALS['wpdb'] ) ) {
		$GLOBALS['wpdb']->prefix = aial_stub_blog_prefix( $blog_id );
	}
	$GLOBALS['aial_stub_options'] = isset( $GLOBALS['aial_stub_blog_options'][ $blog_id ] )
		? $GLOBALS['aial_stub_blog_options'][ $blog_id ]
		: array();
}
function switch_to_blog( $blog_id ) {
	$GLOBALS['aial_stub_switch_calls']++;
	$GLOBALS['aial_stub_switch_stack'][] = $GLOBALS['aial_stub_current_blog'];
	$GLOBALS['aial_stub_blog_options'][ $GLOBALS['aial_stub_current_blog'] ] = $GLOBALS['aial_stub_options'];
	aial_stub_enter_blog( $blog_id );
	return true;
}
function restore_current_blog() {
	$GLOBALS['aial_stub_restore_calls']++;
	$GLOBALS['aial_stub_blog_options'][ $GLOBALS['aial_stub_current_blog'] ] = $GLOBALS['aial_stub_options'];
	aial_stub_enter_blog( array_pop( $GLOBALS['aial_stub_switch_stack'] ) );
	return true;
}
function delete_option( $key ) {
	$GLOBALS['aial_stub_deleted'][] = $GLOBALS['aial_stub_current_blog'] . ':' . $key;
	unset( $GLOBALS['aial_stub_options'][ $key ] );
	return true;
}
function delete_site_option( $key ) {
	$GLOBALS['aial_stub_deleted'][] = 'network:' . $key;
	return true;
}

define( 'WP_UNINSTALL_PLUGIN', 'digitizer-ai-agent-log/digitizer-ai-agent-log.php' );

// A single site: one table dropped, one pair of options deleted, and no
// switching at all - exactly what the file did before it learned to loop.
$GLOBALS['aial_stub_multisite'] = false;
$wpdb                          = new AIAL_Test_Uninstall_DB();
$GLOBALS['wpdb']               = $wpdb;
require dirname( __DIR__ ) . '/uninstall.php';

aial_test_eq( $wpdb->queries, array( 'DROP TABLE IF EXISTS `wp_digitizer_ai_agent_log`' ), 'a single site drops its one log table' );
aial_test_eq( $GLOBALS['aial_stub_switch_calls'], 0, 'and switches no blogs, because there are none to switch to' );
$aial_single_deleted = array_values( array_filter( $GLOBALS['aial_stub_deleted'], function ( $entry ) { return false !== strpos( $entry, 'digitizer_ai_agent_log' ); } ) );
aial_test_eq( $aial_single_deleted, array( '1:digitizer_ai_agent_log_schema', '1:digitizer_ai_agent_log_last_prune' ), 'and deletes both of the log stamps' );

// A network of three: the table and both stamps go from every one of them,
// not only from the site the uninstall happened to run in. Site 4 carries a
// prefix of its own, so a drop that ignored the switch would name wp_ three
// times over.
$GLOBALS['aial_stub_multisite']     = true;
$GLOBALS['aial_stub_sites']         = array( 1, 2, 4 );
$GLOBALS['aial_stub_switch_calls']  = 0;
$GLOBALS['aial_stub_restore_calls'] = 0;
$GLOBALS['aial_stub_deleted']       = array();
$wpdb                              = new AIAL_Test_Uninstall_DB();
$GLOBALS['wpdb']                   = $wpdb;
require dirname( __DIR__ ) . '/uninstall.php';

aial_test_eq(
	$wpdb->queries,
	array(
		'DROP TABLE IF EXISTS `wp_digitizer_ai_agent_log`',
		'DROP TABLE IF EXISTS `wp_2_digitizer_ai_agent_log`',
		'DROP TABLE IF EXISTS `wp_4_digitizer_ai_agent_log`',
	),
	'every site on the network loses its own log table, named from that site prefix'
);

$aial_network_deleted = array_values( array_filter( $GLOBALS['aial_stub_deleted'], function ( $entry ) { return false !== strpos( $entry, 'digitizer_ai_agent_log_' ); } ) );
aial_test_eq(
	$aial_network_deleted,
	array(
		'1:digitizer_ai_agent_log_schema',
		'1:digitizer_ai_agent_log_last_prune',
		'2:digitizer_ai_agent_log_schema',
		'2:digitizer_ai_agent_log_last_prune',
		'4:digitizer_ai_agent_log_schema',
		'4:digitizer_ai_agent_log_last_prune',
	),
	'and both of its stamps, deleted in that site context rather than the uninstalling one'
);

aial_test_eq( $GLOBALS['aial_stub_switch_calls'], 3, 'one switch per site' );
aial_test_eq( $GLOBALS['aial_stub_restore_calls'], 3, 'each paired with a restore, so core switched stack ends balanced' );
aial_test_eq( $GLOBALS['aial_stub_switch_stack'], array(), 'leaving nothing on the stack' );
aial_test_eq( $wpdb->prefix, 'wp_', 'and the prefix back where it started' );

// get_sites() stops at 100 sites unless the cap is lifted, and a network
// larger than that is exactly where an orphaned table would go unnoticed.
aial_test_eq( $GLOBALS['aial_stub_site_args'], array( 'fields' => 'ids', 'number' => 0 ), 'the site query asks for ids and lifts the 100-site default cap' );

// Nothing outside this plugin's own two options and its table is touched.
// The stub records every delete, so anything else showing up here would be
// an uninstall reaching past the plugin that owns it.
aial_test_eq(
	array_values( array_unique( array_map( function ( $entry ) { return substr( $entry, strpos( $entry, ':' ) + 1 ); }, $GLOBALS['aial_stub_deleted'] ) ) ),
	array( 'digitizer_ai_agent_log_schema', 'digitizer_ai_agent_log_last_prune' ),
	'and deletes nothing but its own two options'
);

/* ---- silencing a writer the site does not care about ---- */

// A plugin that rewrites its own settings whenever it loads produces a real
// change - core refuses an identical meta write before the hook ever fires, so
// anything that reaches the log did alter the row. It is still not a change
// anybody asked about, and only the site can say which of its writers those
// are. One filter, over the finished entry, at the moment it would be written.
$writer = new AIAL_Test_Writer();
Digitizer_AI_Agent_Log_Store::set_writer( $writer );
$GLOBALS['aial_stub_options']           = array();
$GLOBALS['aial_stub_multisite']         = false;
$GLOBALS['aial_stub_rest_request']      = false;
$GLOBALS['aial_stub_doing_cron']        = false;
$GLOBALS['aial_stub_app_password_uuid'] = null;
aial_stub_recording();
$GLOBALS['aial_stub_filters'] = array();

function aial_two_changes() {
	Digitizer_AI_Agent_Log_Buffer::reset();
	Digitizer_AI_Agent_Log_Hooks::on_option_updated( 'siteurl' );
	Digitizer_AI_Agent_Log_Hooks::on_plugin_activated( 'akismet/akismet.php' );
}

$GLOBALS['aial_stub_doing_cron'] = true;
$writer->inserted = array();
aial_two_changes();
Digitizer_AI_Agent_Log_Hooks::flush();
aial_test_eq( count( $writer->inserted ), 2, 'with no filter attached both changes are written' );

// The entry the filter is handed carries the channel and the application
// name, which is one of the two reasons the gate runs at flush: neither is
// knowable while the request is still running.
$GLOBALS['aial_seen'] = array();
add_filter(
	'digitizer_ai_agent_log_record',
	function ( $record, $entry ) {
		$GLOBALS['aial_seen'][] = $entry;
		return ( 'plugin' === $entry['object_type'] ) ? false : $record;
	}
);

$writer->inserted = array();
aial_two_changes();
Digitizer_AI_Agent_Log_Hooks::flush();
aial_test_eq( count( $writer->inserted ), 1, 'a filter returning false drops that entry' );
aial_test_eq( $writer->inserted[0]['data']['object_type'], 'option', 'and leaves every other one alone' );
aial_test_eq( count( $GLOBALS['aial_seen'] ), 2, 'the filter is offered every entry, not only the ones that survive' );
aial_test_eq( $GLOBALS['aial_seen'][0]['channel'], 'cron', 'and each one carries the channel' );
aial_test_ok( array_key_exists( 'app', $GLOBALS['aial_seen'][0] ), 'and the application password name' );
aial_test_ok( array_key_exists( 'fields', $GLOBALS['aial_seen'][0] ), 'and the field names it touched' );

// Anything falsy refuses; the value is not trusted to be a boolean. And a
// request whose every row was filtered away has changed nothing on this site,
// so it must not stamp the prune throttle - the same rule a poll that changed
// nothing already follows.
$GLOBALS['aial_stub_filters'] = array();
add_filter( 'digitizer_ai_agent_log_record', function () { return 0; } );
$writer->inserted = array();
$writer->queries  = array();
unset( $GLOBALS['aial_stub_options']['digitizer_ai_agent_log_last_prune'] );
aial_two_changes();
Digitizer_AI_Agent_Log_Hooks::flush();
aial_test_eq( $writer->inserted, array(), 'a falsy non-boolean refuses too, and nothing is written' );
aial_test_eq( $writer->queries, array(), 'and nothing is pruned' );
aial_test_ok( ! isset( $GLOBALS['aial_stub_options']['digitizer_ai_agent_log_last_prune'] ), 'and the prune throttle is not stamped for a request that wrote nothing' );

$GLOBALS['aial_stub_filters']    = array();
$GLOBALS['aial_stub_doing_cron'] = false;
Digitizer_AI_Agent_Log_Buffer::reset();

// And it runs inside the site the entry belongs to. One CLI or cron request
// can walk a whole network, and by the time flush() runs the originating site
// is current again - so a callback that reads get_option() to decide what to
// silence would apply the first site's policy to every other one. The filter
// is asked after the switch, which is the only place that answer is right.
$GLOBALS['wpdb']                    = $writer;
$GLOBALS['aial_stub_multisite']     = true;
$GLOBALS['aial_stub_doing_cron']    = true;
$GLOBALS['aial_stub_current_blog']  = 1;
$GLOBALS['aial_stub_switch_stack']  = array();
$GLOBALS['aial_stub_switch_calls']  = 0;
$GLOBALS['aial_stub_restore_calls'] = 0;
$GLOBALS['aial_stub_blog_options']  = array(
	1 => array( 'digitizer_ai_agent_log_schema' => Digitizer_AI_Agent_Log_Store::SCHEMA_VERSION ),
	2 => array( 'digitizer_ai_agent_log_schema' => Digitizer_AI_Agent_Log_Store::SCHEMA_VERSION ),
);
aial_stub_enter_blog( 1 );

Digitizer_AI_Agent_Log_Buffer::reset();
Digitizer_AI_Agent_Log_Hooks::on_option_updated( 'siteurl' );
switch_to_blog( 2 );
Digitizer_AI_Agent_Log_Hooks::on_option_updated( 'blogname' );
restore_current_blog();

$GLOBALS['aial_context'] = array();
$GLOBALS['aial_stub_filters'] = array();
add_filter(
	'digitizer_ai_agent_log_record',
	function ( $record, $entry ) {
		$GLOBALS['aial_context'][ (int) $entry['blog_id'] ] = (int) get_current_blog_id();
		return $record;
	}
);

$writer->inserted = array();
Digitizer_AI_Agent_Log_Hooks::flush();

aial_test_eq( $GLOBALS['aial_context'], array( 1 => 1, 2 => 2 ), 'each entry is offered to the filter inside its own site' );
aial_test_eq( count( $writer->inserted ), 2, 'and both are written when the filter keeps them' );

$GLOBALS['aial_stub_filters']    = array();
$GLOBALS['aial_stub_multisite']  = false;
$GLOBALS['aial_stub_doing_cron'] = false;
aial_stub_enter_blog( 1 );
Digitizer_AI_Agent_Log_Buffer::reset();

aial_test_summary();
