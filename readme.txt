=== WooCommerce Telegram Stars Gateway ===
Contributors: asghar
Tags: woocommerce, payment gateway, telegram, telegram stars, payment
Requires at least: 5.8
Tested up to: 6.4
Stable tag: 1.0.5
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 5.0
WC tested up to: 8.0

Accept Telegram Stars payments in your WooCommerce store.

== Description ==

This plugin integrates Telegram Stars payments into your WooCommerce store. When customers choose this payment method at checkout, they are redirected to a Telegram bot where they can complete their payment using Telegram Stars.

= Features =

* Seamless integration with WooCommerce
* Customizable payment gateway settings
* Automatic order status updates
* Secure payment processing through Telegram
* Support for multiple currencies (converted to Stars)
* Customizable payment notes
* Payment timeout settings

= Requirements =

* WordPress 5.8 or higher
* WooCommerce 5.0 or higher
* PHP 7.4 or higher
* SSL certificate (required for Telegram payments)
* Telegram Bot Token (obtained from @BotFather)
* Telegram Stars Provider Token (obtained from @BotFather)

== Installation

Download latest Release:
[Download Latest Release](https://github.com/Aliasgharhi/woo-telegram-gateway/releases/latest)

Go To WordPress Dashboard → Plugins → Add New → Upload Plugin and choose the plugin and upload it

= Bot Setup =

1. Create a new Telegram bot using [@BotFather](https://t.me/botfather)
2. Enable payments for your bot using [@BotFather](https://t.me/botfather)
   - Send `/mybots` to @BotFather
   - Select your bot
   - Click "Payments"
   - Select a payment provider
   - Follow the instructions to set up payments
3. Go to WooCommerce → Settings → Payments
4. Find "Telegram Stars" in the payment methods list
5. Click "Manage" to configure the gateway
6. Fill in the following settings:
   - Enable/Disable: Enable the gateway
   - Title: The payment method title shown at checkout
   - Description: The payment method description shown at checkout
   - Telegram Bot Token: Your bot token from @BotFather
   - Bot Username: Your bot username (without @ symbol)
   - Price per Star: The price of one Telegram Star in your store currency
   - Payment Timeout: Time in minutes before the payment expires
   - Payment Notes: Important notes to display to users before payment

== Frequently Asked Questions ==

= How do I get a Telegram Bot Token? =

1. Open Telegram and search for [@BotFather](https://t.me/botfather)
2. Send the `/newbot` command
3. Follow the instructions to create your bot
4. BotFather will give you a token - save this for the plugin settings

= How do I enable payments for my bot? =

1. Send `/mybots` to @BotFather
2. Select your bot
3. Click "Payments"
4. Select a payment provider
5. Follow the instructions to set up payments
6. Save the provider token for the plugin settings

= What happens if a payment times out? =

If a payment is not completed within the configured timeout period:
1. The payment link will expire
2. The order will remain in "Pending" status
3. The customer will need to place a new order

= Can I customize the payment notes? =

Yes, you can customize the payment notes in the gateway settings. These notes will be displayed to customers before they make a payment.

= Is this plugin compatible with HPOS (High-Performance Order Storage)? =

Yes, this plugin fully supports WooCommerce HPOS.

== Screenshots ==

1. Payment Gateway Settings
2. Customer Checkout View
3. Telegram Payment Interface
4. Admin Order Details

== Changelog ==

= 1.0.5 =
* Fixed webhook URL format to improve compatibility with different server configurations
* Enhanced error handling and logging
* Improved security with better input sanitization
* Optimized admin interface

= 1.0.4 =
* Added payment timeout functionality
* Added automatic deletion of payment messages after timeout
* Added payment confirmation messages

= 1.0.3 =
* Changed currency from STARS to XTR
* Fixed stars amount calculation
* Updated button text format

= 1.0.2 =
* Fixed webhook configuration
* Added provider token handling

= 1.0.1 =
* Fixed WC_Admin_Settings class error
* Improved webhook handling

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.5 =
This version improves security and fixes the webhook URL format. Update recommended for all users.

== Privacy Policy ==

This plugin does not collect any personal data from your website visitors other than what's required for WooCommerce order processing.

For Telegram payments, customers may need to comply with Telegram's privacy policy which can be found at: https://telegram.org/privacy 