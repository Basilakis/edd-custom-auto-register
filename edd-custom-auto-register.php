<?php
/*
Plugin Name: Easy Digital Downloads - Custom Auto Register
Plugin URI: https://mc4wp.com/
Description: Automatically creates a WP user account at checkout, based on customer's email address.
Version: 2.0
Author: Danny van Kooten
Contributors: dannyvankooten
Author URI: http://dvk.co/
Text Domain: edd-custom-auto-register
Domain Path: languages
License: GPL-2.0+
License URI: http://www.opensource.org/licenses/gpl-license.php
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


final class EDD_Custom_Auto_Register {

	const FILE = __FILE__;
	const DIR = __DIR__;
	const VERSION = '2.0';


	/**
	 * Constructor Function
	 *
	 * @since  1.0
	 * @access public
	 */
	public function __construct() {}


	/**
	 * Setup the default hooks and actions
	 *
	 * @since 1.0
	 *
	 * @return void
	 */
	public function hook() {

		// accounts are never pending (since we use email verification anyway)
		add_filter( 'edd_user_pending_verification', '__return_false' );

		// create user when purchase is created
		add_action( 'edd_insert_payment', array( $this, 'maybe_insert_user' ), 10, 2 );

		// login customer if this is his or hers first payment ever
		// this needs prio 9 because email notification resets the password right now\
		// @todo fix this
//		add_action( 'edd_auto_register_insert_user', array( $this, 'login_customer' ), 9, 3 );

		// add our new email notifications
		add_action( 'edd_auto_register_insert_user', array( $this, 'email_notifications' ), 10, 3 );

		// Ensure registration form is never shown
		add_filter( 'edd_get_option_show_register_form', array( $this, 'remove_register_form' ), 10, 3 );
	}

	/**
	 * We're removing this meta since we don't use the pending functionality
	 *
	 * @param $user_id
	 */
	public function remove_pending_verification_meta( $user_id ) {
		delete_user_meta( $user_id, '_edd_pending_verification' );
	}


	/**
	 * Notifications
	 * Sends the user an email with their logins details and also sends the site admin an email notifying them of a signup
	 *
	 * @since 1.1
	 */
	public function email_notifications( $user_id, $user_data, $payment_id ) {
		// use wp_new_user_notification for email to the user
		// note that this is the bread & butter of this plugin right now, as it emails a password reset link to the created user.
		// before clicking the link in that email, there's no way for users to log-in.
		wp_new_user_notification( $user_id );
	}

	/**
	 * Login customer if this is first and only payment by customer email address
	 *
	 * @param int $user_id
	 * @param array $user_data
	 * @param int $payment_id
	 * @todo fix this method
	 */
	public function login_customer( $user_id, $user_data, $payment_id ) {

		// only log-in when processing payment from checkout
		if( is_user_logged_in() || ! did_action( 'edd_purchase' ) ) {
			return;
		}

		// query payments by this email
		$payments = edd_get_payments( array(
				'user' => $user_data['user_email'],
				'status' => array( 'publish' ),
				'number' => 2
			)
		);

		// log user in if this is his first payment ever
		if( empty( $payments ) ) {
			edd_log_user_in( $user_id, $user_data['user_login'], $user_data['user_pass'] );
		}
	}


	/**
	 * Maybe create a user when payment is created
	 *
	 * @since 1.3
	 */
	public function maybe_insert_user( $payment_id, $payment_data ) {

		// User has account already
		if( ! empty( $payment_data['user_info']['id'] ) ) {
			return;
		}

		// User account with given email already exists
		// Don't remove this line or people will get multiple accounts when accidentally checking out when not logged-in
		if ( get_user_by( 'email', $payment_data['user_info']['email'] ) ) {
			return;
		}

		// Try to create a username
		$username = $this->create_username_from_payment_user_info( $payment_data['user_info'] );
		if( empty( $username ) ) {
			return;
		}

		// Create user
		$user_args = array(
			'user_login'      => $username,
			'user_pass'       => wp_generate_password( 32 ),
			'user_email'      => $payment_data['user_info']['email'],
			'first_name'      => $payment_data['user_info']['first_name'],
			'last_name'       => $payment_data['user_info']['last_name'],
			'user_registered' => date( 'Y-m-d H:i:s' ),
			'role'            => get_option( 'default_role' )
		);

		// Filter the arguments
		$user_args = apply_filters( 'edd_auto_register_insert_user_args', $user_args, $payment_id, $payment_data );

		// Insert new user
		$user_id = wp_insert_user( $user_args );

		// Validate inserted user
		if( is_wp_error( $user_id ) || empty( $user_id ) ) {
			return;
		}

		// Associate user with payment
		$payment_meta = edd_get_payment_meta( $payment_id );
		$payment_meta['user_info']['id'] = $user_id;

		edd_update_payment_meta( $payment_id, '_edd_payment_user_id', $user_id );
		edd_update_payment_meta( $payment_id, '_edd_payment_meta', $payment_meta );

		// Create new EDD Customer based on user
		$customer = new EDD_Customer( $payment_data['user_info']['email'] );
		$customer->update( array( 'user_id' => $user_id ) );

		// Attach address info to user
		if( isset( $payment_data['user_info']['address'] ) ) {
			$address_info = $payment_data['user_info']['address'];
			update_user_meta( $user_id, '_edd_user_address', $address_info );
		}

		// Allow themes and plugins to hook
		do_action( 'edd_auto_register_insert_user', $user_id, $user_args, $payment_id );
	}

	/**
	 * @param array $user_info
	 * @param bool $use_complete_email_address
	 *
	 * @return string
	 */
	protected function create_username_from_payment_user_info( array $user_info, $use_complete_email_address = false ) {

		// try first and last name
		if( $use_complete_email_address ) {
			$username = sanitize_user( $user_info['email'] );
		} else {
			$username = sanitize_user( explode( '@', $user_info['email'] )[0] );
		}

		if( username_exists( $username ) ) {
			if( $use_complete_email_address ) {
				return '';
			} else {
				return $this->create_username_from_payment_user_info( $user_info, true );
			}
		}

		return $username;
	}


	/**
	 * Change the registration form depending on a few conditions
	 *
	 * @since 1.3
	 */
	public function remove_register_form( $value, $key, $default ) {

		// Can you checkout as guest?
		if( edd_no_guest_checkout() ) {
			// No form if user is nog logged in, login form otherwise
			return is_user_logged_in() ? 'none' : 'login';
		}

		// Always remove login & registration form
		return 'none';
	}
}

/**
 * @return EDD_Custom_Auto_Register
 */
function edd_custom_auto_register() {
	static $edd_custom_auto_register;

	if ( ! $edd_custom_auto_register instanceof EDD_Custom_Auto_Register ) {
		$edd_custom_auto_register = new EDD_Custom_Auto_Register();
		$edd_custom_auto_register->hook();
	}

	return $edd_custom_auto_register;
}

/**
 * Loads plugin after all the others have loaded and have registered their hooks and filters
 *
 * @since 1.0
 */
add_action( 'plugins_loaded', 'edd_custom_auto_register', 20 );