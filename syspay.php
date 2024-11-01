<?php

require __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

/**
 * Plugin Name: Syspay
 * Plugin URI: https://app.syspay.com/
 * Description: Syspay payment gateway. Create your account on <a href="https://app.syspay.com">www.syspay.com</a> to get your merchant credentials and configure your account <a href="../wp-admin/admin.php?page=wc-settings&tab=checkout&section=syspaygateway">here.</a> <strong>Syspay needs WooCommerce plugin.</strong>
 * Version: 1.54
 *
 * Text Domain: syspay
 * Domain Path: /i18n/languages/
 * WC requires at least: 3.0.0
 * WC tested up to: 6.3.1
 *
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Requirements errors messages
function syspay__curl_error() {
	$class   = 'notice notice-error';
	$message = 'Syspay: PHP cURL extension sing';
	printf( '<div class="%1$s"><p>%2$s</p></div>', $class, $message );
}

function syspay__openssl_error() {
	$message = 'Syspay: OpenSSL version not supported "' . OPENSSL_VERSION_TEXT . '" < 1.0.1';
	$class   = 'notice notice-error';
	printf( '<div class="%1$s"><p>%2$s</p></div>', $class, $message );
}

// JS libraries.
function syspay_javascript_enqueues() {
	wp_enqueue_script( 'jquery' );
	wp_enqueue_script( 'syspay-jquery-form-validate', plugins_url( '/includes/jquery.validate.min.js', __FILE__ ), array( 'jquery' ) );
	wp_enqueue_script( 'syspay-tokenizer', '//cdn.syspay.com/js/syspay.tokenizer-current.js', array( 'jquery' ) );
	wp_enqueue_script( 'syspay-form-checkout', plugins_url( '/includes/syspay-form.js', __FILE__ ), array( 'jquery', 'syspay-tokenizer' ), false, true );
}

# WooCommerce integration.
function init_syspay_gateway() {

	// Assert WooCommerce plugin is enabled.
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	class SyspayGateway extends WC_Payment_Gateway {

		function __construct() {

			$this->id           = 'syspay';
			$this->icon         = '';
			$this->has_fields   = true;
			$this->method_title = 'Syspay';

			$this->method_description = __( 'Syspay online payment service', 'syspay-for-woocommerce' );
			$this->description        = __( 'Pay with your credit card.', 'syspay-for-woocommerce' );

			$this->init_form_fields();
			$this->init_settings();
			$this->settings['notifurl'] = get_site_url() . '/?wc-api=syspay';
			$this->supports             = array(
				'products',
				'refunds',
			);

			$this->title = $this->get_option( 'title' );

			// Settings JQuery
			add_action( 'wp_enqueue_scripts', 'syspay_javascript_enqueues' );

			// Settings save hook
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

			// Register Syspay callback handler
			add_action( 'woocommerce_api_syspay', array( $this, 'check_callback_response' ) );

			// Requirements : SSL version and curl extension.
			$openssl_version_supported = OPENSSL_VERSION_NUMBER >= 0x10001000;
			$curl_activated            = function_exists( 'curl_version' );

			if ( ! $openssl_version_supported ) {
				add_action( 'admin_notices', 'syspay__openssl_error' );
			}

			if ( ! $curl_activated ) {
				add_action( 'admin_notices', 'syspay__curl_error' );
			}
		}

		// Payment form.
		function payment_fields() {

			$syspay  = $this->syspay_sdk_factory();
			$baseurl = $syspay->getUrl( '/api/v1/public/' );

			echo '
			<style>form.woocommerce-checkout label.error{ color: red;}</style>
			<script>
				syspay.tokenizer.setBaseUrl("' . esc_attr( $baseurl ) . '");
				syspay.tokenizer.setPublicKey("' . esc_attr( $this->get_option( 'public_key' ) ) . '");
				window.syspayComKey = "' . esc_attr( $this->get_option( 'public_key' ) ) . '";
			</script>';

			echo '<fieldset id="wc-' . esc_attr( $this->id ) . '-cc-form" class="wc-credit-card-form wc-payment-form" style="background:transparent; font-family: sans-serif !important;">';

			echo '
				<div id="SyspayForm"class="form-row form-row-wide" style="margin-bottom: 15px;">
					<label for="syspay_ccNo">' . esc_attr( __( 'Card Number', 'syspay-for-woocommerce' ) ) . ' <span class="required">*</span></label>
					<input title="' . esc_attr( __( 'Please enter a valid credit card number', 'syspay-for-woocommerce' ) ) . '"
							id="syspay_ccNo" type="text" 
							onkeydown="return event.key != \'Enter\';" 
							autocomplete="off" minlength="16" size="19" required="required"
							>
				</div>
				<div class="form-row form-row-first" style="margin-bottom: 15px;">
					<label  for="syspay_expdate">' . esc_attr( __( 'Expiry Date', 'syspay-for-woocommerce' ) ) . ' <span class="required">*</span></label>
					<input title="' . esc_attr( __( 'Please enter the expiration date in format MM/YYYY', 'syspay-for-woocommerce' ) ) . '" 
							id="syspay_expdate" pattern="[0-9]+[0-9]+/[0-9][0-9][0-9][0-9]" 
							maxlength="7"
							type="text" autocomplete="off" 
							required="required" minlength="7" size="7" placeholder="MM / YYYY"
							oninvalid="setCustomValidity(\'Date format: MM/YYYY.\' )" 
  							oninput="setCustomValidity(\'\' )">
				</div>
				<div class="form-row form-row-last" style="margin-bottom: 15px;">
					<label  for="syspay_cvv">' . esc_attr( __( 'Card Code (CVC)', 'syspay-for-woocommerce' ) ) . ' <span class="required">*</span></label>
					<input  title="' . esc_attr( __( 'Please enter the security code', 'syspay-for-woocommerce' ) ) . '"
						id="syspay_cvv" pattern="[0-9]+" type="password" 
						onkeydown="return event.key != \'Enter\';" autocomplete="off" minlength="3" size="3"  
						required="required" placeholder="CVC"
						maxlength="4"
						oninvalid="setCustomValidity(\'3 digits security code required\' )" 
						oninput="setCustomValidity(\'\' )">
				</div>
				<div class="form-row form-row-wide">
					<label  for="syspay_owner">' . esc_attr( __( 'Card Owner', 'syspay-for-woocommerce' ) ) . ' <span class="required">*</span></label>
					<input id="syspay_owner" 
							title="' . esc_attr( __( 'Please enter the name of the card owner', 'syspay-for-woocommerce' ) ) . '"
							type="text" onkeydown="return event.key != \'Enter\';" autocomplete="off" minlength="4" required="required">
				</div>
				<div class="clear" style="padding: 5px;"></div>

				<div class="form-row form-row-last" style="display: none;">
					<input id="syspaySubmitBtn" type="submit" onkeydown="return event.key != \'Enter\';" value="' . esc_attr( __( 'Pay', 'syspay-for-woocommerce' ) ) . '"  style="display: none;"/>
				</div>

				<div class="clear">
			';

			echo '
			  <div style="text-align: right; font-size: 0.7em;">Powered by Syspay Ltd</div>
			';
			echo '</fieldset>';

			echo '
			<script>
			  if (typeof registerSyspayCheckoutButton !== "undefined") {
				registerSyspayCheckoutButton();
			  }
			</script>';
		}

		// Syspay settings form.
		function init_form_fields() {
			$this->form_fields = array(
				'enabled'    => array(
					'title'   => esc_attr( __( 'Enable/Disable', 'syspay-for-woocommerce' ) ),
					'type'    => 'checkbox',
					'label'   => esc_attr( __( 'Enable Syspay payment', 'syspay-for-woocommerce' ) ),
					'default' => 'yes',
				),
				'title'      => array(
					'title'             => esc_attr( __( 'Title', 'syspay-for-woocommerce' ) ) . '<span> *</span>',
					'type'              => 'text',
					'description'       => esc_attr( __( 'This controls the title which the user sees during checkout.', 'syspay-for-woocommerce' ) ),
					'default'           => esc_attr( __( 'Syspay', 'syspay-for-woocommerce' ) ),
					'desc_tip'          => true,
					'custom_attributes' => array(
						'required' => true,
					),
				),
				'api_key'    => array(
					'title'             => esc_attr( __( 'Syspay login', 'syspay-for-woocommerce' ) ) . '<span> *</span>',
					'type'              => 'text',
					'description'       => esc_attr( __( 'Your Syspay application API Key.', 'syspay-for-woocommerce' ) ),
					'default'           => '',
					'desc_tip'          => true,
					'css'               => 'width: 800px;',
					'custom_attributes' => array(
						'required' => true,
					),
				),
				'passphrase' => array(
					'title'             => esc_attr( __( 'Syspay passphrase', 'syspay-for-woocommerce' ) ) . '<span> *</span>',
					'type'              => 'text',
					'description'       => esc_attr( __( 'Your Syspay application password.', 'syspay-for-woocommerce' ) ),
					'default'           => '',
					'desc_tip'          => true,
					'css'               => 'width: 800px;',
					'custom_attributes' => array(
						'required' => true,
					),
				),
				'public_key' => array(
					'title'             => esc_attr( __( 'Syspay public key', 'syspay-for-woocommerce' ) ) . '<span> *</span>',
					'type'              => 'text',
					'description'       => esc_attr( __( 'Your Syspay public key.', 'syspay-for-woocommerce' ) ),
					'default'           => '',
					'desc_tip'          => true,
					'css'               => 'width: 800px;',
					'custom_attributes' => array(
						'required' => true,
					),
				),
				'source_id'  => array(
					'title'       => esc_attr( __( 'Syspay source id', 'syspay-for-woocommerce' ) ),
					'type'        => 'text',
					'description' => esc_attr( __( 'Your Syspay source.', 'syspay-for-woocommerce' ) ),
					'default'     => '',
					'desc_tip'    => true,
					'css'         => 'width: 800px;',
				),
				'notifurl'   => array(
					'title'   => esc_attr( __( 'Notification URL', 'syspay-for-woocommerce' ) ),
					'type'    => 'text',
					'css'     => 'width: 500px;',
					'default' => get_site_url() . '/?wc-api=syspay',
				),
				'testmode'   => array(
					'title'   => esc_attr( __( 'Test mode', 'syspay-for-woocommerce' ) ),
					'type'    => 'checkbox',
					'label'   => esc_attr( __( 'Using Syspay test API with test credentials.', 'syspay-for-woocommerce' ) ),
					'default' => 'no',
				),
			);
		}

		/**
		 * Returns an initialized Syspay SDK object.
		 */
		function syspay_sdk_factory() {
			$merchant_conf = ( new Syspay\MerchantConf )
				->setLogin( trim( $this->get_option( 'api_key' ) ) )
				->setPassphrase( trim( $this->get_option( 'passphrase' ) ) )
				->setPublicKey( trim( $this->get_option( 'public_key' ) ) )
				->setSourceId( trim( $this->get_option( 'source_id' ) ) );

			$sdk = new Syspay\SyspaySDK( $merchant_conf );

			if ( $this->get_option( 'testmode', 'no' ) === 'yes' ) {
				$sdk->enableTestMode();
			}
			return $sdk;
		}

		/**
		 * Process payment gateway on checkout page after client side tokenization.
		 * @param int $order_id
		 */
		function process_payment( $order_id ) {

			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				// Payment failed : Show error to end user.
				wc_add_notice( __( 'Payment failed: ', 'syspay-for-woocommerce' ) . 'order not found', 'error' );

				return array(
					'result' => 'error',
				);
			}

			if ( empty( $_POST['syspay-token'] ) ) {
				wc_add_notice( __( 'Invalid payment submission.', 'syspay-for-woocommerce' ), 'error' );
				return array(
					'result' => 'error',
				);
			}

			$address = $order->get_address();

			$return_url = add_query_arg( 'wc-api', 'syspay', home_url( '/' ) );

			$language = get_locale() === 'fr_FR' ? 'fr' : 'en';

			$syspay = $this->syspay_sdk_factory();

			// Phone number processing of '+' prefix.
			if ( ! preg_match( '/^[0-9]{7,15}$/', $address['phone'] ) ) {
				$address['phone'] = str_replace( '+', '00', $address['phone'] );
				if ( ! preg_match( '/^[0-9]{7,15}$/', $address['phone'] ) ) {
					return wc_add_notice( __( 'Billing phone is not valid phone number.', 'syspay-for-woocommerce' ), 'error' );
				}
			}

			$amount = 100 * $order->get_total();

			$payment_conf = ( new Syspay\PaymentConf )
				->setAmount( $amount )
				->setDescription( $order_id )
				->setReturnUrl( $return_url )
				->setEmsUrl( $return_url )
				->setTokenKey( sanitize_text_field( $_POST['syspay-token'] ) )
				->setCustomerEmail( $address['email'] )
				->setCustomerReference( $order->get_user_id() )
				->setCustomerFirstName( $address['first_name'] )
				->setCustomerLastName( $address['last_name'] )
				->setCustomerLanguage( $language )
				->setCustomerIP();

			try {
				$response = $syspay->sendPayment( $payment_conf );

				if ( $response->hasError() ) {
					throw new Exception( $response->getErrorMessage() );
				}
			} catch ( Exception $exc ) {
				error_log( 'Syspay Payment Exception: ' . $exc->getMessage() );

				wc_add_notice( __( 'Syspay Payment exception:', 'syspay-for-woocommerce' ) . $exc->getMessage(), 'error' );

				// Returns error.
				return array(
					'result' => 'error',
				);
			}

			$_SESSION['syspay_order_id'] = $order_id;

			// Reduce stock levels
			if ( function_exists( 'wc_reduce_stock_levels' ) ) {
				// WooCommerce v3
				wc_reduce_stock_levels( $order );
			} else {
				$order->reduce_order_stock();
			}

			// Redirect to validation page
			if ( $response->isRedirect() ) {

				// Post-Process Redirection
				return array(
					'result'   => 'success',
					'redirect' => $response->getActionUrl(),
				);
			} else {
				// Direct response from Syspay

				if ( $this->process_response( $response ) ) {
					return array(
						'result'   => 'success',
						'redirect' => $this->get_return_url(),
					);
				} else {
					// Returns error.
					return array(
						'result' => 'error',
					);
				}
			}
		}

		/**
		 * Notification Syspay EMS handler.
		 *
		 * Example: http://your-woocommerce-website.com/wc-api/syspay
		 */
		function check_callback_response() {

			// Assert Syspay API Key is setup.
			$api_key = $this->get_option( 'api_key' );

			if ( empty( $api_key ) ) {
				header( 'Location: ' . home_url( '/' ) );
				exit;
			}

			$syspay        = $this->syspay_sdk_factory();
			$received_data = $syspay->emsProcess();

			if ( empty( $received_data ) ) {
				// error_log( 'Syspay callback no data received '.json_encode( $_POST) );
				// error_log( 'Syspay callback no data received GET '.json_encode( $_GET) );
				header( 'Location: ' . home_url( '/' ) );
				exit;
			}

			$is_https = isset( $_SERVER['HTTPS'] ) && 'on' === $_SERVER['HTTPS'];

			if ( $is_https || ( ! $is_https && ! empty( $received_data ) ) ) {
				// FIX : HTTPS return or notification + HTTP api call
				try {
					$response = new Syspay\SyspayResponse( $received_data );

					if ( $response->hasError() ) {
						throw new Exception( $response->getErrorMessage() );
					}

					if ( ! $response ) {
						throw new Exception( 'empty response' );
					}
				} catch ( Exception $exc ) {
					error_log( 'Syspay: Payment error: ' . $exc->getCode() . ' ( ' . $exc->getMessage() . ' ) ' );
					header( 'Location: ' . home_url( '/' ) );
					die;
				}
			}

			if ( $this->process_response( $response ) ) {
				header( 'Location: ' . $this->get_return_url() );
			} else {
				wp_safe_redirect( wc_get_page_permalink( 'cart' ) );
				exit;
			}
		}

		/**
		 * Process Syspay response and saves order.
		 *
		 * @global type $woocommerce
		 *
		 * @todo Use in check_callback_response() which is payment-page-logic only.
		 */
		function process_response( Syspay\SyspayResponse $response ) {
			$order = new WC_Order( $response->getOrderId() );

			// Save transaction ID
			update_post_meta( $response->getOrderId(), 'Syspay_Tid', $response->getTid() );

			if ( ! $response->isSuccess() && $response->isCodeFinal() ) {

				// Payment failed
				error_log( 'Syspay : Payment failed: ' . $response->get( 'failure_category' ) );
				$message = sprintf( 'Status %s category %s', $response->get( 'status' ), $response->get( 'failure_category' ) );
				$order->update_status( 'failed', $message );
				wc_add_notice( __( 'Payment failed:', 'syspay-for-woocommerce' ) . ' ' . $message, 'error' );
				return false;
			} elseif ( $response->isSuccess() ) {

				// Payment success
				$order->payment_complete();

				// Empty cart
				global $woocommerce;
				$woocommerce->cart->empty_cart();
				return true;
			}
		}

		/**
		 * Refund process
		 *
		 * @param int $order_id
		 * @param float $amount
		 * @param string $reason
		 * @return bool|WP_Error True or false based on success, or a WP_Error object.
		 */
		public function process_refund( $order_id, $amount = null, $reason = '' ) {
			$return_url = add_query_arg( 'wc-api', 'syspay', home_url( '/' ) );

			$refund_conf = ( new Syspay\RefundConf )
				->setPaymentId( get_post_meta( $order_id, 'Syspay_Tid', true ) )
				->setAmount( 100 * $amount )
				->setDescription( 'refund' )
				->setEmsUrl( $return_url );

			$syspay = $this->syspay_sdk_factory();

			try {
				$response = $syspay->sendRefund( $refund_conf );

				if ( $response->hasError() ) {
					throw new Exception( $response->getErrorMessage() );
				}
			} catch ( Exception $exc ) {
				error_log( 'Syspay Refund Exception: ' . $exc->getMessage() );

				wc_add_notice( __( 'Syspay Refund exception:', 'syspay-for-woocommerce' ) . $exc->getMessage(), 'error' );

				// Returns error.
				return array(
					'result' => 'error',
				);
			}

			return $response->isSuccess();
		}

		/**
		 * Get payment gateway icon for checkout page.
		 * @return string
		 */
		public function get_icon() {
			$icon_url  = plugin_dir_url( __FILE__ ) . '/includes/icon.png';
			$icon_html = '';
			return apply_filters( 'woocommerce_gateway_icon', $icon_html, $this->id );
		}
	}
}

