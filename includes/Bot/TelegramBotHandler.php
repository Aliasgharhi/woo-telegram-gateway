<?php

namespace WooTelegramStars\Bot;

class TelegramBotHandler {
    private $bot_token;
    private $price_per_star;
    private $payment_timeout;
    private $payment_notes;
    private $welcome_message;
    private $debug_log;
    private $debug_mode;

    public function __construct($bot_token, $price_per_star, $payment_timeout, $payment_notes, $welcome_message = '') {
        try {
            if (empty($bot_token)) {
                throw new \Exception('Bot token is required');
            }

            $this->bot_token = $bot_token;
            $this->price_per_star = floatval($price_per_star);
            $this->payment_timeout = intval($payment_timeout);
            $this->payment_notes = $payment_notes;
            $this->welcome_message = !empty($welcome_message) ? $welcome_message : __('Welcome! This bot helps you pay for your WooCommerce orders using Telegram Stars.', 'woo-telegram-stars-gateway');
            
            // Set debug mode based on option or constant
            $this->debug_mode = apply_filters('wtsg_debug_mode', defined('WTSG_DEBUG') && WTSG_DEBUG);
            
            // Only set up debug logging if in debug mode
            if ($this->debug_mode) {
                // Set up debug logging in uploads directory
                $upload_dir = wp_upload_dir();
                $this->debug_log = $upload_dir['basedir'] . '/telegram-stars-debug.log';
                
                // Ensure log file exists and is writable
                if (!file_exists($this->debug_log)) {
                    @touch($this->debug_log);
                }
                
                if (!is_writable($this->debug_log)) {
                    $this->debug_log = $upload_dir['basedir'] . '/debug.log';
                    if (!file_exists($this->debug_log)) {
                        @touch($this->debug_log);
                    }
                }
                
                $this->log_debug('Bot handler initialized', array(
                    'bot_token_length' => strlen($bot_token),
                    'price_per_star' => $this->price_per_star,
                    'payment_timeout' => $this->payment_timeout,
                    'debug_log' => $this->debug_log
                ));
            }
            
            // Register webhook only if we're in the admin area or during a webhook request
            if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX) || $this->is_webhook_request()) {
                add_action('init', array($this, 'register_webhook'));
            }

