<?php
/*
Plugin Name: PayArc.Mid - PayArc Subscriptions Gateway
Description: A WooCommerce payment gateway for PayArc subscriptions, compatible with WP Swings Subscriptions. Handles customer creation, card tokenization, and subscription setup, with renewals managed by PayArc.
Version: 1.0.6
Author: thestinkyferret
License: GPL-2.0+
Requires PHP: 7.4
Requires WP: 5.0
WC requires at least: 4.0
WC tested up to: 9.9.3
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define constants
define('PAYARC_MID_VERSION', '1.0.0');

// Initialize plugin
add_action('plugins_loaded', 'payarc_mid_init', 11);

function payarc_mid_init() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    // Register payment gateway
    add_filter('woocommerce_payment_gateways', 'payarc_mid_add_gateway');

    // Register webhook endpoint
    add_action('rest_api_init', function () {
        register_rest_route('payarc-mid/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => 'payarc_mid_webhook_handler',
            'permission_callback' => '__return_true',
        ]);
    });

    // PayArc API class
    class PayArc_API {
        private $api_key;
        private $endpoint;
        private $testmode;
        private $debug_mode;

        public function __construct($api_key, $testmode = true, $debug_mode = false) {
            $this->api_key = $api_key;
            $this->testmode = $testmode;
            $this->endpoint = $testmode ? 'https://testapi.payarc.net/v1/' : 'https://api.payarc.net/v1/';
            $this->debug_mode = $debug_mode;
        }

        public function create_customer($email, $name, $address = []) {
            $data = [
                'name' => sanitize_text_field($name),
                'email' => sanitize_email($email),
            ];
            if (!empty($address)) {
                $data = array_merge($data, [
                    'address_1' => sanitize_text_field($address['address_1'] ?? ''),
                    'address_2' => sanitize_text_field($address['address_2'] ?? ''),
                    'city' => sanitize_text_field($address['city'] ?? ''),
                    'state' => sanitize_text_field($address['state'] ?? ''),
                    'zip' => sanitize_text_field($address['zip'] ?? ''),
                    'country' => sanitize_text_field($address['country'] ?? ''),
                    'phone' => sanitize_text_field($address['phone'] ?? ''),
                ]);
            }
            return $this->request('POST', 'customers', $data);
        }

        public function tokenize_card($card_number, $exp_month, $exp_year, $cvv) {
            $response = $this->request('POST', 'tokens', [
                'card_source' => 'INTERNET',
                'card_number' => preg_replace('/\s+/', '', $card_number),
                'exp_month' => absint($exp_month),
                'exp_year' => absint($exp_year),
                'cvv' => absint($cvv),
                'authorize_card' => 1,
            ]);
            return $response ? $response['data']['id'] : false;
        }

        public function attach_token_to_customer($customer_id, $token_id) {
            $response = $this->request('PATCH', "customers/{$customer_id}", [
                'token_id' => sanitize_text_field($token_id),
            ]);
            return $response ? true : false;
        }

        public function create_plan($amount, $interval, $name, $plan_code) {
            $response = $this->request('POST', 'plans', [
                'amount' => floatval($amount) * 100, // Convert to cents
                'currency' => 'usd',
                'interval' => sanitize_text_field($interval),
                'interval_count' => 1,
                'name' => sanitize_text_field($name),
                'plan_code' => sanitize_text_field($plan_code),
                'statement_descriptor' => substr(sanitize_text_field($name), 0, 25),
            ]);
            return $response ? $response['data']['id'] : false;
        }

        public function create_subscription($customer_id, $plan_id) {
            $response = $this->request('POST', 'subscriptions', [
                'customer_id' => sanitize_text_field($customer_id),
                'plan_id' => sanitize_text_field($plan_id),
                'billing_type' => 1, // Automatic charging
            ]);
            return $response ? $response['data']['id'] : false;
        }

        public function cancel_subscription($subscription_id) {
            $response = $this->request('POST', "subscriptions/{$subscription_id}/cancel");
            return $response ? true : false;
        }

        private function request($method, $path, $data = []) {
            $args = [
                'method' => strtoupper($method),
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'timeout' => 30,
            ];

            if (!empty($data)) {
                $args['body'] = http_build_query($data);
            }

            $url = $this->endpoint . $path;
            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                $error_message = 'Payment error: Unable to connect to PayArc API. ' . $response->get_error_message();
                wc_add_notice(__($error_message, 'payarc-mid'), 'error');
                if ($this->debug_mode) {
                    error_log('PayArc API Error: ' . $error_message);
                }
                return false;
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (wp_remote_retrieve_response_code($response) >= 400) {
                $error_message = 'Payment error: ' . ($data['message'] ?? 'Unknown error');
                wc_add_notice(__($error_message, 'payarc-mid'), 'error');
                if ($this->debug_mode) {
                    error_log('PayArc API Error: ' . $body);
                }
                return false;
            }

            if ($this->debug_mode) {
                error_log('PayArc API Request: ' . $method . ' ' . $url . ' | Response: ' . $body);
            }

            return $data;
        }
    }

    // PayArc Gateway class
    class PayArc_Mid_Gateway extends WC_Payment_Gateway {
        public function __construct() {
            $this->id = 'payarc_mid';
            $this->method_title = __('PayArc.Mid', 'payarc-mid');
            $this->method_description = __('Accept subscriptions via PayArc, with renewals managed by PayArc.', 'payarc-mid');
            $this->has_fields = true;
            $this->supports = [
                'products',
                'subscriptions',
                'subscription_cancellation',
                'subscription_reactivation',
                'subscription_amount_changes',
                'subscription_date_changes',
            ];

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');
            $this->testmode = 'yes' === $this->get_option('testmode');
            $this->api_key = $this->testmode ? $this->get_option('test_api_key') : $this->get_option('api_key');
            $this->webhook_secret = $this->get_option('webhook_secret');
            $this->debug_mode = 'yes' === $this->get_option('debug_mode');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_subscription_payment_complete', [$this, 'process_subscription_payment'], 10, 2);
            add_action('woocommerce_subscription_cancelled', [$this, 'cancel_subscription'], 10, 1);
        }

        public function init_form_fields() {
            $this->form_fields = [
                'enabled' => [
                    'title' => __('Enable/Disable', 'payarc-mid'),
                    'type' => 'checkbox',
                    'label' => __('Enable PayArc.Mid Gateway', 'payarc-mid'),
                    'default' => 'yes',
                ],
                'title' => [
                    'title' => __('Title', 'payarc-mid'),
                    'type' => 'text',
                    'description' => __('The title displayed to the user during checkout.', 'payarc-mid'),
                    'default' => __('PayArc.Mid', 'payarc-mid'),
                ],
                'description' => [
                    'title' => __('Description', 'payarc-mid'),
                    'type' => 'textarea',
                    'description' => __('The description displayed to the user during checkout.', 'payarc-mid'),
                    'default' => __('Pay securely using your credit card.', 'payarc-mid'),
                ],
                'transaction_type' => [
                    'title' => __('Transaction Type', 'payarc-mid'),
                    'type' => 'select',
                    'description' => __('Select the transaction type for payments.', 'payarc-mid'),
                    'default' => 'sale',
                    'options' => [
                        'sale' => __('Sale', 'payarc-mid'),
                        'authorize' => __('Authorize', 'payarc-mid'),
                    ],
                ],
                'testmode' => [
                    'title' => __('Environment', 'payarc-mid'),
                    'type' => 'checkbox',
                    'label' => __('Enable Test Mode', 'payarc-mid'),
                    'default' => 'yes',
                    'description' => __('Enable to use the PayArc test environment.', 'payarc-mid'),
                ],
                'api_key' => [
                    'title' => __('Live Access Token', 'payarc-mid'),
                    'type' => 'text',
                    'description' => __('Enter your PayArc Live Access Token from the PayArc Dashboard.', 'payarc-mid'),
                ],
                'test_api_key' => [
                    'title' => __('Test Access Token', 'payarc-mid'),
                    'type' => 'text',
                    'description' => __('Enter your PayArc Test Access Token from the PayArc Dashboard.', 'payarc-mid'),
                ],
                'webhook_secret' => [
                    'title' => __('Webhook Secret', 'payarc-mid'),
                    'type' => 'text',
                    'description' => __('Enter your PayArc Webhook Secret for validating webhook requests.', 'payarc-mid'),
                ],
                'debug_mode' => [
                    'title' => __('Debug Mode', 'payarc-mid'),
                    'type' => 'checkbox',
                    'label' => __('Enable Debug Mode', 'payarc-mid'),
                    'default' => 'no',
                    'description' => __('Enable to log API requests and responses for debugging.', 'payarc-mid'),
                ],
            ];
        }

        public function payment_fields() {
            if ($this->testmode) {
                echo '<p>' . __('TEST MODE ENABLED. Use test card numbers only (e.g., 4012000098765439, Exp: 12/2025, CVV: 999).', 'payarc-mid') . '</p>';
            }
            echo wpautop(wp_kses_post($this->description));
            ?>
            <fieldset>
                <p class="form-row form-row-wide">
                    <label for="payarc_card_number"><?php _e('Card Number', 'payarc-mid'); ?> <span class="required">*</span></label>
                    <input id="payarc_card_number" class="input-text" type="text" autocomplete="off" name="payarc_card_number" />
                </p>
                <p class="form-row form-row-first">
                    <label for="payarc_exp_month"><?php _e('Expiration Month (MM)', 'payarc-mid'); ?> <span class="required">*</span></label>
                    <input id="payarc_exp_month" class="input-text" type="text" autocomplete="off" name="payarc_exp_month" />
                </p>
                <p class="form-row form-row-last">
                    <label for="payarc_exp_year"><?php _e('Expiration Year (YYYY)', 'payarc-mid'); ?> <span class="required">*</span></label>
                    <input id="payarc_exp_year" class="input-text" type="text" autocomplete="off" name="payarc_exp_year" />
                </p>
                <p class="form-row form-row-wide">
                    <label for="payarc_cvv"><?php _e('CVV', 'payarc-mid'); ?> <span class="required">*</span></label>
                    <input id="payarc_cvv" class="input-text" type="text" autocomplete="off" name="payarc_cvv" />
                </p>
            </fieldset>
            <?php
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            $api = new PayArc_API($this->api_key, $this->testmode, $this->debug_mode);

            // Validate card fields
            if (empty($_POST['payarc_card_number']) || empty($_POST['payarc_exp_month']) || empty($_POST['payarc_exp_year']) || empty($_POST['payarc_cvv'])) {
                wc_add_notice(__('All card fields are required.', 'payarc-mid'), 'error');
                return ['result' => 'failure'];
            }

            // Create or retrieve customer
            $user_id = $order->get_user_id();
            $customer_id = get_user_meta($user_id, '_payarc_customer_id', true);
            if (!$customer_id) {
                $address = [
                    'address_1' => $order->get_billing_address_1(),
                    'address_2' => $order->get_billing_address_2(),
                    'city' => $order->get_billing_city(),
                    'state' => $order->get_billing_state(),
                    'zip' => $order->get_billing_postcode(),
                    'country' => $order->get_billing_country(),
                    'phone' => $order->get_billing_phone(),
                ];
                $customer_response = $api->create_customer(
                    $order->get_billing_email(),
                    $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    $address
                );
                if (!$customer_response) {
                    wc_add_notice(__('Failed to create PayArc customer.', 'payarc-mid'), 'error');
                    return ['result' => 'failure'];
                }
                $customer_id = $customer_response['data']['id'];
                update_user_meta($user_id, '_payarc_customer_id', $customer_id);
            }

            // Tokenize card
            $token_id = $api->tokenize_card(
                sanitize_text_field($_POST['payarc_card_number']),
                absint($_POST['payarc_exp_month']),
                absint($_POST['payarc_exp_year']),
                absint($_POST['payarc_cvv'])
            );
            if (!$token_id) {
                wc_add_notice(__('Failed to tokenize card.', 'payarc-mid'), 'error');
                return ['result' => 'failure'];
            }

            // Attach token to customer
            if (!$api->attach_token_to_customer($customer_id, $token_id)) {
                wc_add_notice(__('Failed to attach card to customer.', 'payarc-mid'), 'error');
                return ['result' => 'failure'];
            }

            // Mark order as pending payment
            $order->update_status('pending', __('Awaiting PayArc payment.', 'payarc-mid'));
            return [
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true),
            ];
        }

        public function process_subscription_payment($subscription, $order) {
            $api = new PayArc_API($this->api_key, $this->testmode, $this->debug_mode);
            $subscription_id = get_post_meta($subscription->get_id(), '_payarc_subscription_id', true);
            if ($subscription_id) {
                return; // Subscription already created, renewals handled by PayArc
            }

            $product_id = $subscription->get_items()[array_key_first($subscription->get_items())]['product_id'];
            $customer_id = get_user_meta($subscription->get_user_id(), '_payarc_customer_id', true);

            // Create or retrieve plan
            $plan_id = get_post_meta($product_id, '_payarc_plan_id', true);
            if (!$plan_id) {
                $plan_code = 'plan_' . $product_id . '_' . time();
                $plan_id = $api->create_plan(
                    $subscription->get_total(),
                    'month', // Assuming monthly; adjust based on WP Swings settings
                    get_the_title($product_id),
                    $plan_code
                );
                if (!$plan_id) {
                    $subscription->update_status('failed', __('Failed to create PayArc plan.', 'payarc-mid'));
                    return;
                }
                update_post_meta($product_id, '_payarc_plan_id', $plan_id);
            }

            // Create subscription
            $subscription_id = $api->create_subscription($customer_id, $plan_id);
            if ($subscription_id) {
                update_post_meta($subscription->get_id(), '_payarc_subscription_id', $subscription_id);
                $subscription->update_status('active', __('PayArc subscription created.', 'payarc-mid'));
            } else {
                $subscription->update_status('failed', __('Failed to create PayArc subscription.', 'payarc-mid'));
            }
        }

        public function cancel_subscription($subscription) {
            $subscription_id = get_post_meta($subscription->get_id(), '_payarc_subscription_id', true);
            if ($subscription_id) {
                $api = new PayArc_API($this->api_key, $this->testmode, $this->debug_mode);
                if ($api->cancel_subscription($subscription_id)) {
                    $subscription->add_order_note(__('PayArc subscription cancelled.', 'payarc-mid'));
                } else {
                    $subscription->add_order_note(__('Failed to cancel PayArc subscription.', 'payarc-mid'), true);
                }
            }
        }
    }
}

function payarc_mid_add_gateway($gateways) {
    $gateways[] = 'PayArc_Mid_Gateway';
    return $gateways;
}

/**
 * Webhook handler
 */
