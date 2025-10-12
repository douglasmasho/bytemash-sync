/**
 * ByteMash WooCommerce Amrod Sync - Admin JavaScript
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        
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
                        
                        showSyncMessage('success', response.data.message);
                        
                        // If sync has a sync_id, start batch processing from queue
                        if (response.data.sync_id) {
                            // Determine sync type from sync_id prefix
                            let syncType = 'Products';
                            if (response.data.sync_id.startsWith('stock_')) {
                                syncType = 'Stock Items';
                            } else if (response.data.sync_id.startsWith('prices_')) {
                                syncType = 'Prices';
                            }
                            
                            console.log('🚀 Starting batch sync from database queue:', {
                                syncId: response.data.sync_id,
                                batchCount: response.data.batch_count,
                                total: response.data.total,
                                type: syncType
                            });
                            startBatchSyncFromQueue(response.data.sync_id, response.data.batch_count, response.data.total, syncType);
                        } else {
                            console.log('⚠️ No sync_id in response:', response.data);
                            $('[data-ajax-action]').prop('disabled', false);
                            $button.html(originalText);
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
                'price_sync': 'This will update prices for all products. Continue?'
            };
            
            return messages[actionName] || null;
        }
        
        /**
         * Start batch sync from database queue (simplest approach)
         */
        let currentSyncId = null;
        let isStopped = false;
        let currentBatchIndex = 0;
        
        function startBatchSyncFromQueue(syncId, batchCount, totalProducts, syncType) {
            currentSyncId = syncId;
            isStopped = false;
            currentBatchIndex = 0;
            syncType = syncType || 'Products';
            
            console.log('🎯 Starting queue-based batch sync:', {syncId, batchCount, totalProducts, syncType});
            
            // Show batch list
            displayBatchList(batchCount, totalProducts, syncType);
            
            // Show stop button
            $('#stop_sync_container').show();
            
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
            
            console.log('📦 Requesting next batch from queue');
            
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
                    
                    if (!response.success) {
                        console.error('Batch processing failed');
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
                    const percentage = Math.round((totalProcessed / totalProducts) * 100);
                    
                    // Update batch window if needed (slide to show current batches)
                    updateBatchWindow(batch, totalBatches);
                    
                    // Update UI for completed batch
                    $('#batch_' + batch).removeClass('processing').addClass('completed').find('.batch-status').text('✓ Done (' + processed + ')');
                    
                    // Update overall progress
                    let statsHtml = '<strong>' + totalProcessed.toLocaleString() + '/' + totalProducts.toLocaleString() + '</strong> items synced (' + percentage + '%)';
                    
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
                    
                    // Mark next batch as processing
                    if (batch + 1 < totalBatches) {
                        $('#batch_' + (batch + 1)).addClass('processing').find('.batch-status').text('Processing...');
                    }
                    
                    if (response.data.done) {
                        console.log('✅ All batches completed!');
                        $('#batch_progress_overall').html('<div class="success">✅ All ' + totalBatches + ' batches completed! ' + totalProducts + ' products synced.</div>');
                        $('#stop_sync_container').hide();
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        // Process next batch immediately
                        processNextBatchFromQueue(syncId, totalBatches);
                    }
                },
                error: function(xhr, status, error) {
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
            console.log('🛑 Setting isStopped = true');
            
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

