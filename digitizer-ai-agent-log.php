<?php
/**
 * Plugin Name:       Digitizer AI Agent Log
 * Plugin URI:        https://github.com/Digitizers/digitizer-ai-agent-log
 * Description:       Records what automations changed on this site - anything that arrived over the REST API, WP-Cron, WP-CLI or XML-RPC. A change made by a person in wp-admin is not recorded at all, so the log is what the agents did and nothing else.
 * Version:           1.2.1
 * Requires at least: 5.5
 * Requires PHP:      7.2
 * Author:            Digitizer
 * Author URI:        https://www.digitizer.studio
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       digitizer-ai-agent-log
 *
 * @package Digitizer_AI_Agent_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DIGITIZER_AI_AGENT_LOG_VERSION', '1.2.1' );
define( 'DIGITIZER_AI_AGENT_LOG_PATH', plugin_dir_path( __FILE__ ) );

require_once DIGITIZER_AI_AGENT_LOG_PATH . 'includes/class-digitizer-ai-agent-log-channel.php';
require_once DIGITIZER_AI_AGENT_LOG_PATH . 'includes/class-digitizer-ai-agent-log-store.php';
require_once DIGITIZER_AI_AGENT_LOG_PATH . 'includes/class-digitizer-ai-agent-log-buffer.php';
require_once DIGITIZER_AI_AGENT_LOG_PATH . 'includes/class-digitizer-ai-agent-log-hooks.php';
require_once DIGITIZER_AI_AGENT_LOG_PATH . 'includes/class-digitizer-ai-agent-log-rest.php';
require_once DIGITIZER_AI_AGENT_LOG_PATH . 'includes/class-digitizer-ai-agent-log-core.php';

if ( is_admin() ) {
	require_once DIGITIZER_AI_AGENT_LOG_PATH . 'includes/class-digitizer-ai-agent-log-admin.php';
}

add_action( 'plugins_loaded', array( 'Digitizer_AI_Agent_Log_Core', 'boot' ) );