function payarc_mid_webhook_handler($request) {
    $gateway = WC()->payment_gateways()->payment_gateways()['payarc_mid'];
    $api = new PayArc_API($gateway->api_key, $gateway->testmode, $gateway->debug_mode);

    $payload = json_decode($request->get_body(), true);
    if (!$payload) {
        if ($gateway->debug_mode) {
            error_log('PayArc Webhook Error: Invalid payload');
        }
        return new WP_Error('invalid_payload', 'Invalid webhook payload', ['status' => 400]);
    }

    // Verify webhook signature (assuming PayArc uses HMAC)
    $signature = $request->get_header('x-payarc-signature');
    $computed = hash_hmac('sha256', $request->get_body(), $gateway->webhook_secret);
    if (!hash_equals($computed, $signature)) {
        if ($gateway->debug_mode) {
            error_log('PayArc Webhook Error: Invalid signature');
        }
        return new WP_Error('invalid_signature', 'Invalid webhook signature', ['status' => 401]);
    }

    $event = $payload['event'];
    $data = $payload['data'];

    if ($gateway->debug_mode) {
        error_log('PayArc Webhook Received: ' . $event . ' | Data: ' . wp_json_encode($data));
    }

    switch ($event) {
        case 'invoice.paid':
            $subscription_id = $data['subscription_id'];
            $subscriptions = wcs_get_subscriptions(['meta_key' => '_payarc_subscription_id', 'meta_value' => $subscription_id]);
            if ($subscriptions) {
                $subscription = reset($subscriptions);
                $subscription->update_status('active', __('PayArc invoice paid.', 'payarc-mid'));
                WC_Subscriptions_Manager::process_subscription_payments_on_order($subscription->get_last_order());
            }
            break;
        case 'subscription.cancelled':
            $subscription_id = $data['subscription_id'];
            $subscriptions = wcs_get_subscriptions(['meta_key' => '_payarc_subscription_id', 'meta_value' => $subscription_id]);
            if ($subscriptions) {
                $subscription = reset($subscriptions);
                $subscription->update_status('cancelled', __('PayArc subscription cancelled.', 'payarc-mid'));
            }
            break;
        case 'invoice.payment_failed':
            $subscription_id = $data['subscription_id'];
            $subscriptions = wcs_get_subscriptions(['meta_key' => '_payarc_subscription_id', 'meta_value' => $subscription_id]);
            if ($subscriptions) {
                $subscription = reset($subscriptions);
                $subscription->update_status('on-hold', __('PayArc payment failed.', 'payarc-mid'));
                WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($subscription->get_last_order());
                wc_add_notice(__('Subscription payment failed.', 'payarc-mid'), 'error');
            }
            break;
    }

    return new WP_REST_Response(['status' => 'success'], 200);
}