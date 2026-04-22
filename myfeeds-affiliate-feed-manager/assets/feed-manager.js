// MyFeeds Feed Manager admin screen
// Merged from three inline <script> blocks (Step 8c).

(function() {
    var cfg = window.myfeedsFeeds || {};
    var i18n = cfg.i18n || {};
    var thousandsSep = cfg.thousandsSep || ',';

    // Number formatter matching WP locale (shared across all script blocks)
    window.formatNumber = function(n) {
        n = parseInt(n) || 0;
        if (n === 0) return '0';
        return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, thousandsSep);
    };
    var formatNumber = window.formatNumber;

        jQuery(document).ready(function($) {
            console.log('MyFeeds: Admin JS initialized');
            
            var importInterval = null;
            window.currentReimportFeedKey = null;
            window.currentReimportInterval = null;
            var nonce = myfeedsAdmin.nonce;
            var waitingForRunning = false;
            
            // =====================================================================
            // NON-BLOCKING UI PATTERN with LOGGING:
            // 1. Show panel IMMEDIATELY on click
            // 2. Start polling IMMEDIATELY
            // 3. Fire AJAX to trigger background job
            // 4. AJAX returns 202 Accepted (job queued, not completed)
            // =====================================================================
            
            // Unified Rebuild Button (Full Import)
            $('#myfeeds-unified-rebuild').on('click', function() {
                console.log('MyFeeds: Full Update button clicked');
                
                var $btn = $(this);
                $btn.prop('disabled', true).html('⏳ Starting...');
                
                // INSTANT FEEDBACK: Show panel IMMEDIATELY
                showStatusPanel();
                setInitialStatus('full', 'Queuing full update...');
                
                console.log('MyFeeds: Sending AJAX request to myfeeds_unified_rebuild');
                
                // Fire background job - non-blocking
                $.post(ajaxurl, {
                    action: 'myfeeds_unified_rebuild',
                    nonce: nonce
                }, function(response) {
                    console.log('MyFeeds: AJAX response received', response);
                    
                    if (response.success) {
                        console.log('MyFeeds: Job accepted, starting polling');
                        // Start polling AFTER we confirm job was accepted
                        startStatusPolling();
                        waitingForRunning = false;
                        if (response.data && response.data.status) {
                            updateStatusDisplay(response.data.status);
                        }
                    } else {
                        console.error('MyFeeds: Server returned error', response);
                        alert('Error: ' + (response.data ? response.data.message : 'Unknown error'));
                        hideStatusPanel();
                        clearInterval(importInterval);
                    }
                    $btn.prop('disabled', false).html('🔄 Update All Feeds');
                }).fail(function(xhr, status, error) {
                    console.error('MyFeeds: AJAX failed', status, error, xhr.responseText);
                    alert('Connection error: ' + error + '\nPlease check the browser console for details.');
                    hideStatusPanel();
                    clearInterval(importInterval);
                    $btn.prop('disabled', false).html('🔄 Update All Feeds');
                });
            });
            
            // Quick Sync Button (Active Products Only)
            $('#myfeeds-quick-sync').on('click', function() {
                console.log('MyFeeds: Quick Sync button clicked');
                
                var $btn = $(this);
                $btn.prop('disabled', true).html('⏳ Scanning...');
                
                // INSTANT FEEDBACK: Show panel IMMEDIATELY
                showStatusPanel();
                setInitialStatus('active_only', 'Scanning published posts for product IDs...');
                
                console.log('MyFeeds: Sending AJAX request to myfeeds_quick_sync_active');
                
                // Fire background job - non-blocking
                $.post(ajaxurl, {
                    action: 'myfeeds_quick_sync_active',
                    nonce: nonce
                }, function(response) {
                    console.log('MyFeeds: AJAX response received', response);
                    
                    if (response.success) {
                        console.log('MyFeeds: Job accepted, starting polling');
                        // Start polling AFTER we confirm job was accepted
                        startStatusPolling();
                        waitingForRunning = false;
                        if (response.data && response.data.status) {
                            updateStatusDisplay(response.data.status);
                        }
                    } else {
                        console.error('MyFeeds: Server returned error', response);
                        alert('⚠️ ' + (response.data ? response.data.message : 'Unknown error'));
                        hideStatusPanel();
                        clearInterval(importInterval);
                    }
                    $btn.prop('disabled', false).html('⚡ Quick Sync (Active Only)');
                }).fail(function(xhr, status, error) {
                    console.error('MyFeeds: AJAX failed', status, error, xhr.responseText);
                    alert('Connection error: ' + error + '\nPlease check the browser console for details.');
                    hideStatusPanel();
                    clearInterval(importInterval);
                    $btn.prop('disabled', false).html('⚡ Quick Sync (Active Only)');
                });
            });
            
            function showStatusPanel() {
                console.log('MyFeeds: Showing status panel');
                // Reset panel state before showing
                $('#myfeeds-import-panel').removeClass('completed');
                $('#myfeeds-progress-fill').removeClass('completed').css('width', '0%');
                $('#myfeeds-cancel-import').show();
                $('#myfeeds-success-message').hide();
                $('#myfeeds-import-status').slideDown(200);
                waitingForRunning = true;
            }
            
            function hideStatusPanel() {
                console.log('MyFeeds: Hiding status panel');
                $('#myfeeds-import-status').slideUp(200);
            }
            
            // Set initial status for instant feedback
            function setInitialStatus(mode, message) {
                console.log('MyFeeds: Setting initial status', mode, message);
                $('#myfeeds-status-title').text(mode === 'active_only' ? '⚡ Quick Sync starting...' : '🔄 Full Update starting...');
                $('#myfeeds-import-phase').text(message);
                $('#myfeeds-import-feed').text('');
                $('#myfeeds-import-percent').text('0%');
                $('#myfeeds-progress-fill').css('width', '0%');
                $('#myfeeds-import-products').text('0');
                $('#myfeeds-import-feeds').text('0/0');
            }
            
            // Poll for status - fast interval for responsive UI
            function startStatusPolling() {
                console.log('MyFeeds: Starting status polling');
                if (importInterval) clearInterval(importInterval);
                importInterval = setInterval(checkImportStatus, 1500);
            }
            
            function checkImportStatus() {
                $.post(ajaxurl, {
                    action: 'myfeeds_get_import_status',
                    nonce: nonce
                }, function(response) {
                    if (response.success) {
                        console.log('MyFeeds: Status poll result', response.data.status, response.data.progress_percent + '%');
                        updateStatusDisplay(response.data);
                        
                        // Guard: Ignore stale "completed"/"idle" status until we see "running" at least once
                        if (waitingForRunning) {
                            if (response.data.status === 'running') {
                                waitingForRunning = false;
                            } else if (response.data.status === 'completed' || response.data.status === 'idle') {
                                console.log('MyFeeds: Ignoring stale status while waiting for import to start:', response.data.status);
                                return;
                            }
                        }
                        
                        if (response.data.status === 'completed') {
                            console.log('MyFeeds: Import completed!');
                            clearInterval(importInterval);
                            showCompletionState(response.data);
                            updateReimportButtons(false);
                        } else if (response.data.status === 'cancelled' || response.data.status === 'idle' || response.data.status === 'error') {
                            console.log('MyFeeds: Import stopped with status:', response.data.status);
                            clearInterval(importInterval);
                            if (response.data.status === 'error') {
                                alert('Import failed. Check logs for details.');
                            }
                            hideStatusPanel();
                            updateReimportButtons(false);
                        } else if (response.data.status === 'running') {
                            updateReimportButtons(true);
                        }
                    }
                }).fail(function() {
                    console.error('MyFeeds: Status poll failed');
                });
            }
            
            function showCompletionState(status) {
                // Switch panel to success style
                $('#myfeeds-import-panel').addClass('completed');
                $('#myfeeds-progress-fill').addClass('completed').css('width', '100%');
                $('#myfeeds-import-percent').text('100%');
                
                // Update title
                $('#myfeeds-status-title').html('✅ Update completed successfully!');
                
                // Hide cancel button
                $('#myfeeds-cancel-import').hide();
                
                // Show success message
                $('#myfeeds-success-message').fadeIn(300);
                
                // Bug 2 fix: Update per-feed mapping quality in the table
                if (status.feed_qualities) {
                    $.each(status.feed_qualities, function(feedName, quality) {
                        var $row = $('tr[data-feed-name="' + feedName + '"]');
                        if ($row.length && quality > 0) {
                            var $qualityCell = $row.find('td').eq(5);
                            $qualityCell.html('<div class="myfeeds-confidence-bar myfeeds-quality-clickable" data-feed-name="' + feedName + '" title="Click for details" style="cursor:pointer;">' +
                                '<div class="myfeeds-confidence-fill" style="width:' + quality + '%"></div>' +
                                '<span class="myfeeds-confidence-text">' + Math.round(quality) + '%</span></div>');
                        }
                    });
                }
                
                // Auto-hide after 5 seconds
                setTimeout(function() {
                    hideStatusPanel();
                }, 5000);
                
                // Refresh header stats (defined in separate script scope)
                if (typeof window.refreshHeaderStats === 'function') {
                    window.refreshHeaderStats();
                }
                
                // Fix: Update all IMPORTING badges to current server status
                // (covers page-reload during import where startReimportPolling is not active)
                $('.feed-status-importing').each(function() {
                    var $badge = $(this);
                    var $cell = $badge.closest('td');
                    var $row = $badge.closest('tr');
                    var feedKey = $row.find('.myfeeds-reimport-btn').data('feed-key');
                    if (feedKey === undefined) return;
                    
                    $.get(ajaxurl, {
                        action: 'myfeeds_get_feed_status',
                        feed_key: feedKey,
                        nonce: nonce
                    }).done(function(response) {
                        if (!response.success) return;
                        var data = response.data;
                        if (data.status === 'importing') return; // still running
                        
                        if (data.status === 'active') {
                            $cell.html('<span class="feed-status-badge feed-status-active">Active</span>' +
                                (data.last_sync ? '<br><small>' + data.last_sync + '</small>' : ''));
                        } else if (data.status === 'failed') {
                            $cell.html('<span class="feed-status-badge feed-status-failed" title="' + (data.last_error || '') + '" style="cursor:help;">Failed</span>');
                        }
                        
                        // Update product count
                        var $countCell = $row.find('.myfeeds-feed-product-count');
                        if ($countCell.length && data.product_count > 0) {
                            $countCell.html('<strong>' + formatNumber(data.product_count) + '</strong>' +
                                (data.last_sync ? '<br><small>Last sync: ' + data.last_sync + '</small>' : ''));
                        }
                        
                        // Re-enable buttons
                        $row.find('.button').prop('disabled', false);
                        $row.find('.myfeeds-reimport-btn').text('Reimport').removeAttr('title');
                    });
                });
            }
            
            // Close button for success message
            $(document).on('click', '#myfeeds-close-success', function() {
                hideStatusPanel();
            });
            
            function updateStatusDisplay(status) {
                // CRITICAL: Use server value directly (no client-side calculation)
                // Server guarantees: 100% only when status === 'completed'
                var percent = status.progress_percent || 0;
                var totalFeeds = status.total_feeds || 1;
                var processedFeeds = status.processed_feeds || 0;
                var isSingleFeed = (status.import_type === 'single_feed');
                
                // Single-feed import: orange gradient
                var $fill = $('#myfeeds-progress-fill');
                if (isSingleFeed) {
                    $fill.addClass('single-feed');
                } else {
                    $fill.removeClass('single-feed');
                }
                
                // Update progress bar
                $fill.css('width', percent + '%');
                $('#myfeeds-import-percent').text(percent + '%');
                
                // Phase indicator - different text for different modes/phases
                var phaseText = '';
                var statsText = '';
                
                if (status.mode === 'active_only') {
                    // =====================================================================
                    // QUICK SYNC: Show found / searched products
                    // Example: "3 of 5 products synced"
                    // =====================================================================
                    var foundProducts = status.found_products || status.processed_products || 0;
                    var activeCount = status.active_ids_count || 0;
                    
                    if (status.status === 'completed') {
                        var elapsedMs = status.elapsed_ms || 0;
                        var timeText = elapsedMs > 0 ? ' in ' + elapsedMs + 'ms' : '';
                        phaseText = '✅ ' + foundProducts + ' of ' + activeCount + ' products synced' + timeText + '!';
                    } else {
                        phaseText = '⚡ Searching ' + foundProducts + ' of ' + activeCount + ' products...';
                    }
                    
                    // Stats for Quick Sync: found / searched
                    statsText = foundProducts + '/' + activeCount;
                    $('#myfeeds-import-products').text(statsText);
                    $('#myfeeds-import-feeds').text(processedFeeds + '/' + totalFeeds);
                } else {
                    // Full mode with phases
                    if (status.phase === 'done' || status.status === 'completed') {
                        phaseText = '✅ Import completed!';
                    } else if (isSingleFeed) {
                        var rowsProcessed = status.processed_rows || 0;
                        phaseText = 'Processing products...';
                        if (rowsProcessed > 0) {
                            phaseText = formatNumber(rowsProcessed) + ' rows processed...';
                        }
                    } else if (status.phase === 'priority_active') {
                        phaseText = '🎯 Phase 1/2: Updating active products first...';
                    } else if (status.phase === 'remapping') {
                        phaseText = '🧠 Analyzing mappings...';
                    } else if (status.phase === 'import') {
                        phaseText = '📦 Phase 2/2: Importing remaining products...';
                    }
                    
                    // Stats: total_products now comes from DB (monotonically increasing)
                    var productCount = status.total_products || 0;
                    $('#myfeeds-import-products').text(formatNumber(productCount));
                    if (isSingleFeed) {
                        $('#myfeeds-import-feeds').text('1 Feed');
                    } else {
                        $('#myfeeds-import-feeds').text(processedFeeds + '/' + totalFeeds);
                    }
                }
                
                $('#myfeeds-import-phase').text(phaseText);
                
                // Current feed
                if (status.current_feed_name && status.status !== 'completed') {
                    $('#myfeeds-import-feed').text('→ ' + status.current_feed_name);
                } else {
                    $('#myfeeds-import-feed').text('');
                }
                
                // Title - different for different modes
                if (status.status === 'running') {
                    var title = '';
                    if (isSingleFeed && status.single_feed_name) {
                        title = 'Importing: ' + status.single_feed_name;
                    } else if (status.mode === 'active_only') {
                        title = '⚡ Quick Sync in progress...';
                    } else if (status.phase === 'priority_active') {
                        title = '🎯 Priority Update in progress...';
                    } else {
                        title = '🔄 Full Update in progress...';
                    }
                    $('#myfeeds-status-title').text(title);
                }
                
                // Live-Update: Feed product counts (per-feed only, NOT header)
                if (status.feed_product_counts) {
                    updateFeedProductCounts(status.feed_product_counts);
                }
                
                // Bug 1 fix: Always update header "TOTAL PRODUCTS" with real DB count
                if (status.header_total_products !== undefined) {
                    $('#myfeeds-total-products').text(formatNumber(status.header_total_products));
                }
            }
            
            // Live-update feed table product counts and Total Products header
            function updateFeedProductCounts(feedCounts) {
                // Update per-feed counts in the table (NOT the header — that uses header_total_products)
                $.each(feedCounts, function(feedName, count) {
                    var row = $('tr[data-feed-name="' + feedName + '"]');
                    if (row.length) {
                        row.find('.myfeeds-feed-product-count strong').text(formatNumber(count));
                    }
                });
            }
            
            // Cancel import
            $('#myfeeds-cancel-import').on('click', function() {
                if (!confirm('Cancel the current update?')) return;
                
                $.post(ajaxurl, {
                    action: 'myfeeds_cancel_import',
                    nonce: nonce
                }, function(response) {
                    clearInterval(importInterval);
                    importInterval = null;
                    hideStatusPanel();
                    
                    // Bug 3 fix: Stop reimport polling and restore feed row UI
                    if (window.currentReimportInterval) {
                        clearInterval(window.currentReimportInterval);
                        window.currentReimportInterval = null;
                    }
                    
                    if (window.currentReimportFeedKey !== null) {
                        var $targetRow = $('.myfeeds-reimport-btn[data-feed-key="' + window.currentReimportFeedKey + '"]').closest('tr');
                        if ($targetRow.length) {
                            // Restore status badge from server-returned status (robust: server knows truth)
                            var feedStatus = 'active';
                            if (response.data && response.data.feed_statuses && response.data.feed_statuses[window.currentReimportFeedKey]) {
                                feedStatus = response.data.feed_statuses[window.currentReimportFeedKey];
                            }
                            var badgeClass = 'feed-status-' + feedStatus;
                            var badgeText = feedStatus.charAt(0).toUpperCase() + feedStatus.slice(1);
                            var $statusCell = $targetRow.find('td').eq(3);
                            $statusCell.html('<span class="feed-status-badge ' + badgeClass + '">' + badgeText + '</span>');
                            
                            // Re-enable all buttons in the row
                            $targetRow.find('.button').prop('disabled', false);
                            var $reimportBtn = $targetRow.find('.myfeeds-reimport-btn');
                            $reimportBtn.text('Reimport').removeAttr('title');
                        }
                        window.currentReimportFeedKey = null;
                    }
                    
                    // Re-enable all reimport/delete buttons
                    updateReimportButtons(false);
                    
                    // Refresh header stats with real DB values
                    if (typeof window.refreshHeaderStats === 'function') {
                        window.refreshHeaderStats();
                    }
                });
            });
            
            // Check if import is already running on page load
            $.post(ajaxurl, {
                action: 'myfeeds_get_import_status',
                nonce: nonce
            }, function(response) {
                if (response.success && response.data.status === 'running') {
                    showStatusPanel();
                    startStatusPolling();
                    updateReimportButtons(true);
                    updateStatusDisplay(response.data);
                }
            });
            
            // =====================================================================
            // Mapping Quality Detail Modal
            // =====================================================================
            $(document).on('click', '.myfeeds-quality-clickable', function() {
                var feedName = $(this).data('feed-name');
                if (!feedName) return;
                
                $('#myfeeds-quality-modal-title').text('Mapping Quality: ' + feedName);
                $('#myfeeds-quality-modal-body').html('<p style="text-align:center;">Loading...</p>');
                $('#myfeeds-quality-modal').show();
                
                $.post(ajaxurl, {
                    action: 'myfeeds_get_mapping_quality',
                    nonce: nonce,
                    feed_name: feedName
                }, function(response) {
                    if (response.success) {
                        renderQualityDetails(response.data, feedName);
                    } else {
                        $('#myfeeds-quality-modal-body').html('<p>Error loading quality data.</p>');
                    }
                }).fail(function() {
                    $('#myfeeds-quality-modal-body').html('<p>Request failed.</p>');
                });
            });
            
            // Close modal
            $(document).on('click', '#myfeeds-quality-close, .myfeeds-modal-overlay', function(e) {
                if (e.target === this) {
                    $('#myfeeds-quality-modal').hide();
                }
            });
            $(document).on('click', '.myfeeds-modal-content', function(e) {
                e.stopPropagation();
            });
            
            function renderQualityDetails(data, feedName) {
                var html = '';
                
                // Summary — smaller percentage, more spacing
                html += '<div style="text-align:center; margin-bottom:24px;">';
                html += '<div style="font-size:32px; font-weight:bold; line-height:1.2; margin-bottom:8px; color:' + (data.quality >= 90 ? '#00a32a' : data.quality >= 70 ? '#f39c12' : '#d63638') + ';">' + data.quality + '%</div>';
                html += '<div style="color:#666; font-size:13px;">' + data.complete + ' of ' + data.total + ' products have all required fields</div>';
                html += '</div>';
                
                // Field tier labels
                var tierLabels = {
                    'required':  '<span style="color:#d63638; font-size:10px; font-weight:600; text-transform:uppercase;">REQUIRED</span>',
                    'important': '<span style="color:#dba617; font-size:10px; font-weight:600; text-transform:uppercase;">IMPORTANT</span>',
                    'optional':  '<span style="color:#888; font-size:10px; font-weight:600; text-transform:uppercase;">OPTIONAL</span>'
                };
                
                // Group fields by tier
                var tiers = { 'required': [], 'important': [], 'optional': [] };
                var fields = data.fields || {};
                $.each(fields, function(fieldName, info) {
                    var tier = info.tier || (info.required ? 'required' : 'optional');
                    if (tiers[tier]) {
                        tiers[tier].push({ name: fieldName, info: info });
                    }
                });
                
                // Render each tier
                $.each(['required', 'important', 'optional'], function(i, tier) {
                    if (tiers[tier].length === 0) return;
                    
                    var tierTitle = tier === 'required' ? 'Required Fields' : tier === 'important' ? 'Important Fields' : 'Optional Fields';
                    html += '<h4 style="margin:16px 0 6px; font-size:13px; color:#444;">' + tierTitle + '</h4>';
                    
                    $.each(tiers[tier], function(j, field) {
                        var label = tierLabels[tier] || '';
                        var status = field.info.missing > 0 
                            ? '<span class="myfeeds-quality-field-missing">' + field.info.missing.toLocaleString() + ' missing</span>'
                            : '<span class="myfeeds-quality-field-ok">All filled</span>';
                        html += '<div class="myfeeds-quality-field-row">';
                        html += '<span class="myfeeds-quality-field-name">' + field.name + ' ' + label + '</span>';
                        html += status;
                        html += '</div>';
                    });
                });
                
                // Worst products
                if (data.worst_products && data.worst_products.length > 0) {
                    html += '<h4 style="margin:20px 0 8px;">Products with Most Missing Fields</h4>';
                    $.each(data.worst_products, function(i, product) {
                        if (product.missing_count === 0) return;
                        html += '<div class="myfeeds-quality-product-item">';
                        html += '<strong>' + (product.product_name || '(no name)') + '</strong>';
                        html += '<code>ID: ' + product.external_id + '</code>';
                        html += '<div class="missing-tags">';
                        $.each(product.missing_fields, function(j, field) {
                            html += '<span>' + field + '</span>';
                        });
                        html += '</div></div>';
                    });
                }
                
                $('#myfeeds-quality-modal-body').html(html);
            }
            
            // Expose functions globally for cross-scope access (Block 2 needs these)
            window.showStatusPanel = showStatusPanel;
            window.hideStatusPanel = hideStatusPanel;
            window.startStatusPolling = startStatusPolling;
        });

        jQuery(document).ready(function($) {
            var $modal = $('#myfeeds-feed-modal');
            var $title = $('#myfeeds-feed-modal-title');
            var $form = $('#myfeeds-feed-form');
            var $submitBtn = $('#myfeeds-feed-submit');
            var $feedKey = $('#myfeeds-feed-key');
            var ajaxUrl = myfeedsAdmin.ajaxUrl;
            var nonce = myfeedsAdmin.nonce;
            var isSaving = false;
            
            // Open modal for Add
            $(document).on('click', '#myfeeds-add-feed-btn', function(e) {
                e.preventDefault();
                $title.text(i18n.addNewFeed);
                $submitBtn.val(i18n.createFeed);
                $feedKey.val('');
                $('#feed_name').val('');
                $('#feed_url').val('');
                $('#feed_format_hint').val('');
                $('#feed_network_hint').val('');
                $modal.fadeIn(200);
                $('#feed_name').focus();
            });
            
            // Open modal for Edit
            $(document).on('click', '.myfeeds-edit-feed-btn', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var key = $btn.data('feed-key');
                var name = $btn.data('feed-name');
                var url = $btn.data('feed-url');
                var formatHint = $btn.data('feed-format') || '';
                var networkHint = $btn.data('feed-network') || 'awin';
                
                $title.text(i18n.editFeed + ' ' + name);
                $submitBtn.val(i18n.saveChanges);
                $feedKey.val(key);
                $('#feed_name').val(name);
                $('#feed_url').val(url);
                $('#feed_format_hint').val(formatHint);
                $('#feed_network_hint').val(networkHint);
                $modal.fadeIn(200);
                $('#feed_name').focus();
            });
            
            // Close modal
            function closeFeedModal() {
                if (!isSaving) {
                    $modal.fadeOut(200);
                }
            }
            
            $('#myfeeds-feed-modal-close').on('click', closeFeedModal);
            $modal.on('click', function(e) {
                if ($(e.target).is('.myfeeds-modal-overlay')) {
                    closeFeedModal();
                }
            });
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && $modal.is(':visible')) {
                    closeFeedModal();
                }
            });
            
            // AJAX form submit
            $form.on('submit', function(e) {
                e.preventDefault();
                if (isSaving) return;
                
                // Basic validation
                var feedName = $('#feed_name').val().trim();
                var feedUrl = $('#feed_url').val().trim();
                if (!feedName || !feedUrl) {
                    alert(i18n.feedNameUrlRequired);
                    return;
                }

                isSaving = true;
                var isEdit = $feedKey.val() !== '';
                var actionLabel = isEdit ? i18n.savingChanges : i18n.addingFeed;
                
                // Close modal and show loading
                $modal.fadeOut(200);
                $('.myfeeds-feed-saving-notice').remove();
                var $notice = $('<div class="myfeeds-feed-saving-notice" data-testid="feed-saving-notice">' +
                    '<span class="spinner is-active"></span>' +
                    '<span>' + actionLabel + '</span>' +
                    '</div>');
                $('.myfeeds-feeds-table-header').after($notice);
                
                // Collect form data
                var formData = $form.serialize();
                formData += '&action=myfeeds_save_feed_ajax';
                formData += '&nonce=' + nonce;
                
                $.post(ajaxUrl, formData)
                    .done(function(response) {
                        if (response.success) {
                            // Show success
                            $notice.css('border-left-color', '#46b450')
                                .html('<span style="color:#46b450;font-weight:600;">&#10003;</span> ' + response.data.message);
                            
                            // If import was scheduled for a NEW feed, insert row without page reload
                            if (response.data.import_scheduled && response.data.action === 'created') {
                                var feedKey = response.data.feed_key;
                                var feedName = $('#feed_name').val();
                                var feedUrl = $('#feed_url').val();
                                var feedHost = '';
                                try { feedHost = new URL(feedUrl).hostname; } catch(e) { feedHost = feedUrl.substring(0, 40); }
                                var detectedNetwork = response.data.detected_network || 'auto-detected';
                                
                                var newRow = '<tr data-feed-name="' + feedName + '" data-feed-key="' + feedKey + '">' +
                                    '<td><strong>' + feedName + '</strong></td>' +
                                    '<td><span class="myfeeds-feed-url" title="' + feedUrl + '">' + feedHost + '</span></td>' +
                                    '<td><span class="myfeeds-network-badge ' + detectedNetwork.toLowerCase() + '">' + detectedNetwork.charAt(0).toUpperCase() + detectedNetwork.slice(1) + '</span></td>' +
                                    '<td><span class="feed-status-badge feed-status-importing">IMPORTING...</span></td>' +
                                    '<td class="myfeeds-feed-product-count"><strong>0</strong></td>' +
                                    '<td><span class="myfeeds-confidence-unknown">Analyzing...</span></td>' +
                                    '<td class="myfeeds-action-buttons">' +
                                        '<button type="button" class="button button-small myfeeds-edit-feed-btn" data-feed-key="' + feedKey + '" data-feed-name="' + feedName + '" data-feed-url="' + feedUrl + '" disabled>Edit</button> ' +
                                        '<button type="button" class="button button-small myfeeds-reimport-btn" data-feed-key="' + feedKey + '" data-feed-name="' + feedName + '" disabled title="Import already running">Importing...</button> ' +
                                        '<button type="button" class="button button-small button-link-delete myfeeds-delete-feed-btn" data-feed-key="' + feedKey + '" data-feed-name="' + feedName + '" data-product-count="0" disabled>Delete</button>' +
                                    '</td></tr>';
                                
                                // Remove "Welcome to MyFeeds" empty state row if present
                                $('.myfeeds-empty-state').closest('tr').remove();
                                
                                // Append new row to table
                                $('.myfeeds-feeds-table tbody').append(newRow);
                                
                                // Update header active feeds count
                                var currentFeeds = parseInt($('#myfeeds-active-feeds').text()) || 0;
                                $('#myfeeds-active-feeds').text(currentFeeds + 1);
                                
                                // Start polling for this specific feed
                                startReimportPolling(feedKey);
                                
                                // Also show import status panel and start polling
                                if (typeof window.showStatusPanel === 'function') {
                                    window.showStatusPanel();
                                    window.startStatusPolling();
                                }
                                
                                // Remove saving notice after a moment
                                setTimeout(function() { $notice.fadeOut(400, function() { $(this).remove(); }); }, 3000);
                                
                                isSaving = false;
                            } else {
                                // Normal reload for edits
                                setTimeout(function() {
                                    window.location.href = cfg.feedsPageUrl + '&myfeeds_success=' + encodeURIComponent(response.data.message);
                                }, 800);
                            }
                        } else {
                            // Show error
                            isSaving = false;
                            $notice.css('border-left-color', '#dc3232')
                                .html('<span style="color:#dc3232;font-weight:600;">&#10007;</span> ' + (response.data ? response.data.message : i18n.unknownError));
                            setTimeout(function() { $notice.fadeOut(400, function() { $(this).remove(); }); }, 6000);
                        }
                    })
                    .fail(function(xhr) {
                        isSaving = false;
                        var msg = i18n.serverError;
                        if (xhr.responseJSON && xhr.responseJSON.data) {
                            msg = xhr.responseJSON.data.message || msg;
                        }
                        $notice.css('border-left-color', '#dc3232')
                            .html('<span style="color:#dc3232;font-weight:600;">&#10007;</span> ' + msg);
                        setTimeout(function() { $notice.fadeOut(400, function() { $(this).remove(); }); }, 6000);
                    });
            });
            
            // ===================================================================
            // AUTO-REFRESH POLLING: Check feed status after creation
            // Activated via URL parameter ?myfeeds_poll_feed=<key>
            // Polls every 5 seconds, stops after status change or 5 min timeout
            // ===================================================================
            var urlParams = new URLSearchParams(window.location.search);
            var pollFeedKey = urlParams.get('myfeeds_poll_feed');
            
            if (pollFeedKey !== null && pollFeedKey !== '') {
                var pollInterval = null;
                var pollCount = 0;
                var maxPolls = 60; // 5 min at 5-second intervals
                
                pollInterval = setInterval(function() {
                    pollCount++;
                    if (pollCount > maxPolls) {
                        clearInterval(pollInterval);
                        console.log('MyFeeds: Poll timeout reached (5 min), stopping');
                        return;
                    }
                    
                    $.get(ajaxUrl, {
                        action: 'myfeeds_get_feed_status',
                        feed_key: pollFeedKey,
                        nonce: nonce
                    }).done(function(response) {
                        if (!response.success) return;
                        var data = response.data;
                        
                        // Still importing — update badge to show pulsing orange, keep polling
                        if (data.status === 'importing') {
                            var $rows = $('.myfeeds-feeds-table tbody tr');
                            var $targetRow = $rows.eq(parseInt(pollFeedKey));
                            if ($targetRow.length) {
                                var $statusCell = $targetRow.find('td').eq(3);
                                if ($statusCell.find('.feed-status-importing').length === 0) {
                                    $statusCell.html('<span class="feed-status-badge feed-status-importing">IMPORTING...</span>');
                                }
                            }
                            return;
                        }
                        
                        // Import finished — update the row and stop polling
                        clearInterval(pollInterval);
                        
                        // Find the table row for this feed
                        var $rows = $('.myfeeds-feeds-table tbody tr');
                        var $targetRow = $rows.eq(parseInt(pollFeedKey));
                        
                        if ($targetRow.length) {
                            // Update status badge
                            var $statusCell = $targetRow.find('td').eq(3); // 4th column = Status
                            if (data.status === 'active') {
                                $statusCell.html('<span class="feed-status-badge feed-status-active">Active</span>' + 
                                    (data.last_sync ? '<br><small>' + data.last_sync + '</small>' : ''));
                            } else if (data.status === 'failed') {
                                $statusCell.html('<span class="feed-status-badge feed-status-failed" title="' + (data.last_error || '') + '" style="cursor:help;">Failed</span>' +
                                    (data.last_error ? '<br><small style="color:#d63638;max-width:200px;display:inline-block;">' + data.last_error.substring(0, 80) + '</small>' : ''));
                            }
                            
                            // Update product count
                            var $countCell = $targetRow.find('.myfeeds-feed-product-count');
                            if ($countCell.length && data.product_count > 0) {
                                $countCell.html('<strong>' + formatNumber(data.product_count) + '</strong>' + 
                                    (data.last_sync ? '<br><small>Last sync: ' + data.last_sync + '</small>' : ''));
                            }
                            
                            // Update mapping quality (Fix 1: now always returned fresh from DB)
                            if (data.mapping_confidence > 0) {
                                var $qualityCell = $targetRow.find('td').eq(5); // 6th column = Mapping
                                var feedName = data.name || $targetRow.data('feed-name') || '';
                                $qualityCell.html('<div class="myfeeds-confidence-bar myfeeds-quality-clickable" data-feed-name="' + feedName + '" title="Click for details" style="cursor:pointer;">' +
                                    '<div class="myfeeds-confidence-fill" style="width:' + data.mapping_confidence + '%"></div>' +
                                    '<span class="myfeeds-confidence-text">' + Math.round(data.mapping_confidence) + '%</span></div>');
                            }
                            
                            // Fix 2: Re-enable ALL buttons in the row
                            $targetRow.find('.button').prop('disabled', false);
                            var $reimportBtn = $targetRow.find('.myfeeds-reimport-btn');
                            $reimportBtn.text('Reimport').removeAttr('title');
                        }
                        
                        // Fix 3: Hide progress bar
                        hideStatusPanel();
                        if (importInterval) {
                            clearInterval(importInterval);
                            importInterval = null;
                        }
                        
                        // Fix 4: Refresh header stats (includes real TOTAL PRODUCTS) + Fix 3: Update last sync
                        refreshHeaderStats();
                        
                        console.log('MyFeeds: Feed ' + pollFeedKey + ' import finished — status=' + data.status + ', products=' + data.product_count);
                    });
                }, 5000);
                
                console.log('MyFeeds: Started status polling for feed key=' + pollFeedKey);
            }
            
            // ===================================================================
            // REIMPORT BUTTON: Trigger single-feed reimport via AJAX (Fix 4: no confirm dialog)
            // ===================================================================
            $(document).on('click', '.myfeeds-reimport-btn', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var feedKey = $btn.data('feed-key');
                var feedName = $btn.data('feed-name');
                
                if ($btn.prop('disabled')) return;
                
                // Fix 4: No confirmation dialog — start immediately
                // Disable button + all action buttons in the row
                var $row = $btn.closest('tr');
                $btn.prop('disabled', true).text('Starting...');
                $row.find('.button').prop('disabled', true);
                
                // Track reimport state for cancel recovery (Bug 3 fix)
                window.currentReimportFeedKey = feedKey;
                
                $.post(ajaxUrl, {
                    action: 'myfeeds_reimport_feed',
                    nonce: nonce,
                    feed_key: feedKey
                }).done(function(response) {
                    if (response.success) {
                        // Update badge to importing
                        var $statusCell = $row.find('td').eq(3);
                        $statusCell.html('<span class="feed-status-badge feed-status-importing">IMPORTING...</span>');
                        
                        $btn.text('Importing...');
                        
                        // Show import status panel and start polling
                        showStatusPanel();
                        startStatusPolling();
                        
                        // Start feed-specific polling to update row when done
                        startReimportPolling(feedKey);
                    } else {
                        alert(response.data ? response.data.message : 'Failed to start reimport');
                        $btn.prop('disabled', false).text('Reimport');
                        $row.find('.button').prop('disabled', false);
                    }
                }).fail(function() {
                    alert('Server error. Please try again.');
                    $btn.prop('disabled', false).text('Reimport');
                    $row.find('.button').prop('disabled', false);
                });
            });
            
            // Reimport polling: watch a specific feed until it finishes (Fix 2+3: full state restore)
            function startReimportPolling(feedKey) {
                var reimportPollCount = 0;
                var reimportMaxPolls = 360; // 30 min at 5-second intervals
                
                // Store interval for cancel recovery (Bug 3 fix)
                if (window.currentReimportInterval) {
                    clearInterval(window.currentReimportInterval);
                }
                
                window.currentReimportInterval = setInterval(function() {
                    reimportPollCount++;
                    if (reimportPollCount > reimportMaxPolls) {
                        clearInterval(window.currentReimportInterval);
                        window.currentReimportInterval = null;
                        window.currentReimportFeedKey = null;
                        return;
                    }
                    
                    $.get(ajaxUrl, {
                        action: 'myfeeds_get_feed_status',
                        feed_key: feedKey,
                        nonce: nonce
                    }).done(function(response) {
                        if (!response.success) return;
                        var data = response.data;
                        
                        // Still importing — keep polling
                        if (data.status === 'importing') return;
                        
                        // Import finished — full state restore (Fix 3)
                        clearInterval(window.currentReimportInterval);
                        window.currentReimportInterval = null;
                        window.currentReimportFeedKey = null;
                        
                        var $targetRow = $('.myfeeds-reimport-btn[data-feed-key="' + feedKey + '"]').closest('tr');
                        
                        if ($targetRow.length) {
                            // Update status badge
                            var $statusCell = $targetRow.find('td').eq(3);
                            if (data.status === 'active') {
                                $statusCell.html('<span class="feed-status-badge feed-status-active">Active</span>' + 
                                    (data.last_sync ? '<br><small>' + data.last_sync + '</small>' : ''));
                            } else if (data.status === 'failed') {
                                $statusCell.html('<span class="feed-status-badge feed-status-failed" title="' + (data.last_error || '') + '" style="cursor:help;">Failed</span>');
                            }
                            
                            // Update product count with last sync
                            var $countCell = $targetRow.find('.myfeeds-feed-product-count');
                            if ($countCell.length && data.product_count > 0) {
                                $countCell.html('<strong>' + formatNumber(data.product_count) + '</strong>' + 
                                    (data.last_sync ? '<br><small>Last sync: ' + data.last_sync + '</small>' : ''));
                            }
                            
                            // Fix 1: Update mapping quality (now returned from server)
                            if (data.mapping_confidence > 0) {
                                var $qualityCell = $targetRow.find('td').eq(5);
                                var feedName = data.name || $targetRow.data('feed-name') || '';
                                $qualityCell.html('<div class="myfeeds-confidence-bar myfeeds-quality-clickable" data-feed-name="' + feedName + '" title="Click for details" style="cursor:pointer;">' +
                                    '<div class="myfeeds-confidence-fill" style="width:' + data.mapping_confidence + '%"></div>' +
                                    '<span class="myfeeds-confidence-text">' + Math.round(data.mapping_confidence) + '%</span></div>');
                            }
                            
                            // Fix 2: Re-enable ALL buttons in the row
                            $targetRow.find('.button').prop('disabled', false);
                            var $reimportBtn = $targetRow.find('.myfeeds-reimport-btn');
                            $reimportBtn.text('Reimport').removeAttr('title');
                        }
                        
                        // Fix 3: Hide progress bar and stop main polling
                        hideStatusPanel();
                        if (importInterval) {
                            clearInterval(importInterval);
                            importInterval = null;
                        }
                        
                        // Fix 4: Refresh header stats + Fix 3: Update last sync
                        refreshHeaderStats();
                        
                        console.log('MyFeeds: Reimport finished for feed ' + feedKey + ' — status=' + data.status);
                    });
                }, 5000);
            }
            
            // Disable reimport buttons if a global import is running (Fix 6: also handle delete buttons)
            function updateReimportButtons(importRunning) {
                if (importRunning) {
                    $('.myfeeds-reimport-btn').prop('disabled', true).attr('title', 'Import already running');
                    $('.myfeeds-delete-feed-btn').prop('disabled', true);
                } else {
                    // Re-enable all reimport buttons and remove title
                    $('.myfeeds-reimport-btn').each(function() {
                        var $btn = $(this);
                        // Only re-enable if the button text is not "Importing..." (that feed has its own polling)
                        if ($btn.text().trim() !== 'Importing...') {
                            $btn.prop('disabled', false).removeAttr('title');
                        }
                    });
                    // Re-enable delete and edit buttons
                    $('.myfeeds-feeds-table .myfeeds-edit-feed-btn').prop('disabled', false);
                    $('.myfeeds-feeds-table .myfeeds-delete-feed-btn').prop('disabled', false);
                }
            }
            
            // ===================================================================
            // Fix 1: DELETE FEED AJAX handler with product cleanup
            // ===================================================================
            $(document).on('click', '.myfeeds-delete-feed-btn', function(e) {
                e.preventDefault();
                var $btn = $(this);
                if ($btn.prop('disabled')) return;
                
                var feedKey = $btn.data('feed-key');
                var feedName = $btn.data('feed-name');
                var productCount = parseInt($btn.data('product-count')) || 0;
                
                var msg = 'Delete feed "' + feedName + '"';
                if (productCount > 0) {
                    msg += ' and all its ' + formatNumber(productCount) + ' products';
                }
                msg += '? This cannot be undone.';
                
                if (!confirm(msg)) return;
                
                $btn.prop('disabled', true).text('Deleting...');
                
                $.post(ajaxUrl, {
                    action: 'myfeeds_delete_feed',
                    nonce: nonce,
                    feed_key: feedKey
                }).done(function(response) {
                    if (response.success) {
                        // Remove the table row
                        var $row = $btn.closest('tr');
                        $row.fadeOut(300, function() { $(this).remove(); });
                        
                        // Update header stats from server response
                        var d = response.data;
                        $('#myfeeds-active-feeds').text(d.active_feeds);
                        $('#myfeeds-total-products').text(d.total_products_formatted || formatNumber(d.total_products));
                        $('#myfeeds-avg-quality').text(d.avg_quality + '%');
                        
                        console.log('MyFeeds: Feed "' + feedName + '" deleted, ' + d.deleted_products + ' products removed');
                    } else {
                        alert(response.data ? response.data.message : 'Delete failed');
                        $btn.prop('disabled', false).text('Delete');
                    }
                }).fail(function() {
                    alert('Server error. Please try again.');
                    $btn.prop('disabled', false).text('Delete');
                });
            });
            
            // ===================================================================
            // Fix 4: Update header stats from server
            // ===================================================================
            function refreshHeaderStats() {
                $.get(ajaxUrl, {
                    action: 'myfeeds_get_header_stats',
                    nonce: nonce
                }).done(function(response) {
                    if (response.success) {
                        var d = response.data;
                        $('#myfeeds-active-feeds').text(d.active_feeds);
                        $('#myfeeds-total-products').text(d.total_products_formatted || formatNumber(d.total_products));
                        $('#myfeeds-avg-quality').text(d.avg_quality + '%');
                        if (d.last_sync_text) {
                            $('#myfeeds-last-sync-text').text(d.last_sync_text);
                        }
                    }
                });
            }
            // Expose globally for cross-scope access (showCompletionState is in a separate script block)
            window.refreshHeaderStats = refreshHeaderStats;
            
        });

})();
