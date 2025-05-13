<?php

namespace WooTelegramStars\Gateway;

use WC_Payment_Gateway;
use WC_Order;
use WC_Admin_Settings;
use WooTelegramStars\Bot\TelegramBotHandler;

class TelegramStarsGateway extends WC_Payment_Gateway {
    private $bot_handler;

    public function __construct() {
        try {
            $this->id = 'telegram_stars';
            $this->icon = WTSG_PLUGIN_URL . 'assets/images/logo.svg';
            $this->has_fields = false;
            $this->method_title = __('Telegram Stars', 'woo-telegram-stars-gateway');
            $this->method_description = __('Accept Telegram Stars payments in your WooCommerce store', 'woo-telegram-stars-gateway');

            // Load the settings
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->bot_token = $this->get_option('bot_token');
            $this->bot_username = $this->get_option('bot_username');
            $this->price_per_star = floatval($this->get_option('price_per_star', '0.013'));
            $this->payment_timeout = intval($this->get_option('payment_timeout', '30'));
            $this->payment_notes = $this->get_option('payment_notes');
            $this->welcome_message = $this->get_option('welcome_message', __('Welcome! This bot helps you pay for your WooCommerce orders using Telegram Stars.', 'woo-telegram-stars-gateway'));
            $this->custom_logo = $this->get_option('custom_logo', WTSG_PLUGIN_URL . 'assets/images/logo.svg');

            // Override icon if custom logo is set
            if (!empty($this->custom_logo)) {
                $this->icon = $this->custom_logo;
            }

            // Initialize bot handler only if we have a token
            if (!empty($this->bot_token)) {
                try {
                    $this->bot_handler = new TelegramBotHandler(
                        $this->bot_token,
                        $this->price_per_star,
                        $this->payment_timeout,
                        $this->payment_notes,
                        $this->welcome_message
                    );
                } catch (\Exception $e) {
                    if (is_admin()) {
                        add_action('admin_notices', function() use ($e) {
                            echo '<div class="error"><p>Telegram Stars Gateway Error: ' . esc_html($e->getMessage()) . '</p></div>';
                        });
                    }
                }
            }

            // Actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'validate_bot_settings'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        } catch (\Exception $e) {
            if (is_admin()) {
                add_action('admin_notices', function() use ($e) {
                    echo '<div class="error"><p>Telegram Stars Gateway Error: ' . esc_html($e->getMessage()) . '</p></div>';
                });
            }
        }
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __('Enable/Disable', 'woo-telegram-stars-gateway'),
                'type'    => 'checkbox',
                'label'   => __('Enable Telegram Stars Gateway', 'woo-telegram-stars-gateway'),
                'default' => 'no'
            ),
            'title' => array(
                'title'       => __('Title', 'woo-telegram-stars-gateway'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'woo-telegram-stars-gateway'),
                'default'     => __('Telegram Stars', 'woo-telegram-stars-gateway'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', 'woo-telegram-stars-gateway'),
                'type'        => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'woo-telegram-stars-gateway'),
                'default'     => __('Pay with Telegram Stars', 'woo-telegram-stars-gateway'),
            ),
            'bot_token' => array(
                'title'       => __('Telegram Bot Token', 'woo-telegram-stars-gateway'),
                'type'        => 'text',
                'description' => __('Enter your Telegram Bot Token (obtained from @BotFather)', 'woo-telegram-stars-gateway'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'bot_username' => array(
                'title'       => __('Telegram Bot Username', 'woo-telegram-stars-gateway'),
                'type'        => 'text',
                'description' => __('Enter your Telegram Bot Username (without @ symbol)', 'woo-telegram-stars-gateway'),
                'default'     => '',
                'desc_tip'    => true,
                'custom_attributes' => array(
                    'placeholder' => 'my_store_bot'
                )
            ),
            'price_per_star' => array(
                'title'       => __('Price per Star', 'woo-telegram-stars-gateway'),
                'type'        => 'number',
                'description' => __('Enter the price per Telegram Star in your store currency', 'woo-telegram-stars-gateway'),
                'default'     => '0.013',
                'desc_tip'    => true,
                'custom_attributes' => array(
                    'step' => '0.001',
                    'min'  => '0.001'
                )
            ),
            'payment_timeout' => array(
                'title'       => __('Payment Timeout (minutes)', 'woo-telegram-stars-gateway'),
                'type'        => 'number',
                'description' => __('Time in minutes before the payment expires', 'woo-telegram-stars-gateway'),
                'default'     => '30',
                'desc_tip'    => true,
                'custom_attributes' => array(
                    'step' => '1',
                    'min'  => '1'
                )
            ),
            'payment_notes' => array(
                'title'       => __('Payment Notes', 'woo-telegram-stars-gateway'),
                'type'        => 'textarea',
                'description' => __('Important notes to display to users before payment', 'woo-telegram-stars-gateway'),
                'default'     => __('Please note that payments are processed through Telegram Stars. Make sure you have sufficient Stars balance in your Telegram account.', 'woo-telegram-stars-gateway'),
            ),
            'welcome_message' => array(
                'title'       => __('Welcome Message', 'woo-telegram-stars-gateway'),
                'type'        => 'textarea',
                'description' => __('Customize the welcome message shown in the Telegram bot', 'woo-telegram-stars-gateway'),
                'default'     => __('Welcome! This bot helps you pay for your WooCommerce orders using Telegram Stars.', 'woo-telegram-stars-gateway'),
            ),
            'custom_logo' => array(
                'title'       => __('Custom Logo URL', 'woo-telegram-stars-gateway'),
                'type'        => 'text',
                'description' => __('Enter a custom URL for the gateway logo. Leave empty to use the default.', 'woo-telegram-stars-gateway'),
                'default'     => WTSG_PLUGIN_URL . 'assets/images/logo.svg',
                'desc_tip'    => true,
            ),
            'business_mode_note' => array(
                'title'       => __('Business Mode Setup', 'woo-telegram-stars-gateway'),
                'type'        => 'title',
                'description' => __('To accept Stars, you need to edit the target bot using BotFather and enable Business Mode in the Settings section.', 'woo-telegram-stars-gateway'),
            ),
            'webhook_section' => array(
                'title'       => __('Webhook Setup', 'woo-telegram-stars-gateway'),
                'type'        => 'title',
                'description' => $this->get_webhook_instructions(),
            ),
        );
    }

