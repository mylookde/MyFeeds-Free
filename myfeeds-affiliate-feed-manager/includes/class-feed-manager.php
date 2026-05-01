<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Feed Manager Class
 * Manages product feeds from affiliate networks with enhanced gender-aware search
 * 
 * Version: 3.0 - Clean rebuild with PHP 5.6+ compatibility
 * Date: June 2025
 */
class MyFeeds_Feed_Manager {
    
    const OPTION_KEY = 'myfeeds_feeds';
    const INDEX_FILE = 'myfeeds-feed-index.json';
    
    private $smart_mapper;
    
    public function __construct($smart_mapper) {
        $this->smart_mapper = $smart_mapper;
    }
    
    public function init() {
        // Admin hooks
        add_action('admin_menu', array($this, 'register_admin_pages'));
        add_action('admin_post_myfeeds_save_feed', array($this, 'handle_save_feed'));
        add_action('admin_post_myfeeds_delete_feed', array($this, 'handle_delete_feed'));
        add_action('admin_post_myfeeds_test_feed', array($this, 'handle_test_feed'));
        add_action('admin_post_myfeeds_rebuild_index', array($this, 'handle_rebuild_index'));
        add_action('admin_post_myfeeds_regenerate_mappings', array($this, 'handle_regenerate_mappings'));
        add_action('wp_ajax_myfeeds_rebuild_index', array($this, 'ajax_rebuild_index'));
        add_action('wp_ajax_myfeeds_get_mapping_quality', array($this, 'ajax_get_mapping_quality'));
        add_action('wp_ajax_myfeeds_save_feed_ajax', array($this, 'handle_save_feed_ajax'));
        add_action('wp_ajax_myfeeds_get_feed_status', array($this, 'ajax_get_feed_status'));
        add_action('wp_ajax_myfeeds_reimport_feed', array($this, 'ajax_reimport_feed'));
        add_action('wp_ajax_myfeeds_delete_feed', array($this, 'ajax_delete_feed'));
        add_action('wp_ajax_myfeeds_get_header_stats', array($this, 'ajax_get_header_stats'));
        
        // REST API
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        
        // REMOVED: Daily cron was overwriting correct product counts with partial-download values.
        // Quick Sync (active products) and Weekly Full Import (all products) handle updates correctly
        // via Action Scheduler with full streaming downloads.
        // add_action('myfeeds_daily_feed_index', array($this, 'rebuild_feed_index'));
        
        // Admin styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'myfeeds') === false) {
            return;
        }

        // Shared admin bootstrap on every myfeeds screen
        wp_enqueue_style('myfeeds-admin', MYFEEDS_PLUGIN_URL . 'assets/admin.css', array(), MYFEEDS_VERSION);
        wp_enqueue_script('myfeeds-admin', MYFEEDS_PLUGIN_URL . 'assets/admin.js', array('jquery'), MYFEEDS_VERSION, true);
        wp_localize_script('myfeeds-admin', 'myfeedsAdmin', array(
            'nonce'   => wp_create_nonce('myfeeds_nonce'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
        ));

        // Screen-specific assets for the main feeds page (formerly inline)
        if ($hook === 'toplevel_page_myfeeds-feeds') {
            wp_enqueue_style(
                'myfeeds-feed-manager',
                MYFEEDS_PLUGIN_URL . 'assets/feed-manager.css',
                array('myfeeds-admin'),
                MYFEEDS_VERSION
            );
            wp_enqueue_script(
                'myfeeds-feed-manager',
                MYFEEDS_PLUGIN_URL . 'assets/feed-manager.js',
                array('jquery', 'myfeeds-admin'),
                MYFEEDS_VERSION,
                true
            );

            global $wp_locale;
            $thousands_sep = $wp_locale ? $wp_locale->number_format['thousands_sep'] : ',';

            wp_localize_script('myfeeds-feed-manager', 'myfeedsFeeds', array(
                'feedsPageUrl' => admin_url('admin.php?page=myfeeds-feeds'),
                'thousandsSep' => $thousands_sep,
                'i18n'         => array(
                    'addNewFeed'          => __('Add New Feed', 'myfeeds-affiliate-feed-manager'),
                    'createFeed'          => __('Create Feed', 'myfeeds-affiliate-feed-manager'),
                    'editFeed'            => __('Edit Feed:', 'myfeeds-affiliate-feed-manager'),
                    'saveChanges'         => __('Save Changes', 'myfeeds-affiliate-feed-manager'),
                    'feedNameUrlRequired' => __('Feed name and URL are required.', 'myfeeds-affiliate-feed-manager'),
                    'savingChanges'       => __('Saving changes...', 'myfeeds-affiliate-feed-manager'),
                    'addingFeed'          => __('Adding feed...', 'myfeeds-affiliate-feed-manager'),
                    'unknownError'        => __('An unknown error occurred.', 'myfeeds-affiliate-feed-manager'),
                    'serverError'         => __('Server error. Please try again.', 'myfeeds-affiliate-feed-manager'),
                ),
            ));
        }
    }
    
    public function register_admin_pages() {
        // Add error logging to debug
        myfeeds_log('MyFeeds Plugin: Registering admin pages...', 'debug');
        
        add_menu_page(
            'MyFeeds',              // Page title
            'MyFeeds',              // Menu title
            'manage_options',
            'myfeeds-feeds',
            array($this, 'render_feeds_page'),
            'dashicons-rss',
            60
        );
        
        myfeeds_log('MyFeeds Plugin: Admin pages registered successfully.', 'info');
    }
    
