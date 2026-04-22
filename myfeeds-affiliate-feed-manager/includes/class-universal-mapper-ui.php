<?php
/**
 * MyFeeds Universal Mapper UI
 * Provides admin interface for mapping feed columns to standard fields
 * 
 * Supports CSV, XML, JSON feeds from any affiliate network
 * Includes template system for reusable mappings
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Universal_Mapper_UI {
    
    /**
     * Initialize the mapper UI
     */
    public function init() {
        // Admin AJAX handlers
        add_action('wp_ajax_myfeeds_get_feed_columns', array($this, 'ajax_get_feed_columns'));
        add_action('wp_ajax_myfeeds_save_mapping', array($this, 'ajax_save_mapping'));
        add_action('wp_ajax_myfeeds_save_template', array($this, 'ajax_save_template'));
        add_action('wp_ajax_myfeeds_apply_template', array($this, 'ajax_apply_template'));
        add_action('wp_ajax_myfeeds_delete_template', array($this, 'ajax_delete_template'));

        // Admin page for mapping editor
        add_action('admin_menu', array($this, 'register_mapping_page'));
        add_action('admin_menu', array($this, 'register_settings_submenu'), 30);

        // Enqueue mapping-editor assets only on that screen
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    /**
     * Enqueue the mapping editor assets only on the mapping editor screen.
     * The shared myfeeds-admin script (from class-feed-manager) provides
     * the `myfeedsAdmin` object used by mapping-editor.js.
     */
    public function enqueue_assets($hook) {
        if ($hook !== 'myfeeds_page_myfeeds-mapping-editor') {
            return;
        }

        wp_enqueue_style(
            'myfeeds-mapping-editor',
            MYFEEDS_PLUGIN_URL . 'assets/mapping-editor.css',
            array(),
            MYFEEDS_VERSION
        );
        wp_enqueue_script(
            'myfeeds-mapping-editor',
            MYFEEDS_PLUGIN_URL . 'assets/mapping-editor.js',
            array('jquery', 'myfeeds-admin'),
            MYFEEDS_VERSION,
            true
        );

        $feed_key = isset($_GET['feed_key']) ? intval($_GET['feed_key']) : null;
        wp_localize_script('myfeeds-mapping-editor', 'myfeedsMapping', array(
            'autoDetectUrl'  => admin_url('admin.php?page=myfeeds-mapping-editor'),
            'initialFeedKey' => $feed_key,
            'i18n'           => array(
                'mappingSaved'     => __('Mapping saved successfully!', 'myfeeds'),
                'enterTemplateName'=> __('Please enter a template name', 'myfeeds'),
                'templateSaved'    => __('Template saved!', 'myfeeds'),
                'selectTemplate'   => __('Please select a template', 'myfeeds'),
                'selectFeedFirst'  => __('Please select a feed first', 'myfeeds'),
                'templateApplied'  => __('Template applied! Reloading...', 'myfeeds'),
                'detecting'        => __('Detecting...', 'myfeeds'),
            ),
        ));
    }
    
    /**
     * Register mapping editor page (as sub-page of feeds)
     */
    public function register_mapping_page() {
        add_submenu_page(
            'myfeeds-feeds',
            __('Mapping Editor', 'myfeeds'),
            __('Mapping Editor', 'myfeeds'),
            'manage_options',
            'myfeeds-mapping-editor',
            array($this, 'render_mapping_editor_page')
        );
        
        // Templates is now a tab inside the Mapping Editor page — no separate submenu
    }
    
    /**
     * Register settings submenu (at priority 30 — after Design at 25)
     * NOTE: Settings page removed from menu. Redirect to feeds page if accessed directly.
     */
    public function register_settings_submenu() {
        // Redirect removed Settings page to main feeds page
        if (isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'myfeeds-settings') {
            wp_safe_redirect(admin_url('admin.php?page=myfeeds-feeds'));
            exit;
        }
    }
    
    /**
     * Render the mapping editor page
     */
    public function render_mapping_editor_page() {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'editor';
        
        ?>
        <div class="wrap myfeeds-mapping-editor">
            <h1><?php esc_html_e('Universal Mapping Editor', 'myfeeds'); ?></h1>
            
            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url(admin_url('admin.php?page=myfeeds-mapping-editor')); ?>" class="nav-tab <?php echo $active_tab === 'editor' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Mapping Editor', 'myfeeds'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=myfeeds-mapping-editor&tab=templates')); ?>" class="nav-tab <?php echo $active_tab === 'templates' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Templates', 'myfeeds'); ?>
                </a>
            </h2>
            
            <?php if ($active_tab === 'templates'): ?>
                <?php $this->render_templates_content(); ?>
            <?php else: ?>
                <?php $this->render_mapping_editor_content(); ?>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render the mapping editor content (formerly inline in render_mapping_editor_page)
     */
    private function render_mapping_editor_content() {
        $feed_key = isset($_GET['feed_key']) ? intval($_GET['feed_key']) : null;
        $feeds = get_option('myfeeds_feeds', array());
        $templates = MyFeeds_Settings_Manager::get_mapping_templates();
        $standard_fields = MyFeeds_Settings_Manager::$standard_fields;
        $field_groups = MyFeeds_Settings_Manager::get_field_groups();
        
        ?>
            
            <div class="myfeeds-mapping-container">
                <!-- Feed Selector -->
                <div class="myfeeds-panel">
                    <h2><?php esc_html_e('1. Select Feed', 'myfeeds'); ?></h2>
                    
                    <select id="myfeeds-feed-selector" class="myfeeds-select-large">
                        <option value=""><?php esc_html_e('-- Select a feed --', 'myfeeds'); ?></option>
                        <?php foreach ($feeds as $key => $feed): ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($feed_key, $key); ?>>
                                <?php echo esc_html($feed['name']); ?> 
                                (<?php echo esc_html($feed['product_count'] ?? 0); ?> products)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <?php if (!empty($templates)): ?>
                        <div class="myfeeds-template-apply" style="margin-top: 15px;">
                            <label><?php esc_html_e('Or apply a template:', 'myfeeds'); ?></label>
                            <select id="myfeeds-template-selector" class="myfeeds-select">
                                <option value=""><?php esc_html_e('-- Select template --', 'myfeeds'); ?></option>
                                <?php foreach ($templates as $tid => $template): ?>
                                    <option value="<?php echo esc_attr($tid); ?>">
                                        <?php echo esc_html($template['name']); ?>
                                        <?php if (!empty($template['network'])): ?>
                                            (<?php echo esc_html($template['network']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" id="myfeeds-apply-template" class="button">
                                <?php esc_html_e('Apply Template', 'myfeeds'); ?>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Mapping Interface -->
                <div class="myfeeds-panel" id="myfeeds-mapping-interface" style="display: none;">
                    <h2><?php esc_html_e('2. Map Feed Columns to Fields', 'myfeeds'); ?></h2>
                    
                    <div class="myfeeds-mapping-info">
                        <p><?php esc_html_e('Drag feed columns to the corresponding field, or select from dropdown.', 'myfeeds'); ?></p>
                    </div>
                    
                    <!-- Available Feed Columns -->
                    <div class="myfeeds-columns-panel">
                        <h3><?php esc_html_e('Available Feed Columns', 'myfeeds'); ?></h3>
                        <div id="myfeeds-feed-columns" class="myfeeds-column-list">
                            <p class="description"><?php esc_html_e('Loading feed columns...', 'myfeeds'); ?></p>
                        </div>
                    </div>
                    
                    <!-- Mapping Grid -->
                    <div class="myfeeds-mapping-grid">
                        <?php foreach ($field_groups as $group_key => $group): ?>
                            <div class="myfeeds-field-group">
                                <h3>
                                    <?php echo esc_html($group['label']); ?>
                                    <span class="description"><?php echo esc_html($group['description']); ?></span>
                                </h3>
                                
                                <div class="myfeeds-field-list">
                                    <?php 
                                    foreach ($standard_fields as $field_key => $field):
                                        if ($field['group'] !== $group_key) continue;
                                    ?>
                                        <div class="myfeeds-field-row" data-field="<?php echo esc_attr($field_key); ?>">
                                            <label>
                                                <?php echo esc_html($field['label']); ?>
                                                <?php if ($field['required']): ?>
                                                    <span class="required">*</span>
                                                <?php endif; ?>
                                            </label>
                                            <select class="myfeeds-field-mapping" data-field="<?php echo esc_attr($field_key); ?>">
                                                <option value=""><?php esc_html_e('-- Not mapped --', 'myfeeds'); ?></option>
                                            </select>
                                            <span class="myfeeds-field-help" title="<?php echo esc_attr($field['description']); ?>">?</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Sample Data Preview -->
                    <div class="myfeeds-preview-panel">
                        <h3><?php esc_html_e('Preview', 'myfeeds'); ?></h3>
                        <div id="myfeeds-mapping-preview">
                            <p class="description"><?php esc_html_e('Select a feed to see sample data', 'myfeeds'); ?></p>
                        </div>
                    </div>
                    
                    <!-- Actions -->
                    <div class="myfeeds-mapping-actions">
                        <button type="button" id="myfeeds-save-mapping" class="button button-primary button-large">
                            <?php esc_html_e('💾 Save Mapping', 'myfeeds'); ?>
                        </button>
                        
                        <button type="button" id="myfeeds-save-as-template" class="button">
                            <?php esc_html_e('📑 Save as Template', 'myfeeds'); ?>
                        </button>
                        
                        <button type="button" id="myfeeds-auto-detect" class="button">
                            <?php esc_html_e('🔍 Auto-Detect', 'myfeeds'); ?>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Save as Template Modal -->
            <div id="myfeeds-template-modal" class="myfeeds-modal" style="display: none;">
                <div class="myfeeds-modal-content">
                    <h3><?php esc_html_e('Save as Template', 'myfeeds'); ?></h3>
                    <p><?php esc_html_e('Create a reusable template from this mapping:', 'myfeeds'); ?></p>
                    
                    <label><?php esc_html_e('Template Name', 'myfeeds'); ?></label>
                    <input type="text" id="myfeeds-template-name" placeholder="<?php esc_attr_e('e.g., AWIN Fashion', 'myfeeds'); ?>">
                    
                    <label><?php esc_html_e('Network (optional)', 'myfeeds'); ?></label>
                    <select id="myfeeds-template-network">
                        <option value=""><?php esc_html_e('-- Select --', 'myfeeds'); ?></option>
                        <option value="awin">AWIN</option>
                        <option value="tradedoubler">TradeDoubler</option>
                        <option value="cj">Commission Junction</option>
                        <option value="amazon">Amazon Associates</option>
                        <option value="other"><?php esc_html_e('Other', 'myfeeds'); ?></option>
                    </select>
                    
                    <div class="myfeeds-modal-actions">
                        <button type="button" id="myfeeds-template-save-confirm" class="button button-primary">
                            <?php esc_html_e('Save Template', 'myfeeds'); ?>
                        </button>
                        <button type="button" class="button myfeeds-modal-close">
                            <?php esc_html_e('Cancel', 'myfeeds'); ?>
                        </button>
                    </div>
                </div>
            </div>
        
        <?php
    }
    
    /**
     * Render templates management page (legacy — now redirects to tab)
     */
    public function render_templates_page() {
        $this->render_templates_content();
    }
    
    /**
     * Render templates content (used as tab inside Mapping Editor)
     */
    public function render_templates_content() {
        $templates = MyFeeds_Settings_Manager::get_mapping_templates();
        
        // Handle delete action
        if (isset($_GET['delete_template']) && isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'delete_template')) {
            $template_id = sanitize_text_field(wp_unslash($_GET['delete_template']));
            MyFeeds_Settings_Manager::delete_mapping_template($template_id);
            echo '<div class="notice notice-success"><p>' . esc_html__('Template deleted', 'myfeeds') . '</p></div>';
            $templates = MyFeeds_Settings_Manager::get_mapping_templates();
        }
        
        ?>
            <p><?php esc_html_e('Reusable mapping configurations for different affiliate networks.', 'myfeeds'); ?></p>
            
            <?php if (empty($templates)): ?>
                <div class="notice notice-info">
                    <p><?php esc_html_e('No templates yet. Create one from the Mapping Editor by clicking "Save as Template".', 'myfeeds'); ?></p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Template Name', 'myfeeds'); ?></th>
                            <th><?php esc_html_e('Network', 'myfeeds'); ?></th>
                            <th><?php esc_html_e('Mapped Fields', 'myfeeds'); ?></th>
                            <th><?php esc_html_e('Created', 'myfeeds'); ?></th>
                            <th><?php esc_html_e('Actions', 'myfeeds'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($templates as $tid => $template): ?>
                            <tr>
                                <td><strong><?php echo esc_html($template['name']); ?></strong></td>
                                <td><?php echo esc_html($template['network'] ?: '-'); ?></td>
                                <td><?php echo count($template['mapping'] ?? array()); ?> fields</td>
                                <td><?php echo esc_html($template['created_at']); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(wp_nonce_url(
                                        admin_url('admin.php?page=myfeeds-mapping-editor&tab=templates&delete_template=' . $tid),
                                        'delete_template'
                                    )); ?>" 
                                       class="button button-small" 
                                       onclick="return confirm('<?php esc_html_e('Delete this template?', 'myfeeds'); ?>');">
                                        <?php esc_html_e('Delete', 'myfeeds'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php
    }
    
    /**
     * Render settings page with API keys
     */
    public function render_settings_page() {
        $api_keys = MyFeeds_Settings_Manager::get_api_keys();
        $general_settings = MyFeeds_Settings_Manager::get_general_settings();
        
        // Handle form submission
        if (isset($_POST['myfeeds_save_settings']) && isset($_POST['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'myfeeds_settings')) {
            // Save API keys
            $new_keys = array(
                'supabase_url' => sanitize_text_field(wp_unslash($_POST['supabase_url'] ?? '')),
                'supabase_anon_key' => sanitize_text_field(wp_unslash($_POST['supabase_anon_key'] ?? '')),
                'supabase_service_key' => sanitize_text_field(wp_unslash($_POST['supabase_service_key'] ?? '')),
                'openai_api_key' => sanitize_text_field(wp_unslash($_POST['openai_api_key'] ?? '')),
            );
            MyFeeds_Settings_Manager::save_api_keys($new_keys);
            $api_keys = $new_keys;
            
            // Save general settings
            $new_settings = array(
                'batch_size' => intval($_POST['batch_size'] ?? 100),
                'enable_background_import' => isset($_POST['enable_background_import']),
                'debug_mode' => isset($_POST['debug_mode']),
            );
            MyFeeds_Settings_Manager::save_general_settings($new_settings);
            $general_settings = $new_settings;
            
            // Save log level
            $log_level = sanitize_text_field(wp_unslash($_POST['myfeeds_log_level'] ?? 'info'));
            if (in_array($log_level, array('error', 'info', 'debug'), true)) {
                update_option('myfeeds_log_level', $log_level);
            }
            
            echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved!', 'myfeeds') . '</p></div>';
        }
        
        // Free plugin: Pro-gated API key fields stay disabled.
        $is_pro = false;
        $current_log_level = get_option('myfeeds_log_level', 'info');
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('My Product Picker Settings', 'myfeeds'); ?></h1>
            
            <!-- Database Storage Status (read-only, no toggle) -->
            <?php if (class_exists('MyFeeds_DB_Manager')): ?>
            <?php
                $table_exists = MyFeeds_DB_Manager::table_exists();
                $db_stats = $table_exists ? MyFeeds_DB_Manager::get_stats() : array('count' => 0);
            ?>
            <div class="myfeeds-settings-section" style="border-left: 4px solid #00a32a;">
                <h2><?php esc_html_e('Storage', 'myfeeds'); ?></h2>
                <p style="font-size: 14px;">
                    <?php esc_html_e('Mode:', 'myfeeds'); ?> <strong><?php esc_html_e('Database', 'myfeeds'); ?></strong>
                    <?php if ($table_exists && isset($db_stats['active'])): ?>
                        <?php /* translators: %d: number of active products */ ?>
                        &mdash; <?php echo esc_html(sprintf(__('%d active products', 'myfeeds'), $db_stats['active'])); ?>
                    <?php elseif (!$table_exists): ?>
                        &mdash; <span style="color:#d63638;"><?php esc_html_e('Table missing. Please deactivate and reactivate the plugin.', 'myfeeds'); ?></span>
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>
            
            <form method="post">
                <?php wp_nonce_field('myfeeds_settings'); ?>
                
                <!-- General Settings -->
                <div class="myfeeds-settings-section">
                    <h2><?php esc_html_e('General Settings', 'myfeeds'); ?></h2>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Batch Size', 'myfeeds'); ?></th>
                            <td>
                                <input type="number" name="batch_size" value="<?php echo esc_attr($general_settings['batch_size']); ?>" min="10" max="500">
                                <p class="description"><?php esc_html_e('Number of products to process per batch (10-500)', 'myfeeds'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Background Import', 'myfeeds'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="enable_background_import" <?php checked($general_settings['enable_background_import']); ?>>
                                    <?php esc_html_e('Enable background processing for large imports', 'myfeeds'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Debug Mode', 'myfeeds'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="debug_mode" <?php checked($general_settings['debug_mode'] ?? false); ?>>
                                    <?php esc_html_e('Enable debug logging', 'myfeeds'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Log Level', 'myfeeds'); ?></th>
                            <td>
                                <select name="myfeeds_log_level">
                                    <option value="error" <?php selected($current_log_level, 'error'); ?>>Error — <?php esc_html_e('Only real errors', 'myfeeds'); ?></option>
                                    <option value="info" <?php selected($current_log_level, 'info'); ?>>Info — <?php esc_html_e('Errors + feed/import summaries', 'myfeeds'); ?></option>
                                    <option value="debug" <?php selected($current_log_level, 'debug'); ?>>Debug — <?php esc_html_e('Everything (verbose, for support)', 'myfeeds'); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e('Controls how much detail is written to the error log. Default: Info.', 'myfeeds'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Pro Features: API Keys -->
                <div class="myfeeds-settings-section">
                    <h2>
                        <?php esc_html_e('Pro Features: API Keys', 'myfeeds'); ?>
                        <?php if (!$is_pro): ?>
                            <span class="myfeeds-pro-badge"><?php esc_html_e('PRO', 'myfeeds'); ?></span>
                        <?php endif; ?>
                    </h2>
                    
                    <?php if (!$is_pro): ?>
                        <div class="notice notice-warning inline">
                            <p><?php esc_html_e('These features require a Pro license. Upgrade to unlock Supabase sync and AI-powered search.', 'myfeeds'); ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Supabase URL', 'myfeeds'); ?></th>
                            <td>
                                <input type="url" name="supabase_url" value="<?php echo esc_attr($api_keys['supabase_url']); ?>" 
                                       class="regular-text" placeholder="https://xxxxx.supabase.co" <?php disabled(!$is_pro); ?>>
                                <p class="description"><?php esc_html_e('Your Supabase project URL', 'myfeeds'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Supabase Anon Key', 'myfeeds'); ?></th>
                            <td>
                                <input type="password" name="supabase_anon_key" value="<?php echo esc_attr($api_keys['supabase_anon_key']); ?>" 
                                       class="regular-text" <?php disabled(!$is_pro); ?>>
                                <p class="description"><?php esc_html_e('Public anonymous key (safe for frontend)', 'myfeeds'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Supabase Service Key', 'myfeeds'); ?></th>
                            <td>
                                <input type="password" name="supabase_service_key" value="<?php echo esc_attr($api_keys['supabase_service_key']); ?>" 
                                       class="regular-text" <?php disabled(!$is_pro); ?>>
                                <p class="description"><?php esc_html_e('Service role key (keep secret, backend only)', 'myfeeds'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('OpenAI API Key', 'myfeeds'); ?></th>
                            <td>
                                <input type="password" name="openai_api_key" value="<?php echo esc_attr($api_keys['openai_api_key']); ?>" 
                                       class="regular-text" placeholder="sk-..." <?php disabled(!$is_pro); ?>>
                                <p class="description"><?php esc_html_e('Required for AI-powered vector search and image search', 'myfeeds'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <p class="submit">
                    <input type="submit" name="myfeeds_save_settings" class="button button-primary" value="<?php esc_attr_e('Save Settings', 'myfeeds'); ?>">
                </p>
            </form>
        </div>
        <?php
    }
    
    // =============================================================================
    // AJAX HANDLERS
    // =============================================================================
    
    /**
     * Get feed columns for mapping UI
     */
    public function ajax_get_feed_columns() {
        check_ajax_referer('myfeeds_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $feed_key = intval($_POST['feed_key']);
        $feeds = get_option('myfeeds_feeds', array());
        
        if (!isset($feeds[$feed_key])) {
            wp_send_json_error(array('message' => 'Feed not found'));
        }
        
        $feed = $feeds[$feed_key];
        
        // Download a small portion of the feed to get columns
        $response = wp_remote_get($feed['url'], array(
            'timeout' => 30,
            'headers' => array('Accept-Encoding' => 'gzip, deflate'),
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()));
        }
        
        $body = wp_remote_retrieve_body($response);
        
        // Handle gzip
        if (substr($body, 0, 2) === "\x1f\x8b") {
            $body = @gzdecode($body);
        }
        
        // Parse first few lines to get columns and sample
        $lines = preg_split('/\r\n|\n|\r/', trim($body));
        $header = str_getcsv(array_shift($lines));
        
        // Get sample data (first row)
        $sample_data = array();
        if (!empty($lines)) {
            $first_row = str_getcsv($lines[0]);
            if (count($first_row) === count($header)) {
                $sample_data = array_combine($header, $first_row);
            }
        }
        
        wp_send_json_success(array(
            'columns' => $header,
            'current_mapping' => $feed['mapping'] ?? array(),
            'sample_data' => $sample_data,
        ));
    }
    
    /**
     * Save mapping for a feed
     */
    public function ajax_save_mapping() {
        check_ajax_referer('myfeeds_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $feed_key = intval($_POST['feed_key']);
        $mapping = json_decode(wp_unslash($_POST['mapping']), true);
        
        if (!is_array($mapping)) {
            wp_send_json_error(array('message' => 'Invalid mapping data'));
        }
        
        $feeds = get_option('myfeeds_feeds', array());
        
        if (!isset($feeds[$feed_key])) {
            wp_send_json_error(array('message' => 'Feed not found'));
        }
        
        $feeds[$feed_key]['mapping'] = $mapping;
        $feeds[$feed_key]['last_mapping_update'] = current_time('mysql');
        $feeds[$feed_key]['mapping_source'] = 'manual';
        
        update_option('myfeeds_feeds', $feeds);
        
        wp_send_json_success(array('message' => 'Mapping saved'));
    }
    
    /**
     * Save mapping as template
     */
    public function ajax_save_template() {
        check_ajax_referer('myfeeds_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $name = sanitize_text_field(wp_unslash($_POST['name']));
        $network = sanitize_text_field(wp_unslash($_POST['network']));
        $mapping = json_decode(wp_unslash($_POST['mapping']), true);
        
        if (empty($name)) {
            wp_send_json_error(array('message' => 'Template name required'));
        }
        
        if (!is_array($mapping)) {
            wp_send_json_error(array('message' => 'Invalid mapping data'));
        }
        
        $template_id = 'template_' . sanitize_title($name) . '_' . time();
        
        MyFeeds_Settings_Manager::save_mapping_template($template_id, $name, $mapping, $network);
        
        wp_send_json_success(array('message' => 'Template saved', 'template_id' => $template_id));
    }
    
    /**
     * Apply template to feed
     */
    public function ajax_apply_template() {
        check_ajax_referer('myfeeds_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $template_id = sanitize_text_field(wp_unslash($_POST['template_id']));
        $feed_key = intval($_POST['feed_key']);
        
        $result = MyFeeds_Settings_Manager::apply_template_to_feed($template_id, $feed_key);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array('message' => 'Template applied'));
    }
    
    /**
     * Delete template
     */
    public function ajax_delete_template() {
        check_ajax_referer('myfeeds_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $template_id = sanitize_text_field(wp_unslash($_POST['template_id']));
        
        MyFeeds_Settings_Manager::delete_mapping_template($template_id);
        
        wp_send_json_success(array('message' => 'Template deleted'));
    }
}
