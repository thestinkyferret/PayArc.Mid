<?php
/*
Plugin Name: Payarc.Mid: PayArc Middleware for WP Swings Subscriptions
Description: Middleware to integrate PayArc vault system with Subscriptions for WooCommerce Pro by WP Swings, handling initial payments and renewals using vault IDs.
Version: 1.0.2
Author: thestinkyferret
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Register the PayArc payment gateway
add_action('plugins_loaded', 'init_payarc_middleware_gateway');
function init_payarc_middleware_gateway() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_PayArc_Middleware extends WC_Payment_Gateway {
        public function __construct() {
            $this->id = 'payarc_middleware';
            $this->method_title = 'PayArc Middleware Gateway';
            $this->method_description = 'Pay with PayArc vault system for subscriptions, using vault ID for renewals.';
            $this->has_fields = true;
            $this->supports = array(
                'products',
                'subscriptions',
                'subscription_cancellation',
                'subscription_suspension',
                'subscription_reactivation',
            );

            // Load settings
            $this->init_form_fields();
            $this->init_settings();

            // Define user settings
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->api_key = $this->get_option('api_key');
            $this->merchant_id = $this->get_option('merchant_id');
            $this->test_mode = 'yes' === $this->get_option('test_mode');

            // Save settings
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            // Hook for subscription renewals
            add_action('woocommerce_scheduled_subscription_payment_' . $this->id, array($this, 'process_subscription_payment'), 10, 2);
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'type' => 'checkbox',
                    'label' => 'Enable PayArc Middleware Gateway',
                    'default' => 'yes',
                ),
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'description' => 'The title displayed to customers during checkout.',
                    'default' => 'PayArc (Credit/Debit Card)',
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => 'Description',
                    'type' => 'textarea',
                    'description' => 'The description displayed to customers during checkout.',
                    'default' => 'Pay securely using your credit or debit card via PayArc.',
                ),
                'api_key' => array(
                    'title' => 'API Key',
                    'type' => 'text',
                    'description' => 'Enter your PayArc API Key.',
                    'default' => '',
                ),
                'merchant_id' => array(
                    'title' => 'Merchant ID',
                    'type' => 'text',
                    'description' => 'Enter your PayArc Merchant ID.',
                    'default' => '',
                ),
                'test_mode' => array(
                    'title' => 'Test Mode',
                    'type' => 'checkbox',
                    'label' => 'Enable Test Mode',
                    'default' => 'yes',
                    'description' => 'Enable to use PayArc sandbox environment.',
                ),
            );
        }

        public function payment_fields() {
            echo wpautop(wp_kses_post($this->description));
            ?>
            <p>
                <label for="payarc_card_number">Card Number <span class="required">*</span></label>
                <input type="text" id="payarc_card_number" name="payarc_card_number" required />
            </p>
            <p>
                <label for="payarc_expiry">Expiry (MM/YY) <span class="required">*</span></label>
                <input type="text" id="payarc_expiry" name="payarc_expiry" placeholder="MM/YY" required />
            </p>
            <p>
                <label for="payarc_cvc">CVC <span class="required">*</span></label>
                <input type="text" id="payarc_cvc" name="payarc_cvc" required />
            </p>
            <?php
        }

        public function validate_fields() {
            $errors = array();

            if (empty($_POST['payarc_card_number']) || !preg_match('/^\d{13,19}$/', $_POST['payarc_card_number'])) {
                $errors[] = 'Card number is required and must be valid.';
            }

            if (empty($_POST['payarc_expiry']) || !preg_match('/^\d{2}\/\d{2}$/', $_POST['payarc_expiry'])) {
                $errors[] = 'Card expiry is required and must be in MM/YY format.';
            }

            if (empty($_POST['payarc_cvc']) || !preg_match('/^\d{3,4}$/', $_POST['payarc_cvc'])) {
                $errors[] = 'CVC is required and must be 3 or 4 digits.';
            }

            foreach ($errors as $error) {
                wc_add_notice($error, 'error');
            }

            return empty($errors);
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);

            // Validate card inputs
            if (!$this->validate_fields()) {
                return;
            }

            $card_number = sanitize_text_field($_POST['payarc_card_number']);
            $expiry = sanitize_text_field($_POST['payarc_expiry']);
            $cvc = sanitize_text_field($_POST['payarc_cvc']);

            // Split expiry into month and year
            $expiry_parts = explode('/', $expiry);
            $exp_month = !empty($expiry_parts[0]) ? trim($expiry_parts[0]) : '';
            $exp_year = !empty($expiry_parts[1]) ? trim($expiry_parts[1]) : '';

            // PayArc API endpoint for vault creation and initial payment (replace with actual endpoint)
            $endpoint = $this->test_mode ? 'https://api.payarc.net/sandbox/vault' : 'https://api.payarc.net/vault';

            // Send card details to PayArc vault and process initial payment
            $response = wp_remote_post($endpoint, array(
                'method' => 'POST',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type' => 'application/json',
                ),
                'body' => json_encode(array(
                    'merchant_id' => $this->merchant_id,
                    'card_number' => $card_number,
                    'exp_month' => $exp_month,
                    'exp_year' => $exp_year,
                    'cvc' => $cvc,
                    'amount' => $order->get_total(),
                    'currency' => get_woocommerce_currency(),
                    'description' => 'Order #' . $order_id,
                )),
                'timeout' => 30,
            ));

            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                wc_add_notice('Payment error: ' . $error_message, 'error');
                $order->add_order_note('PayArc payment failed: ' . $error_message);
                return;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);

            if (!empty($body['vault_id']) && $body['status'] === 'success') {
                // Store vault ID in order and subscription meta
                $order->update_meta_data('_payarc_vault_id', $body['vault_id']);
                $order->save();

                // Link vault ID to WP Swings subscriptions
                if (function_exists('wcs_order_contains_subscription') && wcs_order_contains_subscription($order)) {
                    $subscriptions = wcs_get_subscriptions_for_order($order);
                    foreach ($subscriptions as $subscription) {
                        $subscription->update_meta_data('_payarc_vault_id', $body['vault_id']);
                        $subscription->save();
                    }
                }

                // Mark payment complete
                $order->payment_complete($body['transaction_id'] ?? '');
                $order->add_order_note('PayArc payment successful. Vault ID: ' . $body['vault_id'] . ', Transaction ID: ' . ($body['transaction_id'] ?? 'N/A'));

                return array(
                    'result' => 'success',
                    'redirect' => $this->get_return_url($order),
                );
            } else {
                $error_message = $body['message'] ?? 'Unable to create vault entry or process payment.';
                wc_add_notice('Payment error: ' . $error_message, 'error');
                $order->add_order_note('PayArc payment failed: ' . $error_message);
                return;
            }
        }

        public function process_subscription_payment($amount, $order) {
            // Retrieve vault ID from subscription or order meta
            $vault_id = $order->get_meta('_payarc_vault_id');
            if (empty($vault_id)) {
                $order->add_order_note('PayArc renewal failed: No vault ID found.');
                if (function_exists('wcs_get_subscriptions_for_order')) {
                    $subscriptions = wcs_get_subscriptions_for_order($order);
                    foreach ($subscriptions as $subscription) {
                        $subscription->update_status('on-hold', 'PayArc renewal failed: No vault ID.');
                    }
                }
                return;
            }

            // PayArc API endpoint for processing payment with vault ID (replace with actual endpoint)
            $endpoint = $this->test_mode ? 'https://api.payarc.net/sandbox/charge' : 'https://api.payarc.net/charge';

            // Process renewal payment using vault ID
            $response = wp_remote_post($endpoint, array(
                'method' => 'POST',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type' => 'application/json',
                ),
                'body' => json_encode(array(
                    'merchant_id' => $this->merchant_id,
                    'vault_id' => $vault_id,
                    'amount' => $amount,
                    'currency' => get_woocommerce_currency(),
                    'description' => 'Subscription renewal for Order #' . $order->get_id(),
                )),
                'timeout' => 30,
            ));

            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                $order->add_order_note('PayArc renewal failed: ' . $error_message);
                if (function_exists('wcs_get_subscriptions_for_order')) {
                    $subscriptions = wcs_get_subscriptions_for_order($order);
                    foreach ($subscriptions as $subscription) {
                        $subscription->update_status('on-hold', 'PayArc renewal failed: ' . $error_message);
                    }
                }
                return;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($body['status'] === 'success') {
                $order->payment_complete($body['transaction_id'] ?? '');
                $order->add_order_note('PayArc renewal payment successful. Transaction ID: ' . ($body['transaction_id'] ?? 'N/A'));
            } else {
                $error_message = $body['message'] ?? 'Unknown error during renewal.';
                $order->add_order_note('PayArc renewal failed: ' . $error_message);
                if (function_exists('wcs_get_subscriptions_for_order')) {
                    $subscriptions = wcs_get_subscriptions_for_order($order);
                    foreach ($subscriptions as $subscription) {
                        $subscription->update_status('on-hold', 'PayArc renewal failed: ' . $error_message);
                    }
                }
            }
        }
    }

    add_filter('woocommerce_payment_gateways', 'add_payarc_middleware_gateway');
    function add_payarc_middleware_gateway($gateways) {
        $gateways[] = 'WC_Gateway_PayArc_Middleware';
        return $gateways;
    }
}
?>