// Load plugin.
add_action( 'plugins_loaded', 'init_syspay_gateway' );

function add_syspay_gateway( $methods ) {
	$methods[] = 'SyspayGateway';
	return $methods;
}

// Register gateway in WooCommerce.
add_filter( 'woocommerce_payment_gateways', 'add_syspay_gateway' );

// Internationalization.
load_plugin_textdomain( 'syspay-for-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . DIRECTORY_SEPARATOR . 'i18n' . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR );

// Quick link to settings.
function syspay_action_links( $links ) {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return $links;
	}
	$mylinks = array(
		'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=syspaygateway' ) . '">Settings</a>',
	);
	return array_merge( $links, $mylinks );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'syspay_action_links' );

/**
 * Syspay standalone payment form settings page.
 */
function syspay_add_settings_page() {
	add_options_page(
		'Syspay form',
		'Syspay payment form',
		'manage_options',
		'syspay-payment-form-settings-page',
		'syspay_render_settings_page'
	);
}
add_action( 'admin_menu', 'syspay_add_settings_page' );


/**
 * Render payment form settings page.
 */
function syspay_render_settings_page() {
	// test error message
	$mandatory = array(
		'syspay_form_api_key',
		'syspay_form_passphrase',
		'syspay_form_public_key',
	);

	$valid = array_map(
		function ( $el ) {
			return ! empty( syspay__get_option( $el ) );
		},
		$mandatory
	);

	if ( in_array( false, $valid, true ) ) {
		$error_message = __( 'Syspay mandatory API settings missing', 'syspay-for-woocommerce' );
		add_settings_error( 'title_long_error', '', $error_message, 'error' );
		settings_errors( 'title_long_error' );
	}
	?>
	<h2><?php esc_attr_e( 'Syspay standalone payment form settings', 'syspay-for-woocommerce' ); ?></h2>
	<form action="options.php" method="post">
		<?php
		settings_fields( 'syspay_plugin_options' );
		do_settings_sections( 'syspay_payment_form' );
		?>
		<input type="submit" name="submit" class="button button-primary" value="<?php esc_attr_e( 'Save' ); ?>" />
	</form>
	<?php
}