            // Only add webhook handler if we're not in admin
            if (!$this->is_admin_request()) {
                add_action('woocommerce_api_telegram_stars_gateway', array($this, 'handle_webhook'));
            }
            
        } catch (\Exception $e) {
            $this->log_error('Error initializing bot handler: ' . $e->getMessage());
            if (is_admin()) {
                add_action('admin_notices', function() use ($e) {
                    echo '<div class="error"><p>Telegram Stars Gateway Error: ' . esc_html($e->getMessage()) . '</p></div>';
                });
            }
        }
    }

    private function is_admin_request() {
        return is_admin() && !defined('DOING_AJAX');
    }

    private function is_webhook_request() {
        return isset($_SERVER['REQUEST_URI']) && 
               strpos($_SERVER['REQUEST_URI'], 'wc-api/telegram_stars_gateway') !== false;
    }

    private function log_error($message, $data = null) {
        if (!$this->debug_mode) {
            // Always log critical errors to WordPress error log
            if (function_exists('error_log')) {
                error_log('Telegram Stars Gateway Error: ' . $message);
            }
            return;
        }
        
        if (function_exists('error_log') && isset($this->debug_log)) {
            $timestamp = current_time('mysql');
            $log_message = "[{$timestamp}] ERROR: {$message}";
            if ($data !== null) {
                $log_message .= "\nData: " . print_r($data, true);
            }
            $log_message .= "\n" . str_repeat('-', 80) . "\n";
            error_log($log_message, 3, $this->debug_log);
        }
    }

    private function log_debug($message, $data = null) {
        if (!$this->debug_mode || !isset($this->debug_log)) {
            return;
        }
        
        if (function_exists('error_log')) {
            try {
                $timestamp = current_time('mysql');
                $log_message = "[{$timestamp}] {$message}";
                if ($data !== null) {
                    $log_message .= "\nData: " . print_r($data, true);
                }
                $log_message .= "\n" . str_repeat('-', 80) . "\n";
                error_log($log_message, 3, $this->debug_log);
            } catch (\Exception $e) {
                // Silently fail if logging fails
            }
        }
    }

    private function delete_webhook() {
        $this->log_debug('Deleting existing webhook');
        
        $response = wp_remote_post(
            "https://api.telegram.org/bot{$this->bot_token}/deleteWebhook",
            array('timeout' => 30)
        );

        if (is_wp_error($response)) {
            $this->log_debug('Error deleting webhook', array(
                'error' => $response->get_error_message()
            ));
        } else {
            $body = wp_remote_retrieve_body($response);
            $this->log_debug('Webhook deletion response', array(
                'response' => $body
            ));
        }
    }

    public function register_webhook() {
        try {
            if (empty($this->bot_token)) {
                $this->log_debug('Bot token is empty, skipping webhook registration');
                return;
            }

            $webhook_url = home_url('wc-api/telegram_stars_gateway');
            $this->log_debug('Starting webhook registration', array(
                'webhook_url' => $webhook_url,
                'is_admin' => is_admin(),
                'is_ajax' => defined('DOING_AJAX') && DOING_AJAX,
                'is_webhook' => $this->is_webhook_request(),
                'current_hook' => current_action()
            ));

            // First, delete any existing webhook
            $delete_response = wp_remote_post(
                "https://api.telegram.org/bot{$this->bot_token}/deleteWebhook",
                array('timeout' => 30)
            );

            if (is_wp_error($delete_response)) {
                $this->log_error('Error deleting existing webhook', array(
                    'error' => $delete_response->get_error_message()
                ));
            } else {
                $delete_body = wp_remote_retrieve_body($delete_response);
                $this->log_debug('Webhook deletion response', array(
                    'response' => $delete_body
                ));
            }

            // Get current webhook info before setting
            $current_webhook = $this->get_webhook_info();
            $this->log_debug('Current webhook info before setting', $current_webhook);

            // Set the webhook
            $set_response = wp_remote_post(
                "https://api.telegram.org/bot{$this->bot_token}/setWebhook",
                array(
                    'body' => array(
                        'url' => $webhook_url,
                        'allowed_updates' => json_encode(array('message', 'pre_checkout_query')),
                        'drop_pending_updates' => true
                    ),
                    'timeout' => 30
                )
            );

            if (is_wp_error($set_response)) {
                throw new \Exception('Error setting webhook: ' . $set_response->get_error_message());
            }

            $set_body = wp_remote_retrieve_body($set_response);
            $set_data = json_decode($set_body, true);

            $this->log_debug('Webhook set response', array(
                'response' => $set_body,
                'response_code' => wp_remote_retrieve_response_code($set_response)
            ));

            if (!isset($set_data['ok']) || !$set_data['ok']) {
                throw new \Exception('Telegram API error: ' . (isset($set_data['description']) ? $set_data['description'] : 'Unknown error'));
            }

            // Verify webhook was set correctly
            $new_webhook = $this->get_webhook_info();
            $this->log_debug('New webhook info after setting', $new_webhook);

            // Test the webhook by sending a test message
            $this->test_webhook();

        } catch (\Exception $e) {
            $this->log_error('Error registering webhook: ' . $e->getMessage());
            if (is_admin()) {
                add_action('admin_notices', function() use ($e) {
                    echo '<div class="error"><p>Telegram Stars Gateway Webhook Error: ' . esc_html($e->getMessage()) . '</p></div>';
                });
            }
        }
    }

    private function test_webhook() {
        try {
            // Get bot info to get the bot's username
            $bot_info_response = wp_remote_get(
                "https://api.telegram.org/bot{$this->bot_token}/getMe",
                array('timeout' => 30)
            );

            if (is_wp_error($bot_info_response)) {
                throw new \Exception('Error getting bot info: ' . $bot_info_response->get_error_message());
            }

            $bot_info = json_decode(wp_remote_retrieve_body($bot_info_response), true);
            
            if (!isset($bot_info['ok']) || !$bot_info['ok']) {
                throw new \Exception('Error getting bot info: ' . (isset($bot_info['description']) ? $bot_info['description'] : 'Unknown error'));
            }

            $this->log_debug('Bot info retrieved', $bot_info);

            // Send a test message to the bot's creator
            if (isset($bot_info['result']['id'])) {
                $test_message = sprintf(
                    'Webhook test message. Bot is active and webhook is set to: %s',
                    home_url('wc-api/telegram_stars_gateway')
                );

                $send_response = wp_remote_post(
                    "https://api.telegram.org/bot{$this->bot_token}/sendMessage",
                    array(
                        'body' => array(
                            'chat_id' => $bot_info['result']['id'],
                            'text' => $test_message,
                            'parse_mode' => 'HTML'
                        ),
                        'timeout' => 30
                    )
                );

                if (is_wp_error($send_response)) {
                    throw new \Exception('Error sending test message: ' . $send_response->get_error_message());
                }

                $send_body = wp_remote_retrieve_body($send_response);
                $this->log_debug('Test message sent', array(
                    'response' => $send_body,
                    'response_code' => wp_remote_retrieve_response_code($send_response)
                ));
            }
        } catch (\Exception $e) {
            $this->log_error('Error testing webhook: ' . $e->getMessage());
        }
    }

    public function handle_webhook() {
        try {
            $this->log_debug('Webhook endpoint hit', array(
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
                'headers' => getallheaders(),
                'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ));

            $input = file_get_contents('php://input');
            if (empty($input)) {
                throw new \Exception('Empty webhook data received');
            }

            $this->log_debug('Received webhook data', array(
                'input' => $input,
                'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'unknown'
            ));

            $update = json_decode($input, true);
            if (!$update) {
                throw new \Exception('Invalid webhook data received: ' . json_last_error_msg());
            }

            // Log the type of update we received
            $update_type = 'unknown';
            if (isset($update['message'])) {
                $update_type = 'message';
                // Check if the message contains a successful_payment
                if (isset($update['message']['successful_payment'])) {
                    $update_type = 'successful_payment';
                }
            }
            elseif (isset($update['pre_checkout_query'])) $update_type = 'pre_checkout_query';
            elseif (isset($update['callback_query'])) $update_type = 'callback_query';
            
            $this->log_debug('Processing update', array(
                'type' => $update_type,
                'update_id' => $update['update_id'] ?? 'unknown'
            ));

            // Handle different types of updates
            if (isset($update['message'])) {
                // Check for successful payment
                if (isset($update['message']['successful_payment'])) {
                    $this->log_debug('Handling successful payment', $update['message']['successful_payment']);
                    $this->handle_successful_payment($update['message']);
                } else {
                    $this->handle_message($update['message']);
                }
            } elseif (isset($update['pre_checkout_query'])) {
                $this->handle_pre_checkout_query($update['pre_checkout_query']);
            } elseif (isset($update['callback_query'])) {
                $this->handle_callback_query($update['callback_query']);
            }

            status_header(200);
            exit('OK');

        } catch (\Exception $e) {
            $this->log_error('Error handling webhook: ' . $e->getMessage(), array(
                'trace' => $e->getTraceAsString()
            ));
            status_header(500);
            exit('Error: ' . $e->getMessage());
        }
    }

    private function handle_message($message) {
        try {
            $this->log_debug('Processing message', $message);
            
            if (!isset($message['text']) || !isset($message['chat']['id'])) {
                $this->log_debug('Message missing text or chat_id', $message);
                return;
            }

            $chat_id = $message['chat']['id'];
            $text = $message['text'];
            
            $this->log_debug('Processing message text', array(
                'chat_id' => $chat_id,
                'text' => $text
            ));
            
            // Handle /start command
            if (strpos($text, '/start') === 0) {
                $this->log_debug('Processing start command', array(
                    'chat_id' => $chat_id,
                    'text' => $text
                ));
                
                // Extract parameter from start command
                $start_params = explode(' ', $text, 2); // Split only into 2 parts
                if (isset($start_params[1])) {
                    $this->log_debug('Start command with parameter', array(
                        'parameter' => $start_params[1]
                    ));
                    
                    // Check if parameter is just a number or has prefixes
                    $param = trim($start_params[1]);
                    $this->log_debug('Parameter analysis', array(
                        'parameter' => $param,
                        'is_numeric' => is_numeric($param)
                    ));
                    
                    // For a simple number, try to lookup by ID first
                    if (is_numeric($param)) {
                        $this->handle_payment_by_id($chat_id, intval($param));
                    } else {
                        $this->handle_payment_start($chat_id, $param);
                    }
                } else {
                    $this->log_debug('Start command without parameters, sending welcome message');
                    $this->send_welcome_message($chat_id);
                }
            } else {
                $this->log_debug('Non-start command received', array(
                    'text' => $text
                ));
            }
        } catch (\Exception $e) {
            $this->log_error('Error handling message: ' . $e->getMessage(), $message);
        }
    }

    /**
     * Look up payment by numeric database ID
     */
    private function handle_payment_by_id($chat_id, $id) {
        global $wpdb;
        
        $this->log_debug('Looking up payment by numeric ID', array(
            'id' => $id
        ));
        
        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wtsg_payments WHERE id = %d AND status = 'pending'",
            $id
        ));
        
        if (!$payment) {
            $this->log_debug('Payment not found by ID', array(
                'id' => $id
            ));
            $this->send_message($chat_id, __('Invalid or expired payment link.', 'woo-telegram-stars-gateway'));
            return;
        }
        
        $this->log_debug('Payment found by ID', array(
            'payment' => $payment
        ));
        
        $this->process_payment_found($chat_id, $payment);
    }

    private function handle_payment_start($chat_id, $payment_id) {
        global $wpdb;
        
        $this->log_debug('Starting payment process', array(
            'chat_id' => $chat_id,
            'payment_id' => $payment_id,
            'raw_payment_id' => $payment_id
        ));
        
        // Check the format of the payment ID
        $this->log_debug('Payment ID format check', array(
            'original' => $payment_id,
            'contains_payment_prefix' => strpos($payment_id, 'payment_') !== false,
            'is_numeric' => is_numeric($payment_id)
        ));
        
        // Remove 'payment_' prefix if present
        $payment_id = str_replace('payment_', '', $payment_id);
        
        $this->log_debug('Querying database for payment', array(
            'payment_id' => $payment_id,
            'table' => $wpdb->prefix . 'wtsg_payments'
        ));
        
        // Try different query approaches to find the payment
        // First, try exact match
        $query = $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wtsg_payments WHERE telegram_payment_id = %s AND status = 'pending'",
            $payment_id
        );
        $this->log_debug('SQL query (exact match)', array('query' => $query));
        $payment = $wpdb->get_row($query);
        
        // If no exact match, try with wtsg_ prefix
        if (!$payment && strpos($payment_id, 'wtsg_') === false) {
            $query = $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wtsg_payments WHERE telegram_payment_id = %s AND status = 'pending'",
                'wtsg_' . $payment_id
            );
            $this->log_debug('SQL query (with wtsg_ prefix)', array('query' => $query));
            $payment = $wpdb->get_row($query);
        }
        
        // List all pending payments to debug
        $all_pending = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}wtsg_payments WHERE status = 'pending'"
        );
        $this->log_debug('All pending payments', array(
            'count' => count($all_pending),
            'payments' => $all_pending
        ));
        
        if (!$payment) {
            $this->log_debug('Payment not found or not pending', array(
                'payment_id' => $payment_id,
                'all_pending_count' => count($all_pending)
            ));
            $this->send_message($chat_id, __('Invalid or expired payment link.', 'woo-telegram-stars-gateway'));
            return;
        }
        
        // Check if payment has expired (payment_timeout minutes)
        $created_time = strtotime($payment->created_at);
        $expiry_time = $created_time + ($this->payment_timeout * 60);
        $now = time();
        
        if ($now > $expiry_time) {
            $this->log_debug('Payment has expired', array(
                'payment_id' => $payment_id,
                'created_at' => $payment->created_at,
                'created_time' => $created_time,
                'expiry_time' => $expiry_time,
                'now' => $now,
                'timeout_minutes' => $this->payment_timeout
            ));
            
            // Update payment status to expired
            $wpdb->update(
                $wpdb->prefix . 'wtsg_payments',
                array('status' => 'expired', 'updated_at' => current_time('mysql')),
                array('id' => $payment->id),
                array('%s', '%s'),
                array('%d')
            );
            
            $this->send_message($chat_id, __('This payment link has expired. Please create a new order.', 'woo-telegram-stars-gateway'));
            return;
        }
        
        $this->process_payment_found($chat_id, $payment);
    }
    
    /**
     * Common method to process a found payment
     */
    private function process_payment_found($chat_id, $payment) {
        $this->log_debug('Payment found', array(
            'payment_data' => $payment
        ));

        $order = wc_get_order($payment->order_id);
        if (!$order) {
            $this->log_debug('Order not found', array(
                'order_id' => $payment->order_id
            ));
            $this->send_message($chat_id, __('Order is no longer available.', 'woo-telegram-stars-gateway'));
            return;
        }
        
        // Store chat_id with the order for later reference
        update_post_meta($order->get_id(), '_telegram_payment_chat_id', $chat_id);
        
        $this->log_debug('Order status', array(
            'order_id' => $payment->order_id,
            'status' => $order->get_status()
        ));
        
        if ($order->get_status() !== 'pending') {
            $this->log_debug('Order not pending', array(
                'order_id' => $payment->order_id,
                'status' => $order->get_status()
            ));
            $this->send_message($chat_id, __('Order is no longer available.', 'woo-telegram-stars-gateway'));
            return;
        }

        // Send payment notes and invoice in a single message
        $message = '';
        
        // Add payment notes if present
        if (!empty($this->payment_notes)) {
            $message .= $this->payment_notes . "\n\n";
        }
        
        // Add order details
        $message .= sprintf(
            __('Order #%s - %s', 'woo-telegram-stars-gateway'),
            $order->get_order_number(),
            get_bloginfo('name')
        );
        
        // Send the consolidated message
        $this->send_message($chat_id, $message);

        // Send invoice - this will create the payment button
        $this->send_invoice($chat_id, $order, $payment);
    }

    private function send_invoice($chat_id, $order, $payment) {
        $title = sprintf(
            __('Order #%s - %s', 'woo-telegram-stars-gateway'),
            $order->get_order_number(),
            get_bloginfo('name')
        );

        $description = sprintf(
            __('Payment for order #%s', 'woo-telegram-stars-gateway'),
            $order->get_order_number()
        );

        $payload = $payment->telegram_payment_id;
        
        // Telegram Stars uses XTR currency
        $currency = 'XTR';
        $prices = array(
            array(
                'label' => __('Total Amount', 'woo-telegram-stars-gateway'),
                // Don't multiply by 100 again - stars_amount is already in correct units
                'amount' => $payment->stars_amount
            )
        );

        $this->log_debug('Preparing invoice parameters', array(
            'order_id' => $order->get_id(),
            'payment_id' => $payment->telegram_payment_id,
            'currency' => $currency,
            'amount' => $payment->stars_amount,
            'chat_id' => $chat_id
        ));

        // Add payment deadline warning message
        $deadline_message = sprintf(
            __("‼️Important: You have only %d minutes to pay this invoice.\nIf you make the payment after this time, your payment amount will be lost.", 'woo-telegram-stars-gateway'),
            $this->payment_timeout
        );
        
        // Send the warning message
        $this->send_message($chat_id, $deadline_message);
        
        // Send the buy stars message
        $buy_stars_message = __("Buy Telegram Stars From @Premiumbot and get UP to %30 OFF Via Master/Visa Card", 'woo-telegram-stars-gateway');
        $this->send_message($chat_id, $buy_stars_message);
        
        // Custom button text with stars amount
        $button_text = sprintf(__('⭐ Pay %d ⭐', 'woo-telegram-stars-gateway'), $payment->stars_amount);

        // The issue is with start_parameter - this must be unique and match a specific format
        // For Telegram Stars, we shouldn't be providing this parameter at all
        $args = array(
            'chat_id' => $chat_id,
            'title' => $title,
            'description' => $description,
            'payload' => $payload,
            'provider_token' => '284685063:TEST:MTgzNmM5YjY3YzBj', // This is a dummy test provider token
            'currency' => $currency,
            'prices' => json_encode($prices),
            'photo_url' => null,
            'photo_size' => null,
            'photo_width' => null,
            'photo_height' => null,
            'need_name' => false,
            'need_phone_number' => false,
            'need_email' => false,
            'need_shipping_address' => false,
            'send_phone_number_to_provider' => false,
            'send_email_to_provider' => false,
            'is_flexible' => false,
            'disable_notification' => false,
            'protect_content' => false,
            'reply_to_message_id' => null,
            'allow_sending_without_reply' => true,
            'reply_markup' => json_encode(array(
                'inline_keyboard' => array(
                    array(
                        array(
                            'text' => $button_text,
                            'pay' => true
                        )
                    )
                )
            ))
        );

        $this->log_debug('Sending invoice to Telegram with args', array(
            'chat_id' => $chat_id,
            'currency' => $currency,
            'amount' => $payment->stars_amount,
            'args' => $args
        ));

        // First attempt without start_parameter
        $response = wp_remote_post(
            "https://api.telegram.org/bot{$this->bot_token}/sendInvoice",
            array(
                'body' => $args
            )
        );

        // Check response
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log_error(sprintf(
                'Invoice Error for Order #%s: %s',
                $order->get_order_number(),
                $error_message
            ));
            
            $this->send_message($chat_id, sprintf(
                __('Error creating payment invoice: %s', 'woo-telegram-stars-gateway'),
                $error_message
            ));
        } else {
            $body = wp_remote_retrieve_body($response);
            $response_data = json_decode($body, true);
            
            $this->log_debug('Invoice response from Telegram', array(
                'response_code' => wp_remote_retrieve_response_code($response),
                'response_body' => $body,
                'success' => isset($response_data['ok']) ? $response_data['ok'] : false
            ));
            
            if (!isset($response_data['ok']) || !$response_data['ok']) {
                $error_message = isset($response_data['description']) ? $response_data['description'] : 'Unknown error';
                $this->log_error('Failed to send invoice: ' . $error_message);
                
                // Try alternative formats if we get an error
                if (strpos($error_message, 'START_PARAM_INVALID') !== false) {
                    $this->log_debug('START_PARAM_INVALID error, trying alternative approach');
                    
                    // Send simple payment button instead
                    $this->send_payment_button($chat_id, $order, $payment);
                    return;
                }
                
                $this->send_message($chat_id, sprintf(
                    __('Error sending payment invoice: %s', 'woo-telegram-stars-gateway'),
                    $error_message
                ));
            }
        }
    }
    
    /**
     * Send a simple payment button as fallback when invoice fails
     */
    private function send_payment_button($chat_id, $order, $payment) {
        $this->log_debug('Sending payment button as fallback');
        
        // Create a deep link for direct Telegram Stars payment
        $deep_link = "tg://stars/payment_form?slug=pay_".urlencode($payment->id)."&amount=".urlencode($payment->stars_amount);
        
        // Custom button text with stars amount
        $button_text = sprintf(__('⭐ Pay %d ⭐', 'woo-telegram-stars-gateway'), $payment->stars_amount);
        
        $message = sprintf(
            __("🌟 **Payment for Order #%s**\n\nAmount: %d Stars\n\nPlease click the button below to pay:", 'woo-telegram-stars-gateway'),
            $order->get_order_number(),
            $payment->stars_amount
        );
        
        // Send the buy stars message separately
        $buy_stars_message = __("Buy Telegram Stars From @Premiumbot and get UP to %30 OFF Via Master/Visa Card", 'woo-telegram-stars-gateway');
        $this->send_message($chat_id, $buy_stars_message);
        
        $keyboard = array(
            'inline_keyboard' => array(
                array(
                    array(
                        'text' => $button_text,
                        'url' => $deep_link
                    )
                )
            )
        );
        
        $args = array(
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        );
        
        $this->log_debug('Sending message with payment button', $args);
        
        $response = wp_remote_post(
            "https://api.telegram.org/bot{$this->bot_token}/sendMessage",
            array(
                'body' => $args
            )
        );
        
        if (is_wp_error($response)) {
            $this->log_error('Error sending payment button: ' . $response->get_error_message());
            
            // Fallback to a simpler approach without deep linking
            $this->send_simple_instructions($chat_id, $order, $payment);
        } else {
            $body = wp_remote_retrieve_body($response);
            $response_data = json_decode($body, true);
            
            $this->log_debug('Payment button response', array(
                'response_code' => wp_remote_retrieve_response_code($response),
                'response_body' => $body,
                'success' => isset($response_data['ok']) ? $response_data['ok'] : false
            ));
            
            // If we couldn't send the button with deep link
            if (!isset($response_data['ok']) || !$response_data['ok']) {
                $this->send_simple_instructions($chat_id, $order, $payment);
            }
        }
    }
    
    /**
     * Send simple text instructions for manual payment
     */
    private function send_simple_instructions($chat_id, $order, $payment) {
        $message = sprintf(
            __("🌟 **Payment for Order #%s**\n\nAmount: %d Stars\n\nPlease send the stars to complete your payment. After sending, click the 'Complete Payment' button below.", 'woo-telegram-stars-gateway'),
            $order->get_order_number(),
            $payment->stars_amount
        );
        
        // Send the buy stars message separately
        $buy_stars_message = __("Buy Telegram Stars From @Premiumbot and get UP to %30 OFF Via Master/Visa Card", 'woo-telegram-stars-gateway');
        $this->send_message($chat_id, $buy_stars_message);
        
        $keyboard = array(
            'inline_keyboard' => array(
                array(
                    array(
                        'text' => __('Complete Payment', 'woo-telegram-stars-gateway'),
                        'callback_data' => 'complete_' . $payment->id
                    )
                )
            )
        );
        
        $args = array(
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        );
        
        $response = wp_remote_post(
            "https://api.telegram.org/bot{$this->bot_token}/sendMessage",
            array(
                'body' => $args
            )
        );
        
        if (is_wp_error($response)) {
            $this->log_error('Error sending simple instructions: ' . $response->get_error_message());
        }
    }

    private function handle_pre_checkout_query($query) {
        $payment_id = $query['invoice_payload'];
        $order = $this->get_order_by_payment_id($payment_id);

        if (!$order) {
            $this->answer_pre_checkout_query($query['id'], false, __('Order not found', 'woo-telegram-stars-gateway'));
            return;
        }

        if ($order->get_status() !== 'pending') {
            $this->answer_pre_checkout_query($query['id'], false, __('Order is no longer pending', 'woo-telegram-stars-gateway'));
            return;
        }

        $this->answer_pre_checkout_query($query['id'], true);
    }

    private function handle_successful_payment($message) {
        $payment_id = $message['successful_payment']['invoice_payload'];
        $order = $this->get_order_by_payment_id($payment_id);

        if (!$order) {
            return;
        }
        
        $chat_id = $message['chat']['id'];

        // Update order status
        $order->payment_complete();
        $order->add_order_note(__('Payment completed via Telegram Stars', 'woo-telegram-stars-gateway'));

        // Update payment record
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'wtsg_payments',
            array(
                'status' => 'completed',
                'updated_at' => current_time('mysql')
            ),
            array('telegram_payment_id' => $payment_id),
            array('%s', '%s'),
            array('%s')
        );

        // Send success message with payment details
        $payment_details = $message['successful_payment'];
        $amount = $payment_details['total_amount'] / 100; // Convert from cents back to whole units
        
        $success_message = sprintf(
            __("✅ Payment Complete!\n\nYour payment of %d Stars for Order #%s has been received and processed successfully.\n\nThank you for your purchase!", 'woo-telegram-stars-gateway'),
            $amount,
            $order->get_order_number()
        );
        
        $this->send_message($chat_id, $success_message);
        
        // Send order details to user
        $this->send_order_details($chat_id, $order);
    }

    private function send_welcome_message($chat_id) {
        $this->send_message($chat_id, $this->welcome_message);
    }

    private function send_message($chat_id, $text, $parse_mode = 'HTML') {
        $this->log_debug('Attempting to send message', array(
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => $parse_mode
        ));

        $response = wp_remote_post(
            "https://api.telegram.org/bot{$this->bot_token}/sendMessage",
            array(
                'body' => array(
                    'chat_id' => $chat_id,
                    'text' => $text,
                    'parse_mode' => $parse_mode
                ),
                'timeout' => 30
            )
        );

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log_debug('Error sending message', array(
                'error' => $error_message,
                'chat_id' => $chat_id,
                'response_code' => wp_remote_retrieve_response_code($response),
                'response_body' => wp_remote_retrieve_body($response)
            ));
            error_log(sprintf(
                'Telegram Stars Gateway - Message Error: %s for Chat ID %s',
                $error_message,
                $chat_id
            ));
        } else {
            $body = wp_remote_retrieve_body($response);
            $this->log_debug('Message sent successfully', array(
                'response' => $body,
                'response_code' => wp_remote_retrieve_response_code($response)
            ));
        }
        
        return $response;
    }

    private function get_webhook_info() {
        $this->log_debug('Getting webhook info');
        
        $response = wp_remote_get(
            "https://api.telegram.org/bot{$this->bot_token}/getWebhookInfo",
            array('timeout' => 30)
        );
        
        if (is_wp_error($response)) {
            $this->log_debug('Error getting webhook info', array(
                'error' => $response->get_error_message()
            ));
            return array();
        }

        $body = wp_remote_retrieve_body($response);
        $this->log_debug('Webhook info response', array(
            'response' => $body
        ));

        $data = json_decode($body, true);
        return isset($data['result']) ? $data['result'] : array();
    }

    private function get_order_by_payment_id($payment_id) {
        global $wpdb;
        $order_id = $wpdb->get_var($wpdb->prepare(
            "SELECT order_id FROM {$wpdb->prefix}wtsg_payments WHERE telegram_payment_id = %s",
            $payment_id
        ));

        return $order_id ? wc_get_order($order_id) : null;
    }

    private function answer_pre_checkout_query($query_id, $ok, $error_message = '') {
        $args = array(
            'pre_checkout_query_id' => $query_id,
            'ok' => $ok
        );

        if (!$ok) {
            $args['error_message'] = $error_message;
        }

        $response = wp_remote_post(
            "https://api.telegram.org/bot{$this->bot_token}/answerPreCheckoutQuery",
            array(
                'body' => $args
            )
        );

        if (is_wp_error($response)) {
            error_log(sprintf(
                'Telegram Stars Gateway - Pre-checkout Error: %s for Query ID %s',
                $response->get_error_message(),
                $query_id
            ));
        }
    }

    private function send_order_details($chat_id, $order) {
        $message = sprintf(
            __('Thank you for your payment! You can view your order details here: %s', 'woo-telegram-stars-gateway'),
            $order->get_view_order_url()
        );

        $this->send_message($chat_id, $message);
    }

    private function handle_callback_query($callback_query) {
        try {
            $this->log_debug('Processing callback query', $callback_query);
            
            if (!isset($callback_query['message']['chat']['id'])) {
                return;
            }

            $chat_id = $callback_query['message']['chat']['id'];
            $data = $callback_query['data'] ?? '';

            // Answer the callback query to remove the loading state
            $this->answer_callback_query($callback_query['id']);

            // Handle different callback data
            if (strpos($data, 'payment_') === 0) {
                $payment_id = substr($data, 8); // Remove 'payment_' prefix
                $this->handle_payment_start($chat_id, $payment_id);
            } elseif (strpos($data, 'pay_') === 0) {
                // Handle payment button click
                $payment_id = substr($data, 4); // Remove 'pay_' prefix
                $this->log_debug('Processing payment button click', array(
                    'payment_id' => $payment_id
                ));
                
                // Look up the payment
                $this->handle_payment_by_id($chat_id, intval($payment_id));
            } elseif (strpos($data, 'complete_') === 0) {
                // Handle payment completion confirmation
                $payment_id = substr($data, 9); // Remove 'complete_' prefix
                $this->log_debug('Processing payment completion', array(
                    'payment_id' => $payment_id
                ));
                
                $this->complete_manual_payment($chat_id, intval($payment_id));
            }
        } catch (\Exception $e) {
            $this->log_error('Error handling callback query: ' . $e->getMessage(), $callback_query);
        }
    }

    private function answer_callback_query($callback_query_id, $text = '') {
        try {
            $response = wp_remote_post(
                "https://api.telegram.org/bot{$this->bot_token}/answerCallbackQuery",
                array(
                    'body' => array(
                        'callback_query_id' => $callback_query_id,
                        'text' => $text
                    ),
                    'timeout' => 30
                )
            );

            if (is_wp_error($response)) {
                throw new \Exception($response->get_error_message());
            }

            $body = wp_remote_retrieve_body($response);
            $this->log_debug('Callback query answered', array(
                'response' => $body,
                'response_code' => wp_remote_retrieve_response_code($response)
            ));
        } catch (\Exception $e) {
            $this->log_error('Error answering callback query: ' . $e->getMessage());
        }
    }

    /**
     * Mark a payment as complete manually after user confirmation
     */
    private function complete_manual_payment($chat_id, $payment_id) {
        global $wpdb;
        
        $this->log_debug('Manual payment completion', array(
            'payment_id' => $payment_id
        ));
        
        // Get the payment record
        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wtsg_payments WHERE id = %d AND status = 'pending'",
            $payment_id
        ));
        
        if (!$payment) {
            $this->send_message($chat_id, __('Payment not found or already completed.', 'woo-telegram-stars-gateway'));
            return;
        }
        
        // Get the order
        $order = wc_get_order($payment->order_id);
        if (!$order) {
            $this->send_message($chat_id, __('Order not found.', 'woo-telegram-stars-gateway'));
            return;
        }
        
        // Update payment status in database
        $result = $wpdb->update(
            $wpdb->prefix . 'wtsg_payments',
            array(
                'status' => 'completed',
                'updated_at' => current_time('mysql')
            ),
            array('id' => $payment_id),
            array('%s', '%s'),
            array('%d')
        );
        
        if ($result === false) {
            $this->log_error('Error updating payment status', array(
                'payment_id' => $payment_id,
                'db_error' => $wpdb->last_error
            ));
            $this->send_message($chat_id, __('Error updating payment. Please contact customer support.', 'woo-telegram-stars-gateway'));
            return;
        }
        
        // Update order status
        $order->payment_complete();
        $order->add_order_note(__('Payment completed via Telegram Stars (manual confirmation)', 'woo-telegram-stars-gateway'));
        
        // Send confirmation
        $message = sprintf(
            __('Thank you! Your payment of %d Stars has been confirmed for Order #%s.', 'woo-telegram-stars-gateway'),
            $payment->stars_amount,
            $order->get_order_number()
        );
        
        $this->send_message($chat_id, $message);
        
        // Also send order details with view link
        $this->send_order_details($chat_id, $order);
    }
} 