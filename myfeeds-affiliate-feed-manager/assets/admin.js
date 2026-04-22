/**
 * MyFeeds Plugin Admin JavaScript
 * Enhances the WordPress admin interface for feed and credentials management
 */

(function($) {
    'use strict';
    
    // Initialize when DOM is ready
    $(document).ready(function() {
        initFeedForm();
        initCredentialsForm();
        initTestButtons();
    });
    
    /**
     * Initialize feed form enhancements
     */
    function initFeedForm() {
        const networkSelect = $('#feed_network');
        const advertiserInput = $('#advertiser_id');
        const feedUrlInput = $('#feed_url');
        
        if (networkSelect.length) {
            networkSelect.on('change', function() {
                const selectedNetwork = $(this).val();
                updateFormFields(selectedNetwork, advertiserInput, feedUrlInput);
            });
            
            // Trigger change on page load to set initial state
            networkSelect.trigger('change');
        }
    }
    
    /**
     * Update form fields based on selected network
     */
    function updateFormFields(network, advertiserInput, feedUrlInput) {
        // Clear feed URL when network changes
        feedUrlInput.val('');
        
        // Update help text and placeholder based on network
        const helpTexts = {
            'awin': 'For AWIN: Enter the Advertiser ID (e.g., 12345). Feed URL will be auto-generated using Enhanced API.',
            'tradedoubler': 'For TradeDoubler: Enter the Program ID. Feed URL will be auto-generated using your credentials.',
        };
        
        if (helpTexts[network]) {
            advertiserInput.siblings('.description').text(helpTexts[network]);
        }
        
        // Auto-generate URL hint
        if (network && advertiserInput.val()) {
            showUrlPreview(network, advertiserInput.val(), feedUrlInput);
        }
    }
    
    /**
     * Show URL preview for manual feeds
     */
    function showUrlPreview(network, advertiserId, feedUrlInput) {
        if (!advertiserId) return;
        
        const previewTexts = {
            'awin': `Will use AWIN Enhanced Feed API: /publishers/{publisher_id}/awinfeeds/download/${advertiserId}-retail-de_DE`,
            'tradedoubler': `Will use TradeDoubler CSV API with Program ID: ${advertiserId}`
        };
        
        if (previewTexts[network]) {
            const preview = $('<div class="myfeeds-url-preview" style="background: #f0f8ff; border: 1px solid #b3d9ff; padding: 10px; margin-top: 10px; border-radius: 4px; font-family: monospace; font-size: 12px;"></div>');
            preview.text('URL Preview: ' + previewTexts[network]);
            
            // Remove existing preview
            feedUrlInput.siblings('.myfeeds-url-preview').remove();
            feedUrlInput.after(preview);
        }
    }
    
    /**
     * Initialize credentials form enhancements
     */
    function initCredentialsForm() {
        // Password field toggle visibility
        $('.myfeeds-password-toggle').on('click', function() {
            const input = $(this).siblings('input[type="password"], input[type="text"]');
            const currentType = input.attr('type');
            
            if (currentType === 'password') {
                input.attr('type', 'text');
                $(this).text('Hide');
            } else {
                input.attr('type', 'password');
                $(this).text('Show');
            }
        });
        
        // Validate credentials before save
        $('form').on('submit', function(e) {
            const form = $(this);
            if (form.find('input[name="action"][value="myfeeds_save_credentials"]').length) {
                if (!validateCredentialsForm(form)) {
                    e.preventDefault();
                }
            }
        });
    }
    
    /**
     * Validate credentials form
     */
    function validateCredentialsForm(form) {
        const network = form.find('input[name="network"]').val();
        let isValid = true;
        
        // Remove previous error messages
        form.find('.myfeeds-field-error').remove();
        
        // Validate based on network requirements
        if (network === 'awin') {
            const apiToken = form.find('input[name="credentials[api_token]"]').val();
            if (!apiToken || apiToken.length < 10) {
                showFieldError(form.find('input[name="credentials[api_token]"]'), 'AWIN API Token is required and must be valid');
                isValid = false;
            }
        }
        
        if (network === 'tradedoubler') {
            const token = form.find('input[name="credentials[token]"]').val();
            const orgId = form.find('input[name="credentials[organization_id]"]').val();
            
            if (!token) {
                showFieldError(form.find('input[name="credentials[token]"]'), 'TradeDoubler Token is required');
                isValid = false;
            }
            
            if (!orgId) {
                showFieldError(form.find('input[name="credentials[organization_id]"]'), 'Organization ID is required');
                isValid = false;
            }
        }
        
        return isValid;
    }
    
    /**
     * Show field error message
     */
    function showFieldError(field, message) {
        const error = $('<div class="myfeeds-field-error" style="color: #d63638; font-size: 12px; margin-top: 5px;"></div>');
        error.text(message);
        field.after(error);
    }
    
    /**
     * Initialize test buttons with loading states
     */
    function initTestButtons() {
        // Test connection button
        $('button[name="action"][value="myfeeds_test_credentials"]').on('click', function(e) {
            const button = $(this);
            const originalText = button.text();
            
            button.prop('disabled', true);
            button.text('Testing...');
            
            // Re-enable button after form submission (in case of client-side validation failure)
            setTimeout(() => {
                button.prop('disabled', false);
                button.text(originalText);
            }, 5000);
        });
        
        // Test feed button
        $('button[type="submit"]:contains("Test")').on('click', function(e) {
            const button = $(this);
            const originalText = button.text();
            
            button.prop('disabled', true);
            button.text('Testing Feed...');
            
            setTimeout(() => {
                button.prop('disabled', false);
                button.text(originalText);
            }, 10000);
        });
        
        // Rebuild index button
        $('input[name="action"][value="myfeeds_rebuild_index"]').closest('form').on('submit', function(e) {
            const button = $(this).find('input[type="submit"]');
            const originalValue = button.val();
            
            button.prop('disabled', true);
            button.val('Rebuilding...');
            
            setTimeout(() => {
                button.prop('disabled', false);
                button.val(originalValue);
            }, 15000);
        });
    }
    
    /**
     * Auto-save form data in localStorage for recovery
     */
    function initFormAutoSave() {
        const forms = $('form[method="post"]');
        
        forms.each(function() {
            const form = $(this);
            const formId = form.attr('action') || 'myfeeds-form';
            
            // Load saved data
            const savedData = localStorage.getItem('myfeeds-form-' + formId);
            if (savedData) {
                try {
                    const data = JSON.parse(savedData);
                    Object.keys(data).forEach(key => {
                        const field = form.find(`[name="${key}"]`);
                        if (field.length && field.val() === '') {
                            field.val(data[key]);
                        }
                    });
                } catch (e) {
                    // Ignore parsing errors
                }
            }
            
            // Save data on input
            form.on('input change', 'input, select, textarea', function() {
                const formData = {};
                form.find('input, select, textarea').each(function() {
                    const field = $(this);
                    const name = field.attr('name');
                    if (name && field.attr('type') !== 'password') {
                        formData[name] = field.val();
                    }
                });
                
                localStorage.setItem('myfeeds-form-' + formId, JSON.stringify(formData));
            });
            
            // Clear saved data on successful submit
            form.on('submit', function() {
                setTimeout(() => {
                    localStorage.removeItem('myfeeds-form-' + formId);
                }, 1000);
            });
        });
    }
    
    /**
     * Initialize tooltips for help text
     */
    function initTooltips() {
        // Add tooltips to help icons
        $('.myfeeds-help-icon').hover(
            function() {
                const helpText = $(this).data('help');
                if (helpText) {
                    const tooltip = $('<div class="myfeeds-tooltip"></div>').text(helpText);
                    $('body').append(tooltip);
                    
                    const iconPos = $(this).offset();
                    tooltip.css({
                        position: 'absolute',
                        top: iconPos.top - tooltip.outerHeight() - 5,
                        left: iconPos.left + ($(this).outerWidth() / 2) - (tooltip.outerWidth() / 2),
                        background: '#333',
                        color: '#fff',
                        padding: '5px 8px',
                        borderRadius: '3px',
                        fontSize: '12px',
                        zIndex: 1000,
                        whiteSpace: 'nowrap'
                    });
                }
            },
            function() {
                $('.myfeeds-tooltip').remove();
            }
        );
    }
    
    // Initialize additional features
    initFormAutoSave();
    initTooltips();
    
})(jQuery);