/**
 * Register payment form settings.
 * Option prefix: syspay_form_*
 */
function syspay_register_settings() {
	register_setting(
		'syspay_plugin_options',
		'syspay_plugin_options',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'syspay_validate_plugin_settings',
			'default'           => array(),
		)
	);

	add_settings_section(
		'api_settings_section',
		'API Settings *',
		'syspay_plugin_section_text',
		'syspay_payment_form'
	);

	// Login.
	add_settings_field(
		'syspay_form_api_key',
		'Syspay login *',
		'syspay_render_api_key',
		'syspay_payment_form',
		'api_settings_section'
	);

	// Passphrase.
	add_settings_field(
		'syspay_form_passphrase',
		'Passphrase *',
		'syspay_render_passphrase',
		'syspay_payment_form',
		'api_settings_section'
	);

	// Public key.
	add_settings_field(
		'syspay_form_public_key',
		'Public key *',
		'syspay_render_public_key',
		'syspay_payment_form',
		'api_settings_section'
	);

	// Source id.
	add_settings_field(
		'syspay_form_source_id',
		'Source ID',
		'syspay_render_source_id',
		'syspay_payment_form',
		'api_settings_section'
	);

	// Source id.
	add_settings_field(
		'syspay_is_test_mode',
		'Test environment',
		'syspay_render_test_env',
		'syspay_payment_form',
		'api_settings_section'
	);
}
add_action( 'admin_init', 'syspay_register_settings' );


