<?php

// Prevent loading this file directly - Busted!
if ( !defined('ABSPATH') )
	die('-1');

if ( ! class_exists( 'EUAPI' ) ) :

class EUAPI {

	var $handlers = array();

	public function __construct( $config = array() ) {

		add_filter( 'http_request_args',                     array( $this, 'http_request_args' ), 20, 2 );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_plugins' ) );
		add_filter( 'pre_set_site_transient_update_themes',  array( $this, 'check_themes' ) );

		add_filter( 'http_request_timeout',                  array( $this, 'http_request_timeout' ) );

		add_filter( 'plugins_api',                           array( $this, 'get_plugin_info' ), 10, 3 );

		add_filter( 'upgrader_post_install',                 array( $this, 'upgrader_post_install' ), 10, 3 );

	}

	function http_request_args( $args, $url ) {

		if ( 0 === strpos( $url, 'http://api.wordpress.org/plugins/update-check/' ) )
			$this->plugin_request( &$args );
		else if ( 0 === strpos( $url, 'http://api.wordpress.org/themes/update-check/' ) )
			$this->theme_request( &$args );

		# @TODO decide how best to do this
		$args['sslverify'] = false;

		return $args;

	}

	function plugin_request( $args ) {

		$plugins = unserialize( $args['body']['plugins'] );

		foreach ( $plugins->plugins as $plugin => $data ) {

			$item = new EUAPI_Item_Plugin( $plugin, $data );

			if ( $handler = $this->get_handler( 'plugin', $plugin, $item ) ) {
				$handler->item = $item;
				unset( $plugins->plugins[$plugin] );
			}

		}

		$args['body']['plugins'] = serialize( $plugins );

	}

	function theme_request( $args ) {

		$themes = unserialize( $args['body']['themes'] );

		foreach ( $themes->themes as $theme => $data ) {

			$item = new EUAPI_Item_Theme( $theme, $data );

			if ( $handler = $this->get_handler( 'theme', $theme, $item ) ) {
				$handler->item = $item;
				unset( $themes->themes[$theme] );
			}

		}

		$args['body']['themes'] = serialize( $themes );

	}

	function check_plugins( $transient ) {
		if ( !isset( $this->handlers['plugin'] ) )
			return $transient;
		return $this->check( $transient, $this->handlers['plugin'] );
	}

	function check_themes( $transient ) {
		if ( !isset( $this->handlers['theme'] ) )
			return $transient;
		return $this->check( $transient, $this->handlers['theme'] );
	}

	public function check( $transient, $handlers ) {

		if ( empty( $transient->checked ) )
			return $transient;

		foreach ( $handlers as $handler ) {

			$update = $handler->get_update();

			if ( $update->get_new_version() and version_compare( $update->get_new_version(), $handler->get_current_version() ) )
				$transient->response[ $handler->get_file() ] = $update->get_data_to_store();

		}

		return $transient;

	}

	function get_handler( $type, $file, $item = null ) {

		if ( isset( $this->handlers[$type][$file] ) )
			return $this->handlers[$type][$file];

		if ( !$item )
			$item = $this->populate_item( $type, $file );

		if ( !$item )
			return false;

		$handler = apply_filters( "euapi_{$type}_handler", false, $item );

		if ( is_a( $handler, 'EUAPI_Handler_Base' ) )
			$this->handlers[$type][$file] = $handler;

		return $handler;

	}

	function populate_item( $type, $file ) {

		$func = "get_{$type}_data";
		$data = $this->$func( $file );

		if ( $data ) {
			switch ( $type ) {
				case 'plugin':
					return new EUAPI_Item_Plugin( $file, $data );
					break;
				case 'theme':
					return new EUAPI_Item_Theme( $file, $data );
					break;
			}
		}

		return false;

	}

	/**
	 * Get Plugin data
	 *
	 * @since 1.0
	 * @return object $data the data
	 */
	public function get_plugin_data( $file ) {

		require_once ABSPATH . '/wp-admin/includes/plugin.php';

		return get_plugin_data( WP_PLUGIN_DIR . '/' . $file );

	}

	function get_theme_data( $file ) {
		return array();
	}

	/**
	 * Get Plugin info
	 *
	 * @since 1.0
	 * @param bool $false always false
	 * @param string $action the API function being performed
	 * @param object $args plugin arguments
	 * @return object $response the plugin info
	 */
	public function get_plugin_info( $false, $action, $response ) {

		if ( 'plugin_information' != $action )
			return $false;
		if ( false === strpos( $response->slug, '/' ) )
			return $false;

		if ( !( $handler = $this->get_handler( 'plugin', $response->slug ) ) )
			return $false;

		$info = $handler->get_info();

		if ( !$info )
			return new WP_Error( 'plugins_api_failed', __( 'Unable to connect to update server.', 'euapi' ) );

		return $info;

	}

	function fetch( $url, $args = array() ) {

		$args = wp_parse_args( $args, array(
			'sslverify' => $this->config['sslverify']
		) );

		$response = wp_remote_get( $url, $args );

		if ( !$response or is_wp_error( $response ) )
			return false;

		return wp_remote_retrieve_body( $response );

	}

	function get_content_data( $content, $all_headers ) {

		# @see WordPress' get_file_data()

		// Pull only the first 8kiB of the file in.
		if ( function_exists( 'mb_substr' ) )
			$file_data = mb_substr( $content, 0, 8192 );
		else
			$file_data = substr( $content, 0, 8192 );

		// Make sure we catch CR-only line endings.
		$file_data = str_replace( "\r", "\n", $file_data );

		foreach ( $all_headers as $field => $regex ) {
			if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $regex, '/' ) . ':(.*)$/mi', $file_data, $match ) && $match[1] )
				$all_headers[ $field ] = _cleanup_header_comment( $match[1] );
			else
				$all_headers[ $field ] = '';
		}

		return $all_headers;
	}

	/**
	 * Callback fn for the http_request_timeout filter
	 *
	 * @since 1.0
	 * @return int timeout value
	 */
	public function http_request_timeout() {
		return 2;
	}

	public function upgrader_post_install( $true, $hook_extra, $result ) {

		global $wp_filesystem;

		if ( isset( $hook_extra['plugin'] ) )
			$handler = $this->get_handler( 'plugin', $hook_extra['plugin'] );
		else if ( isset( $hook_extra['theme'] ) )
			$handler = $this->get_handler( 'theme', $hook_extra['theme'] );
		else
			return $true;

		// Move
		$proper_destination = WP_PLUGIN_DIR . '/' . $handler->config['folder_name'];
		$move = $wp_filesystem->move( $result['destination'], $proper_destination );
		$result['destination'] = $proper_destination;

		return $result;

	}

}

endif; // endif class exists
