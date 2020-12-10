<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 *Komoju Payment Gateway
 *
 * Provides access to Japanese local payment methods.
 *
 * @class       WC_Gateway_Komoju
 * @extends     WC_Payment_Gateway
 * @version     0.1
 * @package     WooCommerce/Classes/Payment
 * @author      Komoju
 */

require_once dirname(__FILE__) . '/vendor/komoju-php/lib/komoju.php';

class WC_Gateway_Komoju extends WC_Payment_Gateway {

    /** @var array Array of locales */
    public $locale;

    /** @var boolean Whether or not logging is enabled */
    public static $log_enabled;

    /** @var WC_Logger Logger instance */
    public static $log;

    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        $this->id                	= 'komoju';
        $this->icon              	= apply_filters('woocommerce_komoju_icon', plugins_url('assets/images/komoju-logo.png', __FILE__));
        $this->has_fields         	= true;
        $this->method_title       	= __( 'Komoju', 'komoju-woocommerce' );
        $this->method_description 	= __( 'Allows payments by Komoju, dedicated to Japanese online and offline payment gateways.', 'komoju-woocommerce' );
        $this->debug          		= 'yes' === $this->get_option( 'debug', 'yes' );
        $this->invoice_prefix		= $this->get_option( 'invoice_prefix' );
        $this->accountID     		= $this->get_option( 'accountID' );
        $this->secretKey     		= $this->get_option( 'secretKey' );
        $this->webhookSecretToken   = $this->get_option( 'webhookSecretToken' );
        $this->komoju_api = new KomojuApi( $this->secretKey );
        self::$log_enabled    		= $this->debug;
        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();
        // Define user set variables
        $this->title        = $this->get_option( 'title' );
        $this->description  = $this->get_option( 'description' );
        $this->instructions = $this->get_option( 'instructions', $this->description );
        // Filters
        // Actions
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        if ( ! $this->is_valid_for_use() ) {
            $this->enabled = 'no';
            WC_Gateway_Komoju::log( 'is not valid for use. No IPN set.' );
        } else {
            include_once( 'includes/class-wc-gateway-komoju-ipn-handler.php' );
            new WC_Gateway_Komoju_IPN_Handler( $this, $this->webhookSecretToken, $this->secretKey, $this->invoice_prefix );
        }
    }

    /**
     * Logging method
     * @param  string $message
     */
    public static function log( $message ) {
        if ( self::$log_enabled ) {
            if ( empty( self::$log ) ) {
                self::$log = new WC_Logger();
            }
            self::$log->add( 'komoju', $message );
        }
    }

    /**
     * Check if this gateway is enabled and available in the user's country
     *
     * @return bool
     */
    public function is_valid_for_use() {
        return in_array( get_woocommerce_currency(), apply_filters( 'woocommerce_komoju_supported_currencies', array( 'JPY' ) ) );
    }

    /**
     * Admin Panel Options
     */
    public function admin_options() {
        if ( $this->is_valid_for_use() ) {
            parent::admin_options();
        } else {
            ?>
            <div class="inline error"><p><strong><?php _e( 'Gateway Disabled', 'komoju-woocommerce' ); ?></strong>: <?php _e( 'Komoju does not support your store currency.', 'komoju-woocommerce' ); ?></p></div>
            <?php
        }
    }

    /**
     * Initialise Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = include( 'includes/settings-komoju.php' );
    }

    /**
     * Process the payment and return the result
     *
     * @param int $order_id
     * @return array
     */
    public function process_payment( $order_id ) {
        include_once( 'includes/class-wc-gateway-komoju-request.php' );
        $order          = wc_get_order( $order_id );
        $default_locale = $this->get_locale_or_fallback();
        $payment_method = array(sanitize_text_field($_POST['komoju-method']));
        $return_url = $this->get_mydefault_api_url();

        // new session
        $komoju_api = $this->komoju_api;
        $komoju_request = $komoju_api->createSession([
          'return_url'  => $return_url,
          'default_locale' => $this->get_locale_or_fallback(),
          'payment_types' => $payment_method,
          'payment_data' => [
            'amount' => $order->get_total(),
            'currency' => get_woocommerce_currency(),
            'external_order_num' => $this->external_order_num( $order )
          ],
        ]);

        // $komoju_request = new WC_Gateway_Komoju_Request( $this );

        return array(
          'result'   => 'success',
          'redirect' => $komoju_request->session_url
        );
    }

    /**
     * Payment form on checkout page
     */
    public function payment_fields() {
        $this->komoju_method_form();
    }

    /**
     * set KOMOJU side reference for order
     * @param WC_Order $order
     */
    private function external_order_num( $order ) {
      $suffix = substr(str_shuffle("0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 6);
      return ($this->get_option('invoice_prefix') . $order->get_order_number() . '-' . $suffix);
    }

    /**
     * Form to choose the payment method
     */
    private function komoju_method_form(  $args = array(), $fields = array()  ) {
        $default_args = array(
            'fields_have_names' => true,
        );

        $args = wp_parse_args( $args, apply_filters( 'woocommerce_komoju_method_form_args', $default_args, $this->id ) );

        $data = $this->get_input_field_data();
        $method_fields = array( 'method-field' => $data );
        $fields = wp_parse_args( $fields, apply_filters( 'woocommerce_komoju_method_form_fields', $method_fields, $this->id ) );
        ?>
        <fieldset id="<?php echo $this->id; ?>-cc-form">
          <?php do_action( 'woocommerce_komoju_method_form_start', $this->id ); ?>
          <?php
            foreach ( $fields as $field ) {
                echo $field;
            }
          ?>
          <?php do_action( 'woocommerce_komoju_method_form_end', $this->id ); ?>
          <div class="clear"></div>
        </fieldset>
        <?php
    }

    private function get_mydefault_api_url(){
        // In dev the relative plugin URL will remove the host name, but it
        // will appear in production instances
        return WC()->api_request_url( 'WC_Gateway_Komoju' );
    }

    private function get_input_field_data() {
        $komoju_client = $this->komoju_api;

        try {
            $methods = $komoju_client->paymentMethods();
            $page_locale = $this->get_locale_or_fallback();
            $name_property =  "name_{$page_locale}";

            $field_data = '
                <p
                  class="
                    form-row
                    form-row-wide
                    validate-required
                    woocommerce-validated"
                >
                <label
                  for="' . esc_attr( $this->id ) . '-method"
                >' . __( 'Method of payment:', 'komoju-woocommerce' ) . '
                  <abbr
                    class="required"
                    title="required"
                  >*
                  </abbr>
               </label>';
            foreach ($methods as $method) {
              $field_data.= '
                  <input
                    id="' . esc_attr( $this->id) . '-method"
                    class="input-radio"
                    type="radio"
                    value="'. esc_attr( $method->type_slug ) .'"
                    name="' . esc_attr( $this->id). '-method"
                  />
                  '. ( $method->{$name_property} ) .'
                  <br/>';
            }
            $field_data .= '</p>';
        } catch (KomojuExceptionBadServer | KomojuExceptionBadJson $e) {
            $message = $e->getMessage();
            $this->log($message);

            $field_data = '<p>' . __('Encountered an issue communicating with KOMOJU. Please wait a moment and try again.', 'komoju-woocommerce') .'</p>';
        }

        return $field_data;
    }

    private function get_locale_or_fallback() {
        $fallback_locale = 'en';
        $supported_locales = array('ja', 'en', 'ko');
        $page_locale = get_locale();

        if (in_array($page_locale, $supported_locales)) {
            return $page_locale;

        } else {
            return $fallback_locale;
        }
    }

    /**
     * Validate the payment form (for custom fields added)
     */
    function validate_fields() {
        if ( !isset( $_POST['komoju-method'] ) ){
            wc_add_notice( __( 'Please select a payment method (how you want to pay)', 'komoju-woocommerce' ), 'error' );
            return false;
        }
        return true;
    }
}