/**
 * Render payment form settings section.
 */
function syspay_plugin_section_text() {
	printf( '<p>%s</p>', __( 'Please fill in your Syspay API tokens for the standalone payment form widget', 'syspay-for-woocommerce' ) );
}

/**
 * Validate payment form settings.
 */
function syspay_validate_plugin_settings( $input ) {
	$output['syspay_form_api_key']    = sanitize_text_field( $input['syspay_form_api_key'] );
	$output['syspay_form_passphrase'] = sanitize_text_field( $input['syspay_form_passphrase'] );
	$output['syspay_form_public_key'] = sanitize_text_field( $input['syspay_form_public_key'] );
	$output['syspay_form_source_id']  = sanitize_text_field( $input['syspay_form_source_id'] );

	if ( isset( $input['syspay_is_test_mode'] ) ) {
		$output['syspay_is_test_mode'] = sanitize_text_field( $input['syspay_is_test_mode'] );
	} else {
		$output['syspay_is_test_mode'] = 0;
	}
	return $output;
}

/**
 * Helper function for rendering an option.
 */
function syspay__render_option( $option_name, $default = '' ) {
	$options = get_option( 'syspay_plugin_options' );
	$option  = ! empty( $options ) && isset( $options[ $option_name ] ) ? $options[ $option_name ] : $default;

	if ( 'syspay_is_test_mode' !== $option_name ) {
		printf(
			'
			<input type="text" name="%s" value="%s" />',
			esc_attr( sprintf( 'syspay_plugin_options[%s]', $option_name ) ),
			esc_attr( $option )
		);
	} else {
		printf(
			'
			<input type="checkbox" id="teston" name="%s" value="1" %s>
			<label for="teston">Enable Test environment</label><br>',
			esc_attr( sprintf( 'syspay_plugin_options[%s]', $option_name ) ),
			checked( 1 === $option || '1' === $option, true, false ),
		);
	}
}

