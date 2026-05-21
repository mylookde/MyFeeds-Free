jQuery(document).ready(function($) {
    var cfg = window.myfeedsMapping || {};
    var i18n = cfg.i18n || {};
    var currentFeedKey = null;
    var currentMapping = {};
    var feedColumns = [];

    // ---------------------------------------------------------------------
    // Notice + confirm helpers (replace native alert / confirm)
    // ---------------------------------------------------------------------

    function getNoticeHost() {
        var $host = $('#myfeeds-notice-host');
        if (!$host.length) {
            $host = $('<div id="myfeeds-notice-host" class="myfeeds-notice-host" aria-live="polite"></div>').appendTo('body');
        }
        return $host;
    }

    function showNotice(message, type) {
        if (!message) { return; }
        var kind = type || 'info';
        var $host = getNoticeHost();
        var $notice = $(
            '<div class="myfeeds-notice myfeeds-notice--' + kind + '" role="status">' +
                '<span class="myfeeds-notice__dot" aria-hidden="true"></span>' +
                '<div class="myfeeds-notice__body"></div>' +
                '<button type="button" class="myfeeds-notice__close" aria-label="Close">&times;</button>' +
            '</div>'
        );
        $notice.find('.myfeeds-notice__body').text(message);
        $host.append($notice);

        // animate in
        requestAnimationFrame(function() {
            $notice.addClass('is-visible');
        });

        var ttl = (kind === 'error') ? 6000 : 3500;
        var dismiss = function() {
            $notice.removeClass('is-visible');
            setTimeout(function() { $notice.remove(); }, 220);
        };
        var timer = setTimeout(dismiss, ttl);
        $notice.find('.myfeeds-notice__close').on('click', function() {
            clearTimeout(timer);
            dismiss();
        });
    }

    function noticeSuccess(msg) { showNotice(msg, 'success'); }
    function noticeError(msg)   { showNotice(msg, 'error'); }
    function noticeInfo(msg)    { showNotice(msg, 'info'); }

    function ajaxErrorMessage(response) {
        if (response && response.data && response.data.message) {
            return response.data.message;
        }
        return i18n.genericError || 'Something went wrong.';
    }

    function confirmDialog(message, onConfirm, opts) {
        var $modal = $('#myfeeds-confirm-modal');
        if (!$modal.length) {
            // Fallback: native confirm if the modal markup is missing
            if (window.confirm(message)) { onConfirm(); }
            return;
        }
        opts = opts || {};
        $modal.find('#myfeeds-confirm-message').text(message);
        if (opts.okLabel) { $modal.find('.myfeeds-confirm-ok').text(opts.okLabel); }
        if (opts.cancelLabel) { $modal.find('.myfeeds-confirm-cancel').text(opts.cancelLabel); }
        $modal.show();

        var close = function() {
            $modal.hide();
            $modal.find('.myfeeds-confirm-ok').off('click.myfeedsConfirm');
            $modal.find('.myfeeds-confirm-cancel').off('click.myfeedsConfirm');
            $(document).off('keydown.myfeedsConfirm');
        };

        $modal.find('.myfeeds-confirm-ok').one('click.myfeedsConfirm', function() {
            close();
            onConfirm();
        });
        $modal.find('.myfeeds-confirm-cancel').one('click.myfeedsConfirm', function() {
            close();
        });
        $(document).on('keydown.myfeedsConfirm', function(e) {
            if (e.key === 'Escape') { close(); }
        });
    }

    // expose for other admin scripts that may want to reuse
    window.myfeedsNotice = showNotice;
    window.myfeedsConfirm = confirmDialog;

    // ---------------------------------------------------------------------
    // Feed selector / column loading
    // ---------------------------------------------------------------------

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
                $('#myfeeds-feed-columns').html('<p class="error">' + ajaxErrorMessage(response) + '</p>');
                noticeError(ajaxErrorMessage(response));
            }
        }).fail(function() {
            noticeError(i18n.genericError);
        });
    }

    function renderFeedColumns(columns) {
        var html = '';
        columns.forEach(function(col) {
            html += '<span class="myfeeds-column-tag" data-column="' + col + '">' + col + '</span>';
        });
        $('#myfeeds-feed-columns').html(html);
    }

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

    function applyCurrentMapping(mapping) {
        Object.keys(mapping).forEach(function(field) {
            var value = mapping[field];
            if (typeof value === 'string') {
                $('.myfeeds-field-mapping[data-field="' + field + '"]').val(value);
            }
        });
    }

    function renderPreview(sampleData) {
        if (sampleData) {
            $('#myfeeds-mapping-preview').html('<pre>' + JSON.stringify(sampleData, null, 2) + '</pre>');
        }
    }

    // Column tag click - auto-fill the first empty required field, or hint
    $(document).on('click', '.myfeeds-column-tag', function() {
        var col = $(this).data('column');
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
            noticeInfo(i18n.columnTagHint + ' (' + col + ')');
        }
    });

    // ---------------------------------------------------------------------
    // Save mapping
    // ---------------------------------------------------------------------

    $('#myfeeds-save-mapping').on('click', function() {
        var mapping = collectMapping();

        $.post(ajaxurl, {
            action: 'myfeeds_save_mapping',
            feed_key: currentFeedKey,
            mapping: JSON.stringify(mapping),
            nonce: myfeedsAdmin.nonce
        }, function(response) {
            if (response.success) {
                noticeSuccess(i18n.mappingSaved);
            } else {
                noticeError(ajaxErrorMessage(response));
            }
        }).fail(function() {
            noticeError(i18n.genericError);
        });
    });

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

    // ---------------------------------------------------------------------
    // Save as template
    // ---------------------------------------------------------------------

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
            noticeError(i18n.enterTemplateName);
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
                $('#myfeeds-template-modal').hide();
                noticeSuccess(i18n.templateSaved);
                setTimeout(function() { location.reload(); }, 700);
            } else {
                noticeError(ajaxErrorMessage(response));
            }
        }).fail(function() {
            noticeError(i18n.genericError);
        });
    });

    // ---------------------------------------------------------------------
    // Apply template
    // ---------------------------------------------------------------------

    $('#myfeeds-apply-template').on('click', function() {
        var templateId = $('#myfeeds-template-selector').val();
        if (!templateId) {
            noticeError(i18n.selectTemplate);
            return;
        }

        if (!currentFeedKey) {
            noticeError(i18n.selectFeedFirst);
            return;
        }

        $.post(ajaxurl, {
            action: 'myfeeds_apply_template',
            template_id: templateId,
            feed_key: currentFeedKey,
            nonce: myfeedsAdmin.nonce
        }, function(response) {
            if (response.success) {
                noticeSuccess(i18n.templateApplied);
                setTimeout(function() { location.reload(); }, 700);
            } else {
                noticeError(ajaxErrorMessage(response));
            }
        }).fail(function() {
            noticeError(i18n.genericError);
        });
    });

    // ---------------------------------------------------------------------
    // Delete template (Templates tab)
    // ---------------------------------------------------------------------

    $(document).on('click', '.myfeeds-delete-template', function() {
        var $btn = $(this);
        var templateId = $btn.data('template-id');
        var templateName = $btn.data('template-name') || '';
        var tpl = i18n.confirmDeleteTpl || 'Delete the template "%s"?';
        var msg = tpl.replace('%s', templateName);

        confirmDialog(msg, function() {
            $.post(ajaxurl, {
                action: 'myfeeds_delete_template',
                template_id: templateId,
                nonce: myfeedsAdmin.nonce
            }, function(response) {
                if (response.success) {
                    noticeSuccess(i18n.templateDeleted);
                    $('tr[data-template-row="' + templateId + '"]').fadeOut(220, function() {
                        $(this).remove();
                        // If the table is now empty, reload to render the empty state
                        if ($('.myfeeds-templates-table tbody tr').length === 0) {
                            setTimeout(function() { location.reload(); }, 200);
                        }
                    });
                } else {
                    noticeError(ajaxErrorMessage(response));
                }
            }).fail(function() {
                noticeError(i18n.genericError);
            });
        }, { okLabel: i18n.confirmDeleteOk, cancelLabel: i18n.confirmCancel });
    });

    // ---------------------------------------------------------------------
    // Auto-detect button
    // ---------------------------------------------------------------------

    $('#myfeeds-auto-detect').on('click', function() {
        if (!currentFeedKey) {
            noticeError(i18n.selectFeedFirst);
            return;
        }

        $(this).text(i18n.detecting);
        window.location.href = cfg.autoDetectUrl + '&feed_key=' + currentFeedKey + '&action=auto_detect';
    });

    // Auto-load if feed_key in URL
    if (cfg.initialFeedKey) {
        $('#myfeeds-feed-selector').val(cfg.initialFeedKey).trigger('change');
    }
});
