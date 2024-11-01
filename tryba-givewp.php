<?php
/**
 * Plugin Name: Tryba for GiveWP
 * Plugin URI:  http://tryba.io
 * Description: Tryba add-on gateway for GiveWP.
 * Version:     1.2
 * Author:      Tryba
 * Author URI:  https://tryba.io/
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Give\Helpers\Form\Utils as FormUtils;

/**
 * Tryba Gateway form output
 *
 * Tryba Gateway does not use a CC form
 *
 * @return bool
 **/
function waf_tryba_for_give_form_output( $form_id ) {

	if (FormUtils::isLegacyForm($form_id)) {
		return false;
	}

	printf(
		'
		<fieldset class="no-fields">
			<div style="display: flex; justify-content: center;">
				<img src="'. plugin_dir_url( __FILE__ ) .'assets/images/tryba_logo.png" alt="Tryba" style="width: 100%%;">
			</div>
			<p style="text-align: center;"><b>%1$s</b></p>
			<p style="text-align: center;">
				<b>%2$s</b> %3$s
			</p>
		</fieldset>
	',
		__( 'Make your donation quickly and securely with Tryba', 'give' ),
		__( 'How it works:', 'give' ),
		__( 'You will be redirected to Tryba to pay using your Tryba account or credit/debit card. You will then be brought back to this page to view your receipt.', 'give' )
	);

	return true;

}
add_action( 'give_tryba_cc_form', 'waf_tryba_for_give_form_output' );

/**
 * Register payment method.
 *
 * @since 1.0.0
 *
 * @param array $gateways List of registered gateways.
 *
 * @return array
 */
function waf_tryba_for_give_register_payment_method( $gateways ) {
  
    // Duplicate this section to add support for multiple payment method from a custom payment gateway.
    $gateways['tryba'] = array(
      'admin_label'    => 'Tryba', 
      'checkout_label' => 'Tryba',
    );
    
    return $gateways;
  }
  
add_filter( 'give_payment_gateways', 'waf_tryba_for_give_register_payment_method' );

/**
 * Register Section for Payment Gateway Settings.
 *
 * @param array $sections List of payment gateway sections.
 *
 * @since 1.0.0
 *
 * @return array
 */
function waf_tryba_for_give_register_payment_gateway_sections( $sections ) {
	
	// `tryba-settings` is the name/slug of the payment gateway section.
	$sections['tryba-settings'] = 'Tryba';

	return $sections;
}

add_filter( 'give_get_sections_gateways', 'waf_tryba_for_give_register_payment_gateway_sections' );

// Get currently supported currencies from Tryba endpoint
function waf_tryba_for_give_get_supported_currencies($string = false){
	$currency_request = wp_remote_get("https://tryba.io/api/currency-supported2");
	$currency_array = array();
	if ( ! is_wp_error( $currency_request ) && 200 == wp_remote_retrieve_response_code( $currency_request ) ){
		$currencies = json_decode(wp_remote_retrieve_body($currency_request));
		if($currencies->currency_code && $currencies->currency_name){
			foreach ($currencies->currency_code as $index => $item){
				if($string === true){
					$currency_array[] = $currencies->currency_name[$index];
				}else{
					$currency_array[$currencies->currency_code[$index]] = $currencies->currency_name[$index];
				}
			}
		}
	}
	if($string === true){
		return implode(", ", $currency_array);
	}
	return $currency_array;
}

/**
 * Register Admin Settings.
 *
 * @param array $settings List of admin settings.
 *
 * @since 1.0.0
 *
 * @return array
 */
function waf_tryba_for_give_register_payment_gateway_setting_fields( $settings ) {

	switch ( give_get_current_setting_section() ) {

		case 'tryba-settings':
			$settings = array(
				array(
					'id'   => 'give_title_vgc',
                    'desc' => 'Our Supported Currencies: <strong>'.esc_attr(waf_tryba_for_give_get_supported_currencies(true)).'.</strong>',
					'type' => 'title',
				),
				array(
					'id'   => 'tryba-invoicePrefix',
					'name' => 'Invoice Prefix',
					'desc' => 'Please enter a prefix for your invoice numbers. If you use your Tryba account for multiple stores ensure this prefix is unique as Tryba will not allow orders with the same invoice number.',
					'type' => 'text',
				),
                array(
					'id'   => 'tryba-publicKey',
					'name' => 'Public Key',
					'desc' => 'Required: Enter your Public Key here. You can get your Public Key from <a href="https://tryba.io/user/api">here</a>',
					'type' => 'text',
				),
                array(
					'id'   => 'tryba-secretKey',
					'name' => 'Secret Key',
					'desc' => 'Required: Enter your Secret Key here. You can get your Secret Key from <a href="https://tryba.io/user/api">here</a>',
					'type' => 'text',
				),
                array(
                    'id'   => 'give_title_tryba',
                    'type' => 'sectionend',
                )
			);

			break;

	} // End switch().

	return $settings;
}