/**
 * Helper function to get a form setting option.
 */
function syspay__get_option( $option_name, $default = '' ) {
	$options = get_option( 'syspay_plugin_options' );
	$option  = ! empty( $options ) ? $options[ $option_name ] : $default;
	return $option;
}

/**
 * Render API login option.
 */
function syspay_render_api_key() {
	syspay__render_option( 'syspay_form_api_key' );
}

/**
 * Render API passphrase option.
 */
function syspay_render_passphrase() {
	syspay__render_option( 'syspay_form_passphrase' );
}

/**
 * Render API public key option.
 */
function syspay_render_public_key() {
	syspay__render_option( 'syspay_form_public_key' );
}

/**
 * Render API source id option.
 */
function syspay_render_source_id() {
	syspay__render_option( 'syspay_form_source_id' );
}

/**
 * Render API source id option.
 */
function syspay_render_test_env() {
	syspay__render_option( 'syspay_is_test_mode' );
}

/**
 * WordPress Block Integration : standalone payment form.
 */

/**
 * Registers all block assets so that they can be enqueued through the block editor
 * in the corresponding context.
 *
 * @see https://developer.wordpress.org/block-editor/tutorials/block-tutorial/applying-styles-with-stylesheets/
 */
function create_block_syspay_form_block_init() {
	$dir = __DIR__;

	$script_asset_path = "$dir/build/index.asset.php";
	if ( ! file_exists( $script_asset_path ) ) {
		throw new Error(
			'You need to run `npm start` or `npm run build` for the "create-block/syspay-form" block first.'
		);
	}
	$index_js     = 'build/index.js';
	$script_asset = require( $script_asset_path );
	wp_register_script(
		'create-block-syspay-form-block-editor',
		plugins_url( $index_js, __FILE__ ),
		$script_asset['dependencies'],
		$script_asset['version']
	);
	wp_set_script_translations( 'create-block-syspay-form-block-editor', 'syspay-for-woocommerce' );

	$editor_css = 'build/index.css';
	wp_register_style(
		'create-block-syspay-form-block-editor',
		plugins_url( $editor_css, __FILE__ ),
		array(),
		filemtime( "$dir/$editor_css" )
	);

	$style_css = 'build/style-index.css';
	wp_register_style(
		'create-block-syspay-form-block',
		plugins_url( $style_css, __FILE__ ),
		array(),
		filemtime( "$dir/$style_css" )
	);

	register_block_type(
		'create-block/syspay-form',
		array(
			'editor_script'   => 'create-block-syspay-form-block-editor',
			'editor_style'    => 'create-block-syspay-form-block-editor',
			'style'           => 'create-block-syspay-form-block',
			'render_callback' => 'syspay_form_render_callback',
		),
	);
}
add_action( 'init', 'create_block_syspay_form_block_init' );


