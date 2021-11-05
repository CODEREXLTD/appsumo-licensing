<?php

/**
 * Define the internationalization functionality
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @link       https://getwpfunnels.com
 * @since      1.0.0
 *
 * @package    Appsumo_Licensing
 * @subpackage Appsumo_Licensing/includes
 */

/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      1.0.0
 * @package    Appsumo_Licensing
 * @subpackage Appsumo_Licensing/includes
 * @author     WPFunnels Team <admin@getwpfunnels.com>
 */
class Appsumo_Licensing_i18n {


	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since    1.0.0
	 */
	public function load_plugin_textdomain() {

		load_plugin_textdomain(
			'appsumo-licensing',
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);

	}



}