add_filter( 'give_get_settings_gateways', 'waf_tryba_for_give_register_payment_gateway_setting_fields' );


/**
 * Process Tryba checkout submission.
 *
 * @param array $posted_data List of posted data.
 *
 * @since  1.0.0
 * @access public
 *
 * @return void
 */
function waf_tryba_for_give_process( $posted_data ) {
	// Make sure we don't have any left over errors present.
	give_clear_errors();

	// Any errors?
	$errors = give_get_errors();

	// No errors, proceed.
	if ( ! $errors ) {
		$form_id         = intval( $posted_data['post_data']['give-form-id'] );
		$price_id        = ! empty( $posted_data['post_data']['give-price-id'] ) ? $posted_data['post_data']['give-price-id'] : 0;
		$donation_amount = ! empty( $posted_data['price'] ) ? $posted_data['price'] : 0;
		$payment_mode = ! empty( $posted_data['post_data']['give-gateway'] ) ? $posted_data['post_data']['give-gateway'] : '';
		$redirect_to_url  = ! empty( $posted_data['post_data']['give-current-url'] ) ? $posted_data['post_data']['give-current-url'] : site_url();

		// Setup the payment details.
		$donation_data = array(
			'price'           => $donation_amount,
			'give_form_title' => $posted_data['post_data']['give-form-title'],
			'give_form_id'    => $form_id,
			'give_price_id'   => $price_id,
			'date'            => $posted_data['date'],
			'user_email'      => $posted_data['user_email'],
			'purchase_key'    => $posted_data['purchase_key'],
			'currency'        => give_get_currency( $form_id ),
			'user_info'       => $posted_data['user_info'],
			'status'          => 'pending',
			'gateway'         => 'tryba',
		);

		// Record the pending donation.
		$donation_id = give_insert_payment( $donation_data );

		if ( ! $donation_id ) {
			// Record Gateway Error as Pending Donation in Give is not created.
			give_record_gateway_error(
				__( 'Tryba Error', 'tryba-for-give' ),
				sprintf(
				/* translators: %s Exception error message. */
					__( 'Unable to create a pending donation with Give.', 'tryba-for-give' )
				)
			);

			// Send user back to checkout.
			give_send_back_to_checkout( '?payment-mode=tryba' );
			return;
		}

        // Tryba args
        $public_key = give_get_option( 'tryba-publicKey' );
		$secret_key = give_get_option( 'tryba-secretKey' );
        $tx_ref = give_get_option( 'tryba-invoicePrefix' ) . '_' . $donation_id;
        $currency_array = waf_tryba_for_give_get_supported_currencies();
		$currency = give_get_currency( $form_id );
        $currency_code = array_search( $currency , $currency_array );
        $first_name = $donation_data['user_info']['first_name'];
        $last_name = $donation_data['user_info']['last_name'];
        $email = $donation_data['user_email'];
		$callback_url = get_site_url() . "/wp-json/waf-tryba-for-give/v1/process-success?donation_id=". $donation_id . "&secret_key=" . $secret_key . "&form_id=" . $form_id . "&price_id=" . $price_id . "&payment_id=";

		// Validate data before send payment Tryba request
		$invalid = 0;
		$error_msg = array();
        if ( !empty($public_key) && !empty($secret_key) && wp_http_validate_url($callback_url) ) {
            $public_key = sanitize_text_field($public_key);
			$secret_key = sanitize_text_field($secret_key);
            $callback_url = sanitize_url($callback_url);
        } else {
			array_push($error_msg, 'The payment setting of this website is not correct, please contact Administrator');
            $invalid++;
        }
        if ( !empty($tx_ref) ) {
            $tx_ref = sanitize_text_field($tx_ref);
        } else {
			array_push($error_msg, 'It seems that something is wrong with your order. Please try again');
            $invalid++;
        }
        if ( !empty($donation_amount) && is_numeric($donation_amount) ) {
            $donation_amount = floatval(sanitize_text_field($donation_amount));
        } else {
			array_push($error_msg, 'It seems that you have submitted an invalid donation amount for this order. Please try again');
            $invalid++;
        }
        if ( !empty($email) && is_email($email) ) {
            $email = sanitize_email($email);
        } else {
			array_push($error_msg, 'Your email is empty or not valid. Please check and try again');
            $invalid++;
        }
        if ( !empty($first_name) ) {
            $first_name = sanitize_text_field($first_name);
        } else {
			array_push($error_msg, 'Your first name is empty or not valid. Please check and try again');
            $invalid++;
        }
        if ( !empty($last_name) ) {
            $last_name = sanitize_text_field($last_name);
        } else {
			array_push($error_msg, 'Your last name is empty or not valid. Please check and try again');
            $invalid++;
        }
        if ( !empty($currency_code) && is_numeric($currency_code) ) {
            $currency = sanitize_text_field($currency);
        } else {
			array_push($error_msg, 'The currency code is not valid. Please check and try again');
            $invalid++;
        }

		if ( $invalid === 0 ) {
			$apiUrl = 'https://checkout.tryba.io/api/v1/payment-intent/create';
			$apiResponse = wp_remote_post($apiUrl,
				[
					'method' => 'POST',
					'headers' => [
						'content-type' => 'application/json',
						'PUBLIC-KEY' => $public_key,
					],
					'body' => json_encode(array(
						"amount" => $donation_amount,
						"externalId" => $tx_ref,
						"first_name" => $first_name,
						"last_name" => $last_name,
						"meta" => array(
							"redirect_to_url" => urlencode($redirect_to_url)
						),
						"email" => $email,
						"redirect_url" => $callback_url,
						"currency" => $currency
					))
				]
			);
			if (!is_wp_error($apiResponse)) {
				$apiBody = json_decode(wp_remote_retrieve_body($apiResponse));
				$external_url = $apiBody->externalUrl;
				wp_redirect($external_url);
				die();
			} else {
				give_set_error( 'tryba_request_error', "Payment was declined by Tryba." );
				give_send_back_to_checkout( '?payment-mode=tryba' );
				die();
			}
		} else {
			give_set_error( 'tryba_validate_error', implode("<br>", $error_msg) );
			give_send_back_to_checkout( '?payment-mode=tryba' );
			die();
		}
	} else {
		give_send_back_to_checkout( '?payment-mode=tryba' );
		die();
	}
}
add_action( 'give_gateway_tryba', 'waf_tryba_for_give_process' );


