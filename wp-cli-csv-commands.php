<?php
/**
 * Plugin Name: WP CLI Extension for Loading CSV data
 * Plugin URI: https://github.com/kdmurthy/wp-cli-csv-commands
 * Description: Import CSV Data to WordPress
 * Author: Dakshinamurthy Karra
 * Author URI: https://github.com/kdmurthy/wp-cli-csv-commands
 * Version: 0.1.0
 * Text Domain: wp-cli-csv-commands
 * Domain Path: /languages
 * Requires at least: 4.7.0
 * Tested up to: 4.7.1
 *
 * @package extensions
 */

/**
 * Load extension if WP CLI exists.
 *
 * @return void
 * @since 1.0.0
 */
function wp_cli_csv_commands_load() {

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		include dirname( __FILE__ ) . '/class-csvcommands.php';
	}

}
if ( function_exists( 'add_action' ) ) {
	add_action( 'plugins_loaded', 'wp_cli_csv_commands_load' );
} else {
	wp_cli_csv_commands_load();
}
