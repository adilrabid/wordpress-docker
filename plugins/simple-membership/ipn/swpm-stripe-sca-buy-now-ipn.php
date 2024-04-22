<?php

class SwpmStripeSCABuyNowIpnHandler {

	public function __construct() {
		//Handle Stripe IPN
		$this->handle_stripe_ipn();
	}

	public function handle_stripe_ipn() {
		//Stripe API upgrade change log - https://stripe.com/docs/upgrades

		SwpmLog::log_simple_debug( 'Stripe SCA Buy Now IPN received. Processing request...', true );
		//SwpmLog::log_simple_debug(print_r($_REQUEST, true), true);//Useful for debugging purpose

		// Read and sanitize the request parameters.
		$ref_id = isset( $_GET['ref_id'] ) ? sanitize_text_field( stripslashes ( $_GET['ref_id'] ) ) : '';

		if ( empty( $ref_id ) ) {
			//no ref id provided, cannot proceed
			SwpmLog::log_simple_debug( 'Fatal Error! No ref_id provied.', false );
			wp_die( esc_html( 'Fatal Error! No ref_id provied.' ) );

		}

		$trans_info = explode( '|', $ref_id );
		$button_id  = isset( $trans_info[1] ) ? absint( $trans_info[1] ) : false;

		// Retrieve the CPT for this button
		$button_cpt = get_post( $button_id );
		if ( ! $button_cpt ) {
			// Fatal error. Could not find this payment button post object.
			SwpmLog::log_simple_debug( 'Fatal Error! Failed to retrieve the payment button post object for the given button ID: ' . $button_id, false );
			wp_die( esc_html( sprintf( 'Fatal Error! Payment button (ID: %d) does not exist. This request will fail.', $button_id ) ) );
		}

		$settings        = SwpmSettings::get_instance();
		$sandbox_enabled = $settings->get_value( 'enable-sandbox-testing' );

		//API keys
		$api_keys = SwpmMiscUtils::get_stripe_api_keys_from_payment_button( $button_id, ! $sandbox_enabled );

		// Include the Stripe library.
		SwpmMiscUtils::load_stripe_lib();

		try {
			\Stripe\Stripe::setApiKey( $api_keys['secret'] );

			$events = \Stripe\Event::all(
				array(
					'type'    => 'checkout.session.completed',
					'created' => array(
						'gte' => time() - 60 * 60,
					),
				)
			);

			$sess = false;

			foreach ( $events->autoPagingIterator() as $event ) {
				$session = $event->data->object;
				if ( isset( $session->client_reference_id ) && $session->client_reference_id === $ref_id ) {
					$sess = $session;
					break;
				}
			}

			if ( false === $sess ) {
				// Can't find session.
				$error_msg = sprintf( "Fatal error! Payment with ref_id %s can't be found", $ref_id );
				SwpmLog::log_simple_debug( $error_msg, false );
				wp_die( esc_html( $error_msg ) );
			}

			$pi_id = $sess->payment_intent;

			$pi = \Stripe\PaymentIntent::retrieve( $pi_id );
		} catch ( Exception $e ) {
			$error_msg = 'Error occurred: ' . $e->getMessage();
			SwpmLog::log_simple_debug( $error_msg, false );
			wp_die( esc_html( $error_msg ) );
		}

		//Get the charge object based on the Stripe API version used in the payment intents object.
		if( isset ( $pi->latest_charge ) ){
			//Using the new Stripe API version 2022-11-15 or later
			SwpmLog::log_simple_debug( 'Using the Stripe API version 2022-11-15 or later for Payment Intents object. Need to retrieve the charge object.', true );
			$charge_id = $pi->latest_charge;
			//For Stripe API version 2022-11-15 or later, the charge object is not included in the payment intents object. It needs to be retrieved using the charge ID.
			try {
				//Retrieve the charge object using the charge ID
				$charge = \Stripe\Charge::retrieve($charge_id);
			} catch (\Stripe\Exception\ApiErrorException $e) {
				// Handle the error
				SwpmLog::log_simple_debug( 'Stripe error occurred trying to retrieve the charge object using the charge ID. ' . $e->getMessage(), false );
				exit;
			}
		} else {
			//Using the old Stripe API version 2022-08-01 or earlier
			$charge = $pi->charges;
			$charge = $pi->charges->data[0];
			$charge_id = $charge->id;
			//The old method that is not needed anymore as we will read it from the charge object below.
			// $stripe_email = $charge->data[0]->billing_details->email;
			// $name = trim( $charge->data[0]->billing_details->name );
			// $bd_addr = $charge->data[0]->billing_details->address;		
		}

		//Get the email, name and address from the charge object.
		$stripe_email = $charge->billing_details->email;
		$name = trim( $charge->billing_details->name );
		$bd_addr = $charge->billing_details->address;

		SwpmLog::log_simple_debug( "Email: " . $stripe_email . ", Name: " . $name . ", Charge ID: " . $charge_id, true );

		// Grab the charge ID and set it as the transaction ID.
		$txn_id = $charge_id;//The charge ID.

		//check if this payment has already been processed
		$payment = get_posts(
			array(
				'meta_key'       => 'txn_id',
				'meta_value'     => $txn_id,
				'posts_per_page' => 1,
				'offset'         => 0,
				'post_type'      => 'swpm_transactions',
			)
		);
		wp_reset_postdata();

		if ( $payment ) {
			//payment has already been processed. Redirecting user to return_url
			$return_url = get_post_meta( $button_id, 'return_url', true );
			if ( empty( $return_url ) ) {
				$return_url = SIMPLE_WP_MEMBERSHIP_SITE_HOME_URL;
			}
			SwpmMiscUtils::redirect_to_url( $return_url );
			return;
		}

		$price_in_cents = floatval( $pi->amount_received );
		$currency_code  = strtoupper( $pi->currency );

		$zero_cents = unserialize( SIMPLE_WP_MEMBERSHIP_STRIPE_ZERO_CENTS );
		if ( in_array( $currency_code, $zero_cents, true ) ) {
			$payment_amount = $price_in_cents;
		} else {
			$payment_amount = $price_in_cents / 100;// The amount (in cents). This value is used in Stripe API.
		}

		$payment_amount = floatval( $payment_amount );

		$membership_level_id = get_post_meta( $button_id, 'membership_level_id', true );

		// Validate and verify some of the main values.
		$true_payment_amount = get_post_meta( $button_id, 'payment_amount', true );
		$true_payment_amount = apply_filters( 'swpm_payment_amount_filter', $true_payment_amount, $button_id );
		$true_payment_amount = floatval( $true_payment_amount );

		if ( $payment_amount !== $true_payment_amount ) {
			// Fatal error. Payment amount may have been tampered with.
			$error_msg = 'Fatal Error! Received payment amount (' . $payment_amount . ') does not match with the original amount (' . $true_payment_amount . ')';
			SwpmLog::log_simple_debug( $error_msg, false );
			wp_die( esc_html( $error_msg ) );
		}
		$true_currency_code = get_post_meta( $button_id, 'payment_currency', true );
		if ( $currency_code !== $true_currency_code ) {
			// Fatal error. Currency code may have been tampered with.
			$error_msg = 'Fatal Error! Received currency code (' . $currency_code . ') does not match with the original code (' . $true_currency_code . ')';
			SwpmLog::log_simple_debug( $error_msg, false );
			wp_die( esc_html( $error_msg ) );
		}

		// Everything went ahead smoothly with the charge.
		SwpmLog::log_simple_debug( 'Stripe SCA Buy Now charge successful.', true );

		$user_ip = SwpmUtils::get_user_ip_address();

		//Custom field data
		$custom_field_value  = 'subsc_ref=' . $membership_level_id;
		$custom_field_value .= '&user_ip=' . $user_ip;
		if ( SwpmMemberUtils::is_member_logged_in() ) {
			$custom_field_value .= '&swpm_id=' . SwpmMemberUtils::get_logged_in_members_id();
		}
		$custom_field_value = apply_filters( 'swpm_custom_field_value_filter', $custom_field_value );

		$custom = $custom_field_value;

		$custom_var = SwpmTransactions::parse_custom_var( $custom );
		$swpm_id    = isset( $custom_var['swpm_id'] ) ? $custom_var['swpm_id'] : '';

		// Let's try to get first_name and last_name from full name
		$last_name  = ( strpos( $name, ' ' ) === false ) ? '' : preg_replace( '#.*\s([\w-]*)$#', '$1', $name );
		$first_name = trim( preg_replace( '#' . $last_name . '#', '', $name ) );

		// Create the $ipn_data array.
		$ipn_data                     = array();
		$ipn_data['mc_gross']         = $payment_amount;
		$ipn_data['first_name']       = $first_name;
		$ipn_data['last_name']        = $last_name;
		$ipn_data['payer_email']      = $stripe_email;
		$ipn_data['membership_level'] = $membership_level_id;
		$ipn_data['txn_id']           = $txn_id;
		$ipn_data['subscr_id']        = $txn_id;/* Set the txn_id as subscriber_id so it is similar to PayPal buy now. Also, it can connect to the profile in the "payments" menu. */
		$ipn_data['swpm_id']          = $swpm_id;
		$ipn_data['ip']               = $custom_var['user_ip'];
		$ipn_data['custom']           = $custom;
		$ipn_data['gateway']          = 'stripe-sca';
		$ipn_data['status']           = 'completed';

		$ipn_data['address_street']  = isset( $bd_addr->line1 ) ? $bd_addr->line1 : '';
		$ipn_data['address_city']    = isset( $bd_addr->city ) ? $bd_addr->city : '';
		$ipn_data['address_state']   = isset( $bd_addr->state ) ? $bd_addr->state : '';
		$ipn_data['address_zipcode'] = isset( $bd_addr->postal_code ) ? $bd_addr->postal_code : '';

		//Get country value from the Stripe response. It can be a country code or a country name.
		$country = isset( $bd_addr->country ) ? $bd_addr->country : '';
		if( strlen($country) == 2 ){//We have a country code. Let's convert it to country name.
			$ipn_data['address_country'] = SwpmMiscUtils::get_country_name_by_country_code($country);
		} else {
			$ipn_data['address_country'] = $country;
		}

		$ipn_data['payment_button_id'] = $button_id;
		$ipn_data['is_live']           = ! $sandbox_enabled;

		// Handle the membership signup related tasks.
		swpm_handle_subsc_signup_stand_alone( $ipn_data, $membership_level_id, $txn_id, $swpm_id );

		// Save the transaction record
		SwpmTransactions::save_txn_record( $ipn_data );
		SwpmLog::log_simple_debug( 'Transaction data saved.', true );

		// Trigger the stripe IPN processed action hook (so other plugins can can listen for this event).
		do_action( 'swpm_stripe_sca_ipn_processed', $ipn_data );

		do_action( 'swpm_payment_ipn_processed', $ipn_data );

		// Redirect the user to the return URL (or to the homepage if a return URL is not specified for this payment button).
		$return_url = get_post_meta( $button_id, 'return_url', true );
		if ( empty( $return_url ) ) {
			$return_url = SIMPLE_WP_MEMBERSHIP_SITE_HOME_URL;
		}
		SwpmLog::log_simple_debug( 'Redirecting customer to: ' . $return_url, true );
		SwpmLog::log_simple_debug( 'End of Stripe SCA Buy Now IPN processing.', true, true );
		SwpmMiscUtils::redirect_to_url( $return_url );

	}

}

new SwpmStripeSCABuyNowIpnHandler();