// Register process success rest api
add_action('rest_api_init', 'waf_tryba_for_give_add_callback_url_endpoint_process_success');

function waf_tryba_for_give_add_callback_url_endpoint_process_success() {
	register_rest_route(
		'waf-tryba-for-give/v1/',
		'process-success',
		array(
			'methods' => 'GET',
			'callback' => 'waf_tryba_for_give_process_success'
		)
	);
}

// Callback function of process success rest api
function waf_tryba_for_give_process_success($request_data) {

	$parameters = $request_data->get_params();
    $secret_key = sanitize_text_field($parameters['secret_key']);
	$payment_mode = sanitize_text_field($parameters['payment_mode']);
    $donation_id = intval(sanitize_text_field($parameters['donation_id']));
	$price_id = $parameters['price_id'];
	$form_id = $parameters['form_id'];

	if ( $donation_id ) {
		// Verify Tryba payment
		$tryba_payment_id = str_replace('?payment_id=', '', sanitize_text_field($parameters['payment_id']));
		$tryba_request = wp_remote_get(
			'https://checkout.tryba.io/api/v1/payment-intent/' . $tryba_payment_id,
			[
				'method' => 'GET',
				'headers' => [
					'content-type' => 'application/json',
					'SECRET-KEY' => $secret_key,
				]
			]
		);

		if (!is_wp_error($tryba_request) && 200 == wp_remote_retrieve_response_code($tryba_request)) {
			$tryba_payment = json_decode(wp_remote_retrieve_body($tryba_request));
			$status = $tryba_payment->status;
			$redirect_to_url = urldecode(json_decode($tryba_payment->meta)->redirect_to_url);

			if ( $status === "SUCCESS" ) {
                give_update_payment_status( $donation_id, 'publish' );
				give_set_payment_transaction_id( $donation_id, $tryba_payment_id );
                give_insert_payment_note( $donation_id, "Payment via Tryba successful with Reference ID: " . $tryba_payment_id );
				give_send_to_success_page();
				die();
			} else if ($status === "CANCELLED") {
                give_update_payment_status( $donation_id, 'failed' );
                give_insert_payment_note( $donation_id, "Payment was canceled.");
                give_set_error( 'tryba_request_error', "Payment was canceled." );
				wp_redirect( $redirect_to_url . "?form-id=" . $form_id . "&level-id=" . $price_id . "&payment-mode=tryba#give-form-" . $form_id . "-wrap" );
				die();
			} else {
                give_update_payment_status( $donation_id, 'failed' );
                give_insert_payment_note( $donation_id, "Payment was declined by Tryba.");
				give_set_error( 'tryba_request_error', "Payment was declined by Tryba." );
				wp_redirect( $redirect_to_url . "?form-id=" . $form_id . "&level-id=" . $price_id . "&payment-mode=tryba#give-form-" . $form_id . "-wrap" );
				die();
			}
		}
	}
	die();
}