/**
 * Only for standalone form payment.
 */
function syspay__factory() {
	$api_key      = syspay__get_option( 'syspay_form_api_key' );
	$passphrase   = syspay__get_option( 'syspay_form_passphrase' );
	$public_key   = syspay__get_option( 'syspay_form_public_key' );
	$source_id    = syspay__get_option( 'syspay_form_source_id' );
	$is_test_mode = syspay__get_option( 'syspay_is_test_mode' );

	if ( empty( $api_key ) || empty( $passphrase ) || empty( $public_key ) ) {
		return false;
	}
	$merchant_conf = ( new Syspay\MerchantConf )
						->setLogin( trim( $api_key ) )
						->setPassphrase( trim( $passphrase ) )
						->setPublicKey( trim( $public_key ) )
						->setSourceId( trim( $source_id ) );

	$syspay = new Syspay\SyspaySDK( $merchant_conf );

	if ( 1 === $is_test_mode || '1' === $is_test_mode ) {
		$syspay->enableTestMode();
	}
	return $syspay;
}


/**
 * Syspay payment form block render.
 */
function syspay_form_render_callback( $block_attributes, $content ) {
	$options = get_option( 'syspay_plugin_options' );

	if ( ! is_array( $options ) || empty( $options ) ) {
		return;
	}
	$valid = array_map(
		function ( $el ) {
			return ! empty( $el ) || is_numeric( $el );
		},
		$options
	);

	unset( $valid['syspay_form_source_id'] );
	unset( $valid['syspay_is_test_mode'] );

	if ( in_array( false, $valid, true ) ) {
		$err = sprintf( '<p>%s</p>', __( 'Error : Syspay payment form configuration not completed' ) );
		return $err;
	}

	$syspay = syspay__factory();

	wp_enqueue_script( 'jquery' );

	wp_enqueue_script(
		'syspay-jquery-form-validate',
		plugins_url( '/includes/jquery.validate.min.js', __FILE__ ),
		array( 'jquery' )
	);

	wp_enqueue_script(
		'syspay-tokenizer',
		'https://cdn.syspay.com/js/syspay.tokenizer-current.js',
		array( 'jquery' )
	);

	wp_enqueue_script(
		'syspay-form-checkout',
		plugins_url( '/includes/syspay-standalone.js', __FILE__ ),
		array( 'jquery', 'syspay-tokenizer' ),
		false,
		true
	);

	// View variables.
	$baseurl           = $syspay->getUrl( '/api/v1/public/' );
	$syspay_public_key = $options['syspay_form_public_key'];
	global $syspay_global_return_status;
	global $syspay_global_return_error;

	ob_start();
	include __DIR__ . '/includes/payment_form.php';
	return ob_get_clean();
}