    /**
     * Get webhook setup instructions
     */
    private function get_webhook_instructions() {
        $token = esc_attr($this->get_option('bot_token'));
        $domain = esc_url(site_url());
        
        // Clean domain of trailing slashes
        $domain = rtrim($domain, '/');
        
        // Create the webhook URL template
        if (!empty($token)) {
            $webhook_url = "https://api.telegram.org/bot{$token}/setWebhook?url={$domain}/wc-api/telegram_stars_gateway";
        } else {
            $webhook_url = "https://api.telegram.org/bot[Token]/setWebhook?url={$domain}/wc-api/telegram_stars_gateway";
        }
        
        // Create the instructions HTML
        $instructions = '<div class="webhook-instructions" style="background: #f8f8f8; padding: 15px; border: 1px solid #ddd; margin-bottom: 15px;">';
        $instructions .= '<p><strong>' . esc_html__('WebHook Set', 'woo-telegram-stars-gateway') . '</strong></p>';
        $instructions .= '<p>' . esc_html__('You need manually set telegram webhook (Connection between Telegram and WooCommerce). For doing this, open this link:', 'woo-telegram-stars-gateway') . '</p>';
        $instructions .= '<p><code style="display: block; padding: 10px; background: #fff; border: 1px solid #ddd; word-break: break-all;">' . esc_url($webhook_url) . '</code></p>';
        
        if (empty($token)) {
            $instructions .= '<p>' . esc_html__('When token message is not filled, alternatively use [Token] in link.', 'woo-telegram-stars-gateway') . '</p>';
        }
        
        $instructions .= '</div>';
        
        return $instructions;
    }

    public function validate_bot_settings() {
        $bot_token = sanitize_text_field($this->get_option('bot_token'));
        $bot_username = sanitize_text_field($this->get_option('bot_username'));

        if (empty($bot_token) || empty($bot_username)) {
            return;
        }

        // Remove @ symbol if present
        $bot_username = ltrim($bot_username, '@');

        // Update the username in case it was entered with @ symbol
        $this->update_option('bot_username', $bot_username);
    }

