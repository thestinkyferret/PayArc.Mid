# PayArc Middleware for WP Swings Subscriptions (***WIP***)

**PayArc.Mid** is a WordPress plugin that acts as middleware to integrate the PayArc payment gateway’s vault system with [Subscriptions for WooCommerce Pro](https://wpswings.com/product/subscriptions-for-woocommerce-pro/) by WP Swings. It enables subscription-based payments by capturing card details, storing them in PayArc’s vault, and processing initial and renewal payments using vault IDs.



CURRENTLY VERY WIP. NEEDS MAJOR CONFIGURATION CHANGES BEFORE IT CAN WORK : )




## Purpose

This plugin addresses the challenge of using PayArc, which lacks native recurring tokenization, for subscription payments in WooCommerce. It:
- Captures card details during checkout and sends them to PayArc’s vault for secure storage.
- Stores the returned vault ID in WooCommerce order and subscription meta.
- Processes initial payments and subscription renewals using the vault ID, triggered by WP Swings Subscriptions’ hooks.
- Ensures per-order/customer vault ID storage for individual customer transactions.

## Features

- **Custom Payment Gateway:** Adds a PayArc payment gateway to WooCommerce checkout.
- **Vault Integration:** Securely stores card details in PayArc’s vault, retrieving a unique vault ID.
- **Subscription Support:** Integrates with WP Swings Subscriptions for initial and renewal payments.
- **Error Handling:** Validates card inputs and logs errors for failed payments or renewals.
- **Test Mode:** Supports PayArc’s sandbox environment for testing.

## Requirements

- WordPress 5.0 or higher
- WooCommerce 4.0 or higher
- Subscriptions for WooCommerce Pro by WP Swings
- PayArc API credentials (API Key, Merchant ID)
- PHP 7.2 or higher

## Installation

1. **Download the Plugin:**
   - Clone this repository or download the `payarc-subscriptions-middleware.php` file.

2. **Install the Plugin:**
   - Upload the plugin file to your WordPress site’s `/wp-content/plugins/` directory.
   - Activate the plugin via the WordPress admin dashboard under **Plugins**.

3. **Configure the Gateway:**
   - Go to **WooCommerce > Settings > Payments**.
   - Enable the **PayArc Middleware Gateway**.
   - Enter your PayArc **API Key** and **Merchant ID** (obtained from PayArc’s developer portal).
   - Optionally, enable **Test Mode** for sandbox testing.

4. **Update API Endpoints:**
   - Replace the placeholder API endpoints in the plugin (`https://api.payarc.net/vault` and `https://api.payarc.net/charge`) with the actual endpoints provided by PayArc.
   - Verify the request/response structure matches PayArc’s API documentation.

5. **Test the Integration:**
   - Create a subscription product in WooCommerce using Subscriptions for WooCommerce Pro.
   - Test a subscription purchase to confirm the vault ID is stored and the initial payment processes.
   - Simulate a renewal (via WP Swings’ manual renewal or cron job) to ensure the vault ID is used correctly.

## How It Works

The plugin acts as middleware between WP Swings Subscriptions and PayArc’s vault system:

1. **Initial Payment:**
   - Captures card details (number, expiry, CVC) during checkout.
   - Sends details to PayArc’s vault API to store the card and process the initial payment.
   - Stores the returned `vault_id` in the order and subscription meta.

2. **Renewal Payments:**
   - When WP Swings triggers a renewal, the plugin retrieves the `vault_id` from the order/subscription meta.
   - Sends a payment request to PayArc’s charge API using the `vault_id`.
   - Updates the order/subscription status based on the payment outcome.

## System Diagram

```mermaid
graph TD
    A[WP Swings Subscriptions for WooCommerce Pro] -->|Triggers Renewal| B[PayArc.Mid Plugin]
    B -->|Captures Card Details, Stores Vault ID| C[PayArc Payment Gateway]
    B -->|Processes Renewals with Vault ID| C
    C -->|Vault API: Stores Card, Returns Vault ID| B
    C -->|Charge API: Processes Payments| B
    A -->|Manages Subscription Lifecycle| B
```

## Known Issues and Limitations

- **No Recurring Tokenization:** PayArc does not support native recurring tokenization, so renewals may fail if the vault system requires CVC for each charge (PCI DSS prohibits storing CVC).
- **Placeholder API Endpoints:** The plugin uses generic PayArc API endpoints (`/vault` and `/charge`). You must update these with actual endpoints from PayArc’s documentation.
- **Renewal Challenges:** If PayArc requires CVC for renewals, automated payments may fail, requiring manual intervention or a custom PayArc solution.
- **Testing Required:** Thorough testing in PayArc’s sandbox is necessary to ensure compatibility with your specific PayArc account and WP Swings setup.
- **Limited PayArc Documentation:** The plugin assumes a standard vault system response structure. Confirm the exact API parameters and responses with PayArc.

## Workarounds

- **CVC Requirement:** If renewals require CVC, implement manual renewals via WP Swings’ invoice system or prompt users to update payment methods.
- **API Customization:** Contact PayArc for API documentation to update endpoints and parameters.
- **Alternative Gateways:** Consider switching to WP Swings-supported gateways like Stripe or PayPal for seamless recurring payments.

## Contributing

Contributions are welcome! Please submit pull requests or open issues for bug fixes, improvements, or additional features. Ensure code follows WordPress coding standards and includes tests.

## Support

- **WP Swings Support:** Contact WP Swings at [support.wpswings.com](https://support.wpswings.com) for compatibility or custom development.
- **PayArc Support:** Reach out to PayArc’s developer support for API documentation and vault system details.
- **Issues:** Report bugs or request features via [GitHub Issues](https://github.com/your-repo/issues).

## License

This project is licensed under the [GPLv3 License](LICENSE).
