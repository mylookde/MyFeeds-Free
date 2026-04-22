<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Network Handlers Class
 * Manages affiliate network integrations and credential handling
 */
class MyFeeds_Network_Handlers {
    
    private $supported_networks;
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'myfeeds_network_credentials';
        $this->init_supported_networks();
    }
    
    /**
     * Initialize supported networks configuration
     */
    private function init_supported_networks() {
        $this->supported_networks = [
            'awin' => [
                'name' => 'AWIN',
                'fields' => [
                    'api_token' => 'API Token (OAuth2 Bearer Token)',
                    'publisher_id' => 'Publisher ID (optional - will be auto-discovered)'
                ],
                'api_base_url' => 'https://api.awin.com',
                'requires_advertiser_id' => true,
                'feed_format' => 'json_lines', // Enhanced Google Feed Format
                'description' => 'Uses modern AWIN OAuth2 API with Enhanced Google Feeds'
            ],
            'tradedoubler' => [
                'name' => 'TradeDoubler',
                'fields' => [
                    'token' => 'API Token',
                    'organization_id' => 'Organization ID'
                ],
                'feed_url_template' => 'http://export.tradedoubler.com/v1_0/ProductData?token={token}&format=CSV&fid={program_id}',
                'link_template' => 'https://clkuk.tradedoubler.com/click?p={program_id}&a={organization_id}&g=0&url={affiliate_link}',
                'requires_program_id' => true
            ]
        ];
    }
    
    /**
     * Get supported networks
     */
    public function get_supported_networks() {
        return $this->supported_networks;
    }
    
    /**
     * Save network credentials
     */
    public function save_credentials($network, $credentials) {
        global $wpdb;
        
        if (!isset($this->supported_networks[$network])) {
            return new WP_Error('invalid_network', 'Network not supported');
        }
        
        // Get existing credentials to merge with new ones
        $existing_credentials = $this->get_credentials($network);
        $final_credentials = [];
        
        // Validate required fields and merge with existing
        $required_fields = array_keys($this->supported_networks[$network]['fields']);
        foreach ($required_fields as $field) {
            if (!empty($credentials[$field])) {
                // New value provided
                $final_credentials[$field] = $credentials[$field];
            } elseif ($existing_credentials && isset($existing_credentials[$field])) {
                // Keep existing value
                $final_credentials[$field] = $existing_credentials[$field];
            } else {
                // No existing value and no new value
                return new WP_Error('missing_field', "Field '$field' is required");
            }
        }
        
        // Encrypt sensitive data
        $encrypted_credentials = $this->encrypt_credentials($final_credentials);
        
        $result = $wpdb->replace(
            $this->table_name,
            [
                'network_name' => $network,
                'credentials' => json_encode($encrypted_credentials),
                'status' => 'active'
            ],
            ['%s', '%s', '%s']
        );
        
        if ($result === false) {
            return new WP_Error('save_failed', 'Failed to save credentials');
        }
        
        MyFeeds_Affiliate_Product_Picker::log("Credentials updated for network: $network");
        return true;
    }
    
    /**
     * Get network credentials
     */
    public function get_credentials($network) {
        global $wpdb;
        
        $result = $wpdb->get_row(
            $wpdb->prepare("SELECT credentials FROM {$this->table_name} WHERE network_name = %s AND status = 'active'", $network),
            ARRAY_A
        );
        
        if (!$result) {
            return false;
        }
        
        $encrypted_credentials = json_decode($result['credentials'], true);
        return $this->decrypt_credentials($encrypted_credentials);
    }
    
    /**
     * Get publisher ID for AWIN via API
     */
    private function get_awin_publisher_id($api_token) {
        $response = wp_remote_get('https://api.awin.com/accounts?type=publisher', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_token,
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . ' MyFeeds Plugin/' . MYFEEDS_VERSION
            ]
        ]);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (is_array($data) && !empty($data)) {
            // Return first publisher account ID
            return $data[0]['accountId'] ?? false;
        }
        
        return false;
    }
    
    /**
     * Build feed URL for network with advertiser/program ID
     */
    public function build_feed_url($network, $advertiser_id, $credentials = null) {
        if (!isset($this->supported_networks[$network])) {
            return false;
        }
        
        if (!$credentials) {
            $credentials = $this->get_credentials($network);
        }
        
        if (!$credentials) {
            return false;
        }
        
        if ($network === 'awin') {
            // Modern AWIN API
            $publisher_id = $credentials['publisher_id'];
            
            // Auto-discover publisher ID if not provided
            if (empty($publisher_id)) {
                $publisher_id = $this->get_awin_publisher_id($credentials['api_token']);
                if (!$publisher_id) {
                    return false;
                }
                
                // Update credentials with discovered publisher ID
                $credentials['publisher_id'] = $publisher_id;
                $this->save_credentials($network, $credentials);
            }
            
            // Enhanced/Google Feed URL
            $locale = 'de_DE'; // Default, could be made configurable
            return "https://api.awin.com/publishers/{$publisher_id}/awinfeeds/download/{$advertiser_id}-retail-{$locale}";
            
        } elseif ($network === 'tradedoubler') {
            // TradeDoubler remains the same
            $template = $this->supported_networks[$network]['feed_url_template'];
            $url_params = $credentials;
            $url_params['program_id'] = $advertiser_id;
            
            foreach ($url_params as $key => $value) {
                $template = str_replace('{' . $key . '}', urlencode($value), $template);
            }
            
            return $template;
        }
        
        return false;
    }
    
    /**
     * Generate affiliate link with advertiser/program ID
     */
    public function generate_affiliate_link($network, $original_link, $advertiser_id, $credentials = null) {
        if (!$credentials) {
            $credentials = $this->get_credentials($network);
        }
        
        if (!$credentials) {
            return $original_link;
        }
        
        if ($network === 'awin') {
            // Use AWIN Link Builder API
            return $this->generate_awin_affiliate_link($original_link, $advertiser_id, $credentials);
            
        } elseif ($network === 'tradedoubler') {
            // TradeDoubler traditional method
            if (empty($this->supported_networks[$network]['link_template'])) {
                return $original_link;
            }
            
            $template = $this->supported_networks[$network]['link_template'];
            $link_params = $credentials;
            $link_params['program_id'] = $advertiser_id;
            
            foreach ($link_params as $key => $value) {
                $template = str_replace('{' . $key . '}', urlencode($value), $template);
            }
            
            return str_replace('{affiliate_link}', urlencode($original_link), $template);
        }
        
        return $original_link;
    }
    
    /**
     * Generate AWIN affiliate link using Link Builder API
     */
    private function generate_awin_affiliate_link($destination_url, $advertiser_id, $credentials) {
        $publisher_id = $credentials['publisher_id'];
        $api_token = $credentials['api_token'];
        
        if (empty($publisher_id)) {
            $publisher_id = $this->get_awin_publisher_id($api_token);
            if (!$publisher_id) {
                return $destination_url; // Fallback
            }
        }
        
        $request_body = [
            'advertiserId' => intval($advertiser_id),
            'destinationUrl' => $destination_url,
            'parameters' => [
                'clickref' => 'wp_plugin_' . uniqid()
            ]
        ];
        
        $response = wp_remote_post("https://api.awin.com/publishers/{$publisher_id}/linkbuilder/generate", [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_token,
                'Content-Type' => 'application/json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . ' MyFeeds Plugin/' . MYFEEDS_VERSION
            ],
            'body' => json_encode($request_body)
        ]);
        
        if (is_wp_error($response)) {
            return $destination_url; // Fallback
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['url'])) {
            return $data['url'];
        }
        
        return $destination_url; // Fallback
    }
    
    /**
     * Test network connection
     */
    public function test_connection($network, $advertiser_id, $credentials) {
        if ($network === 'awin') {
            return $this->test_awin_connection($credentials, $advertiser_id);
        } elseif ($network === 'tradedoubler') {
            return $this->test_tradedoubler_connection($credentials, $advertiser_id);
        }
        
        return new WP_Error('unsupported_network', 'Network not supported');
    }
    
    /**
     * Test AWIN OAuth2 API connection
     */
    private function test_awin_connection($credentials, $advertiser_id = null) {
        $api_token = $credentials['api_token'];
        
        // First test: Get publisher accounts
        $response = wp_remote_get('https://api.awin.com/accounts?type=publisher', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_token,
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . ' MyFeeds Plugin/' . MYFEEDS_VERSION
            ]
        ]);
        
        if (is_wp_error($response)) {
            return new WP_Error('connection_failed', 'API request failed: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 401) {
            return new WP_Error('auth_failed', 'Invalid API token - please check your OAuth2 Bearer token');
        }
        
        if ($response_code !== 200) {
            return new WP_Error('connection_failed', "HTTP Error: $response_code");
        }
        
        $body = wp_remote_retrieve_body($response);
        $accounts = json_decode($body, true);
        
        if (!is_array($accounts) || empty($accounts)) {
            return new WP_Error('no_accounts', 'No publisher accounts found');
        }
        
        $publisher_id = $accounts[0]['accountId'];
        
        // Second test: Get programmes (joined advertisers)
        $response = wp_remote_get("https://api.awin.com/publishers/{$publisher_id}/programmes?relationship=joined", [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_token,
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . ' MyFeeds Plugin/' . MYFEEDS_VERSION
            ]
        ]);
        
        if (is_wp_error($response)) {
            return new WP_Error('programmes_failed', 'Failed to fetch programmes: ' . $response->get_error_message());
        }
        
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return new WP_Error('programmes_failed', 'Failed to fetch programmes');
        }
        
        return [
            'status' => 'success',
            'message' => 'AWIN OAuth2 API connection successful',
            'publisher_id' => $publisher_id,
            'programmes_count' => count(json_decode(wp_remote_retrieve_body($response), true))
        ];
    }
    
    /**
     * Test TradeDoubler connection
     */
    private function test_tradedoubler_connection($credentials, $program_id) {
        $feed_url = $this->build_feed_url('tradedoubler', $program_id, $credentials);
        
        if (!$feed_url) {
            return new WP_Error('invalid_url', 'Could not build feed URL');
        }
        
        $response = wp_remote_get($feed_url, [
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . ' MyFeeds Plugin/' . MYFEEDS_VERSION
            ]
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            return new WP_Error('connection_failed', "HTTP Error: $response_code");
        }
        
        $body = wp_remote_retrieve_body($response);
        
        // Handle gzipped content
        if (substr($body, 0, 2) === "\x1f\x8b") {
            $decoded = @gzdecode($body);
            if ($decoded !== false) {
                $body = $decoded;
            }
        }
        
        // Basic validation - check if we got CSV data
        $lines = explode("\n", trim($body), 3);
        if (count($lines) < 2) {
            return new WP_Error('invalid_data', 'Feed does not contain valid CSV data');
        }
        
        return [
            'status' => 'success',
            'message' => 'TradeDoubler connection successful',
            'sample_data' => $lines[0] // Return header for field mapping
        ];
    }
    
    /**
     * Encrypt credentials for storage
     */
    private function encrypt_credentials($credentials) {
        $encrypted = [];
        
        foreach ($credentials as $key => $value) {
            // Simple encryption - in production, use proper encryption
            $encrypted[$key] = base64_encode($value);
        }
        
        return $encrypted;
    }
    
    /**
     * Decrypt credentials
     */
    private function decrypt_credentials($encrypted_credentials) {
        $decrypted = [];
        
        foreach ($encrypted_credentials as $key => $value) {
            // Simple decryption - in production, use proper decryption
            $decrypted[$key] = base64_decode($value);
        }
        
        return $decrypted;
    }
    
    /**
     * Delete network credentials
     */
    public function delete_credentials($network) {
        global $wpdb;
        
        $result = $wpdb->delete(
            $this->table_name,
            ['network_name' => $network],
            ['%s']
        );
        
        MyFeeds_Affiliate_Product_Picker::log("Credentials deleted for network: $network");
        return $result !== false;
    }
    
    /**
     * Get all configured networks
     */
    public function get_configured_networks() {
        global $wpdb;
        
        $results = $wpdb->get_results(
            "SELECT network_name, status FROM {$this->table_name} WHERE status = 'active'",
            ARRAY_A
        );
        
        return $results ?: [];
    }
    
    /**
     * Validate network configuration
     */
    public function validate_network_config($network) {
        if (!isset($this->supported_networks[$network])) {
            return false;
        }
        
        $credentials = $this->get_credentials($network);
        if (!$credentials) {
            return false;
        }
        
        $required_fields = array_keys($this->supported_networks[$network]['fields']);
        foreach ($required_fields as $field) {
            if (empty($credentials[$field])) {
                return false;
            }
        }
        
        return true;
    }
}