function syspay_init_function() {

	if ( stristr( $_SERVER['REQUEST_URI'], 'wc-api' ) !== false ) {
		// WooCommerce payment callback process.
		return;
	}

	// Syspay standalone form EMS.
	if ( ! empty( $_GET['syspay-standalone-form-return'] ) ) {
		echo 'Syspay standalone form return received.';
		exit;
	};

	/**
	 * Syspay return after redirect.
	 */
	$keys = array(
		'result',
		'merchant',
		'checksum',
	);

	/**
	 * Helper function for checking mandatory input.
	 */
	# test http://localhost:8888/?p=1&result=eyJjbGFzcyI6InBheW1lbnQiLCJpZCI6MjI3MjcyLCJyZWZlcmVuY2UiOiI5MjA3NzAwMDAxMTYxODQxMjAwN2NiNzEzZGM1ZWVlMjRhZGU5YjQ2ZmQ5NTAxZDE5YzRjIiwiYW1vdW50IjozNDAwLCJjdXJyZW5jeSI6IkVVUiIsInN0YXR1cyI6IlNVQ0NFU1MiLCJwcmVhdXRoX2V4cGlyYXRpb25fZGF0ZSI6bnVsbCwiYWN0aW9uX3VybCI6bnVsbCwiZmxvdyI6IkFQSSIsInByb2Nlc3NpbmdfdGltZSI6MTYxODQxMTk4MSwic291cmNlIjoxMjU2NSwiY29udHJhY3QiOiJTeXNQYXkiLCJkZXNjcmlwdG9yIjoiVGVzdCBDb250cmFjdCIsImV4dHJhIjpudWxsLCJkZXNjcmlwdGlvbiI6IlJlZmVyZW5jZSBiaWxsIFRFUzAxOSIsImFjY291bnRfaWQiOjkyMDc3LCJtZXJjaGFudF9pZCI6MTE0NDksIm1lcmNoYW50X2xvZ2luIjoiOTIwNzcwMDAwMSIsInNldHRsZW1lbnRfZGF0ZSI6MTYxODQxMTk4MiwiZmFpbHVyZV9jYXRlZ29yeSI6bnVsbCwiY2hpcF9hbmRfcGluX3N0YXR1cyI6bnVsbCwiY2FwdHVyZV9kYXRlIjpudWxsLCJ0b2tlbiI6bnVsbCwiY3VzdG9tZXIiOnsiY2xhc3MiOiJjdXN0b21lciIsImVtYWlsIjoibmF0YWNoYSt0ZXN0QG1vdmlkb25lLmNvbSIsImxhbmd1YWdlIjoiZW4iLCJmaXJzdG5hbWUiOm51bGwsImxhc3RuYW1lIjpudWxsLCJiaWxsaW5nX2FkZHJlc3MiOm51bGx9LCJwYXltZW50X21ldGhvZCI6eyJjbGFzcyI6InBheW1lbnRfbWV0aG9kIiwidHlwZSI6IkNSRURJVENBUkQiLCJ2YWxpZGF0aW9uX3N0YXR1cyI6Ik5PVF9WQUxJREFURUQiLCJ2YWxpZGF0aW9uX2RhdGUiOm51bGwsImludmFsaWRfcmVhc29uIjpudWxsLCJkaXNwbGF5IjoiNTM0Ny05NHh4LXh4eHgtNzE1NiIsImRldGFpbHMiOnsiY2xhc3MiOiJwYXltZW50X21ldGhvZF9kZXRhaWxzIiwiZmluZ2VycHJpbnQiOiI0OGZlZGNlMC03OGE3LTQ1MTQtOTA5NC1mODg3NzIzZTA3OTgiLCJob2xkZXIiOiJUZXNuYW0gVGVzdXJuYW0iLCJzY2hlbWUiOiJNQVNURVJDQVJEIiwiZXhwX21vbnRoIjoiMDEiLCJleHBfeWVhciI6IjIwMjgifX0sInByb2Nlc3Nvcl9yZWZlcmVuY2UiOm51bGx9&merchant=9207700001&checksum=b6e114cbece37a97ee18af8f52ffa2b4c5c87d42

	# http://localhost:8888/?p=1&result=eyJjbGFzcyI6InBheW1lbnQiLCJpZCI6MjI3Mjg4LCJyZWZlcmVuY2UiOiI5MjA3NzAwMDAxMTYxODQ4MzM0MDk2MzllMjk5ZDE1NGRlZDhjOWVmOTMyOTU3YjNmM2RmIiwiYW1vdW50IjozNDAwLCJjdXJyZW5jeSI6IkVVUiIsInN0YXR1cyI6IlNVQ0NFU1MiLCJwcmVhdXRoX2V4cGlyYXRpb25fZGF0ZSI6bnVsbCwiYWN0aW9uX3VybCI6bnVsbCwiZmxvdyI6IkFQSSIsInByb2Nlc3NpbmdfdGltZSI6MTYxODQ4MzMxNCwic291cmNlIjoxMjU2NSwiY29udHJhY3QiOiJTeXNQYXkiLCJkZXNjcmlwdG9yIjoiVGVzdCBDb250cmFjdCIsImV4dHJhIjpudWxsLCJkZXNjcmlwdGlvbiI6IlJlZmVyZW5jZSBiaWxsIFRFUzAxOSIsImFjY291bnRfaWQiOjkyMDc3LCJtZXJjaGFudF9pZCI6MTE0NDksIm1lcmNoYW50X2xvZ2luIjoiOTIwNzcwMDAwMSIsInNldHRsZW1lbnRfZGF0ZSI6MTYxODQ4MzMxNSwiZmFpbHVyZV9jYXRlZ29yeSI6bnVsbCwiY2hpcF9hbmRfcGluX3N0YXR1cyI6bnVsbCwiY2FwdHVyZV9kYXRlIjpudWxsLCJ0b2tlbiI6bnVsbCwiY3VzdG9tZXIiOnsiY2xhc3MiOiJjdXN0b21lciIsImVtYWlsIjoibmF0YWNoYSt0ZXN0QG1vdmlkb25lLmNvbSIsImxhbmd1YWdlIjoiZW4iLCJmaXJzdG5hbWUiOm51bGwsImxhc3RuYW1lIjpudWxsLCJiaWxsaW5nX2FkZHJlc3MiOm51bGx9LCJwYXltZW50X21ldGhvZCI6eyJjbGFzcyI6InBheW1lbnRfbWV0aG9kIiwidHlwZSI6IkNSRURJVENBUkQiLCJ2YWxpZGF0aW9uX3N0YXR1cyI6Ik5PVF9WQUxJREFURUQiLCJ2YWxpZGF0aW9uX2RhdGUiOm51bGwsImludmFsaWRfcmVhc29uIjpudWxsLCJkaXNwbGF5IjoiNTM0Ny05NHh4LXh4eHgtNzE1NiIsImRldGFpbHMiOnsiY2xhc3MiOiJwYXltZW50X21ldGhvZF9kZXRhaWxzIiwiZmluZ2VycHJpbnQiOiI0OGZlZGNlMC03OGE3LTQ1MTQtOTA5NC1mODg3NzIzZTA3OTgiLCJob2xkZXIiOiJUZXNuYW0gVGVzdXJuYW0iLCJzY2hlbWUiOiJNQVNURVJDQVJEIiwiZXhwX21vbnRoIjoiMDEiLCJleHBfeWVhciI6IjIwMjgifX0sInByb2Nlc3Nvcl9yZWZlcmVuY2UiOm51bGx9&merchant=9207700001&checksum=cfff8625eb2cfc878d5fc67361e25a3b49fa8e17
	$mandatory_isset = function( $keys_to_check, $var = 'POST' ) {
		$key_set = function( $k ) use ( $var ) {
			if ( 'GET' === $var ) {
				return ! empty( $_GET[ $k ] );
			} else {
				return ! empty( $_POST[ $k ] );
			}
		};

		$keys_are_set = array_map( $key_set, $keys_to_check );

		return ! in_array( false, $keys_are_set, true );
	};

	if ( $mandatory_isset( array( 'result', 'merchant', 'checksum' ), 'GET' ) ) {
		$syspay = syspay__factory();

		if ( ! $syspay ) {
			error_log( 'Syspay standalone settings not complete.' );
			return;
		}

		// View variables.
		global $syspay_global_return_status;
		global $syspay_global_return_error;
		$syspay_global_return_status = null;
		$syspay_global_return_error  = null;

		$received_data = null;
		try {
			$received_data = $syspay->postProcess();
		} catch ( \Throwable $th ) {
		}

		if ( ! empty( $received_data ) ) {
			try {
				$response = new Syspay\SyspayResponse( $received_data );

				if ( $response->hasError() ) {
					throw new Exception( $response->getErrorMessage() );
				}

				if ( ! $response ) {
					throw new Exception( 'empty response' );
				}

				$syspay_global_return_status = $response->isSuccess();

				if ( ! $response->isSuccess() ) {
					if ( $response->getFailureCategory() ) {
						$syspay_global_return_error = $response->getFailureCategory();
					} else {
						$syspay_global_return_error = __( 'Your payment has failed' );
					}
				}
			} catch ( Exception $exc ) {
				$message = 'Syspay: Payment error: ' . $exc->getCode() . ' ( ' . $exc->getMessage() . ' ) ';

				$syspay_global_return_error = $message;
				error_log( $message );
			}
		}
	}

	/**
	 * Syspay standalone form endpoint
	 */
	$post_keys = array(
		'syspay-token',
		'syspay-single-form-page',
		'syspay_name',
		'syspay_surname',
		'syspay_email',
		'syspay_description',
		'syspay_amount',
	);

	if ( ! $mandatory_isset( $post_keys ) ) {
		return;
	}

	$payform_token       = sanitize_text_field( $_POST['syspay-token'] );
	$payform_name        = sanitize_text_field( $_POST['syspay_name'] );
	$payform_surname     = sanitize_text_field( $_POST['syspay_surname'] );
	$payform_email       = sanitize_text_field( $_POST['syspay_email'] );
	$payform_description = sanitize_text_field( $_POST['syspay_description'] );
	$payform_amount      = sanitize_text_field( $_POST['syspay_amount'] );

	// Optional phone number.
	$payform_phone = null;
	if ( ! empty( $_POST['syspay_phone'] ) ) {
		$payform_phone = sanitize_text_field( $_POST['syspay_phone'] );
	}

	$return_url = sprintf( '%s?syspay-standalone-form-return=12', home_url( '/' ) );

	$language = get_locale() === 'fr_FR' ? 'fr' : 'en';

	$syspay = syspay__factory();
	if ( ! $syspay ) {
		error_log( 'Syspay standalone settings not complete.' );
		return;
	}

	$amount = intval( 100 * $payform_amount );

	$reference = 'SyspWpForm_' . strval( random_int( 9999999, 99999999999 ) );

	if ( ! empty( $payform_phone ) ) {
		$payform_description .= ' - Tel. ' . strval( $payform_phone );
	}

	// debug
	// $debug = [
	// 	$amount, $payform_description, $return_url, $payform_token,
	// 	$payform_email, $reference, $payform_surname, $payform_name,
	// 	$language
	// ];
	// echo json_encode( $debug);
	// exit;
	$return_link = home_url( sanitize_text_field( $_SERVER['REQUEST_URI'] ) );
	$payment_conf = ( new Syspay\PaymentConf )
		->setAmount( $amount )
		->setDescription( $payform_description )
		->setReturnUrl( $return_link )
		->setEmsUrl( $return_url )
		->setTokenKey( $payform_token )
		->setCustomerEmail( $payform_email )
		->setCustomerReference( $reference )
		->setCustomerFirstName( $payform_surname )
		->setCustomerLastName( $payform_name )
		->setCustomerLanguage( $language )
		->setCustomerIP();

	try {
		$response = $syspay->sendPayment( $payment_conf );

		if ( $response->hasError() ) {
			throw new Exception( $response->getErrorMessage() );
		}
	} catch ( Exception $exc ) {
		echo json_encode(
			array(
				'error' => 'Syspay Payment Exception: ' . $exc->getMessage(),
			)
		);
		exit;
	}

	// Redirect to validation page
	if ( $response->isRedirect() ) {

		// Post-Process Redirection
		echo json_encode( array( 'redirect' => $response->getActionUrl() ) );

	} else {

		// Direct response from Syspay

		if ( ! $response->isSuccess() && $response->isCodeFinal() ) {

			// Payment failed

			error_log( 'Syspay : Payment failed: ' . $response->get( 'failure_category' ) );

			$message = sprintf( 'Status %s category %s', $response->get( 'status' ), $response->get( 'failure_category' ) );
			$message = __( 'Payment failed:', 'syspay-for-woocommerce' ) . ' ' . $message;

			echo json_encode( array( 'error' => $message ) );

			// syspay_form_payment_mail( $payform_email, false);

		} elseif ( $response->isSuccess() ) {

			// syspay_form_payment_mail( $payform_email, true);

			echo json_encode( array( 'success' => __( 'Your payment has been processed', 'syspay-for-woocommerce' ) ) );
		}
	}
	exit;
}

add_action( 'init', 'syspay_init_function' );


/**
 * Standalone form shortcode.
 */

function syspay_shortcodes_init() {
	add_shortcode( 'syspay', 'syspay_form_render_callback' );
}

// adds all shortcodes
add_action( 'init', 'syspay_shortcodes_init' );

