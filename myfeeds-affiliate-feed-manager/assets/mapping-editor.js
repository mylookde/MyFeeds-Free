jQuery(document).ready(function($) {
    var cfg = window.myfeedsMapping || {};
    var i18n = cfg.i18n || {};
    var currentFeedKey = null;
    var currentMapping = {};
    var feedColumns = [];

    // Feed selector change
    $('#myfeeds-feed-selector').on('change', function() {
        var feedKey = $(this).val();
        if (feedKey) {
            currentFeedKey = feedKey;
            loadFeedColumns(feedKey);
            $('#myfeeds-mapping-interface').show();
        } else {
            $('#myfeeds-mapping-interface').hide();
        }
    });

    // Load feed columns via AJAX
    function loadFeedColumns(feedKey) {
        $('#myfeeds-feed-columns').html('<p>Loading...</p>');

        $.post(ajaxurl, {
            action: 'myfeeds_get_feed_columns',
            feed_key: feedKey,
            nonce: myfeedsAdmin.nonce
        }, function(response) {
            if (response.success) {
                feedColumns = response.data.columns;
                currentMapping = response.data.current_mapping || {};

                renderFeedColumns(feedColumns);
                populateDropdowns(feedColumns);
                applyCurrentMapping(currentMapping);
                renderPreview(response.data.sample_data);
            } else {
                $('#myfeeds-feed-columns').html('<p class="error">' + response.data.message + '</p>');
            }
        });
    }

    // Render available columns
    function renderFeedColumns(columns) {
        var html = '';
        columns.forEach(function(col) {
            html += '<span class="myfeeds-column-tag" data-column="' + col + '">' + col + '</span>';
        });
        $('#myfeeds-feed-columns').html(html);
    }

    // Populate field dropdowns with columns
    function populateDropdowns(columns) {
        $('.myfeeds-field-mapping').each(function() {
            var $select = $(this);
            var currentVal = $select.val();

            $select.find('option:not(:first)').remove();

            columns.forEach(function(col) {
                $select.append('<option value="' + col + '">' + col + '</option>');
            });

            if (currentVal) {
                $select.val(currentVal);
            }
        });
    }

    // Apply current mapping to dropdowns
    function applyCurrentMapping(mapping) {
        Object.keys(mapping).forEach(function(field) {
            var value = mapping[field];
            if (typeof value === 'string') {
                $('.myfeeds-field-mapping[data-field="' + field + '"]').val(value);
            }
        });
    }

    // Render preview
    function renderPreview(sampleData) {
        if (sampleData) {
            $('#myfeeds-mapping-preview').html('<pre>' + JSON.stringify(sampleData, null, 2) + '</pre>');
        }
    }

    // Column tag click - copy to clipboard or auto-fill
    $(document).on('click', '.myfeeds-column-tag', function() {
        var col = $(this).data('column');
        // Find first empty required field and fill it
        var filled = false;
        $('.myfeeds-field-row').each(function() {
            var $select = $(this).find('.myfeeds-field-mapping');
            if (!$select.val() && $(this).find('.required').length > 0) {
                $select.val(col);
                filled = true;
                return false;
            }
        });

        if (!filled) {
            alert('Column: ' + col + '\n\nSelect this column in one of the dropdowns.');
        }
    });

    // Save mapping
    $('#myfeeds-save-mapping').on('click', function() {
        var mapping = collectMapping();

        $.post(ajaxurl, {
            action: 'myfeeds_save_mapping',
            feed_key: currentFeedKey,
            mapping: JSON.stringify(mapping),
            nonce: myfeedsAdmin.nonce
        }, function(response) {
            if (response.success) {
                alert(i18n.mappingSaved);
            } else {
                alert('Error: ' + response.data.message);
            }
        });
    });

    // Collect mapping from form
    function collectMapping() {
        var mapping = {};
        $('.myfeeds-field-mapping').each(function() {
            var field = $(this).data('field');
            var value = $(this).val();
            if (value) {
                mapping[field] = value;
            }
        });
        return mapping;
    }

    // Save as template
    $('#myfeeds-save-as-template').on('click', function() {
        $('#myfeeds-template-modal').show();
    });

    $('.myfeeds-modal-close').on('click', function() {
        $('#myfeeds-template-modal').hide();
    });

    $('#myfeeds-template-save-confirm').on('click', function() {
        var name = $('#myfeeds-template-name').val();
        var network = $('#myfeeds-template-network').val();
        var mapping = collectMapping();

        if (!name) {
            alert(i18n.enterTemplateName);
            return;
        }

        $.post(ajaxurl, {
            action: 'myfeeds_save_template',
            name: name,
            network: network,
            mapping: JSON.stringify(mapping),
            nonce: myfeedsAdmin.nonce
        }, function(response) {
            if (response.success) {
                alert(i18n.templateSaved);
                $('#myfeeds-template-modal').hide();
                location.reload();
            } else {
                alert('Error: ' + response.data.message);
            }
        });
    });

    // Apply template
    $('#myfeeds-apply-template').on('click', function() {
        var templateId = $('#myfeeds-template-selector').val();
        if (!templateId) {
            alert(i18n.selectTemplate);
            return;
        }

        if (!currentFeedKey) {
            alert(i18n.selectFeedFirst);
            return;
        }

        $.post(ajaxurl, {
            action: 'myfeeds_apply_template',
            template_id: templateId,
            feed_key: currentFeedKey,
            nonce: myfeedsAdmin.nonce
        }, function(response) {
            if (response.success) {
                alert(i18n.templateApplied);
                location.reload();
            } else {
                alert('Error: ' + response.data.message);
            }
        });
    });

    // Auto-detect button
    $('#myfeeds-auto-detect').on('click', function() {
        if (!currentFeedKey) return;

        $(this).text(i18n.detecting);

        // Trigger the backend auto-detect via page reload with action
        window.location.href = cfg.autoDetectUrl + '&feed_key=' + currentFeedKey + '&action=auto_detect';
    });

    // Auto-load if feed_key in URL
    if (cfg.initialFeedKey) {
        $('#myfeeds-feed-selector').val(cfg.initialFeedKey).trigger('change');
    }
});
