/**
 * ByteMash WooCommerce Amrod Sync - Admin JavaScript
 */

(function($) {
    'use strict';
    
    // Safety check: Verify plugin JS is loading correctly
    if (typeof bytemashWooSync === 'undefined') {
        console.error('❌ ByteMash WooSync: Plugin JavaScript loaded but localized data is missing!');
        console.error('This usually means:');
        console.error('1. Cache is preventing wp_localize_script from running');
        console.error('2. Another plugin is conflicting');
        console.error('3. Assets are being loaded in the wrong order');
        console.error('Solution: Clear all caches (browser, WordPress, server) and hard refresh (Ctrl+Shift+R)');
        
        // Show user-friendly error
        $(document).ready(function() {
            if ($('.bytemash-admin-wrap').length > 0) {
                $('.bytemash-admin-wrap').prepend(
                    '<div class="notice notice-error" style="padding: 15px; margin: 20px 0;">' +
                    '<h3 style="margin-top: 0;">⚠️ Plugin Assets Loading Issue</h3>' +
                    '<p><strong>The plugin JavaScript loaded but essential data is missing.</strong></p>' +
                    '<p>This is usually caused by caching. Please try these steps in order:</p>' +
                    '<ol>' +
                    '<li><strong>Hard refresh this page:</strong> Press <code>Ctrl+Shift+R</code> (Windows/Linux) or <code>Cmd+Shift+R</code> (Mac)</li>' +
                    '<li><strong>Clear WordPress cache:</strong> If using a caching plugin, clear its cache</li>' +
                    '<li><strong>Clear server cache:</strong> Contact your host or check cPanel</li>' +
                    '<li><strong>Disable other plugins:</strong> Temporarily disable other plugins to check for conflicts</li>' +
                    '</ol>' +
                    '<p><a href="' + (window.location.origin + '/wp-content/plugins/bytemash-woo-sync/diagnostics.php') + '" class="button button-primary">Run Diagnostics Tool</a></p>' +
                    '</div>'
                );
            }
        });
        
        // Stop execution - don't try to run without proper data
        return;
    }
    
    // Log successful load
    console.log('✅ ByteMash WooSync Admin JS initialized successfully');
    console.log('AJAX URL:', bytemashWooSync.ajax_url);
    console.log('Plugin URL:', bytemashWooSync.debug.plugin_url);
    
    $(document).ready(function() {
        
        /**
         * Handle full sync test mode toggle
         */
        $('#toggle-full-test-mode').on('click', function() {
            const $button = $(this);
            const originalText = $button.text();
            
            $button.prop('disabled', true).text('Processing...');
            
            $.ajax({
                url: bytemashWooSync.ajax_url,
                type: 'POST',
                data: {
                    action: 'bytemash_toggle_full_test_mode',
                    nonce: bytemashWooSync.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#full-test-mode-status').html(
                            '<div class="notice notice-success"><p>' + response.data.message + '</p></div>'
                        );
                        
                        // Update button text and class
                        if (response.data.test_mode) {
                            $button.text('Disable Full Test Mode').removeClass('button-primary').addClass('button-secondary');
                        } else {
                            $button.text('Enable Full Test Mode').removeClass('button-secondary').addClass('button-primary');
                        }
                        
                        // Reload page after 2 seconds to show updated status
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $('#full-test-mode-status').html(
                            '<div class="notice notice-error"><p>' + response.data.message + '</p></div>'
                        );
                    }
                },
                error: function() {
                    $('#full-test-mode-status').html(
                        '<div class="notice notice-error"><p>Request failed. Please try again.</p></div>'
                    );
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        });
        
        /**
         * Handle incremental sync test mode toggle
         */
        $('#toggle-incremental-test-mode').on('click', function() {
            const $button = $(this);
            const originalText = $button.text();
            
            $button.prop('disabled', true).text('Processing...');
            
            $.ajax({
                url: bytemashWooSync.ajax_url,
                type: 'POST',
                data: {
                    action: 'bytemash_toggle_incremental_test_mode',
                    nonce: bytemashWooSync.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#incremental-test-mode-status').html(
                            '<div class="notice notice-success"><p>' + response.data.message + '</p></div>'
                        );
                        
                        // Update button text and class
                        if (response.data.test_mode) {
                            $button.text('Disable Incremental Test Mode').removeClass('button-primary').addClass('button-secondary');
                        } else {
                            $button.text('Enable Incremental Test Mode').removeClass('button-secondary').addClass('button-primary');
                        }
                        
                        // Reload page after 2 seconds to show updated status
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $('#incremental-test-mode-status').html(
                            '<div class="notice notice-error"><p>' + response.data.message + '</p></div>'
                        );
                    }
                },
                error: function() {
                    $('#incremental-test-mode-status').html(
                        '<div class="notice notice-error"><p>Request failed. Please try again.</p></div>'
                    );
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        });
        
        /**
         * Handle production cron enable
         */
        $('#enable-production-cron').on('click', function() {
            const $button = $(this);
            const originalText = $button.text();
            
            $button.prop('disabled', true).text('Enabling...');
            
            $.ajax({
                url: bytemashWooSync.ajax_url,
                type: 'POST',
                data: {
                    action: 'bytemash_enable_production_cron',
                    nonce: bytemashWooSync.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#production-cron-status').html(
                            '<div class="notice notice-success"><p>' + response.data.message + '</p></div>'
                        );
                        $button.text('Enabled').prop('disabled', true);
                    } else {
                        $('#production-cron-status').html(
                            '<div class="notice notice-error"><p>' + response.data.message + '</p></div>'
                        );
                        $button.text(originalText);
                    }
                },
                error: function() {
                    $('#production-cron-status').html(
                        '<div class="notice notice-error"><p>Request failed. Please try again.</p></div>'
                    );
                    $button.text(originalText);
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        });
        
        /**
         * Handle emergency stop
         */
        $('#emergency-stop-syncs').on('click', function() {
            if (!confirm('Are you sure you want to stop all running syncs? This action cannot be undone.')) {
                return;
            }
            
            const $button = $(this);
            const originalText = $button.text();
            
            $button.prop('disabled', true).text('Stopping...');
            
            $.ajax({
                url: bytemashWooSync.ajax_url,
                type: 'POST',
                data: {
                    action: 'bytemash_emergency_stop_syncs',
                    nonce: bytemashWooSync.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#emergency-stop-status').html(
                            '<div class="notice notice-success"><p>' + response.data.message + '</p></div>'
                        );
                    } else {
                        $('#emergency-stop-status').html(
                            '<div class="notice notice-error"><p>' + response.data.message + '</p></div>'
                        );
                    }
                },
                error: function() {
                    $('#emergency-stop-status').html(
                        '<div class="notice notice-error"><p>Request failed. Please try again.</p></div>'
                    );
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });
        
        /**
         * Handle production system cron enable (combined)
         */
        $('#enable-production-system-cron').on('click', function() {
            const $button = $(this);
            const originalText = $button.text();
            
            $button.prop('disabled', true).text('Enabling...');
            
            $.ajax({
                url: bytemashWooSync.ajax_url,
                type: 'POST',
                data: {
                    action: 'bytemash_enable_production_system_cron',
                    nonce: bytemashWooSync.nonce
                },
                success: function(response) {
                    if (response.success) {
                        let html = '<div class="notice notice-success"><p>' + response.data.message + '</p></div>';
                        
                        // Check if there's a warning (exec() not available)
                        if (response.data.warning) {
                            html += '<div class="notice notice-warning" style="margin-top: 10px;"><p><strong>⚠️ ' + response.data.warning + '</strong></p></div>';
                        }
                        
                        // Show instructions if provided
                        if (response.data.show_instructions && response.data.instructions) {
                            html += response.data.instructions;
                        }
                        
                        $('#production-system-cron-status').html(html);
                        
                        // If fully successful, disable button
                        if (!response.data.warning) {
                            $button.text('Enabled').prop('disabled', true);
                        } else {
                            // If warning (exec not available), change button text but allow retry
                            $button.text('Schedules Enabled').removeClass('button-primary').addClass('button-secondary');
                        }
                    } else {
                        $('#production-system-cron-status').html(
                            '<div class="notice notice-error"><p>' + response.data.message + '</p></div>'
                        );
                        $button.text(originalText);
                    }
                },
                error: function() {
                    $('#production-system-cron-status').html(
                        '<div class="notice notice-error"><p>Request failed. Please try again.</p></div>'
                    );
                    $button.text(originalText);
                },
                complete: function() {
                    // Only re-enable if there was an error
                    if (!response || !response.success) {
                        $button.prop('disabled', false);
                    }
                }
            });
        });
        
        /**
         * Handle system cron enable
         */
        $('#enable-system-cron').on('click', function() {
            const $button = $(this);
            const originalText = $button.text();
            
            $button.prop('disabled', true).text('Installing...');
            
            $.ajax({
                url: bytemashWooSync.ajax_url,
                type: 'POST',
                data: {
                    action: 'bytemash_enable_system_cron',
                    nonce: bytemashWooSync.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#system-cron-status').html(
                            '<div class="notice notice-success"><p>' + response.data.message + '</p></div>'
                        );
                        $button.text('Enabled').prop('disabled', true);
                    } else {
                        $('#system-cron-status').html(
                            '<div class="notice notice-error"><p>' + response.data.message + '</p></div>'
                        );
                        $button.text(originalText);
                    }
                },
                error: function() {
                    $('#system-cron-status').html(
                        '<div class="notice notice-error"><p>Request failed. Please try again.</p></div>'
                    );
                    $button.text(originalText);
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        });
        
        /**
         * Scheduled Sync Monitoring
         */
        let autoRefreshInterval = null;
        let autoRefreshEnabled = false;
        
        // Auto-refresh toggle
        $('#toggle_auto_refresh').on('click', function() {
            if (autoRefreshEnabled) {
                stopAutoRefresh();
            } else {
                startAutoRefresh();
            }
        });
        
        // Manual refresh
        $('#refresh_scheduled_status').on('click', function() {
            refreshScheduledStatus();
        });
        
        function startAutoRefresh() {
            autoRefreshEnabled = true;
            autoRefreshInterval = setInterval(refreshScheduledStatus, 5000); // Every 5 seconds
            
            $('#auto_refresh_text').text('Disable Auto-refresh');
            $('#auto_refresh_indicator').show();
            $('#toggle_auto_refresh .dashicons').removeClass('dashicons-controls-play').addClass('dashicons-controls-pause');
        }
        
        function stopAutoRefresh() {
            autoRefreshEnabled = false;
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
                autoRefreshInterval = null;
            }
            
            $('#auto_refresh_text').text('Enable Auto-refresh');
            $('#auto_refresh_indicator').hide();
            $('#toggle_auto_refresh .dashicons').removeClass('dashicons-controls-pause').addClass('dashicons-controls-play');
        }
        
        function refreshScheduledStatus() {
            $.ajax({
                url: bytemashWooSync.ajax_url,
                type: 'POST',
                data: {
                    action: 'bytemash_get_scheduled_sync_status',
                    nonce: bytemashWooSync.nonce
                },
                success: function(response) {
                    if (response.success) {
                        updateScheduledStatus(response.data);
                    }
                },
                error: function() {
                    console.log('Failed to refresh scheduled status');
                }
            });
        }
        
        function updateScheduledStatus(data) {
            // Update sync times
            $('#full_sync_status').text(data.full_sync_next || 'Not scheduled');
            $('#incremental_sync_status').text(data.incremental_sync_next || 'Not scheduled');
            
            // Update test mode status
            let testModeText = '';
            if (data.full_test_mode) {
                testModeText += '<span style="color: #28a745; font-weight: bold;">Full Test Mode: Enabled</span><br>';
            }
            if (data.incremental_test_mode) {
                testModeText += '<span style="color: #28a745; font-weight: bold;">Incremental Test Mode: Enabled</span><br>';
            }
            if (!data.full_test_mode && !data.incremental_test_mode) {
                testModeText = '<span style="color: #6c757d;">Test Modes: Disabled</span>';
            }
            $('#test_mode_status').html(testModeText);
            
            // Update running indicators
            if (data.full_sync_running) {
                $('#full_sync_running').show();
            } else {
                $('#full_sync_running').hide();
            }
            
            if (data.incremental_sync_running) {
                $('#incremental_sync_running').show();
            } else {
                $('#incremental_sync_running').hide();
            }
            
            // Update real-time logs
            if (data.recent_logs && data.recent_logs.length > 0) {
                updateRealtimeLogs(data.recent_logs);
            }
            
            // Update progress if sync is running
            if (data.sync_progress) {
                updateSyncProgress(data.sync_progress);
            }
        }
        
        function updateRealtimeLogs(logs) {
            const $logsContainer = $('#realtime_sync_logs');
            $logsContainer.empty();
            
            logs.slice(0, 5).forEach(function(log) {
                const time = new Date(log.created_at).toLocaleTimeString();
                const statusClass = log.status === 'success' ? 'success' : 
                                   log.status === 'error' ? 'error' : 'info';
                
                const logEntry = `
                    <div class="bytemash-log-entry">
                        <span class="log-time">${time}</span>
                        <span class="log-type">${log.sync_type}</span>
                        <span class="log-status ${statusClass}">${log.message}</span>
                    </div>
                `;
                $logsContainer.append(logEntry);
            });
        }
        
        function updateSyncProgress(progress) {
            if (progress && progress.active_syncs && progress.active_syncs.length > 0) {
                $('#scheduled_sync_progress').show();
                updateScheduledActiveSyncs(progress.active_syncs);
            } else {
                $('#scheduled_sync_progress').hide();
            }
        }
        
        function updateScheduledActiveSyncs(activeSyncs) {
            const $container = $('#scheduled_sync_batches');
            $container.empty();
            
            if (activeSyncs && activeSyncs.length > 0) {
                $('#scheduled_sync_progress').show();
                
                activeSyncs.forEach(function(sync) {
                    createScheduledSyncDisplay(sync);
                });
            } else {
                $('#scheduled_sync_progress').hide();
            }
        }
        
        /**
         * Create scheduled sync batch processing display
         */
        function createScheduledSyncDisplay(sync) {
            const $container = $('#scheduled_sync_batches');
            const batchCount = sync.batch_count || Math.ceil(sync.total / 50);
            const syncType = sync.type || 'Products';
            const percentage = sync.total > 0 ? Math.round((sync.processed / sync.total) * 100) : 0;
            
            let html = '<div class="scheduled-sync-item">';
            html += '<div class="sync-header">';
            html += '<h4><span class="dashicons dashicons-update-alt spinning"></span> ' + syncType + ' Sync</h4>';
            html += '<span class="sync-percentage">' + percentage + '%</span>';
            html += '</div>';
            html += '<div class="sync-progress-bar">';
            html += '<div class="progress-fill" style="width: ' + percentage + '%"></div>';
            html += '</div>';
            html += '<div class="sync-stats">';
            html += '<span class="processed">' + sync.processed.toLocaleString() + ' / ' + sync.total.toLocaleString() + ' processed</span>';
            if (sync.errors > 0) {
                html += '<span class="errors">' + sync.errors + ' errors</span>';
            }
            if (sync.skipped > 0) {
                html += '<span class="skipped">' + sync.skipped + ' skipped</span>';
            }
            html += '</div>';
            html += '<div class="batch-list">';
            
            // Show individual batches
            for (let i = 0; i < Math.min(batchCount, 15); i++) {
                const batchStatus = getBatchStatus(i, sync.processed, sync.total, batchCount);
                html += '<div class="batch-item ' + batchStatus.class + '">';
                html += '<span class="batch-number">Batch ' + (i + 1) + '</span>';
                html += '<span class="batch-status">' + batchStatus.text + '</span>';
                html += '</div>';
            }
            
            if (batchCount > 15) {
                html += '<div class="batch-item more">... and ' + (batchCount - 15) + ' more batches</div>';
            }
            
            html += '</div>';
            html += '</div>';
            
            $container.html(html);
        }
        
        /**
         * Get batch status based on progress
         */
        function getBatchStatus(batchIndex, processed, total, batchCount) {
            const itemsPerBatch = Math.ceil(total / batchCount);
            const currentBatch = Math.floor(processed / itemsPerBatch);
            
            if (batchIndex < currentBatch) {
                return { class: 'completed', text: '✓ Completed' };
            } else if (batchIndex === currentBatch) {
                return { class: 'processing', text: '🔄 Processing...' };
            } else {
                return { class: 'waiting', text: '⏳ Waiting...' };
            }
        }
        
        // Start auto-refresh by default
        startAutoRefresh();
        
        /**
         * Handle stop scheduled sync button
         */
        $('#scheduled_stop_sync_button').on('click', function() {
            if (!confirm('Are you sure you want to stop the scheduled sync? This action cannot be undone.')) {
                return;
            }
            
            const $button = $(this);
            $button.prop('disabled', true).text('Stopping...');
            
            $.ajax({
                url: bytemashWooSync.ajax_url,
                type: 'POST',
                data: {
                    action: 'bytemash_stop_sync',
                    nonce: bytemashWooSync.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#scheduled_sync_message').html(
                            '<div class="notice notice-success"><p>' + response.data.message + '</p></div>'
                        );
                        $('#scheduled_sync_progress').hide();
                        $('#scheduled_stop_sync_container').hide();
                    } else {
                        $('#scheduled_sync_message').html(
                            '<div class="notice notice-error"><p>' + response.data.message + '</p></div>'
                        );
                    }
                },
                error: function() {
                    $('#scheduled_sync_message').html(
                        '<div class="notice notice-error"><p>Request failed. Please try again.</p></div>'
                    );
                },
                complete: function() {
                    $button.prop('disabled', false).text('Stop Scheduled Sync');
                }
            });
        });
        
        /**
         * Handle authentication form submission
         */
        $('#bytemash_auth_form').on('submit', function(e) {
            e.preventDefault();
            
            const $form = $(this);
            const $button = $('#btn_authenticate');
            const $status = $('#auth_status');
            const username = $('#amrod_username').val();
            const password = $('#amrod_password').val();
            const customer_code = $('#customer_code').val();
            const api_url = $('#api_url_auth').val();
            
            // Disable button and show loading
            $button.prop('disabled', true).html('<span class="bytemash-spinner"></span> Authenticating...');
            $status.html('');
            
            // Save API URL first
            $.ajax({
                url: bytemashWooSync.ajax_url,
                type: 'POST',
                data: {
                    action: 'bytemash_save_api_url',
                    nonce: bytemashWooSync.nonce,
                    api_url: api_url
                },
                success: function() {
                    // Now authenticate
                    $.ajax({
                        url: bytemashWooSync.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'bytemash_authenticate',
                            nonce: bytemashWooSync.nonce,
                            username: username,
                            password: password,
                            customer_code: customer_code
                        },
                        success: function(response) {
                            if (response.success) {
                                $status.html('<div class="notice notice-success"><p><strong>✓ ' + response.data.message + '</strong></p><p>Redirecting...</p></div>');
                                
                                // Reload page after 1.5 seconds
                                setTimeout(function() {
                                    window.location.reload();
                                }, 1500);
                            } else {
                                $status.html('<div class="notice notice-error"><p><strong>✗ ' + response.data.message + '</strong></p></div>');
                                $button.prop('disabled', false).html('<span class="dashicons dashicons-admin-network"></span> Authenticate & Connect');
                            }
                        },
                        error: function() {
                            $status.html('<div class="notice notice-error"><p><strong>✗ Authentication failed. Please try again.</strong></p></div>');
                            $button.prop('disabled', false).html('<span class="dashicons dashicons-admin-network"></span> Authenticate & Connect');
                        }
                    });
                }
            });
        });
        
        /**
         * Toggle API token visibility
         */
        $('#toggle_token').on('click', function() {
            const $input = $('#api_token');
            const type = $input.attr('type');
            
            if (type === 'password') {
                $input.attr('type', 'text');
            } else {
                $input.attr('type', 'password');
            }
        });
        
        /**
         * Test API connection
         */
        $('#test_connection').on('click', function() {
            const $button = $(this);
            const $status = $('#connection_status');
            
            $button.prop('disabled', true).text('Testing...');
            $status.html('<span class="bytemash-spinner"></span>');
            
            $.ajax({
                url: bytemashWooSync.ajax_url,
                type: 'POST',
                data: {
                    action: 'bytemash_test_connection',
                    nonce: bytemashWooSync.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $status.html('<span class="dashicons dashicons-yes-alt"></span> ' + response.data.message)
                               .removeClass('error')
                               .addClass('success');
                    } else {
                        $status.html('<span class="dashicons dashicons-warning"></span> ' + response.data.message)
                               .removeClass('success')
                               .addClass('error');
                    }
                },
                error: function() {
                    $status.html('<span class="dashicons dashicons-warning"></span> Connection test failed')
                           .removeClass('success')
                           .addClass('error');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Test Connection');
                }
            });
        });
        
        /**
         * Universal sync button handler
         */
        $('[data-ajax-action]').on('click', function() {
            const $button = $(this);
            const action = $button.data('ajax-action');
            const actionName = $button.data('action');
            const confirmMessage = getConfirmMessage(actionName);
            
            if (confirmMessage && !confirm(confirmMessage)) {
                return;
            }
            
            const originalText = $button.html();
            $button.prop('disabled', true)
                   .html('<span class="bytemash-spinner"></span> Starting...');
            
            // Disable all other sync buttons
            $('[data-ajax-action]').prop('disabled', true);
            
            $.ajax({
                url: bytemashWooSync.ajax_url,
                type: 'POST',
                data: {
                    action: action,
                    nonce: bytemashWooSync.nonce
                },
                success: function(response) {
                    console.log('🔥 SYNC RESPONSE:', response);
                    
                    if (response.success) {
                        console.log('✅ Sync initiated successfully');
                        console.log('Response data:', response.data);
                        
                        // If sync has a sync_id, start batch processing from queue
                        if (response.data.sync_id && response.data.batch_count) {
                            showSyncMessage('success', response.data.message);
                            
                            // Determine sync type from sync_id prefix
                            let syncType = 'Products';
                            if (response.data.sync_id.startsWith('stock_')) {
                                syncType = 'Stock Items';
                            } else if (response.data.sync_id.startsWith('prices_')) {
                                syncType = 'Prices';
                            } else if (response.data.sync_id.startsWith('orphan_prices_')) {
                                syncType = 'Orphan Prices';
                            } else if (response.data.sync_id.startsWith('categories_')) {
                                syncType = 'Categories';
                            } else if (response.data.sync_id.startsWith('color_swatches_')) {
                                syncType = 'Color Swatches';
                            } else if (response.data.sync_id.startsWith('brands_')) {
                                syncType = 'Brands';
                            } else if (response.data.sync_id.startsWith('branding_depts_')) {
                                syncType = 'Branding Departments';
                            } else if (response.data.sync_id.startsWith('branding_prices_')) {
                                syncType = 'Branding Prices';
                            } else if (response.data.sync_id.startsWith('inclusive_brandings_')) {
                                syncType = 'Inclusive Brandings';
                            }
                            
                            console.log('🚀 Starting batch sync from database queue:', {
                                syncId: response.data.sync_id,
                                batchCount: response.data.batch_count,
                                total: response.data.total,
                                type: syncType
                            });
                            startBatchSyncFromQueue(response.data.sync_id, response.data.batch_count, response.data.total, syncType);
                        } else {
                            // No batches to process (e.g., no updates available, or simple sync complete)
                            console.log('ℹ️ Sync completed without batches:', response.data);
                            showSyncMessage('success', response.data.message || 'Sync completed successfully');
                                $('[data-ajax-action]').prop('disabled', false);
                                $button.html(originalText);
                            
                            // Show stats if available
                            if (response.data.total !== undefined) {
                                const statsMsg = response.data.total === 0 
                                    ? '✅ ' + (response.data.message || 'No updates available')
                                    : '✅ ' + response.data.message + ' (' + response.data.total + ' items)';
                                showSyncMessage('success', statsMsg);
                            }
                        }
                    } else {
                        console.log('❌ Sync failed:', response.data.message);
                        showSyncMessage('error', response.data.message);
                        $('[data-ajax-action]').prop('disabled', false);
                        $button.html(originalText);
                    }
                },
                error: function(xhr) {
                    showSyncMessage('error', 'Sync failed. Please try again. Error: ' + xhr.statusText);
                    $('[data-ajax-action]').prop('disabled', false);
                    $button.html(originalText);
                }
            });
        });
        
        /**
         * Get confirm message for sync type
         */
        function getConfirmMessage(actionName) {
            const messages = {
                'manual_sync': 'This will sync ALL products from Amrod. This may take several minutes. Continue?',
                'stock_sync': 'This will update stock levels for all products. Continue?',
                'price_sync': 'This will update prices for all products. Continue?',
                'orphan_price_sync': 'This will find products WITHOUT prices and match them using SKU prefix patterns (e.g., ALT-GCG matches ALT-GCG-NT). Run this AFTER normal price sync. Continue?',
                'category_sync': 'This will sync all product categories from Amrod. Continue?',
                'color_swatches_sync': 'This will sync color swatches from Amrod (for product variations). Continue?',
                'brands_sync': 'This will sync all brands from Amrod with progress tracking. Continue?',
                'branding_departments_sync': 'This will sync branding departments (methods and file types) with progress tracking. Continue?',
                'branding_prices_sync': 'This will sync branding pricing information with progress tracking. Continue?',
                'inclusive_brandings_sync': 'This will sync inclusive branding options with progress tracking. Continue?'
            };
            
            return messages[actionName] || null;
        }
        
        /**
         * Start batch sync from database queue (simplest approach)
         */
        let currentSyncId = null;
        let isStopped = false;
        let currentBatchIndex = 0;
        let isProcessingBatch = false; // Lock to prevent parallel processing
        
        function startBatchSyncFromQueue(syncId, batchCount, totalProducts, syncType) {
            currentSyncId = syncId;
            isStopped = false;
            isProcessingBatch = false; // Reset lock for new sync
            currentBatchIndex = 0;
            syncType = syncType || 'Products';
            
            console.log('🎯 Starting queue-based batch sync:', {syncId, batchCount, totalProducts, syncType});
            
            // Show batch list
            displayBatchList(batchCount, totalProducts, syncType);
            
            // Show stop button
            $('#stop_sync_container').show();
            
            // Mark first batch as processing
            $('#batch_0').addClass('processing').find('.batch-status').text('Processing...');
            
            // Process batches from queue
            processNextBatchFromQueue(syncId, batchCount);
        }
        
        /**
         * Display all batches on screen (sliding window of 10)
         */
        function displayBatchList(batchCount, totalProducts, syncType) {
            const $container = $('#active_syncs');
            
            syncType = syncType || 'Products';
            
            let html = '<div class="bytemash-batch-list">';
            html += '<h3><span class="dashicons dashicons-update-alt spinning"></span> Syncing ' + totalProducts.toLocaleString() + ' ' + syncType + ' in ' + batchCount + ' Batches</h3>';
            html += '<div id="batch_progress_overall" class="batch-progress-overall"></div>';
            html += '<div id="batch_list" class="batch-items">';
            
            // Show first 10 batches initially
            for (let i = 0; i < Math.min(batchCount, 10); i++) {
                html += '<div class="batch-item" id="batch_' + i + '" data-batch="' + i + '">';
                html += '<span class="batch-number">Batch ' + (i + 1) + '</span>';
                html += '<span class="batch-status">Waiting...</span>';
                html += '</div>';
            }
            
            if (batchCount > 10) {
                html += '<div class="batch-item more" id="batch_more">... and ' + (batchCount - 10) + ' more batches</div>';
            }
            
            html += '</div></div>';
            
            $container.html(html);
        }
        
        /**
         * Update batch window (slide to show current batches)
         */
        function updateBatchWindow(currentBatch, totalBatches) {
            // Determine which window to show (batches 0-9, 10-19, 20-29, etc.)
            const windowStart = Math.floor(currentBatch / 10) * 10;
            const windowEnd = Math.min(windowStart + 10, totalBatches);
            
            // Only update if we've moved to a new window
            if (currentBatch % 10 === 0 && currentBatch > 0) {
                console.log('📊 Updating batch window:', windowStart, '-', windowEnd);
                
                const $batchList = $('#batch_list');
                let html = '';
                
                // Show current window of 10 batches
                for (let i = windowStart; i < windowEnd; i++) {
                    html += '<div class="batch-item" id="batch_' + i + '" data-batch="' + i + '">';
                    html += '<span class="batch-number">Batch ' + (i + 1) + '</span>';
                    html += '<span class="batch-status">Waiting...</span>';
                    html += '</div>';
                }
                
                // Show "more batches" indicator
                const remaining = totalBatches - windowEnd;
                if (remaining > 0) {
                    html += '<div class="batch-item more" id="batch_more">... and ' + remaining + ' more batches</div>';
                }
                
                $batchList.html(html);
                
                // Mark already completed batches in this window
                for (let i = windowStart; i < currentBatch; i++) {
                    $('#batch_' + i).addClass('completed').find('.batch-status').text('✓ Done');
                }
            }
        }
        
        /**
         * Process next batch from queue (server manages the queue)
         */
        function processNextBatchFromQueue(syncId, totalBatches) {
            if (isStopped) {
                console.log('Sync stopped by user');
                return;
            }
            
            // Prevent parallel batch processing
            if (isProcessingBatch) {
                console.warn('⚠️ Already processing a batch, waiting...');
                setTimeout(() => processNextBatchFromQueue(syncId, totalBatches), 500);
                return;
            }
            
            isProcessingBatch = true;
            console.log('📦 Requesting next batch from queue (lock acquired)');
            
            // Just call process_batch - server will pull from queue automatically
                $.ajax({
                    url: bytemashWooSync.ajax_url,
                    type: 'POST',
                    data: {
                    action: 'bytemash_process_batch',
                    sync_id: syncId,
                        nonce: bytemashWooSync.nonce
                    },
                    success: function(response) {
                    console.log('✅ Batch response:', response);
                    
                    // Release lock
                    isProcessingBatch = false;
                    
                    if (!response.success) {
                        console.error('Batch processing failed, retrying...');
                        setTimeout(() => processNextBatchFromQueue(syncId, totalBatches), 2000);
                        return;
                    }
                    
                    if (response.data.stopped) {
                        console.log('🛑 Sync stopped');
                        isStopped = true;
                        $('#active_syncs').html('<div class="success">🛑 Sync stopped</div>');
                        $('#stop_sync_container').hide();
                        return;
                    }
                    
                    const batch = response.data.batch;
                    const processed = response.data.processed;
                    const totalProcessed = response.data.total_processed;
                    const totalProducts = response.data.total_products;
                    const wooProductCount = response.data.woo_product_count || 0;
                    
                    console.log('📊 Batch details:', {
                        batch: batch,
                        batchNumber: batch + 1,
                        processed: processed,
                        totalProcessed: totalProcessed,
                        currentBatchIndex: currentBatchIndex,
                        expected: 'Should be processing batch ' + (currentBatchIndex + 1)
                    });
                    
                    // Track batch index
                    currentBatchIndex = batch;
                    
                    // Update batch window FIRST (slide to show current batches if needed)
                    updateBatchWindow(batch, totalBatches);
                    
                    // Safety check: Ensure batch element exists AFTER window update
                    if ($('#batch_' + batch).length === 0) {
                        console.error('❌ Batch element STILL not found for batch ' + batch + ' after window update!');
                        // Try to create it dynamically
                        const windowStart = Math.floor(batch / 10) * 10;
                        if (batch >= windowStart && batch < windowStart + 10) {
                            console.log('Creating missing batch element dynamically');
                            const batchHtml = '<div class="batch-item" id="batch_' + batch + '" data-batch="' + batch + '">' +
                                '<span class="batch-number">Batch ' + (batch + 1) + '</span>' +
                                '<span class="batch-status">Processing...</span>' +
                                '</div>';
                            $('#batch_list').append(batchHtml);
                        }
                    }
                    
                    // Update UI for completed batch with detailed status
                    const batchErrors = response.data.errors || 0;
                    const batchSkipped = response.data.skipped || 0;
                    const totalSkipped = response.data.total_skipped || 0;
                    const totalErrors = response.data.total_errors || 0;
                    
                    // Calculate percentage based on ALL attempted items (processed + skipped + errors)
                    const totalAttempted = totalProcessed + totalSkipped + totalErrors;
                    const percentage = Math.round((totalAttempted / totalProducts) * 100);
                    let batchStatusText = '✓ ';
                    
                    if (processed > 0) {
                        batchStatusText += processed + ' done';
                    }
                    if (batchSkipped > 0) {
                        batchStatusText += (processed > 0 ? ', ' : '') + batchSkipped + ' skipped';
                    }
                    if (batchErrors > 0) {
                        batchStatusText += (processed > 0 || batchSkipped > 0 ? ', ' : '') + batchErrors + ' errors';
                    }
                    if (processed === 0 && batchSkipped === 0 && batchErrors === 0) {
                        batchStatusText += 'No items';
                    }
                    
                    $('#batch_' + batch).removeClass('processing').addClass('completed').find('.batch-status').text(batchStatusText);
                    
                    // Update overall progress
                    let statsHtml = '<strong>' + totalAttempted.toLocaleString() + '/' + totalProducts.toLocaleString() + '</strong> items attempted (' + percentage + '%)';
                    
                    // Break down the counts
                    if (totalProcessed > 0) {
                        statsHtml += ' | <span style="color: #5cb85c;">' + totalProcessed.toLocaleString() + ' synced</span>';
                    }
                    
                    // Add skipped/errors if any
                    if (totalSkipped > 0) {
                        statsHtml += ' | <span style="color: #f0ad4e;">' + totalSkipped.toLocaleString() + ' skipped</span>';
                    }
                    if (totalErrors > 0) {
                        statsHtml += ' | <span style="color: #d9534f;">' + totalErrors.toLocaleString() + ' errors</span>';
                    }
                    
                    // Add WooCommerce count for products only
                    if (wooProductCount > 0) {
                        statsHtml += ' | <strong>' + wooProductCount.toLocaleString() + '</strong> total in WooCommerce';
                    }
                    
                    $('#batch_progress_overall').html(
                        '<div class="overall-progress-bar">' +
                        '<div class="overall-progress-fill" style="width: ' + percentage + '%">' +
                        '<span class="progress-text">' + percentage + '%</span>' +
                        '</div>' +
                        '</div>' +
                        '<div class="overall-stats">' + statsHtml + '</div>'
                    );
                    
                    // Update dashboard product count if element exists
                    if ($('.bytemash-stat-value').length > 0) {
                        $('.bytemash-stat-value').first().text(wooProductCount.toLocaleString());
                    }
                    
                    // Mark next batch as processing (if there is one)
                    if (batch + 1 < totalBatches) {
                        $('#batch_' + (batch + 1)).addClass('processing').find('.batch-status').text('Processing...');
                    }
                    
                    // Check if we're done - ONLY rely on server signal or all items processed
                    const serverSaysDone = response.data.done;
                    const allItemsProcessed = (totalAttempted >= totalProducts);
                    
                    console.log('📊 Progress check:', {
                        batch: batch,
                        totalBatches: totalBatches,
                        totalAttempted: totalAttempted,
                        totalProducts: totalProducts,
                        serverSaysDone: serverSaysDone,
                        allItemsProcessed: allItemsProcessed
                    });
                    
                    if (serverSaysDone || allItemsProcessed) {
                        console.log('✅ All batches completed! (serverSaysDone=' + serverSaysDone + ', allItemsProcessed=' + allItemsProcessed + ')');
                        
                        // Check if we need to start orphan price sync
                        if (response.data.start_orphan_sync) {
                            console.log('🔄 Price sync completed. Starting orphan price matching...');
                            $('#batch_progress_overall').html('<div class="info">🔄 Starting orphan price matching...</div>');
                            
                            // Trigger orphan price sync
                            setTimeout(() => {
                                $.ajax({
                                    url: bytemashWooSync.ajax_url,
                                    type: 'POST',
                                    data: {
                                        action: 'bytemash_sync_orphan_prices',
                                        nonce: bytemashWooSync.nonce
                                    },
                                    success: function(orphanResponse) {
                                        console.log('🔍 Orphan sync response:', orphanResponse);
                                        
                                        if (orphanResponse.success && orphanResponse.data.sync_id) {
                                            // Start orphan batch processing
                                            startBatchSyncFromQueue(
                                                orphanResponse.data.sync_id, 
                                                orphanResponse.data.batch_count, 
                                                orphanResponse.data.total, 
                                                'Orphan Prices'
                                            );
                                        } else {
                                            // No orphans to process
                                            $('#batch_progress_overall').html('<div class="success">✅ Price sync completed! ' + (orphanResponse.data.message || '') + '</div>');
                                            $('#stop_sync_container').hide();
                                            setTimeout(() => location.reload(), 3000);
                                        }
                                    },
                                    error: function() {
                                        console.error('❌ Failed to start orphan sync');
                                        $('#batch_progress_overall').html('<div class="success">✅ Price sync completed (orphan sync failed to start)</div>');
                                        $('#stop_sync_container').hide();
                                        setTimeout(() => location.reload(), 3000);
                                    }
                                });
                            }, 500);
                            return;
                        }
                        
                        // Build completion message with detailed breakdown
                        let completionMsg = '✅ All ' + totalBatches + ' batches completed! ';
                        completionMsg += totalAttempted.toLocaleString() + ' total items: ';
                        
                        let parts = [];
                        if (totalProcessed > 0) {
                            parts.push('<span style="color: #5cb85c;">' + totalProcessed.toLocaleString() + ' synced</span>');
                        }
                        if (totalSkipped > 0) {
                            parts.push('<span style="color: #f0ad4e;">' + totalSkipped.toLocaleString() + ' skipped</span>');
                        }
                        if (totalErrors > 0) {
                            parts.push('<span style="color: #d9534f;">' + totalErrors.toLocaleString() + ' errors</span>');
                        }
                        
                        completionMsg += parts.join(', ') + '.';
                        
                        // Add helpful note if many items were skipped
                        if (totalSkipped > 0 && totalSkipped > totalProcessed) {
                            completionMsg += '<br><small style="color: #f0ad4e;">ℹ️ Note: Skipped items are products that don\'t exist in WooCommerce yet. Sync products from Amrod first.</small>';
                        }
                        
                        $('#batch_progress_overall').html('<div class="success">' + completionMsg + '</div>');
                        $('#stop_sync_container').hide();
                        setTimeout(() => location.reload(), 3000);
                        return; // Stop processing
                    }
                    
                    // Continue to next batch
                    console.log('➡️ Processing next batch...');
                    processNextBatchFromQueue(syncId, totalBatches);
                },
                error: function(xhr, status, error) {
                    // Release lock
                    isProcessingBatch = false;
                    
                    console.error('❌ AJAX error:', error);
                    // Retry after 2 seconds
                    setTimeout(() => processNextBatchFromQueue(syncId, totalBatches), 2000);
                }
            });
        }
        
        // OLD progress monitoring removed - now using simple batch display
        
        /**
         * Stop Sync Button Handler - IMMEDIATE STOP
         */
        $('#stop_sync_button').on('click', function() {
            if (!confirm('Are you sure you want to stop the sync? Already synced products will remain.')) {
                return;
            }
            
            console.log('STOP button clicked - halting all operations');
            
            const $button = $(this);
            $button.prop('disabled', true).html('<span class="bytemash-spinner"></span> Stopping...');
            
            // IMMEDIATELY stop all processing
            isStopped = true;
            isProcessingBatch = false; // Release lock
            console.log('🛑 Setting isStopped = true, releasing batch lock');
            
            // Clear any running intervals (even though we don't use them anymore)
            if (typeof progressMonitorInterval !== 'undefined' && progressMonitorInterval) {
                clearInterval(progressMonitorInterval);
                progressMonitorInterval = null;
            }
            
            // Update sync status in database
            if (currentSyncId) {
                $.ajax({
                    url: bytemashWooSync.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'bytemash_stop_sync',
                        sync_id: currentSyncId,
                        nonce: bytemashWooSync.nonce
                    },
                    success: function(response) {
                        console.log('Sync stopped in database:', response);
                        
                        // IMMEDIATELY clear UI
                        $('#active_syncs').html('<div class="success" style="padding: 20px; text-align: center;">🛑 Sync stopped. ' + response.data.message + '</div>');
                        $('#stop_sync_container').hide();
                        
                        // Re-enable sync buttons
                        $('[data-ajax-action]').prop('disabled', false);
                        
                        showSyncMessage('success', '🛑 ' + response.data.message + ' You can start a new sync now.');
                    },
                    error: function() {
                        // Even if AJAX fails, clear UI
                        $('#active_syncs').html('<div class="success" style="padding: 20px; text-align: center;">🛑 Sync stopped.</div>');
                        $('#stop_sync_container').hide();
                        $('[data-ajax-action]').prop('disabled', false);
                        showSyncMessage('success', '🛑 Sync stopped. You can start a new sync now.');
                    }
                });
            } else {
                // No sync ID, just clear UI
                $('#active_syncs').empty();
                $('#stop_sync_container').hide();
                $('[data-ajax-action]').prop('disabled', false);
            }
        });
        
        // displayActiveSyncs removed - using simple batch list instead
        
        /**
         * Show sync message
         */
        function showSyncMessage(type, message) {
            const $container = $('#sync_message');
            const className = type === 'success' ? 'notice-success' : 'notice-error';
            
            $container.removeClass('notice-success notice-error')
                      .addClass('notice ' + className)
                      .html('<p>' + escapeHtml(message) + '</p>')
                      .show();
            
            // Auto hide success messages
            if (type === 'success') {
                setTimeout(function() {
                    $container.fadeOut();
                }, 5000);
            }
        }
        
        /**
         * Escape HTML
         */
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }
        
        // On page load, check if there are any active syncs to resume
        // (This is for when user refreshes during sync)
        
        /**
         * Clear logs
         */
        $('#clear_logs').on('click', function() {
            if (!confirm('Are you sure you want to clear all logs? This cannot be undone.')) {
                return;
            }
            
            const $button = $(this);
            
            $button.prop('disabled', true).text('Clearing...');
            
            // Submit form via AJAX (or regular form submission)
            $.ajax({
                url: bytemashWooSync.ajax_url,
                type: 'POST',
                data: {
                    action: 'bytemash_clear_logs',
                    nonce: bytemashWooSync.nonce
                },
                success: function(response) {
                    showNotice('success', 'Logs cleared successfully!');
                    
                    setTimeout(function() {
                        location.reload();
                    }, 500);
                },
                error: function() {
                    showNotice('error', 'Failed to clear logs.');
                    $button.prop('disabled', false).text('Clear All Logs');
                }
            });
        });
        
        /**
         * View log details modal
         */
        $(document).on('click', '.bytemash-view-details', function() {
            const details = $(this).data('details');
            const formattedDetails = JSON.stringify(details, null, 2);
            
            $('#bytemash_log_details').text(formattedDetails);
            $('#bytemash_log_modal').fadeIn();
        });
        
        /**
         * Close modal
         */
        $('.bytemash-modal-close, .bytemash-modal').on('click', function(e) {
            if (e.target === this) {
                $('#bytemash_log_modal').fadeOut();
            }
        });
        
        /**
         * Show notification
         */
        function showNotice(type, message) {
            const $notice = $('<div>')
                .addClass('bytemash-notice notice-' + type)
                .html('<p>' + message + '</p>')
                .hide();
            
            $('.bytemash-admin-wrap h1').after($notice);
            $notice.slideDown();
            
            // Auto dismiss after 5 seconds
            setTimeout(function() {
                $notice.slideUp(function() {
                    $(this).remove();
                });
            }, 5000);
        }
        
        // Auto-refresh removed - batch sync displays progress without needing refresh
        
    });
    
})(jQuery);