    /**
     * Render main feeds page with universal approach
     */
    public function render_feeds_page() {
        $feeds = get_option(self::OPTION_KEY, array());

        // Free is a single-feed plugin. Option storage may still contain
        // leftovers from a previous multi-feed install; we keep that data
        // intact but never expose more than one feed in the UI.
        if (count($feeds) > 1) {
            $first_key = array_key_first($feeds);
            $feeds = array($first_key => $feeds[$first_key]);
        }

        // Handle messages
        $this->display_admin_messages();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('MyFeeds – Affiliate Feed Manager', 'myfeeds-affiliate-feed-manager'); ?></h1>
            
            <div class="myfeeds-intro-banner">
                <h2><?php esc_html_e('Affiliate Feed Manager', 'myfeeds-affiliate-feed-manager'); ?></h2>
                <p><?php esc_html_e('Import and manage affiliate product feeds from any network. Our smart mapping system automatically detects and maps all product fields.', 'myfeeds-affiliate-feed-manager'); ?></p>
                
                <div class="myfeeds-stats-row">
                    <div class="myfeeds-stat-item">
                        <span class="myfeeds-stat-number" id="myfeeds-active-feeds"><?php echo count($feeds); ?></span>
                        <span class="myfeeds-stat-label">Active Feeds</span>
                    </div>
                    <div class="myfeeds-stat-item">
                        <span class="myfeeds-stat-number" data-testid="total-products" id="myfeeds-total-products"><?php
                            $total_products = 0;
                            if (class_exists('MyFeeds_DB_Manager') && MyFeeds_DB_Manager::table_exists()) {
                                $total_products = MyFeeds_DB_Manager::get_active_product_count();
                            }
                            echo esc_html(number_format_i18n($total_products));
                        ?></span>
                        <span class="myfeeds-stat-label">Active Products</span>
                    </div>
                    <div class="myfeeds-stat-item">
                        <span class="myfeeds-stat-number" data-testid="avg-mapping-quality" id="myfeeds-avg-quality"><?php 
                            $avg_confidence = count($feeds) > 0 ? array_sum(array_column($feeds, 'mapping_confidence')) / count($feeds) : 0;
                            echo esc_html(round($avg_confidence));
                        ?>%</span>
                        <span class="myfeeds-stat-label">Avg. Mapping Quality</span>
                    </div>
                    <div class="myfeeds-stat-item">
                        <span class="myfeeds-stat-number" id="myfeeds-plan-badge" data-testid="plan-badge">
                            <?php esc_html_e('Free', 'myfeeds-affiliate-feed-manager'); ?>
                        </span>
                        <span class="myfeeds-stat-label">Plan</span>
                    </div>
                </div>
            </div>
            
            <?php $this->render_feeds_table($feeds); ?>
            
            <div class="myfeeds-actions-section">
                <h3><?php esc_html_e('Feed Management', 'myfeeds-affiliate-feed-manager'); ?></h3>
                
                <!-- Import Status Panel - Enhanced -->
                <div id="myfeeds-import-status" style="display: none; margin-bottom: 20px;">
                    <div class="myfeeds-import-panel" id="myfeeds-import-panel">
                        <h4 id="myfeeds-status-title"><?php esc_html_e('Processing...', 'myfeeds-affiliate-feed-manager'); ?></h4>
                        <div class="myfeeds-progress-bar">
                            <div class="myfeeds-progress-fill" id="myfeeds-progress-fill" style="width: 0%"></div>
                        </div>
                        <p class="myfeeds-import-info">
                            <span id="myfeeds-import-phase"></span>
                            <span id="myfeeds-import-feed"></span>
                        </p>
                        <p class="myfeeds-import-stats">
                            <span id="myfeeds-import-percent">0%</span> • 
                            <span id="myfeeds-import-products">0</span> <?php esc_html_e('products', 'myfeeds-affiliate-feed-manager'); ?> • 
                            <span id="myfeeds-import-feeds">0/0</span> <?php esc_html_e('feeds', 'myfeeds-affiliate-feed-manager'); ?>
                        </p>
                        <div id="myfeeds-buttons-row">
                            <button type="button" id="myfeeds-cancel-import" class="button">
                                <?php esc_html_e('Cancel', 'myfeeds-affiliate-feed-manager'); ?>
                            </button>
                        </div>
                        <!-- Success message (hidden) -->
                        <div id="myfeeds-success-message" class="myfeeds-success-message" style="display: none;">
                            <span>✅ <?php esc_html_e('Update completed successfully!', 'myfeeds-affiliate-feed-manager'); ?></span>
                            <button type="button" class="close-btn" id="myfeeds-close-success"><?php esc_html_e('Close', 'myfeeds-affiliate-feed-manager'); ?></button>
                        </div>
                    </div>
                </div>
                
                <!-- BUTTONS ROW - Harmonized Layout -->
                <div class="myfeeds-buttons-row">
                    <!-- UNIFIED REBUILD BUTTON - Primary Action -->
                    <div class="myfeeds-action-card myfeeds-primary-action">
                        <button type="button" id="myfeeds-unified-rebuild" class="button button-primary myfeeds-action-btn">
                            🔄 <?php esc_html_e('Update All Feeds', 'myfeeds-affiliate-feed-manager'); ?>
                        </button>
                        <p class="description">
                            <?php esc_html_e('Loads all products (active first)', 'myfeeds-affiliate-feed-manager'); ?>
                        </p>
                    </div>
                    
                    <!-- QUICK SYNC BUTTON - Active Products Only -->
                    <div class="myfeeds-action-card myfeeds-secondary-action">
                        <button type="button" id="myfeeds-quick-sync" class="button button-secondary myfeeds-action-btn">
                            ⚡ <?php esc_html_e('Quick Sync (Active Only)', 'myfeeds-affiliate-feed-manager'); ?>
                        </button>
                        <p class="description">
                            <?php esc_html_e('Only products in posts/pages', 'myfeeds-affiliate-feed-manager'); ?>
                        </p>
                    </div>
                </div>
                
                <p class="myfeeds-info-note" style="margin-top: 15px; color: #666; font-size: 12px;">
                    <?php esc_html_e('Updates run in the background. You can navigate away and return later.', 'myfeeds-affiliate-feed-manager'); ?>
                </p>
                
                <!-- Auto-Sync Schedule Info -->
                <?php $this->render_auto_sync_info_compact(); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render feed form as a modal (used for both Add and Edit)
     */
    private function render_feed_modal() {
        ?>
        <!-- Feed Add/Edit Modal -->
        <div id="myfeeds-feed-modal" class="myfeeds-modal-overlay" style="display:none;" data-testid="feed-modal">
            <div class="myfeeds-modal-content myfeeds-feed-modal-content">
                <div class="myfeeds-modal-header">
                    <h3 id="myfeeds-feed-modal-title" data-testid="feed-modal-title"><?php esc_html_e('Add New Feed', 'myfeeds-affiliate-feed-manager'); ?></h3>
                    <button type="button" class="myfeeds-modal-close" id="myfeeds-feed-modal-close" data-testid="feed-modal-close">&times;</button>
                </div>
                <div class="myfeeds-modal-body">
                    <div class="myfeeds-help-text">
                        <p><?php esc_html_e('This plugin supports product feeds from all major affiliate networks. Simply provide your feed name and URL – the smart mapping system will automatically detect and map all product fields!', 'myfeeds-affiliate-feed-manager'); ?></p>
                        <p class="description"><?php esc_html_e('Supported networks: AWIN, Webgains, Admitad, TradeDoubler, Impact.com, Partnerize, Rakuten, CJ, ShareASale, and more.', 'myfeeds-affiliate-feed-manager'); ?></p>
                        <p><strong><?php esc_html_e('Supported formats:', 'myfeeds-affiliate-feed-manager'); ?></strong> CSV, TSV, CSV (semicolon), CSV (gzip), XML, JSON, JSON-Lines</p>
                    </div>
                    
                    <form id="myfeeds-feed-form" method="post">
                        <?php wp_nonce_field('myfeeds_save_feed'); ?>
                        <input type="hidden" name="feed_key" id="myfeeds-feed-key" value="">
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="feed_name"><?php esc_html_e('Feed Name', 'myfeeds-affiliate-feed-manager'); ?> *</label>
                                </th>
                                <td>
                                    <input name="feed_name" type="text" id="feed_name" class="regular-text" 
                                           value="" required data-testid="feed-name-input">
                                    <p class="description"><?php esc_html_e('A descriptive name for this feed (e.g., "Fashion Products", "Electronics")', 'myfeeds-affiliate-feed-manager'); ?></p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="feed_url"><?php esc_html_e('Feed URL', 'myfeeds-affiliate-feed-manager'); ?> *</label>
                                </th>
                                <td>
                                    <input name="feed_url" type="url" id="feed_url" class="large-text" 
                                           value="" required data-testid="feed-url-input">
                                    <p class="description">
                                        <?php esc_html_e('The direct URL to your affiliate product feed.', 'myfeeds-affiliate-feed-manager'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                        
                        <div class="myfeeds-advanced-options" style="margin-top: 20px;">
                            <h3><?php esc_html_e('Advanced Options', 'myfeeds-affiliate-feed-manager'); ?></h3>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="feed_format_hint"><?php esc_html_e('Feed Format Hint', 'myfeeds-affiliate-feed-manager'); ?></label>
                                    </th>
                                    <td>
                                        <select name="feed_format_hint" id="feed_format_hint" data-testid="feed-format-hint">
                                            <option value=""><?php esc_html_e('Auto-detect', 'myfeeds-affiliate-feed-manager'); ?></option>
                                            <option value="csv"><?php esc_html_e('CSV (Comma-separated)', 'myfeeds-affiliate-feed-manager'); ?></option>
                                            <option value="tsv"><?php esc_html_e('TSV (Tab-separated)', 'myfeeds-affiliate-feed-manager'); ?></option>
                                            <option value="ssv"><?php esc_html_e('CSV (Semicolon-separated)', 'myfeeds-affiliate-feed-manager'); ?></option>
                                            <option value="csv_gz"><?php esc_html_e('CSV (gzip compressed)', 'myfeeds-affiliate-feed-manager'); ?></option>
                                            <option value="xml"><?php esc_html_e('XML', 'myfeeds-affiliate-feed-manager'); ?></option>
                                            <option value="json"><?php esc_html_e('JSON', 'myfeeds-affiliate-feed-manager'); ?></option>
                                            <option value="json_lines"><?php esc_html_e('JSON-Lines', 'myfeeds-affiliate-feed-manager'); ?></option>
                                        </select>
                                        <p class="description"><?php esc_html_e('Leave as auto-detect unless you experience issues', 'myfeeds-affiliate-feed-manager'); ?></p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row">
                                        <label for="feed_network_hint"><?php esc_html_e('Network Hint', 'myfeeds-affiliate-feed-manager'); ?></label>
                                    </th>
                                    <td>
                                        <select name="feed_network_hint" id="feed_network_hint" data-testid="feed-network-hint">
                                            <option value=""><?php esc_html_e('Auto-detect', 'myfeeds-affiliate-feed-manager'); ?></option>
                                            <option value="awin"><?php esc_html_e('AWIN', 'myfeeds-affiliate-feed-manager'); ?></option>
                                            <option value="webgains"><?php esc_html_e('Webgains', 'myfeeds-affiliate-feed-manager'); ?></option>
                                            <option value="admitad"><?php esc_html_e('Admitad', 'myfeeds-affiliate-feed-manager'); ?></option>
                                            <option value="tradedoubler"><?php esc_html_e('TradeDoubler', 'myfeeds-affiliate-feed-manager'); ?></option>
                                            <option value="commissionjunction"><?php esc_html_e('Commission Junction', 'myfeeds-affiliate-feed-manager'); ?></option>
                                            <option value="shareasale"><?php esc_html_e('ShareASale', 'myfeeds-affiliate-feed-manager'); ?></option>
                                            <option value="impact"><?php esc_html_e('Impact.com', 'myfeeds-affiliate-feed-manager'); ?></option>
                                            <option value="partnerize"><?php esc_html_e('Partnerize', 'myfeeds-affiliate-feed-manager'); ?></option>
                                            <option value="rakuten"><?php esc_html_e('Rakuten Advertising', 'myfeeds-affiliate-feed-manager'); ?></option>
                                            <option value="ebay"><?php esc_html_e('eBay Partner Network', 'myfeeds-affiliate-feed-manager'); ?></option>
                                            <option value="amazon"><?php esc_html_e('Amazon Associates', 'myfeeds-affiliate-feed-manager'); ?></option>
                                        </select>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        
                        <p class="submit">
                            <input type="submit" id="myfeeds-feed-submit" class="button button-primary" 
                                   value="<?php esc_attr_e('Create Feed', 'myfeeds-affiliate-feed-manager'); ?>" data-testid="feed-submit-btn">
                        </p>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render compact auto-sync info (displayed next to buttons - Fix 7)
     */
    private function render_auto_sync_info_compact() {
        $timezone = wp_timezone();
        $last_sync = get_option('myfeeds_last_auto_sync', null);
        $next_daily = wp_next_scheduled('myfeeds_daily_feed_index');
        $next_weekly = wp_next_scheduled('myfeeds_weekly_full_import');
        
        ?>
        <div class="myfeeds-auto-sync-compact" id="myfeeds-auto-sync-info" style="font-size: 12px; color: #666; line-height: 1.8; white-space: nowrap;">
            <div><strong><?php esc_html_e('Last sync:', 'myfeeds-affiliate-feed-manager'); ?></strong> 
                <span id="myfeeds-last-sync-text"><?php
                if ($last_sync && !empty($last_sync['time'])) {
                    echo esc_html($last_sync['time'] . ' (' . self::get_sync_type_label($last_sync) . ')');
                } else {
                    echo '<span style="color: #999;">' . esc_html__('None yet', 'myfeeds-affiliate-feed-manager') . '</span>';
                }
                ?></span>
            </div>
            <div><strong><?php esc_html_e('Next Quick Sync:', 'myfeeds-affiliate-feed-manager'); ?></strong> 
                <?php
                if ($next_daily) {
                    $dt = new \DateTime('@' . $next_daily);
                    $dt->setTimezone($timezone);
                    echo esc_html($dt->format('d.m.Y H:i'));
                } else {
                    echo '<span style="color: #cc0000;">' . esc_html__('Not scheduled', 'myfeeds-affiliate-feed-manager') . '</span>';
                }
                ?>
            </div>
            <div><strong><?php esc_html_e('Next Full Import:', 'myfeeds-affiliate-feed-manager'); ?></strong> 
                <?php
                if ($next_weekly) {
                    $dt = new \DateTime('@' . $next_weekly);
                    $dt->setTimezone($timezone);
                    echo esc_html($dt->format('d.m.Y H:i'));
                } else {
                    echo '<span style="color: #cc0000;">' . esc_html__('Not scheduled', 'myfeeds-affiliate-feed-manager') . '</span>';
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get human-readable label for sync type (Fix 8)
     */
    private static function get_sync_type_label($last_sync) {
        $type = $last_sync['type'] ?? '';
        $feed_name = $last_sync['feed_name'] ?? '';
        
        switch ($type) {
            case 'daily_quick_sync':
                return __('Quick Sync', 'myfeeds-affiliate-feed-manager');
            case 'weekly_full_import':
                return __('Full Import', 'myfeeds-affiliate-feed-manager');
            case 'reimport':
                return $feed_name 
                    /* translators: %s: feed name */
                    ? sprintf(__('Reimport: %s', 'myfeeds-affiliate-feed-manager'), $feed_name) 
                    : __('Reimport', 'myfeeds-affiliate-feed-manager');
            case 'new_feed':
                return $feed_name 
                    /* translators: %s: feed name */
                    ? sprintf(__('New Feed: %s', 'myfeeds-affiliate-feed-manager'), $feed_name) 
                    : __('New Feed', 'myfeeds-affiliate-feed-manager');
            default:
                return __('Sync', 'myfeeds-affiliate-feed-manager');
        }
    }
    
    /**
     * Render auto-sync schedule information box (kept for backwards compatibility)
     */
    private function render_auto_sync_info() {
        $timezone = wp_timezone();
        $now = new DateTime('now', $timezone);
        
        // Last auto-sync
        $last_sync = get_option('myfeeds_last_auto_sync', null);
        
        // Next scheduled events
        $next_daily = wp_next_scheduled('myfeeds_daily_feed_index');
        $next_weekly = wp_next_scheduled('myfeeds_weekly_full_import');
        
        ?>
        <div class="myfeeds-auto-sync-info" style="margin-top: 20px; padding: 12px 16px; background: #f8f9fa; border: 1px solid #e2e4e7; border-radius: 4px; font-size: 13px;">
            <strong style="display: block; margin-bottom: 8px;"><?php esc_html_e('Automatic Sync Schedule', 'myfeeds-affiliate-feed-manager'); ?></strong>
            <table style="border-collapse: collapse; width: 100%;">
                <tr>
                    <td style="padding: 3px 12px 3px 0; color: #666; white-space: nowrap;"><?php esc_html_e('Last auto-sync:', 'myfeeds-affiliate-feed-manager'); ?></td>
                    <td style="padding: 3px 0;">
                        <?php
                        if ($last_sync && !empty($last_sync['time'])) {
                            $type_label = self::get_sync_type_label($last_sync);
                            echo esc_html($last_sync['time'] . ' (' . $type_label . ')');
                        } else {
                            echo '<span style="color: #999;">' . esc_html__('No auto-sync yet', 'myfeeds-affiliate-feed-manager') . '</span>';
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 3px 12px 3px 0; color: #666; white-space: nowrap;"><?php esc_html_e('Next Quick Sync:', 'myfeeds-affiliate-feed-manager'); ?></td>
                    <td style="padding: 3px 0;">
                        <?php
                        if ($next_daily) {
                            $dt = new DateTime('@' . $next_daily);
                            $dt->setTimezone($timezone);
                            echo esc_html($dt->format('d.m.Y H:i') . ' (' . __('daily at 02:00', 'myfeeds-affiliate-feed-manager') . ')');
                        } else {
                            echo '<span style="color: #cc0000;">' . esc_html__('Not scheduled', 'myfeeds-affiliate-feed-manager') . '</span>';
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 3px 12px 3px 0; color: #666; white-space: nowrap;"><?php esc_html_e('Next Full Import:', 'myfeeds-affiliate-feed-manager'); ?></td>
                    <td style="padding: 3px 0;">
                        <?php
                        if ($next_weekly) {
                            $dt = new DateTime('@' . $next_weekly);
                            $dt->setTimezone($timezone);
                            echo esc_html($dt->format('d.m.Y H:i') . ' (' . __('weekly, Sunday 03:00', 'myfeeds-affiliate-feed-manager') . ')');
                        } else {
                            echo '<span style="color: #cc0000;">' . esc_html__('Not scheduled', 'myfeeds-affiliate-feed-manager') . '</span>';
                        }
                        ?>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }
    
    /**
     * Render simplified feeds table
     */
    private function render_feeds_table($feeds) {
        $has_feed = !empty($feeds);
        ?>
        <div class="myfeeds-feeds-table">
            <div class="myfeeds-feeds-table-header">
                <h2><?php esc_html_e('Configured Feed', 'myfeeds-affiliate-feed-manager'); ?></h2>
            </div>
            <?php if (!$has_feed): ?>
                <div class="myfeeds-empty-state" style="text-align:center; padding:48px 24px; border:1px dashed #c3c4c7; border-radius:8px; background:#fff;">
                    <h3 style="margin-top:0;"><?php esc_html_e('Add your first feed', 'myfeeds-affiliate-feed-manager'); ?></h3>
                    <p style="max-width:480px; margin:8px auto 20px; color:#50575e;">
                        <?php esc_html_e('Paste the product feed URL from your affiliate network. MyFeeds auto-detects the format and maps every product field for you.', 'myfeeds-affiliate-feed-manager'); ?>
                    </p>
                    <button type="button" id="myfeeds-add-feed-btn" class="button button-primary button-hero" data-testid="add-feed-btn">
                        + <?php esc_html_e('Add your first feed', 'myfeeds-affiliate-feed-manager'); ?>
                    </button>
                </div>
            <?php else: ?>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Feed Name', 'myfeeds-affiliate-feed-manager'); ?></th>
                        <th><?php esc_html_e('Feed URL', 'myfeeds-affiliate-feed-manager'); ?></th>
                        <th><?php esc_html_e('Detected Network', 'myfeeds-affiliate-feed-manager'); ?></th>
                        <th><?php esc_html_e('Status', 'myfeeds-affiliate-feed-manager'); ?></th>
                        <th><?php esc_html_e('Products', 'myfeeds-affiliate-feed-manager'); ?></th>
                        <th><?php esc_html_e('Mapping Quality', 'myfeeds-affiliate-feed-manager'); ?></th>
                        <th><?php esc_html_e('Actions', 'myfeeds-affiliate-feed-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($feeds as $key => $feed): ?>
                        <?php
                        $detected_network = isset($feed['detected_network']) ? $feed['detected_network'] : __('Auto-detected', 'myfeeds-affiliate-feed-manager');
                        $mapping_confidence = isset($feed['mapping_confidence']) ? $feed['mapping_confidence'] : 0;
                        ?>
                        <tr data-feed-name="<?php echo esc_attr($feed['name']); ?>"
                            data-feed-key="<?php echo esc_attr($key); ?>">
                            <td><strong><?php echo esc_html($feed['name']); ?></strong></td>
                            <td>
                                <span class="myfeeds-feed-url" title="<?php echo esc_attr($feed['url']); ?>">
                                    <?php echo esc_html(wp_parse_url($feed['url'], PHP_URL_HOST)); ?>
                                </span>
                            </td>
                            <td>
                                <span class="myfeeds-network-badge <?php echo esc_attr(strtolower($detected_network)); ?>">
                                    <?php echo esc_html(ucfirst($detected_network)); ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                $feed_status = $feed['status'] ?? '';
                                $last_error = $feed['last_error'] ?? '';
                                
                                // Legacy fallback: Feeds without explicit status field
                                if (empty($feed_status)) {
                                    $feed_count = intval($feed['product_count'] ?? 0);
                                    if ($feed_count > 0 || !empty($feed['last_sync'])) {
                                        $feed_status = 'active';
                                    } else {
                                        $feed_status = 'untested';
                                    }
                                }
                                
                                // Check if an import is currently running for this feed
                                $is_importing = false;
                                $import_status = get_option('myfeeds_import_status', array());
                                if (!empty($import_status['status']) && $import_status['status'] === 'running') {
                                    $current_feed_key = $import_status['current_feed'] ?? null;
                                    if ($current_feed_key !== null && intval($current_feed_key) === intval($key)) {
                                        $is_importing = true;
                                    }
                                    // Also check: single-feed import has total_feeds=1
                                    // and this feed is in the queue as pending/running
                                    if (!$is_importing) {
                                        $import_queue = get_option('myfeeds_import_queue', array());
                                        foreach ($import_queue as $q_item) {
                                            if (intval($q_item['feed_key'] ?? -1) === intval($key) 
                                                && in_array($q_item['status'] ?? '', array('pending', 'processing'))) {
                                                $is_importing = true;
                                                break;
                                            }
                                        }
                                    }
                                }
                                
                                // Also treat feeds with config status 'importing' as importing
                                // (covers the time between AS job scheduling and first processing)
                                if (!$is_importing && $feed_status === 'importing') {
                                    $is_importing = true;
                                }
                                
                                if ($is_importing): ?>
                                    <span class="feed-status-badge feed-status-importing" data-testid="feed-status-<?php echo esc_attr($key); ?>">IMPORTING...</span>
                                <?php elseif ($feed_status === 'active'): ?>
                                    <span class="feed-status-badge feed-status-active" data-testid="feed-status-<?php echo esc_attr($key); ?>">Active</span>
                                    <?php if (!empty($feed['last_updated'])): ?>
                                        <br><small><?php echo esc_html($feed['last_updated']); ?></small>
                                    <?php endif; ?>
                                <?php elseif ($feed_status === 'failed'): ?>
                                    <span class="feed-status-badge feed-status-failed" data-testid="feed-status-<?php echo esc_attr($key); ?>" 
                                          title="<?php echo esc_attr($last_error); ?>"
                                          style="cursor: help;">Failed</span>
                                    <?php if (!empty($last_error)): ?>
                                        <br><small class="feed-error-detail" style="color: #d63638; max-width: 200px; display: inline-block;"><?php echo esc_html(mb_strimwidth($last_error, 0, 80, '...')); ?></small>
                                    <?php endif; ?>
                                <?php elseif ($feed_status === 'untested'): ?>
                                    <span class="feed-status-badge feed-status-untested" data-testid="feed-status-<?php echo esc_attr($key); ?>">Untested</span>
                                <?php else: ?>
                                    <span class="feed-status-badge feed-status-unknown" data-testid="feed-status-<?php echo esc_attr($key); ?>">Unknown</span>
                                <?php endif; ?>
                            </td>
                            <td class="myfeeds-feed-product-count">
                                <?php 
                                    $feed_count = intval($feed['product_count'] ?? 0);
                                    $has_imported = !empty($feed['last_sync']);
                                ?>
                                <strong data-testid="feed-product-count-<?php echo esc_attr($key); ?>"><?php 
                                    echo $feed_count > 0 ? esc_html(number_format_i18n($feed_count)) : ($has_imported ? '0' : 'No data yet');
                                ?></strong>
                                <?php if ($has_imported): ?>
                                    <br><small>Last sync: <?php echo esc_html($feed['last_sync']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($mapping_confidence > 0): ?>
                                    <div class="myfeeds-confidence-bar myfeeds-quality-clickable" 
                                         data-feed-name="<?php echo esc_attr($feed['name']); ?>"
                                         data-testid="mapping-quality-<?php echo esc_attr($key); ?>"
                                         title="Click for details"
                                         style="cursor: pointer;">
                                        <div class="myfeeds-confidence-fill" style="width: <?php echo esc_attr($mapping_confidence); ?>%"></div>
                                        <span class="myfeeds-confidence-text"><?php echo esc_html(round($mapping_confidence)); ?>%</span>
                                    </div>
                                <?php else: ?>
                                    <span class="myfeeds-confidence-unknown">Not tested</span>
                                <?php endif; ?>
                            </td>
                            <td class="myfeeds-action-buttons">
                                <button type="button" class="button button-small myfeeds-edit-feed-btn"
                                    data-feed-key="<?php echo esc_attr($key); ?>"
                                    data-feed-name="<?php echo esc_attr($feed['name']); ?>"
                                    data-feed-url="<?php echo esc_attr($feed['url']); ?>"
                                    data-feed-format="<?php echo esc_attr($feed['format_hint'] ?? ''); ?>"
                                    data-feed-network="<?php echo esc_attr($feed['network_hint'] ?? 'awin'); ?>"
                                    data-testid="edit-feed-<?php echo esc_attr($key); ?>">
                                    <?php esc_html_e('Edit', 'myfeeds-affiliate-feed-manager'); ?>
                                </button>

                                <button type="button" class="button button-small myfeeds-reimport-btn"
                                    data-feed-key="<?php echo esc_attr($key); ?>"
                                    data-feed-name="<?php echo esc_attr($feed['name']); ?>"
                                    data-testid="reimport-feed-<?php echo esc_attr($key); ?>"
                                    <?php if ($is_importing): ?>disabled title="<?php esc_attr_e('Import already running', 'myfeeds-affiliate-feed-manager'); ?>"<?php endif; ?>>
                                    <?php esc_html_e('Reimport', 'myfeeds-affiliate-feed-manager'); ?>
                                </button>
                                
                                <button type="button" class="button button-small button-link-delete myfeeds-delete-feed-btn"
                                    data-feed-key="<?php echo esc_attr($key); ?>"
                                    data-feed-name="<?php echo esc_attr($feed['name']); ?>"
                                    data-product-count="<?php echo esc_attr($feed['product_count'] ?? 0); ?>"
                                    data-testid="delete-feed-<?php echo esc_attr($key); ?>"
                                    <?php if ($is_importing): ?>disabled<?php endif; ?>>
                                    <?php esc_html_e('Delete', 'myfeeds-affiliate-feed-manager'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        
        <!-- Mapping Quality Detail Modal -->
        <div id="myfeeds-quality-modal" class="myfeeds-modal-overlay" style="display:none;">
            <div class="myfeeds-modal-content">
                <div class="myfeeds-modal-header">
                    <h3 id="myfeeds-quality-modal-title">Mapping Quality Details</h3>
                    <button type="button" class="myfeeds-modal-close" id="myfeeds-quality-close">&times;</button>
                </div>
                <div class="myfeeds-modal-body" id="myfeeds-quality-modal-body">
                    <p>Loading...</p>
                </div>
            </div>
        </div>

        <?php $this->render_feed_modal(); ?>
        <?php
    }
    
    private function display_admin_messages() {
        // Notice payloads are written by our own redirect_with_success/error
        // helpers, but $_GET is still untrusted, so sanitize on read.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['myfeeds_success'])) {
            $message = sanitize_text_field(wp_unslash($_GET['myfeeds_success']));
            echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        }
        if (isset($_GET['myfeeds_error'])) {
            $message = sanitize_text_field(wp_unslash($_GET['myfeeds_error']));
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }
    
    /**
     * Handle simplified feed save (legacy form submit fallback)
     */
    public function handle_save_feed() {
        try {
            if (!current_user_can('manage_options') || !check_admin_referer('myfeeds_save_feed')) {
                wp_die(esc_html__('Security check failed', 'myfeeds-affiliate-feed-manager'));
            }
            
            $feeds = get_option(self::OPTION_KEY, array());
            $key = isset($_REQUEST['feed_key']) && $_REQUEST['feed_key'] !== '' ? intval($_REQUEST['feed_key']) : null;
            
            $name = isset($_POST['feed_name']) ? sanitize_text_field(wp_unslash($_POST['feed_name'])) : '';
            $url = isset($_POST['feed_url']) ? esc_url_raw(wp_unslash($_POST['feed_url'])) : '';
            $format_hint = isset($_POST['feed_format_hint']) ? sanitize_text_field(wp_unslash($_POST['feed_format_hint'])) : '';
            $network_hint = isset($_POST['feed_network_hint']) ? sanitize_text_field(wp_unslash($_POST['feed_network_hint'])) : '';
            
            if (empty($name) || empty($url)) {
                $this->redirect_with_error(__('Feed name and URL are required.', 'myfeeds-affiliate-feed-manager'));
                return;
            }
            
            $detected_network = $this->detect_network_from_url($url);
            if (!$detected_network) {
                $detected_network = $network_hint ? $network_hint : 'auto-detected';
            }
            
            // Build base entry
            $entry = array(
                'name' => $name,
                'url' => $url,
                'format_hint' => $format_hint,
                'network_hint' => $network_hint,
                'detected_network' => $detected_network,
                'last_updated' => current_time('mysql'),
            );
            
            if (is_int($key) && isset($feeds[$key])) {
                $entry['created_at'] = $feeds[$key]['created_at'] ?? current_time('mysql');
                $feeds[$key] = array_merge($feeds[$key], $entry);
            } else {
                $entry['created_at'] = current_time('mysql');
                $entry['detected_format'] = '';
                $entry['mapping'] = array();
                $entry['mapping_confidence'] = 0;
                $entry['status'] = 'untested';
                $feeds[] = $entry;
                $key = array_key_last($feeds);
                // Assign stable_id to new feed
                MyFeeds_DB_Manager::assign_stable_id($feeds[$key]);
            }
            
            // Save immediately
            update_option(self::OPTION_KEY, $feeds);
            
            // Attempt quick test
            $test_result = $this->quick_test_feed_url($url, $format_hint);
            
            $status_msg = '';
            if (!is_wp_error($test_result)) {
                $feeds[$key]['detected_format'] = $test_result['format'];
                $feeds[$key]['last_test'] = current_time('mysql');
                $feeds[$key]['status'] = 'active';
                
                if (!empty($test_result['sample_data'])) {
                    $mapping = $this->smart_mapper->auto_map_fields($test_result['sample_data'], $url);
                    if ($mapping) {
                        $feeds[$key]['mapping'] = $mapping;
                        $feeds[$key]['mapping_confidence'] = $this->smart_mapper->get_mapping_confidence($mapping);
                    }
                }
                
                update_option(self::OPTION_KEY, $feeds);
                $this->rebuild_feed_index();
                
                $confidence = $feeds[$key]['mapping_confidence'] ?? 0;
                $status_msg = sprintf(
                    /* translators: %1$s: feed name, %2$s: detected network, %3$d: mapping confidence percentage */
                    __('Feed "%1$s" saved! Network: %2$s, Confidence: %3$d%%.', 'myfeeds-affiliate-feed-manager'),
                    $name, ucfirst($detected_network), round($confidence)
                );
            } else {
                $status_msg = sprintf(
                    /* translators: %1$s: feed name, %2$s: error message */
                    __('Feed "%1$s" saved, but test failed: %2$s. Will be tested during import.', 'myfeeds-affiliate-feed-manager'),
                    $name, $test_result->get_error_message()
                );
            }
            
            $this->redirect_with_success($status_msg);
            
        } catch (\Throwable $e) {
            MyFeeds_Affiliate_Product_Picker::log("FATAL ERROR in handle_save_feed: " . $e->getMessage());
            MyFeeds_Affiliate_Product_Picker::log("Stack trace: " . $e->getTraceAsString());
            $this->redirect_with_error(__('A system error occurred: ', 'myfeeds-affiliate-feed-manager') . $e->getMessage());
        }
    }
    
    /**
     * AJAX handler for saving feeds (called from modal)
     * Save-first approach: Feed is always saved, test runs after.
     */
    public function handle_save_feed_ajax() {
        try {
            check_ajax_referer('myfeeds_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => __('Permission denied', 'myfeeds-affiliate-feed-manager')));
                return;
            }
            
            $feeds = get_option(self::OPTION_KEY, array());
            $key = isset($_POST['feed_key']) && $_POST['feed_key'] !== '' ? intval($_POST['feed_key']) : null;

            $name = sanitize_text_field(wp_unslash($_POST['feed_name'] ?? ''));
            $url = esc_url_raw(wp_unslash($_POST['feed_url'] ?? ''));
            $format_hint = sanitize_text_field(wp_unslash($_POST['feed_format_hint'] ?? ''));
            $network_hint = sanitize_text_field(wp_unslash($_POST['feed_network_hint'] ?? ''));
            
            if (empty($name) || empty($url)) {
                wp_send_json_error(array('message' => __('Feed name and URL are required.', 'myfeeds-affiliate-feed-manager')));
                return;
            }
            
            // Detect network from URL or use hint
            $detected_network = $this->detect_network_from_url($url);
            if (!$detected_network) {
                $detected_network = $network_hint ? $network_hint : 'auto-detected';
            }
            
            // Build base entry (saved regardless of test outcome)
            $entry = array(
                'name' => $name,
                'url' => $url,
                'format_hint' => $format_hint,
                'network_hint' => $network_hint,
                'detected_network' => $detected_network,
                'last_updated' => current_time('mysql'),
            );
            
            $action_label = 'created';
            if (is_int($key) && isset($feeds[$key])) {
                $entry['created_at'] = $feeds[$key]['created_at'] ?? current_time('mysql');
                $feeds[$key] = array_merge($feeds[$key], $entry);
                $action_label = 'updated';
            } else {
                $entry['created_at'] = current_time('mysql');
                $entry['detected_format'] = '';
                $entry['mapping'] = array();
                $entry['mapping_confidence'] = 0;
                $entry['status'] = 'untested';
                $feeds[] = $entry;
                $key = array_key_last($feeds);
                // Assign stable_id to new feed
                MyFeeds_DB_Manager::assign_stable_id($feeds[$key]);
            }
            
            // Save immediately so the feed is never lost
            update_option(self::OPTION_KEY, $feeds);
            
            // Now attempt quick test (partial download, fast)
            $test_result = $this->quick_test_feed_url($url, $format_hint);
            
            $warning = '';
            if (is_wp_error($test_result)) {
                // Test failed — feed is saved but untested. Start background import anyway.
                $feeds[$key]['status'] = 'untested';
                update_option(self::OPTION_KEY, $feeds);
                
                // Trigger background import for this new feed
                $import_scheduled = false;
                if ($action_label === 'created') {
                    $importer = new \MyFeeds_Batch_Importer();
                    $import_scheduled = $importer->schedule_new_feed_import($key);
                    if ($import_scheduled) {
                        $feeds[$key]['status'] = 'importing';
                        update_option(self::OPTION_KEY, $feeds);
                    }
                }
                
                $import_hint = $import_scheduled 
                    ? __(' Importing products in the background...', 'myfeeds-affiliate-feed-manager')
                    : __(' The feed will be imported during the next "Update All Feeds".', 'myfeeds-affiliate-feed-manager');
                
                wp_send_json_success(array(
                    'message' => sprintf(
                        /* translators: %1$s: feed name, %2$s: action label, %3$s: error message, %4$s: import hint */
                        __('Feed "%1$s" %2$s, but the connectivity test failed: %3$s.%4$s', 'myfeeds-affiliate-feed-manager'),
                        $name, $action_label, $test_result->get_error_message(), $import_hint
                    ),
                    'action' => $action_label,
                    'status' => 'untested',
                    'import_scheduled' => $import_scheduled,
                    'feed_key' => $key,
                    'detected_network' => $detected_network,
                ));
                return;
            }
            
            // Update feed with test results
            $feeds[$key]['detected_format'] = $test_result['format'];
            $feeds[$key]['last_test'] = current_time('mysql');
            $feeds[$key]['status'] = 'active';
            
            // Try mapping if sample data was parsed
            if (!empty($test_result['sample_data'])) {
                $mapping = $this->smart_mapper->auto_map_fields($test_result['sample_data'], $url);
                if ($mapping) {
                    $feeds[$key]['mapping'] = $mapping;
                    $feeds[$key]['mapping_confidence'] = $this->smart_mapper->get_mapping_confidence($mapping);
                }
                
                // Update detected_network from Smart Mapper if it detected a different network
                // (e.g. URL says "admitad" but fields say "google_shopping")
                $mapper_network = $this->smart_mapper->get_last_detected_network();
                if (!empty($mapper_network) && $mapper_network !== $detected_network) {
                    myfeeds_log("Network override: Smart Mapper detected '{$mapper_network}' (URL suggested '{$detected_network}')", 'info');
                    $detected_network = $mapper_network;
                    $feeds[$key]['detected_network'] = $detected_network;
                }
            } else {
                $warning = __(' Sample parsing incomplete (large feed) — full mapping will run during import.', 'myfeeds-affiliate-feed-manager');
            }
            
            update_option(self::OPTION_KEY, $feeds);
            
            // Rebuild index — wrapped in try/catch so feed creation always succeeds
            try {
                $this->rebuild_feed_index();
            } catch (\Throwable $e) {
                myfeeds_log("Warning: rebuild_feed_index failed after save: " . $e->getMessage(), 'error');
                $warning .= __(' Index rebuild failed, products will sync during background import.', 'myfeeds-affiliate-feed-manager');
            }
            
            // Trigger background import for newly created feeds
            $import_scheduled = false;
            if ($action_label === 'created') {
                $importer = new \MyFeeds_Batch_Importer();
                $import_scheduled = $importer->schedule_new_feed_import($key);
                if ($import_scheduled) {
                    $feeds[$key]['status'] = 'importing';
                    update_option(self::OPTION_KEY, $feeds);
                }
            }
            
            $confidence = $feeds[$key]['mapping_confidence'] ?? 0;
            $confidence_message = '';
            if ($confidence >= 80) {
                $confidence_message = __(' Excellent field mapping detected!', 'myfeeds-affiliate-feed-manager');
            } elseif ($confidence >= 60) {
                $confidence_message = __(' Good field mapping detected.', 'myfeeds-affiliate-feed-manager');
            } elseif ($confidence > 0) {
                $confidence_message = __(' Basic field mapping detected.', 'myfeeds-affiliate-feed-manager');
            }
            
            $import_hint = $import_scheduled 
                ? __(' Importing products in the background...', 'myfeeds-affiliate-feed-manager')
                : '';
            
            wp_send_json_success(array(
                'message' => sprintf(
                    /* translators: %1$s: feed name, %2$s: action label, %3$s: detected network, %4$s: format, %5$s: confidence message, %6$s: warning, %7$s: import hint */
                    __('Feed "%1$s" %2$s successfully! Network: %3$s, Format: %4$s.%5$s%6$s%7$s', 'myfeeds-affiliate-feed-manager'),
                    $name, $action_label, ucfirst($detected_network),
                    strtoupper($test_result['format']),
                    $confidence_message, $warning, $import_hint
                ),
                'action' => $action_label,
                'status' => 'active',
                'import_scheduled' => $import_scheduled,
                'feed_key' => $key,
                'detected_network' => $detected_network,
            ));
            
        } catch (\Throwable $e) {
            MyFeeds_Affiliate_Product_Picker::log("FATAL ERROR in handle_save_feed_ajax: " . $e->getMessage());
            wp_send_json_error(array('message' => __('A system error occurred: ', 'myfeeds-affiliate-feed-manager') . $e->getMessage()));
        }
    }
    
    /**
     * AJAX: Get current status of a specific feed (for polling after creation).
     * Returns feed status, product count, mapping confidence, and last_sync.
     */
    public function ajax_get_feed_status() {
        check_ajax_referer('myfeeds_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        $feed_key = isset($_GET['feed_key']) ? intval($_GET['feed_key']) : -1;
        $feeds = get_option(self::OPTION_KEY, array());
        
        if (!isset($feeds[$feed_key])) {
            wp_send_json_error(array('message' => 'Feed not found'));
            return;
        }
        
        $feed = $feeds[$feed_key];
        $feed_status = $feed['status'] ?? '';
        
        // Legacy fallback
        if (empty($feed_status)) {
            $feed_count = intval($feed['product_count'] ?? 0);
            $feed_status = ($feed_count > 0 || !empty($feed['last_sync'])) ? 'active' : 'untested';
        }
        
        // Check if import is currently running for this feed
        $is_importing = false;
        $import_status = get_option('myfeeds_import_status', array());
        if (!empty($import_status['status']) && $import_status['status'] === 'running') {
            $current_feed = $import_status['current_feed'] ?? null;
            if ($current_feed !== null && intval($current_feed) === $feed_key) {
                $is_importing = true;
            }
            if (!$is_importing) {
                $import_queue = get_option('myfeeds_import_queue', array());
                foreach ($import_queue as $q_item) {
                    if (intval($q_item['feed_key'] ?? -1) === $feed_key 
                        && in_array($q_item['status'] ?? '', array('pending', 'processing'))) {
                        $is_importing = true;
                        break;
                    }
                }
            }
        }
        
        // Also treat config status 'importing' as importing (AS job not yet started)
        if (!$is_importing && $feed_status === 'importing') {
            $is_importing = true;
        }
        
        // Get product count from DB for accuracy
        $product_count = intval($feed['product_count'] ?? 0);
        $mapping_quality = intval($feed['mapping_confidence'] ?? 0);
        if (MyFeeds_DB_Manager::is_db_mode()) {
            $feed_stable_id = (int) ($feed['stable_id'] ?? 0);
            $feed_counts = MyFeeds_DB_Manager::get_feed_counts();
            $db_count = $feed_counts[$feed['name']] ?? MyFeeds_DB_Manager::get_feed_product_count($feed_stable_id);
            if ($db_count > 0) {
                $product_count = $db_count;
            }
            // Always get fresh mapping quality from DB if products exist
            if ($product_count > 0 && !empty($feed['name'])) {
                $quality_data = MyFeeds_DB_Manager::calculate_mapping_quality($feed['name']);
                $mapping_quality = $quality_data['quality'] ?? $mapping_quality;
            }
        }
        
        wp_send_json_success(array(
            'feed_key' => $feed_key,
            'status' => $is_importing ? 'importing' : $feed_status,
            'product_count' => $product_count,
            'product_count_formatted' => number_format_i18n($product_count),
            'mapping_confidence' => $mapping_quality,
            'last_sync' => $feed['last_sync'] ?? '',
            'last_error' => $feed['last_error'] ?? '',
            'name' => $feed['name'] ?? '',
            'detected_network' => $feed['detected_network'] ?? 'auto-detected',
            'url' => $feed['url'] ?? '',
        ));
    }

    /**
     * AJAX handler: Reimport a single feed
     * Uses the same pipeline as auto-import after feed creation.
     */
    public function ajax_reimport_feed() {
        check_ajax_referer('myfeeds_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        $feed_key = isset($_POST['feed_key']) ? intval($_POST['feed_key']) : -1;
        $feeds = get_option(self::OPTION_KEY, array());
        
        if (!isset($feeds[$feed_key])) {
            wp_send_json_error(array('message' => 'Feed not found'));
            return;
        }
        
        // DIAG LOG 6: Log reimport request
        myfeeds_log("DIAG reimport: feed_key={$feed_key}, feed_name=" . ($feeds[$feed_key]['name'] ?? 'N/A') . ", stable_id=" . ($feeds[$feed_key]['stable_id'] ?? 'N/A'), 'info');
        
        // Check if any import is already running
        $import_status = get_option('myfeeds_import_status', array());
        if (!empty($import_status['status']) && $import_status['status'] === 'running') {
            // Fix 5: If a full import is running, block reimport. If single-feed, also block.
            wp_send_json_error(array('message' => 'An import is already running. Please wait until it finishes.'));
            return;
        }
        
        // Set feed status to 'importing'
        $feeds[$feed_key]['status'] = 'importing';
        update_option(self::OPTION_KEY, $feeds);
        
        // Schedule single-feed import (same pipeline as auto-import)
        $importer = new \MyFeeds_Batch_Importer();
        $result = $importer->schedule_new_feed_import($feed_key, true);
        
        if ($result) {
            wp_send_json_success(array(
                'message' => 'Reimport started for "' . esc_html($feeds[$feed_key]['name'] ?? 'feed') . '"',
                'feed_key' => $feed_key,
                'feed_name' => $feeds[$feed_key]['name'] ?? '',
            ));
        } else {
            // Revert status if scheduling failed
            $feeds[$feed_key]['status'] = 'active';
            update_option(self::OPTION_KEY, $feeds);
            wp_send_json_error(array('message' => 'Failed to start reimport. Another import may be running.'));
        }
    }

    /**
     * AJAX handler: Delete feed and all its products
     */
    public function ajax_delete_feed() {
        check_ajax_referer('myfeeds_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        $feed_key = isset($_POST['feed_key']) ? intval($_POST['feed_key']) : -1;
        $feeds = get_option(self::OPTION_KEY, array());
        
        if (!isset($feeds[$feed_key])) {
            wp_send_json_error(array('message' => 'Feed not found'));
            return;
        }
        
        $feed_name = $feeds[$feed_key]['name'] ?? 'Unknown';
        $feed_stable_id = (int) ($feeds[$feed_key]['stable_id'] ?? 0);
        $deleted_products = 0;
        
        // Delete all products from DB for this feed (by name to catch feed_id bug leftovers)
        if (class_exists('MyFeeds_DB_Manager') && MyFeeds_DB_Manager::is_db_mode()) {
            $deleted_products = MyFeeds_DB_Manager::delete_products_by_feed_id($feed_stable_id, $feed_name);
        }
        
        // Remove feed from config
        array_splice($feeds, $feed_key, 1);
        update_option(self::OPTION_KEY, $feeds);
        
        // Cleanup any remaining orphaned products (catches feed_id=0 leftovers etc.)
        if (class_exists('MyFeeds_DB_Manager') && MyFeeds_DB_Manager::is_db_mode()) {
            $orphan_cleanup = MyFeeds_DB_Manager::cleanup_orphaned_products();
            if ($orphan_cleanup > 0) {
                $deleted_products += $orphan_cleanup;
            }
        }
        
        // Get updated stats
        $total_products = 0;
        if (class_exists('MyFeeds_DB_Manager') && MyFeeds_DB_Manager::table_exists()) {
            $total_products = MyFeeds_DB_Manager::get_active_product_count();
        }
        $active_feeds = count($feeds);
        $avg_quality = $active_feeds > 0 ? round(array_sum(array_column($feeds, 'mapping_confidence')) / $active_feeds) : 0;

        MyFeeds_Logger::info("Feed '{$feed_name}' deleted. Removed {$deleted_products} products from DB.");
        
        wp_send_json_success(array(
            'message' => sprintf('Feed "%s" deleted. %s products removed.', $feed_name, number_format_i18n($deleted_products)),
            'deleted_products' => $deleted_products,
            'total_products' => $total_products,
            'total_products_formatted' => number_format_i18n($total_products),
            'active_feeds' => $active_feeds,
            'avg_quality' => $avg_quality,
        ));
    }
    
    /**
     * AJAX handler: Get current header stats (Fix 4)
     */
    public function ajax_get_header_stats() {
        check_ajax_referer('myfeeds_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        $feeds = get_option(self::OPTION_KEY, array());
        $total_products = 0;
        if (class_exists('MyFeeds_DB_Manager') && MyFeeds_DB_Manager::table_exists()) {
            $total_products = MyFeeds_DB_Manager::get_active_product_count();
        }
        $active_feeds = count($feeds);
        $avg_quality = $active_feeds > 0 ? round(array_sum(array_column($feeds, 'mapping_confidence')) / $active_feeds) : 0;

        // Also return last sync info for Fix 3
        $last_sync = get_option('myfeeds_last_auto_sync', null);
        $last_sync_text = '';
        if ($last_sync && !empty($last_sync['time'])) {
            $last_sync_text = $last_sync['time'] . ' (' . self::get_sync_type_label($last_sync) . ')';
        }
        
        wp_send_json_success(array(
            'total_products' => $total_products,
            'total_products_formatted' => number_format_i18n($total_products),
            'active_feeds' => $active_feeds,
            'avg_quality' => $avg_quality,
            'last_sync_text' => $last_sync_text,
        ));
    }
    
    /**
     * Detect network from URL patterns
     */
    private function detect_network_from_url($url) {
        $url_patterns = array(
            'awin' => array('awin.com', 'affiliate-window'),
            'webgains' => array('webgains.com', 'platform-api.webgains.com', 'ikhnaie.link'),
            'admitad' => array('admitad.com', 'export.admitad.com', 'bywiola.com'),
            'tradedoubler' => array('tradedoubler.com', 'clkuk.tradedoubler'),
            'amazon' => array('amazon.', 'amzn.'),
            'ebay' => array('ebay.', 'rover.ebay'),
            'commissionjunction' => array('cj.com', 'commission-junction', 'dpbolvw.net', 'anrdoezrs.net', 'jdoqocy.com'),
            'shareasale' => array('shareasale.com'),
            'impact' => array('impact.com', 'impactradius.com', 'sjv.io'),
            'partnerize' => array('partnerize.com', 'performancehorizon.com', 'prf.hn'),
            'rakuten' => array('rakutenadvertising.com', 'linksynergy.com', 'click.linksynergy.com'),
        );
        
        foreach ($url_patterns as $network => $patterns) {
            foreach ($patterns as $pattern) {
                if (stripos($url, $pattern) !== false) {
                    return $network;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Test feed URL for connectivity and format detection
     * Used by "Test Feed" button — downloads more data, higher timeout
     */
    public function test_feed_url($url, $format_hint = '') {
        $response = wp_remote_get($url, array('timeout' => 60));
        
        if (is_wp_error($response)) {
            return new WP_Error('connection_failed', 'Could not connect to feed URL: ' . $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $http_code = wp_remote_retrieve_response_code($response);
        
        if ($http_code !== 200) {
            return new WP_Error('http_error', "HTTP Error $http_code when accessing feed");
        }
        
        if (empty($body)) {
            return new WP_Error('empty_response', 'Empty response from feed URL');
        }
        
        // Handle gzipped content (URL-based + magic bytes detection)
        $is_gzip = (function_exists('myfeeds_is_gzip_url') && myfeeds_is_gzip_url($url))
                   || substr($body, 0, 2) === "\x1f\x8b";
        if ($is_gzip) {
            $decoded = function_exists('gzdecode') ? @gzdecode($body) : false;
            if ($decoded !== false) {
                $body = $decoded;
            }
        }
        
        // Auto-detect format
        $detected_format = $this->detect_feed_format($body, $format_hint);
        
        if (!$detected_format) {
            return new WP_Error('format_detection_failed', 'Could not detect feed format');
        }
        
        // Parse first item as sample
        $sample_data = $this->parse_feed_sample($body, $detected_format);
        
        if (!$sample_data) {
            return new WP_Error('parsing_failed', 'Could not parse feed data');
        }
        
        return array(
            'format' => $detected_format,
            'sample_data' => $sample_data,
            'total_size' => strlen($body)
        );
    }
    
    /**
     * Quick feed test — partial download for fast validation during Create/Edit.
     * Downloads max 32KB with 10s timeout. Enough for format detection + sample parsing.
     * Returns test result array on success, WP_Error on failure.
     */
    private function quick_test_feed_url($url, $format_hint = '') {
        $is_likely_gzip = function_exists('myfeeds_is_gzip_url') && myfeeds_is_gzip_url($url);
        $response = wp_remote_get($url, array(
            'timeout' => $is_likely_gzip ? 30 : 10,
            'limit_response_size' => $is_likely_gzip ? 2097152 : 32768, // 2MB for gzip, 32KB otherwise
        ));
        
        if (is_wp_error($response)) {
            return new \WP_Error('connection_failed', $response->get_error_message());
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200 && $http_code !== 206) {
            return new \WP_Error('http_error', "HTTP Error $http_code when accessing feed");
        }
        
        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return new \WP_Error('empty_response', 'Empty response from feed URL');
        }
        
        // Handle gzipped content (URL-based + magic bytes detection)
        $is_gzip = (function_exists('myfeeds_is_gzip_url') && myfeeds_is_gzip_url($url))
                   || substr($body, 0, 2) === "\x1f\x8b";
        if ($is_gzip) {
            $decoded = function_exists('gzdecode') ? @gzdecode($body) : false;
            if ($decoded !== false) {
                $body = $decoded;
            }
        }
        
        // Format detection works fine with partial data
        $detected_format = $this->detect_feed_format($body, $format_hint);
        if (!$detected_format) {
            return new \WP_Error('format_detection_failed', 'Could not detect feed format');
        }
        
        // Sample parsing — may fail with truncated XML/JSON, that's OK
        $sample_data = $this->parse_feed_sample($body, $detected_format);
        
        return array(
            'format' => $detected_format,
            'sample_data' => $sample_data,  // May be null/false for truncated data
            'partial' => true,
        );
    }
    
    /**
     * Detect feed format from content
     */
    private function detect_feed_format($content, $hint = '') {
        // Use hint if provided (csv_gz maps to csv — decompression handled by feed cache)
        if ($hint === 'csv_gz') {
            return 'csv';
        }
        $valid = array('csv', 'tsv', 'ssv', 'psv', 'xml', 'json', 'json_lines');
        if ($hint && in_array($hint, $valid, true)) {
            return $hint;
        }
        
        // Auto-detect based on content
        $content = trim($content);
        
        if (substr($content, 0, 5) === '<?xml' || substr($content, 0, 1) === '<') {
            return 'xml';
        }
        
        if (substr($content, 0, 1) === '{' || substr($content, 0, 1) === '[') {
            return 'json';
        }
        
        // Check for JSON Lines (each line is a JSON object)
        $lines = explode("\n", $content);
        if (count($lines) > 1 && substr(trim($lines[0]), 0, 1) === '{') {
            return 'json_lines';
        }
        
        // Auto-detect delimiter in first line
        $first_line = $lines[0] ?? '';
        $tab_count   = substr_count($first_line, "\t");
        $semi_count  = substr_count($first_line, ';');
        $pipe_count  = substr_count($first_line, '|');
        $comma_count = substr_count($first_line, ',');
        
        if ($tab_count > $comma_count && $tab_count > $semi_count && $tab_count > $pipe_count) return 'tsv';
        if ($semi_count > $comma_count && $semi_count > $tab_count && $semi_count > $pipe_count) return 'ssv';
        if ($pipe_count > $comma_count && $pipe_count > $tab_count && $pipe_count > $semi_count) return 'psv';
        
        // Default to CSV
        return 'csv';
    }
    
    /**
     * Parse feed sample data using Feed Reader
     */
    private function parse_feed_sample($content, $format) {
        $tmp_path = wp_tempnam('myfeeds_fmsample_');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temp file for Feed Reader
        file_put_contents($tmp_path, $content);
        
        $reader = new MyFeeds_Feed_Reader();
        if (!$reader->open($tmp_path, $format)) {
            wp_delete_file($tmp_path);
            return false;
        }
        
        $first_item = $reader->read_next();
        $reader->close();
        wp_delete_file($tmp_path);
        
        return $first_item !== false ? $first_item : false;
    }
    
    /**
     * Convert XML element to array
     */
    private function xml_to_array($xml_element) {
        $array = array();
        
        foreach ($xml_element->children() as $child) {
            $name = $child->getName();
            
            if ($child->count() > 0) {
                $array[$name] = $this->xml_to_array($child);
            } else {
                $array[$name] = (string) $child;
            }
        }
        
        return $array;
    }
    
    /**
     * Register REST API routes with enhanced gender-aware search
     */
    public function register_rest_routes() {
        register_rest_route('myfeeds/v1', '/feeds', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_list_feeds'),
            'permission_callback' => function() { return current_user_can('manage_options'); },
        ));
        
        register_rest_route('myfeeds/v1', '/products', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_search_products'),
            'permission_callback' => function() { return current_user_can('edit_posts'); },
        ));
        
        register_rest_route('myfeeds/v1', '/product', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_single_product'),
            'permission_callback' => function() { return current_user_can('edit_posts'); },
        ));
        
        register_rest_route('myfeeds/v1', '/products-by-ids', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_get_products_by_ids'),
            'permission_callback' => function() { return current_user_can('edit_posts'); },
        ));
        
        register_rest_route('myfeeds/v1', '/product-sizes', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_product_sizes'),
            'permission_callback' => function() { return current_user_can('edit_posts'); },
        ));
        
        register_rest_route('myfeeds/v1', '/plan-limits', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_plan_limits'),
            'permission_callback' => function() { return current_user_can('edit_posts'); },
        ));
    }
    
    public function rest_list_feeds(WP_REST_Request $request) {
        return rest_ensure_response(get_option(self::OPTION_KEY, array()));
    }
    
    /**
     * REST API: Get multiple products by their IDs.
     * Used when showing selected products in the search modal (empty search).
     */
    public function rest_get_products_by_ids(WP_REST_Request $req) {
        $body = $req->get_json_params();
        $ids = isset($body['ids']) ? $body['ids'] : array();
        if (empty($ids) || !is_array($ids)) {
            return rest_ensure_response(array());
        }
        
        // Sanitize and limit
        $ids = array_map('sanitize_text_field', array_slice($ids, 0, 100));
        
        if (class_exists('MyFeeds_DB_Manager') && MyFeeds_DB_Manager::is_db_mode()) {
            $products = MyFeeds_DB_Manager::get_products($ids);
            return rest_ensure_response(array_values($products));
        }
        
        return rest_ensure_response(array());
    }
    
    /**
     * REST API: Get available sizes for a product_name + colour combination.
     * Used in the detail view to show all size variants of a deduplicated product.
     */
    public function rest_get_product_sizes(WP_REST_Request $req) {
        $name = sanitize_text_field($req->get_param('name'));
        $colour = sanitize_text_field($req->get_param('colour'));
        
        if (empty($name)) {
            return rest_ensure_response(array());
        }
        
        if (class_exists('MyFeeds_DB_Manager') && MyFeeds_DB_Manager::is_db_mode()) {
            $sizes = MyFeeds_DB_Manager::get_available_sizes($name, $colour);
            return rest_ensure_response($sizes);
        }
        
        return rest_ensure_response(array());
    }
    
    /**
     * REST API: Report the plan label so the editor can render the upgrade
     * link in the upsell card. The Pro plugin overrides this route with its
     * own implementation.
     */
    public function rest_get_plan_limits() {
        return rest_ensure_response(array(
            'plan'        => 'Free',
            'upgrade_url' => 'https://myfeeds.site/?utm_source=wp-plugin-free&utm_medium=block-editor',
        ));
    }
    
    /**
     * Enhanced REST API search with gender-aware logic (fixed substring bug)
     * In DB mode: Uses SQL LIKE on product_name and brand (FULLTEXT-upgradeable)
     * In JSON mode: Original in-memory search with synonyms and scoring
     */
    public function rest_search_products(WP_REST_Request $req) {
        $query = sanitize_text_field($req->get_param('q'));
        if (!$query || strlen($query) < 2) return array();
        
        $offset = intval($req->get_param('offset'));
        if ($offset < 0) $offset = 0;
        $limit = 50;
        
        // =====================================================================
        // DB MODE: SQL-based search — fast, no RAM spike
        // =====================================================================
        if (class_exists('MyFeeds_DB_Manager') && MyFeeds_DB_Manager::is_db_mode()) {
            $results = MyFeeds_DB_Manager::search_products($query, $limit, $offset);
            return rest_ensure_response(array_values($results));
        }
        
        // =====================================================================
        // JSON MODE: Original in-memory search (unchanged below)
        // =====================================================================
        myfeeds_log("REST Search called with query: " . $query, 'debug');
        
        $index_path = wp_upload_dir()['basedir'] . '/' . self::INDEX_FILE;
        if (!file_exists($index_path) || filemtime($index_path) < time() - DAY_IN_SECONDS) {
            $this->rebuild_feed_index();
        }
        
        $query = sanitize_text_field($req->get_param('q'));
        if (!$query || strlen($query) < 2) return array();

        $json = json_decode(file_get_contents($index_path), true);
        if (!is_array($json) || !isset($json['items'], $json['__search_fields'])) return array();

        $search_fields = $json['__search_fields'];
        $data = $json['items'];

        // Extract original keywords (without synonyms for main logic)
        $original_tokens = array_filter(array_map('trim', preg_split('/\s+/', strtolower($query))));
        myfeeds_log("📝 Original tokens: " . implode(', ', $original_tokens), 'debug');
        
        // Extended token list with comprehensive German/English synonyms for smart search
        $all_tokens = $original_tokens;
        $synonyms = array(
            // Gender terms - CRITICAL for mixed language search + ENHANCED with children terms
            'women'    => array('damen', 'frau', 'frauen', 'woman', 'dame', 'female', 'lady', 'ladies'),
            'woman'    => array('damen', 'frau', 'frauen', 'women', 'dame', 'female', 'lady', 'ladies'),
            'damen'    => array('women', 'woman', 'frau', 'frauen', 'dame', 'female', 'lady', 'ladies'),
            'frau'     => array('women', 'woman', 'damen', 'frauen', 'dame', 'female', 'lady', 'ladies'),
            'frauen'   => array('women', 'woman', 'damen', 'frau', 'dame', 'female', 'lady', 'ladies'),
            'dame'     => array('women', 'woman', 'damen', 'frau', 'frauen', 'female', 'lady', 'ladies'),
            'female'   => array('women', 'woman', 'damen', 'frau', 'frauen', 'dame', 'lady', 'ladies'),
            'lady'     => array('women', 'woman', 'damen', 'frau', 'frauen', 'dame', 'female', 'ladies'),
            'ladies'   => array('women', 'woman', 'damen', 'frau', 'frauen', 'dame', 'female', 'lady'),
            
            'men'      => array('herren', 'mann', 'männer', 'herr', 'male', 'gentleman', 'gentlemen'),
            'herren'   => array('men', 'mann', 'männer', 'herr', 'male', 'gentleman', 'gentlemen'),
            'mann'     => array('men', 'herren', 'männer', 'herr', 'male', 'gentleman', 'gentlemen'),
            'männer'   => array('men', 'herren', 'mann', 'herr', 'male', 'gentleman', 'gentlemen'),
            'herr'     => array('men', 'herren', 'mann', 'männer', 'male', 'gentleman', 'gentlemen'),
            'male'     => array('men', 'herren', 'mann', 'männer', 'herr', 'gentleman', 'gentlemen'),
            'gentleman' => array('men', 'herren', 'mann', 'männer', 'herr', 'male', 'gentlemen'),
            'gentlemen' => array('men', 'herren', 'mann', 'männer', 'herr', 'male', 'gentleman'),
            
            // Children/Kids terms - SYSTEMATIC German/English + Singular/Plural
            'girls'    => array('mädchen', 'girl'),
            'girl'     => array('mädchen', 'girls'),
            'mädchen'  => array('girls', 'girl'),
            'boys'     => array('jungen', 'boy', 'junge'),
            'boy'      => array('jungen', 'boys', 'junge'),
            'junge'    => array('boys', 'boy', 'jungen'),
            'jungen'   => array('boys', 'boy', 'junge'),
            'kids'     => array('kinder', 'children', 'kid', 'kind'),
            'kid'      => array('kinder', 'children', 'kids', 'kind'),
            'kind'     => array('kinder', 'children', 'kids', 'kid'),
            'kinder'   => array('kids', 'children', 'kid', 'kind'),
            'children' => array('kinder', 'kids', 'kid', 'kind'),
            
            // SYSTEMATIC Clothing Terms - German/English + Singular/Plural
            'shirt'    => array('hemd', 'shirts', 'hemden'),
            'shirts'   => array('hemd', 'shirt', 'hemden'),
            'hemd'     => array('shirt', 'shirts', 'hemden'),
            'hemden'   => array('shirt', 'shirts', 'hemd'),
            'jacket'   => array('jacke', 'jackets', 'jacken'),
            'jackets'  => array('jacke', 'jacket', 'jacken'),
            'jacke'    => array('jacket', 'jackets', 'jacken'),
            'jacken'   => array('jacket', 'jackets', 'jacke'),
            'shoe'     => array('schuh', 'shoes', 'schuhe'),
            'shoes'    => array('schuh', 'shoe', 'schuhe'),
            'schuh'    => array('shoe', 'shoes', 'schuhe'),
            'schuhe'   => array('shoe', 'shoes', 'schuh'),
            // CRITICAL: Sneaker synonyms - was missing!
            'sneaker'  => array('sneakers', 'turnschuh', 'turnschuhe', 'sportschuh', 'sportschuhe'),
            'sneakers' => array('sneaker', 'turnschuh', 'turnschuhe', 'sportschuh', 'sportschuhe'),
            'turnschuh' => array('sneaker', 'sneakers', 'turnschuhe', 'sportschuh', 'sportschuhe'),
            'turnschuhe' => array('sneaker', 'sneakers', 'turnschuh', 'sportschuh', 'sportschuhe'),
            'sportschuh' => array('sneaker', 'sneakers', 'turnschuh', 'turnschuhe', 'sportschuhe'),
            'sportschuhe' => array('sneaker', 'sneakers', 'turnschuh', 'turnschuhe', 'sportschuh'),
            'pant'     => array('hose', 'pants', 'hosen'),
            'pants'    => array('hose', 'pant', 'hosen'),
            'hose'     => array('pant', 'pants', 'hosen'),
            'hosen'    => array('pant', 'pants', 'hose'),
            'dress'    => array('kleid', 'dresses', 'kleider'),
            'dresses'  => array('kleid', 'dress', 'kleider'),
            'kleid'    => array('dress', 'dresses', 'kleider'),
            'kleider'  => array('dress', 'dresses', 'kleid'),
            'hat'      => array('hut', 'hats', 'hüte'),
            'hats'     => array('hut', 'hat', 'hüte'),
            'hut'      => array('hat', 'hats', 'hüte'),
            'hüte'     => array('hat', 'hats', 'hut'),
            'cap'      => array('mütze', 'caps', 'mützen'),
            'caps'     => array('mütze', 'cap', 'mützen'),
            'mütze'    => array('cap', 'caps', 'mützen'),
            'mützen'   => array('cap', 'caps', 'mütze'),
            
            // SYSTEMATIC Colors - German/English
            'black'    => array('schwarz'),
            'schwarz'  => array('black'),
            'white'    => array('weiß', 'weiss'),
            'weiß'     => array('white', 'weiss'),
            'weiss'    => array('white', 'weiß'),
            'red'      => array('rot'),
            'rot'      => array('red'),
            'blue'     => array('blau'),
            'blau'     => array('blue'),
            'green'    => array('grün'),
            'grün'     => array('green'),
            'yellow'   => array('gelb'),
            'gelb'     => array('yellow'),
            'brown'    => array('braun'),
            'braun'    => array('brown'),
            'gray'     => array('grau', 'grey'),
            'grey'     => array('grau', 'gray'),
            'grau'     => array('gray', 'grey'),
            
            // SYSTEMATIC Accessories - German/English + Singular/Plural
            'bag'      => array('tasche', 'bags', 'taschen'),
            'bags'     => array('tasche', 'bag', 'taschen'),
            'tasche'   => array('bag', 'bags', 'taschen'),
            'taschen'  => array('bag', 'bags', 'tasche'),
            'watch'    => array('uhr', 'watches', 'uhren'),
            'watches'  => array('uhr', 'watch', 'uhren'),
            'uhr'      => array('watch', 'watches', 'uhren'),
            'uhren'    => array('watch', 'watches', 'uhr'),
            'belt'     => array('gürtel', 'belts'),
            'belts'    => array('gürtel', 'belt'),
            'gürtel'   => array('belt', 'belts'),
            
            // Numbers - German/English/Digits as requested
            'eins'     => array('one', '1'),
            'one'      => array('eins', '1'),
            '1'        => array('eins', 'one'),
            'zwei'     => array('two', '2'),
            'two'      => array('zwei', '2'),
            '2'        => array('zwei', 'two'),
            'drei'     => array('three', '3'),
            'three'    => array('drei', '3'),
            '3'        => array('drei', 'three'),
            'vier'     => array('four', '4'),
            'four'     => array('vier', '4'),
            '4'        => array('vier', 'four'),
            'fünf'     => array('five', '5'),
            'five'     => array('fünf', '5'),
            '5'        => array('fünf', 'five'),
            'sechs'    => array('six', '6'),
            'six'      => array('sechs', '6'),
            '6'        => array('sechs', 'six'),
            'sieben'   => array('seven', '7'),
            'seven'    => array('sieben', '7'),
            '7'        => array('sieben', 'seven'),
            'acht'     => array('eight', '8'),
            'eight'    => array('acht', '8'),
            '8'        => array('acht', 'eight'),
            'neun'     => array('nine', '9'),
            'nine'     => array('neun', '9'),
            '9'        => array('neun', 'nine'),
            'zehn'     => array('ten', '10'),
            'ten'      => array('zehn', '10'),
            '10'       => array('zehn', 'ten'),

        );
        
        foreach ($original_tokens as $token) {
            if (isset($synonyms[$token])) {
                $all_tokens = array_merge($all_tokens, $synonyms[$token]);
            }
        }

        // Gender exclusion logic - Complete German and English variations + Children terms
        $male_terms = array('men', 'herren', 'mann', 'männer', 'herr', 'male', 'gentleman', 'gentlemen', 'boys', 'boy', 'junge', 'jungen');
        $female_terms = array('women', 'damen', 'frau', 'frauen', 'woman', 'dame', 'female', 'lady', 'ladies', 'girls', 'girl', 'mädchen');
        
        $search_for_male = false;
        $search_for_female = false;
        
        foreach ($original_tokens as $token) {
            if (in_array($token, $male_terms)) {
                $search_for_male = true;
                myfeeds_log("🚹 Male search detected: $token", 'debug');
            }
            if (in_array($token, $female_terms)) {
                $search_for_female = true;
                myfeeds_log("🚺 Female search detected: $token", 'debug');
            }
        }

        $results = array();
        $excluded_count = 0;
        $processed_count = 0;
        
        foreach ($data as $entry) {
            $processed_count++;
            
            // Collect all searchable content of the product
            $all_product_text = '';
            $title_text = '';
            $description_text = '';
            
            foreach ($search_fields as $field => $weight) {
                if (!empty($entry[$field])) {
                    $field_value = $this->extract_field_value($entry, $field);
                    if (is_array($field_value)) {
                        $field_value = implode(' ', $field_value);
                    }
                    $field_value_lower = strtolower($field_value);
                    $all_product_text .= ' ' . $field_value_lower;
                    
                    // Separate title and description for priority scoring
                    if ($field === 'title') {
                        $title_text = $field_value_lower;
                    } elseif (in_array($field, array('description', 'product_description', 'long_description'))) {
                        $description_text .= ' ' . $field_value_lower;
                    }
                }
            }
            
            // Apply gender exclusion - Only when searching for a specific gender
            $gender_conflict = false;
            
            if ($search_for_male && !$search_for_female) {
                // Search for male products - exclude pure female products
                $contains_female_terms = false;
                $contains_male_terms = false;
                
                // Check for female terms with WORD BOUNDARIES (not substring)
                foreach ($female_terms as $female_term) {
                    if (preg_match('/\b' . preg_quote($female_term, '/') . '\b/i', $all_product_text)) {
                        $contains_female_terms = true;
                        myfeeds_log("🚺 Found female term (word boundary): $female_term", 'debug');
                        break;
                    }
                }
                
                // Check for male terms with WORD BOUNDARIES (not substring)
                foreach ($male_terms as $male_term) {
                    if (preg_match('/\b' . preg_quote($male_term, '/') . '\b/i', $all_product_text)) {
                        $contains_male_terms = true;
                        myfeeds_log("🚹 Found male term (word boundary): $male_term", 'debug');
                        break;
                    }
                }
                
                // If only female terms found, exclude
                if ($contains_female_terms && !$contains_male_terms) {
                    $gender_conflict = true;
                    myfeeds_log("❌ Excluded female product: " . substr($entry['title'], 0, 50), 'error');
                }
            } elseif ($search_for_female && !$search_for_male) {
                // Search for female products - exclude pure male products
                $contains_male_terms = false;
                $contains_female_terms = false;
                
                // Check for male terms with WORD BOUNDARIES (not substring)
                foreach ($male_terms as $male_term) {
                    if (preg_match('/\b' . preg_quote($male_term, '/') . '\b/i', $all_product_text)) {
                        $contains_male_terms = true;
                        myfeeds_log("🚹 Found male term (word boundary): $male_term", 'debug');
                        break;
                    }
                }
                
                // Check for female terms with WORD BOUNDARIES (not substring)
                foreach ($female_terms as $female_term) {
                    if (preg_match('/\b' . preg_quote($female_term, '/') . '\b/i', $all_product_text)) {
                        $contains_female_terms = true;
                        myfeeds_log("🚺 Found female term (word boundary): $female_term", 'debug');
                        break;
                    }
                }
                
                // If only male terms found, exclude
                if ($contains_male_terms && !$contains_female_terms) {
                    $gender_conflict = true;
                    myfeeds_log("❌ Excluded male product: " . substr($entry['title'], 0, 50), 'error');
                }
            }
            
            // Skip products with gender conflict
            if ($gender_conflict) {
                $excluded_count++;
                continue;
            }
            
            // ENHANCED SMART SEARCH: Flexible keyword matching without restrictive first keyword rule
            // All keywords must be found somewhere (title, description, brand, etc.) for maximum flexibility
            if (count($original_tokens) > 0) {
                $all_keywords_found = true;
                $missing_keywords = array();
                
                myfeeds_log("🔍 FLEXIBLE SEARCH: Checking all keywords: " . implode(', ', $original_tokens), 'debug');
                
                foreach ($original_tokens as $keyword) {
                    $keyword_found_somewhere = false;
                    
                    // Check in all searchable fields (title, description, brand, etc.)
                    foreach ($search_fields as $field => $weight) {
                        if (!empty($entry[$field])) {
                            $field_value = strtolower($this->extract_field_value($entry, $field));
                            if (is_array($field_value)) {
                                $field_value = implode(' ', $field_value);
                            }
                            
                            // Check exact keyword match
                            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $field_value)) {
                                $keyword_found_somewhere = true;
                                myfeeds_log("✅ KEYWORD '$keyword' found in $field: " . substr($field_value, 0, 30), 'info');
                                break;
                            }
                            
                            // Check synonyms
                            if (isset($synonyms[$keyword])) {
                                foreach ($synonyms[$keyword] as $synonym) {
                                    if (preg_match('/\b' . preg_quote($synonym, '/') . '\b/i', $field_value)) {
                                        $keyword_found_somewhere = true;
                                        myfeeds_log("✅ SYNONYM '$synonym' (for '$keyword') found in $field: " . substr($field_value, 0, 30), 'info');
                                        break 2;
                                    }
                                }
                            }
                        }
                    }
                    
                    if (!$keyword_found_somewhere) {
                        $all_keywords_found = false;
                        $missing_keywords[] = $keyword;
                        myfeeds_log("❌ MISSING KEYWORD: '$keyword' not found anywhere in product", 'error');
                    }
                }
                
                if (!$all_keywords_found) {
                    myfeeds_log("❌ PRODUCT REJECTED: Missing keywords: " . implode(', ', $missing_keywords) . " in product: " . substr($entry['title'], 0, 50), 'error');
                    continue; // Skip this product - not all keywords found
                }
                
                myfeeds_log("✅ ALL KEYWORDS FOUND: Product accepted for scoring: " . substr($entry['title'], 0, 50), 'info');
            }
            
            // Rule: At least 50% of keywords must be found, with high preference for title matches
            $min_required_matches = ceil(count($original_tokens) * 0.5); // At least 50% of keywords
            
            // For single keyword searches, it must be found somewhere (title, brand, or description)
            // ENHANCED: Support both word boundary AND substring "contains" matching
            if (count($original_tokens) === 1) {
                $single_keyword = $original_tokens[0];
                $found_somewhere = false;
                
                myfeeds_log("🔍 ENHANCED SINGLE WORD SEARCH: '$single_keyword' - checking word boundary AND substring contains", 'debug');
                
                // Check in title, brand, and all searchable fields
                foreach ($search_fields as $field => $weight) {
                    if (!empty($entry[$field])) {
                        $field_value = strtolower($this->extract_field_value($entry, $field));
                        if (is_array($field_value)) {
                            $field_value = implode(' ', $field_value);
                        }
                        
                        // METHOD 1: Check exact keyword with word boundaries (highest priority)
                        if (preg_match('/\b' . preg_quote($single_keyword, '/') . '\b/i', $field_value)) {
                            $found_somewhere = true;
                            myfeeds_log("✅ WORD BOUNDARY match for '$single_keyword' in $field: " . substr($field_value, 0, 50), 'info');
                            break;
                        }
                        
                        // METHOD 2: NEW - Check "contains" substring match (e.g., "suit" in "Bodysuit")
                        if (strpos($field_value, $single_keyword) !== false) {
                            $found_somewhere = true;
                            myfeeds_log("✅ SUBSTRING CONTAINS match for '$single_keyword' in $field: " . substr($field_value, 0, 50), 'info');
                            break;
                        }
                        
                        // METHOD 3: Check synonyms with word boundaries
                        if (isset($synonyms[$single_keyword])) {
                            foreach ($synonyms[$single_keyword] as $synonym) {
                                if (preg_match('/\b' . preg_quote($synonym, '/') . '\b/i', $field_value)) {
                                    $found_somewhere = true;
                                    myfeeds_log("✅ SYNONYM WORD BOUNDARY match for '$synonym' (from '$single_keyword') in $field: " . substr($field_value, 0, 50), 'info');
                                    break 2;
                                }
                                
                                // METHOD 4: NEW - Check synonym "contains" substring match  
                                if (strpos($field_value, strtolower($synonym)) !== false) {
                                    $found_somewhere = true;
                                    myfeeds_log("✅ SYNONYM SUBSTRING match for '$synonym' (from '$single_keyword') in $field: " . substr($field_value, 0, 50), 'info');
                                    break 2;
                                }
                            }
                        }
                    }
                }
                
                if (!$found_somewhere) {
                    myfeeds_log("❌ SINGLE WORD '$single_keyword' not found anywhere in product: " . substr($entry['title'], 0, 50), 'error');
                    continue; // Skip this product
                }
            }
            
            // Check if we have enough keyword matches (for multi-keyword searches)
            $keywords_found = 0;
            $title_keyword_matches = 0;
            $title_synonym_matches = 0;
            $brand_matches = 0;
            $description_matches = 0;
            
            foreach ($original_tokens as $original_token) {
                $token_found = false;
                
                // Check the original token in title first (highest priority)
                if (preg_match('/\b' . preg_quote($original_token, '/') . '\b/i', $title_text)) {
                    $token_found = true;
                    $title_keyword_matches++;
                }
                
                // Check in brand field (high priority for brand names like "nike")
                if (!$token_found && !empty($entry['brand'])) {
                    $brand_text = strtolower($entry['brand']);
                    if (preg_match('/\b' . preg_quote($original_token, '/') . '\b/i', $brand_text)) {
                        $token_found = true;
                        $brand_matches++;
                    }
                }
                
                // If not found, check synonyms in title
                if (!$token_found && isset($synonyms[$original_token])) {
                    foreach ($synonyms[$original_token] as $synonym) {
                        if (preg_match('/\b' . preg_quote($synonym, '/') . '\b/i', $title_text)) {
                            $token_found = true;
                            $title_synonym_matches++;
                            break;
                        }
                    }
                }
                
                // Check in other searchable fields
                if (!$token_found) {
                    foreach ($search_fields as $field => $weight) {
                        if ($field === 'title' || empty($entry[$field])) continue;
                        
                        $field_value = strtolower($this->extract_field_value($entry, $field));
                        if (is_array($field_value)) {
                            $field_value = implode(' ', $field_value);
                        }
                        
                        if (preg_match('/\b' . preg_quote($original_token, '/') . '\b/i', $field_value)) {
                            $token_found = true;
                            if (in_array($field, array('description', 'product_description', 'long_description'))) {
                                $description_matches++;
                            }
                            break;
                        }
                        
                        // Check synonyms in other fields
                        if (!$token_found && isset($synonyms[$original_token])) {
                            foreach ($synonyms[$original_token] as $synonym) {
                                if (preg_match('/\b' . preg_quote($synonym, '/') . '\b/i', $field_value)) {
                                    $token_found = true;
                                    if (in_array($field, array('description', 'product_description', 'long_description'))) {
                                        $description_matches++;
                                    }
                                    break 2;
                                }
                            }
                        }
                    }
                }
                
                if ($token_found) {
                    $keywords_found++;
                }
            }
            
            // Only include products with enough keyword matches
            if ($keywords_found >= $min_required_matches) {
                // Calculate sophisticated priority score (Google-style)
                $score = 0;
                
                // PRIORITY 1: All keywords in title = 1000 points
                if ($title_keyword_matches === count($original_tokens)) {
                    $score += 1000;
                    myfeeds_log("🏆 PRIORITY 1: All keywords in title - " . substr($entry['title'], 0, 50), 'debug');
                }
                // PRIORITY 2: Mix of title keywords and title synonyms = 800 points
                elseif (($title_keyword_matches + $title_synonym_matches) === count($original_tokens)) {
                    $score += 800;
                    myfeeds_log("🥈 PRIORITY 2: Keywords + synonyms in title - " . substr($entry['title'], 0, 50), 'debug');
                }
                // PRIORITY 3: Some keywords in title + brand matches = 600 points
                elseif ($title_keyword_matches > 0 && $brand_matches > 0) {
                    $score += 600;
                    myfeeds_log("🥉 PRIORITY 3: Title + brand matches - " . substr($entry['title'], 0, 50), 'debug');
                }
                // PRIORITY 4: Title + description mix = 400 points
                elseif ($title_keyword_matches > 0 || $title_synonym_matches > 0) {
                    $score += 400;
                    myfeeds_log("📝 PRIORITY 4: Title + other matches - " . substr($entry['title'], 0, 50), 'debug');
                }
                // PRIORITY 5: Other combinations = 200 points
                else {
                    $score += 200;
                    myfeeds_log("📋 PRIORITY 5: Other combinations - " . substr($entry['title'], 0, 50), 'debug');
                }
                
                // Bonus points for specific match types
                $score += $title_keyword_matches * 100;  // Big bonus for title keywords
                $score += $title_synonym_matches * 50;   // Medium bonus for title synonyms
                $score += $brand_matches * 75;           // Good bonus for brand matches
                $score += $description_matches * 10;     // Small bonus for description
                
                // Bonus for high keyword match percentage
                $match_percentage = (count($original_tokens) > 0) ? ($keywords_found / count($original_tokens)) * 100 : 0;
                $score += $match_percentage;
                
                if ($score > 0) {
                    $results[] = array('product' => $entry, 'score' => $score);
                    myfeeds_log("✅ Added product with score $score: " . substr($entry['title'], 0, 50), 'info');
                }
            } else {
                myfeeds_log("❌ Rejected product - not enough keywords ($keywords_found/" . count($original_tokens) . "): " . substr($entry['title'], 0, 50), 'error');
            }
        }

        myfeeds_log("📊 Search stats - Processed: $processed_count, Gender-excluded: $excluded_count, Results: " . count($results), 'info');

        usort($results, function($a, $b){ return $b['score'] - $a['score']; });
        $top = array_slice($results, 0, 50);
        $final = array_map(function($e){ return $e['product']; }, $top);
        return rest_ensure_response($final);
    }
    
    public function rest_get_single_product(WP_REST_Request $request) {
        $id = sanitize_text_field($request->get_param('id'));
        $color = sanitize_text_field($request->get_param('color'));
        $image_url = sanitize_text_field($request->get_param('image_url'));
        
        if (!$id) {
            return new WP_Error('missing_id', 'Missing product ID', array('status' => 400));
        }
        
        // =====================================================================
        // DB MODE: Direct query by external_id
        // =====================================================================
        if (class_exists('MyFeeds_DB_Manager') && MyFeeds_DB_Manager::is_db_mode()) {
            $product = MyFeeds_DB_Manager::get_product((string) $id);
            if ($product) {
                return rest_ensure_response($product);
            }
            return new WP_Error('not_found', 'Product not found', array('status' => 404));
        }
        
        // =====================================================================
        // JSON MODE: Original file-based lookup (unchanged below)
        // =====================================================================
        $this->log_api_debug('single_product_request', array(
            'requested_id' => $id,
            'color' => $color,
            'image_url' => $image_url ? substr($image_url, 0, 50) : '',
        ));
        
        $index_path = wp_upload_dir()['basedir'] . '/' . self::INDEX_FILE;
        
        if (!file_exists($index_path)) {
            $this->log_api_debug('single_product_error', array(
                'requested_id' => $id,
                'error' => 'index_not_found',
            ));
            return new WP_Error('missing_index', 'Product index not found', array('status' => 404));
        }
        
        $json = json_decode(file_get_contents($index_path), true);
        $items = isset($json['items']) ? $json['items'] : array();
        
        $this->log_api_debug('single_product_index_loaded', array(
            'requested_id' => $id,
            'index_items_count' => count($items),
        ));
        
        // SIMPLE FIX: Direct key access with string casting
        $search_id = (string)$id;
        
        // First try: Direct key access (fastest)
        if (isset($items[$search_id])) {
            $found = $items[$search_id];
            $this->log_api_debug('single_product_found', array(
                'requested_id' => $id,
                'found_via' => 'direct_key',
                'found_id' => $found['id'] ?? 'MISSING',
                'found_title' => isset($found['title']) ? substr($found['title'], 0, 30) : '',
            ));
            return $found;
        }
        
        // Second try: Search through values (in case key structure is different)
        foreach ($items as $key => $product) {
            if (isset($product['id']) && (string)$product['id'] === $search_id) {
                $this->log_api_debug('single_product_found', array(
                    'requested_id' => $id,
                    'found_via' => 'loop_search',
                    'found_key' => $key,
                    'found_id' => $product['id'],
                    'found_title' => isset($product['title']) ? substr($product['title'], 0, 30) : '',
                ));
                return $product;
            }
        }
        
        $this->log_api_debug('single_product_NOT_FOUND', array(
            'requested_id' => $id,
            'search_id' => $search_id,
            'first_5_keys' => array_slice(array_keys($items), 0, 5),
        ));
        
        return new WP_Error('not_found', 'Product not found', array('status' => 404));
    }
    
    /**
     * Safe debug logging for API
     */
    private function log_api_debug($event, $data) {
        $message = 'MYFEEDS_API_' . $event . ': ' . wp_json_encode($data, JSON_UNESCAPED_SLASHES);
        myfeeds_log($message, 'debug');
    }
    
    /**
     * Rebuild product index with all feeds
     */
    public function rebuild_feed_index() {
        myfeeds_log('🔄 Rebuilding feed index...', 'info');

        $feeds = get_option(self::OPTION_KEY, array());
        $items = array();
        $seen = array();

        $search_fields = array(
            'title'               => 3,
            'brand'               => 2,
            'shopname'            => 1,
            'attributes.color'    => 2,
            'attributes.material' => 1,
            'attributes.style'    => 1,
            'attributes.occasion' => 1,
        );

        foreach ($feeds as $feed_key => $feed) {
            myfeeds_log("📥 Processing feed: " . $feed['name'], 'debug');
            
            $resp = wp_remote_get($feed['url'], array('timeout' => 30));
            if (is_wp_error($resp)) {
                myfeeds_log('❌ Feed error: ' . $feed['url'] . ' - ' . $resp->get_error_message(), 'error');
                continue;
            }

            $body = wp_remote_retrieve_body($resp);
            if (substr($body, 0, 2) === "\x1f\x8b") {
                $decoded = @gzdecode($body);
                if ($decoded !== false) $body = $decoded;
            }

            // Write to temp file and use Feed Reader for multi-format support
            $tmp_path = wp_tempnam('myfeeds_rebuild_');
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temp file for Feed Reader
            file_put_contents($tmp_path, $body);
            unset($body);
            
            $reader = new MyFeeds_Feed_Reader();
            if (!$reader->open($tmp_path, $feed['detected_format'] ?? '')) {
                wp_delete_file($tmp_path);
                myfeeds_log('Feed Reader cannot open feed: ' . $feed['name'], 'error');
                continue;
            }
            
            $product_count = 0;
            
            while (($raw = $reader->read_next()) !== false) {
                // Apply stored mapping to raw data
                $mapped = $this->map_product($raw, $feed['mapping']);

                // SINGLE SOURCE OF TRUTH: Process all critical fields from raw data
                $mapped = $this->process_critical_fields($mapped, $raw);

                // Add additional fields for exact matching
                if (isset($mapped['attributes']['color'][0])) {
                    $mapped['color'] = $mapped['attributes']['color'][0];
                }

                $dedup_key = strtolower(trim(is_array($mapped['image_url'] ?? '') ? ($mapped['image_url'][0] ?? '') : (string)($mapped['image_url'] ?? '')));

                if (!$dedup_key && !empty($mapped['id'])) {
                    $color = strtolower(trim(is_array($mapped['attributes']['color'][0] ?? '') ? '' : (string)($mapped['attributes']['color'][0] ?? '')));
                    $image_hash = md5(isset($mapped['image_url']) ? $mapped['image_url'] : '');
                    $dedup_key = $mapped['id'] . '|' . $color . '|' . $image_hash;
                }

                if (!$dedup_key || isset($seen[$dedup_key])) continue;
                $seen[$dedup_key] = true;

                if (!empty($mapped['id'])) {
                    $mapped['feed_name'] = $feed['name'];
                    $items[(string)$mapped['id']] = $mapped;
                    $product_count++;
                }
            }
            
            $reader->close();
            wp_delete_file($tmp_path);
            
            // Update feed product count
            $feeds[$feed_key]['product_count'] = $product_count;
            $feeds[$feed_key]['last_sync'] = current_time('mysql');
            
            myfeeds_log("✅ Processed $product_count products from feed: " . $feed['name'], 'info');
        }

        // Save updated feeds with product counts
        update_option(self::OPTION_KEY, $feeds);

        // DB mode: write products to DB instead of JSON file
        if (class_exists('MyFeeds_DB_Manager') && MyFeeds_DB_Manager::is_db_mode()) {
            if (!empty($items)) {
                MyFeeds_DB_Manager::upsert_batch($items, 0, '');
            }
            myfeeds_log('Feed index written to DB (' . count($items) . ' total items)', 'info');
        } else {
            $upload = wp_upload_dir();
            $path = $upload['basedir'] . '/' . self::INDEX_FILE;

            $output = array(
                '__search_fields' => $search_fields,
                'items' => $items,
            );

            file_put_contents($path, json_encode($output));
            myfeeds_log('Feed index written: ' . $path . ' (' . count($items) . ' total items)', 'info');
        }
        
        // CRITICAL: Clear all product transient caches after rebuild
        // This ensures fresh data is loaded on next page view
        $this->clear_product_cache();
    }
    
    /**
     * Clear all myfeeds product transient caches
     * Called after feed index rebuild to ensure fresh data
     */
    private function clear_product_cache() {
        global $wpdb;
        
        // Delete all transients starting with 'myfeeds_product_'
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_myfeeds_product_%' 
             OR option_name LIKE '_transient_timeout_myfeeds_product_%'"
        );
        
        myfeeds_log('🧹 Cleared all myfeeds product caches', 'info');
    }
    
    /**
     * SINGLE SOURCE OF TRUTH: Process all critical fields from raw CSV data
     * This method handles: ID, Title, Images, Brand, Merchant, Prices, Discounts, Shipping
     * 
     * Architecture: Instead of scattered logic, all field processing happens here
     * This ensures consistency and makes future network additions simple
     */
    /**
     * Parse a price value that may contain currency text.
     * Handles: "2.00", "2.00 EUR", "EUR 2.00", "2,00", "$19.99"
     */
    private static function parse_price_value($value) {
        // Array safety (XML feeds may return arrays for repeated elements)
        if (is_array($value)) {
            $value = isset($value[0]) ? $value[0] : '';
        }
        if (is_numeric($value)) {
            return floatval($value);
        }
        if (!is_string($value) || trim($value) === '') {
            return 0;
        }
        $value = preg_replace('/[€$£¥₹]/', '', $value);
        $value = preg_replace('/\b(EUR|USD|GBP|CHF|AED|SAR|EGP|JPY|CNY|INR|AUD|CAD|SEK|NOK|DKK|PLN|CZK|HUF|RON|BGN|HRK|TRY|BRL|MXN|KRW)\b/i', '', $value);
        $value = trim($value);
        // Handle comma as decimal separator (European format: 2,00)
        if (preg_match('/^\d+,\d{2}$/', $value)) {
            $value = str_replace(',', '.', $value);
        }
        if (preg_match('/(\d+\.?\d*)/', $value, $matches)) {
            return floatval($matches[1]);
        }
        return 0;
    }
    
    /**
     * Safely get a string value from a raw field that may be an array.
     * Arrays (from XML repeated elements) return first element.
     * Strings return trimmed string.
     */
    private static function safe_string_value($value) {
        if (is_array($value)) {
            return isset($value[0]) ? trim((string) $value[0]) : '';
        }
        return trim((string) $value);
    }
    
    public function process_critical_fields(array $mapped, array $raw) {
        
        // ============================================================
        // 1. PRODUCT IDENTITY (ID, Title, Link, Images)
        // ============================================================
        
        // ID
        if (empty($mapped['id'])) {
            $id_fields = array('aw_product_id', 'product_id', 'id', 'sku');
            foreach ($id_fields as $f) {
                if (!empty($raw[$f])) { $mapped['id'] = is_array($raw[$f]) ? (string)$raw[$f][0] : (string)$raw[$f]; break; }
            }
        }
        
        // Title
        if (empty($mapped['title'])) {
            $title_fields = array('product_name', 'title', 'name', 'n');
            foreach ($title_fields as $f) {
                if (!empty($raw[$f])) { $mapped['title'] = self::safe_string_value($raw[$f]); break; }
            }
        }
        
        // Affiliate Link
        if (empty($mapped['affiliate_link'])) {
            $link_fields = array('aw_deep_link', 'merchant_deep_link', 'deep_link', 'product_url', 'link', 'url');
            foreach ($link_fields as $f) {
                if (!empty($raw[$f])) { $mapped['affiliate_link'] = is_array($raw[$f]) ? $raw[$f][0] : $raw[$f]; break; }
            }
        }
        
        // Main Image
        if (empty($mapped['image_url'])) {
            $img_fields = array('merchant_image_url', 'aw_image_url', 'large_image', 'image_url', 'image', 'image_link', 'picture');
            foreach ($img_fields as $f) {
                if (!empty($raw[$f])) { $mapped['image_url'] = is_array($raw[$f]) ? $raw[$f][0] : $raw[$f]; break; }
            }
        }
        
        // Additional Images
        if (empty($mapped['additional_images'])) {
            $imgs = array();
            $add_img_fields = array('alternate_image', 'alternate_image_two', 'alternate_image_three', 'alternate_image_four', 'additional_image_link');
            foreach ($add_img_fields as $f) {
                if (!empty($raw[$f])) $imgs[] = $raw[$f];
            }
            // Handle repeated <picture> elements (array from XML Feed Reader)
            if (!empty($raw['picture']) && is_array($raw['picture'])) {
                foreach ($raw['picture'] as $pic) {
                    if (!empty($pic)) $imgs[] = $pic;
                }
            }
            if (!empty($imgs)) $mapped['additional_images'] = array_values(array_unique($imgs));
        }
        
        // ============================================================
        // 2. BRAND & MERCHANT (Shop Name)
        // ============================================================
        
        // Brand
        if (empty($mapped['brand'])) {
            $brand_fields = array('brand_name', 'brand', 'manufacturer', 'vendor');
            foreach ($brand_fields as $f) {
                if (!empty($raw[$f])) { $mapped['brand'] = self::safe_string_value($raw[$f]); break; }
            }
        }
        
        // Merchant/Shop Name - ALWAYS get from raw data, ignore bad mappings
        $merchant_fields = array('merchant_name', 'advertiser_name', 'shop_name', 'shopname', 'store_name', 'retailer_name', 'seller_name', 'program_name');
        foreach ($merchant_fields as $f) {
            if (!empty($raw[$f])) {
                $val = self::safe_string_value($raw[$f]);
                // Only use if NOT an ID (reject pure numbers or number+letter codes)
                if (!preg_match('/^\d+$/', $val) && !preg_match('/^\d+[A-Z]+$/i', $val)) {
                    $mapped['merchant'] = $val;
                    $mapped['shopname'] = $val;
                    break;
                }
            }
        }
        
        // ============================================================
        // 3. PRICES (Current Price, Old Price/RRP)
        // ============================================================
        
        // Current Price - ALWAYS get from raw data first
        $price = 0;
        $price_fields = array('search_price', 'store_price', 'price', 'display_price', 'current_price', 'sale_price');
        foreach ($price_fields as $f) {
            if (!empty($raw[$f])) {
                $val = self::parse_price_value($raw[$f]);
                if ($val > 0) { 
                    $price = $val;
                    $mapped['price'] = $price; 
                    break; 
                }
            }
        }
        // Fallback to mapped price if raw didn't have it
        if ($price <= 0 && isset($mapped['price'])) {
            $price = self::parse_price_value($mapped['price']);
        }
        
        // Old Price (RRP) - ALWAYS get from raw data
        $old_price = 0;
        $old_price_fields = array('rrp_price', 'product_price_old', 'rrp', 'msrp', 'list_price', 'original_price', 'was_price', 'oldprice');
        foreach ($old_price_fields as $f) {
            if (!empty($raw[$f])) {
                $val = self::parse_price_value($raw[$f]);
                if ($val > 0 && $val > $price) {
                    $old_price = $val;
                    $mapped['old_price'] = round($val, 2);
                    break;
                }
            }
        }
        
        // SECTION 3b: Google Shopping price logic
        // In Google Shopping feeds: price = RRP, sale_price = current price
        // Detect: If sale_price exists AND is LOWER than price, swap them
        if (!empty($raw['sale_price']) && !empty($raw['price'])) {
            $raw_sale = self::parse_price_value($raw['sale_price']);
            $raw_price = self::parse_price_value($raw['price']);
            
            if ($raw_sale > 0 && $raw_price > 0 && $raw_sale < $raw_price) {
                $mapped['price'] = $raw_sale;
                $mapped['old_price'] = $raw_price;
                $price = $raw_sale;
                $old_price = $raw_price;
            }
        }
        
        // ============================================================
        // 4. DISCOUNT CALCULATION
        // ============================================================
        
        // Step A: Try to get discount percentage directly from feed
        $discount_pct = 0;
        $discount_fields = array('savings_percent', 'saving', 'discount', 'discount_percent', 'discount_percentage');
        foreach ($discount_fields as $f) {
            if (!empty($raw[$f])) {
                $val = floatval($raw[$f]);
                if ($val > 0 && $val < 100) {
                    $discount_pct = round($val);
                    break;
                }
            }
        }
        
        // Step B: If we have discount but no old_price, calculate old_price
        if ($discount_pct > 0 && $discount_pct < 100 && $old_price <= 0 && $price > 0) {
            $old_price = $price / (1 - $discount_pct / 100);
            $mapped['old_price'] = round($old_price, 2);
        }
        
        // Step C: If we have old_price but no discount, calculate discount
        if ($discount_pct <= 0 && $old_price > $price && $price > 0) {
            $discount_pct = round((($old_price - $price) / $old_price) * 100);
        }
        
        // Step D: Store discount percentage
        if ($discount_pct > 0 && $discount_pct < 100) {
            $mapped['discount_percentage'] = $discount_pct;
        }
        
        // ============================================================
        // 5. OTHER FIELDS (Currency, Shipping, Category, Color)
        // ============================================================
        
        // Currency — first try dedicated fields, then extract from price string
        if (empty($mapped['currency'])) {
            $curr_fields = array('currency', 'currencyId');
            foreach ($curr_fields as $f) {
                if (!empty($raw[$f])) { $mapped['currency'] = self::safe_string_value($raw[$f]); break; }
            }
            // If still empty, try to extract currency code from price or sale_price string
            if (empty($mapped['currency'])) {
                $price_string_fields = array('price', 'sale_price', 'search_price');
                foreach ($price_string_fields as $psf) {
                    if (!empty($raw[$psf]) && is_string($raw[$psf])) {
                        if (preg_match('/\b(EUR|USD|GBP|CHF|AED|SAR|EGP|JPY|CNY|INR|AUD|CAD|SEK|NOK|DKK|PLN|CZK|HUF|RON|BGN|HRK|TRY|BRL|MXN|KRW)\b/i', $raw[$psf], $curr_match)) {
                            $mapped['currency'] = strtoupper($curr_match[1]);
                            break;
                        }
                    }
                }
            }
            if (empty($mapped['currency'])) {
                $mapped['currency'] = 'EUR'; // Default
            }
        }
        
        // Shipping — includes shipping_price for flattened XML nested elements (e.g. <g:shipping><g:price>)
        if (empty($mapped['shipping'])) {
            $ship_fields = array('delivery_cost', 'shipping_cost', 'shipping_price', 'shipping');
            foreach ($ship_fields as $f) {
                if (!empty($raw[$f])) {
                    $val = is_array($raw[$f]) ? $raw[$f][0] : $raw[$f];
                    // Skip empty strings (parent XML elements with only children have empty string value)
                    if (trim((string) $val) !== '') {
                        $mapped['shipping'] = $val;
                        break;
                    }
                }
            }
        }
        
        // Shipping Text
        if (!isset($mapped['shipping_text'])) {
            $ship_val = isset($mapped['shipping']) ? $mapped['shipping'] : '';
            if (empty($ship_val)) {
                $mapped['shipping_text'] = 'Shipping costs may apply';
            } elseif (is_numeric($ship_val) && floatval($ship_val) == 0) {
                $mapped['shipping_text'] = 'Free Shipping';
            } elseif (is_numeric($ship_val)) {
                $mapped['shipping_text'] = 'Shipping: ' . number_format(floatval($ship_val), 2) . ' ' . ($mapped['currency'] ?? 'EUR');
            } else {
                $mapped['shipping_text'] = 'Shipping costs may apply';
            }
        }
        
        // Category
        if (empty($mapped['category'])) {
            $cat_fields = array('category_name', 'category', 'product_type', 'categoryId', 'google_product_category_text');
            foreach ($cat_fields as $f) {
                if (!empty($raw[$f])) { $mapped['category'] = is_array($raw[$f]) ? $raw[$f][0] : $raw[$f]; break; }
            }
        }
        
        // Color attribute
        if (empty($mapped['attributes']['color'])) {
            $color_fields = array('colour', 'color', 'Fashion:swatch', 'Fashion:colour');
            foreach ($color_fields as $f) {
                if (!empty($raw[$f])) {
                    $mapped['attributes']['color'] = is_array($raw[$f]) ? $raw[$f] : array($raw[$f]);
                    break;
                }
            }
        }
        
        // Description
        if (empty($mapped['description'])) {
            $desc_fields = array('product_short_description', 'description', 'long_description');
            foreach ($desc_fields as $f) {
                if (!empty($raw[$f])) { $mapped['description'] = is_array($raw[$f]) ? implode(' ', $raw[$f]) : $raw[$f]; break; }
            }
        }
        
        // Parse combined param field (Admitad CSV format: "size:28|gender:male")
        if (!empty($raw['param']) && is_string($raw['param'])) {
            $param_pairs = explode('|', $raw['param']);
            foreach ($param_pairs as $pair) {
                $parts = explode(':', $pair, 2);
                if (count($parts) === 2) {
                    $param_key = trim($parts[0]);
                    $param_val = trim($parts[1]);
                    
                    if ($param_key === 'size' && empty($mapped['attributes']['size'])) {
                        $mapped['attributes']['size'] = array($param_val);
                    }
                    if ($param_key === 'gender' && empty($mapped['attributes']['gender'])) {
                        $mapped['attributes']['gender'] = array($param_val);
                    }
                    if (in_array($param_key, array('color', 'colour'), true) && empty($mapped['attributes']['color'])) {
                        $mapped['attributes']['color'] = array($param_val);
                    }
                    if ($param_key === 'material' && empty($mapped['attributes']['material'])) {
                        $mapped['attributes']['material'] = array($param_val);
                    }
                }
            }
        }
        
        // Check param_* fields from XML Feed Reader (Phase 1 YML flattening)
        $param_attr_map = array(
            'param_size' => 'size',
            'param_gender' => 'gender',
            'param_color' => 'color',
            'param_colour' => 'color',
            'param_material' => 'material',
        );
        foreach ($param_attr_map as $raw_key => $attr_key) {
            if (!empty($raw[$raw_key]) && empty($mapped['attributes'][$attr_key])) {
                $mapped['attributes'][$attr_key] = array($raw[$raw_key]);
            }
        }
        
        return $mapped;
    }
    
    /**
     * Enrich mapped product with fallbacks
     * @deprecated Use process_critical_fields instead - kept for backwards compatibility
     */
    private function enrich_mapped_product(array $mapped, array $raw) {
        // ID fallback
        if (empty($mapped['id']) && !empty($raw['aw_product_id'])) {
            $mapped['id'] = (string) $raw['aw_product_id'];
        }

        // Title fallback
        if (empty($mapped['title']) && !empty($raw['product_name'])) {
            $mapped['title'] = self::safe_string_value($raw['product_name']);
        }

        // Affiliate link fallback
        if (empty($mapped['affiliate_link'])) {
            if (!empty($raw['aw_deep_link'])) {
                $mapped['affiliate_link'] = $raw['aw_deep_link'];
            } elseif (!empty($raw['merchant_deep_link'])) {
                $mapped['affiliate_link'] = $raw['merchant_deep_link'];
            }
        }

        // Image fallbacks
        if (empty($mapped['image_url'])) {
            $image_fields = array('merchant_image_url','aw_image_url','large_image','aw_thumb_url','merchant_thumb_url');
            foreach ($image_fields as $ik) {
                if (!empty($raw[$ik])) { 
                    $mapped['image_url'] = $raw[$ik]; 
                    break; 
                }
            }
        }
        
        // Additional images
        if (empty($mapped['additional_images'])) {
            $imgs = array();
            $additional_image_fields = array('alternate_image','alternate_image_two','alternate_image_three','alternate_image_four','aw_image_url','large_image','merchant_thumb_url','additional_image_link');
            foreach ($additional_image_fields as $ak) {
                if (!empty($raw[$ak])) $imgs[] = $raw[$ak];
            }
            if (!empty($imgs)) { 
                $mapped['additional_images'] = array_values(array_unique($imgs)); 
            }
        }

        // Brand fallback
        if (empty($mapped['brand'])) {
            $brand_fields = array('brand_name','brand');
            foreach ($brand_fields as $bk) {
                if (!empty($raw[$bk])) { 
                    $mapped['brand'] = $raw[$bk]; 
                    break; 
                }
            }
        }

        // Merchant/Shop Name - ALWAYS use the correct field, ignore bad mappings
        // This runs for ALL products, regardless of what the stored mapping says
        // Priority: merchant_name > advertiser_name > shop_name > existing value (if valid)
        $merchant_name_fields = array(
            'merchant_name',      // AWIN standard - most common
            'advertiser_name',    // Alternative AWIN field
            'shop_name',          // Generic
            'shopname',           // Alternative
            'store_name',         // TradeDoubler, generic
            'retailer_name',      // Generic
            'vendor_name',        // Amazon, generic
            'seller_name',        // eBay, generic
        );
        
        $found_merchant_name = '';
        foreach ($merchant_name_fields as $field) {
            if (!empty($raw[$field])) {
                $value = self::safe_string_value($raw[$field]);
                // Make sure it's not an ID (pure numbers or number+letters code)
                if (!preg_match('/^\d+$/', $value) && !preg_match('/^\d+[A-Z]+$/i', $value)) {
                    $found_merchant_name = $value;
                    break;
                }
            }
        }
        
        // Always use the found merchant name if we have one
        if (!empty($found_merchant_name)) {
            $mapped['merchant'] = $found_merchant_name;
        }
        
        // Sync shopname with merchant
        if (!empty($mapped['merchant'])) {
            $mapped['shopname'] = $mapped['merchant'];
        }

        // Currency fallback
        if (empty($mapped['currency']) && !empty($raw['currency'])) {
            $mapped['currency'] = $raw['currency'];
        }

        // Shipping fallback
        if (empty($mapped['shipping'])) {
            $shipping_fields = array('delivery_cost','shipping_cost');
            foreach ($shipping_fields as $sk) {
                if (isset($raw[$sk])) { 
                    $mapped['shipping'] = $raw[$sk]; 
                    break; 
                }
            }
        }

        // Color attribute fallback
        if (empty($mapped['attributes']['color'])) {
            $color = '';
            $color_fields = array('colour','color','Fashion:swatch','Fashion:colour');
            foreach ($color_fields as $ck) {
                if (!empty($raw[$ck])) { 
                    $color = $raw[$ck]; 
                    break; 
                }
            }
            if (!empty($color)) {
                $mapped['attributes']['color'] = is_array($color) ? $color : array($color);
            }
        }

        // Price normalization
        $price_now = isset($mapped['price']) ? floatval($mapped['price']) : 0;
        if ($price_now <= 0) {
            $price_fields = array('search_price','store_price','price');
            foreach ($price_fields as $pk) {
                if (!empty($raw[$pk])) { 
                    $price_now = floatval($raw[$pk]); 
                    break; 
                }
            }
            if ($price_now > 0) $mapped['price'] = $price_now;
        }
        
        $price_old = isset($mapped['old_price']) ? floatval($mapped['old_price']) : 0;
        if ($price_old <= 0) {
            $old_price_fields = array('product_price_old','rrp_price','rrp','msrp','list_price');
            foreach ($old_price_fields as $opk) {
                if (!empty($raw[$opk])) { 
                    $price_old = floatval($raw[$opk]); 
                    break; 
                }
            }
        }
        
        // DISCOUNT CALCULATION - Universal logic for ALL affiliate networks
        // Step 1: Try to get discount percentage directly from feed
        $pct = 0;
        $discount_fields = array('savings_percent', 'saving', 'discount', 'discount_percent', 'discount_percentage');
        foreach ($discount_fields as $dk) {
            if (!empty($raw[$dk])) { 
                $pct = floatval($raw[$dk]); 
                break; 
            }
        }
        
        // Step 2: Calculate old_price from discount if we have discount but no old_price
        if ($price_old <= 0 && $pct > 0 && $price_now > 0 && $pct < 100) {
            $price_old = $price_now / (1 - $pct / 100);
        }
        
        // Step 3: If we have both prices, calculate discount if not already set
        if ($pct <= 0 && $price_old > $price_now && $price_now > 0) {
            $pct = (($price_old - $price_now) / $price_old) * 100;
        }
        
        // Step 4: Store all discount-related data
        if ($price_old > $price_now && $price_now > 0) {
            $mapped['old_price'] = round($price_old, 2);
            $mapped['sale_price'] = $price_now;
        }
        
        // CRITICAL: Always store discount_percentage when we have a valid discount
        if ($pct > 0 && $pct < 100) {
            $mapped['discount_percentage'] = round($pct);
        }

        return $mapped;
    }
    
    /**
     * Map product using mapping configuration
     */
    public function map_product(array $item, array $mapping) {
        $m = array();
        foreach ($mapping as $k => $p) {
            if ($k === 'attributes' && is_array($p)) {
                foreach ($p as $attr => $path) {
                    $m['attributes'][$attr] = (array) $this->extract_field_value($item, $path);
                }
            } else {
                $m[$k] = $this->extract_field_value($item, $p);
            }
        }
        return $m;
    }
    
    /**
     * Extract field value by path
     */
    public function extract_field_value(array $item, $path) {
        $segs = preg_split('/\.(?![^\[]*\])/', $path);
        $v = $item;
        foreach ($segs as $seg) {
            if (preg_match('/(.+)\[(\d+)\]$/', $seg, $m)) {
                $key = $m[1]; 
                $idx = intval($m[2]);
                if (isset($v[$key][$idx])) {
                    $v = $v[$key][$idx];
                } else {
                    return null;
                }
            } elseif (isset($v[$seg])) {
                $v = $v[$seg];
            } else {
                return null;
            }
        }
        return $v;
    }

    private function redirect_with_success($message) {
        wp_safe_redirect(add_query_arg('myfeeds_success', urlencode($message), wp_get_referer()));
        exit;
    }
    
    private function redirect_with_error($message) {
        wp_safe_redirect(add_query_arg('myfeeds_error', urlencode($message), wp_get_referer()));
        exit;
    }
    
    /**
     * Handle feed deletion
     */
    public function handle_delete_feed() {
        if (!current_user_can('manage_options') || !check_admin_referer('myfeeds_delete_feed')) {
            wp_die(esc_html__('Security check failed', 'myfeeds-affiliate-feed-manager'));
        }

        $key = isset($_POST['feed_key']) ? intval(wp_unslash($_POST['feed_key'])) : 0;
        $feeds = get_option(self::OPTION_KEY, array());
        
        if (isset($feeds[$key])) {
            array_splice($feeds, $key, 1);
            update_option(self::OPTION_KEY, $feeds);
        }
        
        $this->redirect_with_success(esc_html__('Feed deleted successfully!', 'myfeeds-affiliate-feed-manager'));
    }
    
    /**
     * Handle index rebuild
     */
    public function handle_rebuild_index() {
        if (!current_user_can('manage_options') || !check_admin_referer('myfeeds_rebuild_index')) {
            wp_die(esc_html__('Security check failed', 'myfeeds-affiliate-feed-manager'));
        }
        $this->rebuild_feed_index();
        $this->redirect_with_success(esc_html__('Product index rebuilt successfully!', 'myfeeds-affiliate-feed-manager'));
    }
    
    /**
     * Handle mapping regeneration for all feeds
     * This re-analyzes all feeds with the Smart Mapper and updates stored mappings
     */
    public function handle_regenerate_mappings() {
        if (!current_user_can('manage_options') || !check_admin_referer('myfeeds_regenerate_mappings')) {
            wp_die(esc_html__('Security check failed', 'myfeeds-affiliate-feed-manager'));
        }
        
        $feeds = get_option(self::OPTION_KEY, array());
        $updated_count = 0;
        $error_count = 0;
        
        foreach ($feeds as $key => $feed) {
            myfeeds_log("🔄 Regenerating mapping for feed: " . $feed['name'], 'info');
            
            // Test the feed URL to get sample data
            $test_result = $this->test_feed_url($feed['url'], $feed['format_hint'] ?? '');
            
            if (is_wp_error($test_result)) {
                myfeeds_log("❌ Feed test failed: " . $test_result->get_error_message(), 'error');
                $error_count++;
                continue;
            }
            
            // Generate new mapping with Smart Mapper
            $new_mapping = $this->smart_mapper->auto_map_fields($test_result['sample_data'], $feed['url']);
            
            if ($new_mapping) {
                $feeds[$key]['mapping'] = $new_mapping;
                $feeds[$key]['mapping_confidence'] = $this->smart_mapper->get_mapping_confidence($new_mapping);
                $feeds[$key]['last_mapping_update'] = current_time('mysql');
                $updated_count++;
                myfeeds_log("✅ Mapping regenerated for: " . $feed['name'], 'info');
            } else {
                myfeeds_log("❌ Mapping generation failed for: " . $feed['name'], 'error');
                $error_count++;
            }
        }
        
        // Save updated feeds
        update_option(self::OPTION_KEY, $feeds);
        
        // Rebuild index with new mappings
        $this->rebuild_feed_index();
        
        if ($error_count > 0) {
            $this->redirect_with_success(
                /* translators: %1$d: number of feeds updated, %2$d: number of errors */
                sprintf(__('Mappings regenerated: %1$d feeds updated, %2$d errors. Product index rebuilt.', 'myfeeds-affiliate-feed-manager'), 
                    $updated_count, $error_count)
            );
        } else {
            $this->redirect_with_success(
                /* translators: %d: number of feeds updated */
                sprintf(__('All %d feed mappings regenerated successfully! Product index rebuilt.', 'myfeeds-affiliate-feed-manager'), 
                    $updated_count)
            );
        }
    }

    /**
     * Handle AJAX index rebuild
     */
    public function ajax_rebuild_index() {
        check_ajax_referer('myfeeds_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'myfeeds-affiliate-feed-manager')), 403);
        }
        try {
            $this->rebuild_feed_index();
            wp_send_json_success(array('message' => __('Product index rebuilt successfully!', 'myfeeds-affiliate-feed-manager')));
        } catch (\Throwable $e) {
            myfeeds_log('ajax_rebuild_index error: ' . $e->getMessage(), 'error');
            wp_send_json_error(array('message' => 'Rebuild failed: ' . $e->getMessage()), 500);
        }
    }
    
    /**
     * AJAX: Get mapping quality details for a feed
     * Defensive: try/catch(\Throwable), handles missing table and 0 products
     */
    public function ajax_get_mapping_quality() {
        check_ajax_referer('myfeeds_nonce', 'nonce');
        try {
            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => 'Unauthorized'), 403);
            }
            
            $feed_name = sanitize_text_field(wp_unslash($_POST['feed_name'] ?? ''));
            if (empty($feed_name)) {
                wp_send_json_error(array('message' => 'Feed name required'), 400);
            }
            
            if (!class_exists('MyFeeds_DB_Manager') || !MyFeeds_DB_Manager::table_exists()) {
                wp_send_json_error(array('message' => 'No product data available. Please run a Full Import first.'), 400);
            }
            
            $quality = MyFeeds_DB_Manager::calculate_mapping_quality($feed_name);
            
            if ($quality['total'] === 0) {
                wp_send_json_success(array(
                    'quality' => 0,
                    'total' => 0,
                    'complete' => 0,
                    'fields' => array(),
                    'worst_products' => array(),
                    'message' => 'No products imported yet for this feed.',
                ));
                return;
            }
            
            $worst = MyFeeds_DB_Manager::get_worst_mapped_products($feed_name, 3);
            
            wp_send_json_success(array(
                'quality' => $quality['quality'],
                'total' => $quality['total'],
                'complete' => $quality['complete'],
                'fields' => $quality['fields'],
                'worst_products' => $worst,
            ));
        } catch (\Throwable $e) {
            myfeeds_log('ajax_get_mapping_quality error: ' . $e->getMessage(), 'error');
            wp_send_json_error(array('message' => 'An error occurred while calculating quality. Check error log for details.'), 500);
        }
    }
    
    /**
     * Handle feed testing
     */
    public function handle_test_feed() {
        if (!current_user_can('manage_options') || !check_admin_referer('myfeeds_test_feed')) {
            wp_die(esc_html__('Security check failed', 'myfeeds-affiliate-feed-manager'));
        }

        $key = isset($_POST['feed_key']) ? intval(wp_unslash($_POST['feed_key'])) : 0;
        $feeds = get_option(self::OPTION_KEY, array());
        
        if (!isset($feeds[$key])) {
            $this->redirect_with_error(esc_html__('Feed not found', 'myfeeds-affiliate-feed-manager'));
            return;
        }
        
        $feed = $feeds[$key];
        $format_hint = isset($feed['format_hint']) ? $feed['format_hint'] : '';
        $test_result = $this->test_feed_url($feed['url'], $format_hint);
        
        if (is_wp_error($test_result)) {
            wp_die(esc_html__('Feed Test Error: ', 'myfeeds-affiliate-feed-manager') . esc_html($test_result->get_error_message()));
        }
        
        $mapped = $this->smart_mapper->map_product($test_result['sample_data'], $feed['mapping']);
        // Use the SINGLE SOURCE OF TRUTH method for all field processing
        $mapped = $this->process_critical_fields($mapped, $test_result['sample_data']);
        
        // Get mapping confidence
        $confidence = $this->smart_mapper->get_mapping_confidence($feed['mapping']);
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Feed Test Results', 'myfeeds-affiliate-feed-manager'); ?>: <?php echo esc_html($feed['name']); ?></h1>
            
            <div style="background: #f5f3ff; border-left: 4px solid #667eea; padding: 20px; margin: 20px 0;">
                <h3><?php esc_html_e('Test Summary', 'myfeeds-affiliate-feed-manager'); ?></h3>
                <ul>
                    <li><strong><?php esc_html_e('Feed URL:', 'myfeeds-affiliate-feed-manager'); ?></strong> <?php echo esc_html($feed['url']); ?></li>
                    <li><strong><?php esc_html_e('Detected Format:', 'myfeeds-affiliate-feed-manager'); ?></strong> <?php echo esc_html($test_result['format']); ?></li>
                    <li><strong><?php esc_html_e('Mapping Confidence:', 'myfeeds-affiliate-feed-manager'); ?></strong> <?php echo esc_html(round($confidence)); ?>%</li>
                </ul>
            </div>
            
            <div style="background: #fff; border: 1px solid #ddd; padding: 20px; margin: 20px 0;">
                <h3><?php esc_html_e('Raw Feed Sample (First Product)', 'myfeeds-affiliate-feed-manager'); ?></h3>
                <div style="background: #f8f9fa; padding: 15px; font-family: monospace; font-size: 12px; max-height: 300px; overflow-y: auto;">
                    <?php echo '<pre>' . esc_html(print_r($test_result['sample_data'], true)) . '</pre>'; ?>
                </div>
            </div>
            
            <div style="background: #fff; border: 1px solid #ddd; padding: 20px; margin: 20px 0;">
                <h3><?php esc_html_e('Mapped + Enriched Product Data', 'myfeeds-affiliate-feed-manager'); ?></h3>
                <div style="background: #e8f5e8; padding: 15px; font-family: monospace; font-size: 12px; max-height: 300px; overflow-y: auto;">
                    <?php echo '<pre>' . esc_html(print_r($mapped, true)) . '</pre>'; ?>
                </div>
            </div>
            
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=myfeeds-feeds')); ?>" class="button button-primary"><?php esc_html_e('Back to Feeds', 'myfeeds-affiliate-feed-manager'); ?></a></p>
        </div>
        <?php
    }
    
    public function add_feed($url, $name = '') {
        myfeeds_log("📡 FEED MANAGEMENT: Starting add_feed operation for URL: " . substr($url, 0, 50), 'info');
        
        try {
            if (empty($url)) {
                myfeeds_log("❌ FEED MANAGEMENT: Empty URL provided for add_feed", 'error');
                throw new Exception('Feed URL cannot be empty');
            }
            
            $feeds = get_option('myfeeds_feeds', array());
            
            if (!is_array($feeds)) {
                myfeeds_log("⚠️ FEED MANAGEMENT: Feeds option is not an array, resetting", 'debug');
                $feeds = array();
            }
            
            $feed_id = md5($url);
            $feeds[$feed_id] = array(
                'url' => $url,
                'name' => $name ?: 'Feed ' . gmdate('Y-m-d H:i:s'),
                'added' => current_time('mysql'),
                'last_update' => null,
                'status' => 'active'
            );
            
            $result = update_option('myfeeds_feeds', $feeds);
            
            if ($result) {
                myfeeds_log("✅ FEED MANAGEMENT: Successfully added feed with ID: " . $feed_id, 'info');
                return $feed_id;
            } else {
                myfeeds_log("❌ FEED MANAGEMENT: Failed to update option myfeeds_feeds", 'error');
                throw new Exception('Failed to save feed to database');
            }
            
        } catch (Exception $e) {
            myfeeds_log("🚨 FEED MANAGEMENT CRITICAL ERROR: " . $e->getMessage(), 'error');
            myfeeds_log("🚨 FEED MANAGEMENT STACK TRACE: " . $e->getTraceAsString(), 'error');
            throw $e;
        }
    }
}