    public function process_payment($order_id) {
        try {
            $order_id = absint($order_id);
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new \Exception(__('Invalid order', 'woo-telegram-stars-gateway'));
            }
            
            // Calculate stars amount - convert total to integer stars
            $total = $order->get_total();
            $stars_amount = ceil($total / $this->price_per_star);
            
            // Generate unique payment ID
            $payment_id = uniqid('wtsg_', true);
            
            // Log the payment initialization
            error_log(sprintf(
                '[Telegram Stars] Creating payment for Order #%s, Payment ID: %s, Stars Amount: %d, Price per Star: %s, Total: %s',
                $order_id,
                $payment_id,
                $stars_amount,
                $this->price_per_star,
                $total
            ));
            
            // Store payment information
            global $wpdb;
            $result = $wpdb->insert(
                $wpdb->prefix . 'wtsg_payments',
                array(
                    'order_id' => $order_id,
                    'telegram_payment_id' => $payment_id,
                    'stars_amount' => $stars_amount,
                    'status' => 'pending',
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ),
                array('%d', '%s', '%d', '%s', '%s', '%s')
            );

            if ($result === false) {
                error_log(sprintf(
                    '[Telegram Stars] Error inserting payment record: %s',
                    $wpdb->last_error
                ));
                throw new \Exception(__('Error storing payment information', 'woo-telegram-stars-gateway'));
            }
            
            // Get the actual ID from database
            $payment_db_id = $wpdb->insert_id;
            error_log(sprintf(
                '[Telegram Stars] Payment record created with DB ID: %d',
                $payment_db_id
            ));

            // Generate Telegram payment URL using bot username
            $bot_username = sanitize_text_field(ltrim($this->bot_username, '@'));
            if (empty($bot_username)) {
                throw new \Exception(__('Bot username is not configured', 'woo-telegram-stars-gateway'));
            }

            // Create the Telegram deep link - use the numeric DB ID which is simpler
            // Format: https://t.me/botusername?start=123
            $payment_url = esc_url_raw("https://t.me/{$bot_username}?start={$payment_db_id}");
            
            error_log(sprintf(
                '[Telegram Stars] Payment URL generated: %s',
                $payment_url
            ));

            // Update order status
            $order->update_status('pending', __('Awaiting Telegram Stars payment', 'woo-telegram-stars-gateway'));
            $order->add_order_note(sprintf(
                __('Telegram payment link created: %s', 'woo-telegram-stars-gateway'),
                $payment_url
            ));
            
            // Empty cart
            WC()->cart->empty_cart();

            // Return thankyou redirect
            return array(
                'result'   => 'success',
                'redirect' => $payment_url
            );
        } catch (\Exception $e) {
            error_log(sprintf(
                '[Telegram Stars] Error in process_payment: %s',
                $e->getMessage()
            ));
            wc_add_notice($e->getMessage(), 'error');
            return array(
                'result' => 'failure',
                'messages' => $e->getMessage()
            );
        }
    }

    public function process_admin_options() {
        try {
            $saved = parent::process_admin_options();
            
            if ($saved) {
                // Reinitialize bot handler with new settings
                if (!empty($this->bot_token)) {
                    try {
                        $this->bot_handler = new TelegramBotHandler(
                            $this->bot_token,
                            $this->price_per_star,
                            $this->payment_timeout,
                            $this->payment_notes,
                            $this->welcome_message
                        );
                    } catch (\Exception $e) {
                        \WC_Admin_Settings::add_error(sprintf(
                            __('Error initializing bot handler: %s', 'woo-telegram-stars-gateway'),
                            $e->getMessage()
                        ));
                    }
                }
            }
            
            return $saved;
        } catch (\Exception $e) {
            \WC_Admin_Settings::add_error(sprintf(
                __('Error saving settings: %s', 'woo-telegram-stars-gateway'),
                $e->getMessage()
            ));
            return false;
        }
    }

    public function enqueue_admin_scripts() {
        wp_enqueue_script(
            'wtsg-admin',
            WTSG_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            WTSG_VERSION,
            true
        );

        wp_localize_script('wtsg-admin', 'wtsgAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wtsg_admin_nonce'),
            'testing' => __('Testing...', 'woo-telegram-stars-gateway'),
            'success' => __('Success!', 'woo-telegram-stars-gateway'),
            'error' => __('Error:', 'woo-telegram-stars-gateway'),
            'viewLogsUrl' => admin_url('admin.php?page=wc-settings&tab=checkout&section=telegram_stars&action=view_logs&_wpnonce=' . wp_create_nonce('view_telegram_logs')),
        ));
    }
} 