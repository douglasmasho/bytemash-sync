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

        $('#bytemash_delete_excess_button').on('click', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();

            const confirmDelete = 'This will fetch the latest Amrod catalog and permanently delete WooCommerce products that are no longer returned by the API. Continue?';
            if (!confirm(confirmDelete)) {
                return;
            }

            requestExcessCleanup('manual_cleanup', {
                button: $(this)
            });
        });
        
        // Stop execution - don't try to run without proper data
        return;
    }
    
    // Log successful load
    console.log('✅ ByteMash WooSync Admin JS initialized successfully');
    console.log('AJAX URL:', bytemashWooSync.ajax_url);
    console.log('Plugin URL:', bytemashWooSync.debug.plugin_url);
    
    const PRODUCTION_PROGRESS_SELECTOR = '#production-full-sync-progress';
    let productionSyncIntervalId = null;
    let productionProgressHasData = false;
    const productionSyncConfig = bytemashWooSync.production_full_sync || {};
    const uiStrings = bytemashWooSync.strings || {};
    
    const escapeHtml = (value) => {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    };
    
    const formatValueOrFallback = (value, fallbackText) => {
        const fallback = fallbackText || uiStrings.never || 'Never';
        if (!value) {
            return '<em>' + escapeHtml(fallback) + '</em>';
        }
        return '<span class="bytemash-timestamp">' + escapeHtml(value) + '</span>';
    };
    
    const formatHookName = (hook) => {
        if (!hook) {
            return 'unknown';
        }
        return hook.replace('bytemash_action_scheduler_', '').replace(/[_-]/g, ' ').trim() || hook;
    };
    
    const buildProgressBar = (percentage) => {
        const normalized = Math.min(100, Math.max(0, Number(percentage) || 0));
        return '' +
            '<div class="production-sync-progress-bar">' +
            '<span class="progress-fill" style="width:' + normalized + '%;"></span>' +
            '</div>' +
            '<p class="progress-label">' + escapeHtml(normalized.toFixed(2)) + '%</p>';
    };
    
    const renderProductionFullSyncError = (message) => {
        const $container = $(PRODUCTION_PROGRESS_SELECTOR);
        if (!$container.length) {
            return;
        }
        productionProgressHasData = true;
        const displayMessage = message || uiStrings.monitor_error || 'Unable to fetch Action Scheduler status.';
        $container.html(
            '<div class="notice notice-error inline"><p>' + escapeHtml(displayMessage) + '</p></div>'
        );
    };
    
    const renderProductionFullSyncProgress = (payload) => {
        const $container = $(PRODUCTION_PROGRESS_SELECTOR);
        if (!$container.length) {
            return;
        }
        if (!payload || !payload.action_scheduler_available) {
            renderProductionFullSyncError(uiStrings.action_scheduler_missing || 'Action Scheduler is not available on this site.');
            return;
        }
        
        productionProgressHasData = true;
        
        const progress = payload.progress || {};
        const next = payload.next_scheduled || {};
        const last = payload.last_completed || {};
        const recent = payload.recent_activity || {};
        
        const runningCount = Number(progress.running) || 0;
        const pendingCount = Number(progress.pending) || 0;
        const failedCount = Number(progress.failed) || 0;
        const completedCount = Number(progress.completed) || 0;
        const normalizedPercentage = Math.min(100, Math.max(0, Number(progress.percentage) || 0));
        
        let html = '<div class="production-progress-card">';
        const statusText = runningCount > 0
            ? (uiStrings.scheduler_running || 'Action Scheduler is running now')
            : (uiStrings.scheduler_idle || 'Action Scheduler is waiting for the next schedule');
        html += '<p><strong>Status:</strong> ' + escapeHtml(statusText) + '</p>';
        html += '<div class="production-progress-meta">';
        html += '<p><strong>Next full sync:</strong> ' + formatValueOrFallback(next.full_sync, uiStrings.schedule_pending || 'Schedule pending') + '</p>';
        html += '<p><strong>Next incremental sync:</strong> ' + formatValueOrFallback(next.incremental_sync, uiStrings.schedule_pending || 'Schedule pending') + '</p>';
        html += '<p><strong>Last full sync:</strong> ' + formatValueOrFallback(last.full_sync, uiStrings.never || 'Never') + '</p>';
        html += '<p><strong>Last incremental sync:</strong> ' + formatValueOrFallback(last.incremental_sync, uiStrings.never || 'Never') + '</p>';
        html += '</div>';
        
        if (runningCount > 0 || normalizedPercentage > 0) {
            html += buildProgressBar(normalizedPercentage);
            const summaryText = runningCount + ' running · ' + pendingCount + ' pending · ' + failedCount + ' failed';
            html += '<p class="production-progress-summary">' + escapeHtml(summaryText) + '</p>';
        } else {
            const summaryText = completedCount + ' completed actions recorded';
            html += '<p class="production-progress-summary">' + escapeHtml(summaryText) + '</p>';
        }
        
        const runningList = Array.isArray(recent.running) ? recent.running.slice(0, 3) : [];
        if (runningList.length) {
            html += '<div class="production-progress-list"><strong>Running tasks:</strong><ul>';
            runningList.forEach((action) => {
                const hookLabel = escapeHtml(formatHookName(action.hook));
                html += '<li>' + hookLabel + ' — ' + formatValueOrFallback(action.scheduled_at, uiStrings.schedule_pending || 'Schedule pending') + '</li>';
            });
            html += '</ul></div>';
        } else if (Array.isArray(recent.completed) && recent.completed.length) {
            const latest = recent.completed[0];
            const hookLabel = escapeHtml(formatHookName(latest.hook));
            html += '<p><strong>Last completed action:</strong> ' + hookLabel + ' — ' + formatValueOrFallback(latest.scheduled_at, uiStrings.schedule_pending || 'Schedule pending') + '</p>';
        }
        
        html += '</div>';
        $container.html(html);
    };
    
    const showProductionProgressLoading = () => {
        if (productionProgressHasData) {
            return;
        }
        const $container = $(PRODUCTION_PROGRESS_SELECTOR);
        if (!$container.length) {
            return;
        }
        const loadingMessage = uiStrings.monitor_loading || 'Checking Action Scheduler status...';
        $container.html('<p class="description">' + escapeHtml(loadingMessage) + '</p>');
    };
    
    const fetchProductionFullSyncProgress = () => {
        if (!productionSyncConfig.enabled || !productionSyncConfig.action_scheduler_available) {
            return;
        }
        const $container = $(PRODUCTION_PROGRESS_SELECTOR);
        if (!$container.length) {
            return;
        }
        
        showProductionProgressLoading();
        
        jQuery.ajax({
            url: bytemashWooSync.ajax_url,
            type: 'POST',
            data: {
                action: 'bytemash_get_sync_status_progress',
                nonce: bytemashWooSync.nonce
            },
            success: function(response) {
                if (response && response.success && response.data) {
                    renderProductionFullSyncProgress(response.data);
                } else {
                    renderProductionFullSyncError((response && response.data && response.data.message) || (uiStrings.monitor_error || 'Unable to fetch Action Scheduler status.'));
                }
            },
            error: function() {
                renderProductionFullSyncError(uiStrings.monitor_error || 'Unable to fetch Action Scheduler status.');
            }
        });
    };
    
    const initProductionFullSyncMonitor = () => {
        if (!productionSyncConfig.enabled || productionSyncIntervalId) {
            return;
        }
        fetchProductionFullSyncProgress();
        const interval = Number(productionSyncConfig.poll_interval) || 30000;
        productionSyncIntervalId = setInterval(fetchProductionFullSyncProgress, interval);
    };
    
    $(document).ready(function() {
        
        // Add modern loading states and animations
        $('.bytemash-card').each(function() {
            $(this).css('opacity', '0').animate({opacity: 1}, 300);
        });
        
        if (productionSyncConfig.enabled) {
            if (productionSyncConfig.action_scheduler_available) {
                initProductionFullSyncMonitor();
            } else {
                renderProductionFullSyncError(uiStrings.action_scheduler_missing || 'Action Scheduler is not available on this site.');
            }
        }
        
        // Add hover effects for cards
        $('.bytemash-card').hover(
            function() {
                $(this).addClass('card-hover');
            },
            function() {
                $(this).removeClass('card-hover');
            }
        );
        
        // Add smooth scrolling for anchor links
        $('a[href^="#"]').on('click', function(e) {
            e.preventDefault();
            var target = $(this.getAttribute('href'));
            if (target.length) {
                $('html, body').animate({
                    scrollTop: target.offset().top - 20
                }, 500);
            }
        });
        
        // Add modern button interactions
        $('.bytemash-button-group .button').on('mouseenter', function() {
            $(this).addClass('button-hover');
        }).on('mouseleave', function() {
            $(this).removeClass('button-hover');
        });
        
        // Add progress bar animations
        $('.bytemash-progress-fill').each(function() {
            var $this = $(this);
            var width = $this.data('width') || $this.attr('style').match(/width:\s*(\d+%)/);
            if (width) {
                $this.css('width', '0%').animate({width: width}, 1000);
            }
        });
        
        // Add modern tooltips
        $('[title]').each(function() {
            var $this = $(this);
            var title = $this.attr('title');
            $this.removeAttr('title').attr('data-tooltip', title);
        });
        
        // Add click animations for buttons
        $('.button').on('click', function() {
            $(this).addClass('button-clicked');
            setTimeout(() => {
                $(this).removeClass('button-clicked');
            }, 200);
        });
        
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
         * Handle production full sync toggle
         */
        $('#toggle-production-full-sync').on('click', function() {
            const $button = $(this);
            const originalText = $button.text();
            
            $button.prop('disabled', true).text('Processing...');
            
            $.ajax({
                url: bytemashWooSync.ajax_url,
                type: 'POST',
                data: {
                    action: 'bytemash_toggle_production_full_sync',
                    nonce: bytemashWooSync.nonce
                },
                success: function(response) {
                    if (response.success) {
                        let statusHtml = '<div class="notice notice-success"><p>' + response.data.message;
                        if (response.data.next_full_sync) {
                            statusHtml += '<br><strong>Next full sync:</strong> ' + response.data.next_full_sync;
                        }
                        if (response.data.next_incremental_sync) {
                            statusHtml += '<br><strong>Next incremental sync:</strong> ' + response.data.next_incremental_sync;
                        }
                        statusHtml += '</p></div>';
                        $('#production-full-sync-status').html(statusHtml);
                        
                        // Update button text and class
                        if (response.data.enabled) {
                            $button.text('Disable Production Full Sync').removeClass('button-primary').addClass('button-secondary');
                        } else {
                            $button.text('Enable Production Full Sync').removeClass('button-secondary').addClass('button-primary');
                        }
                        
                        // Update badge
                        const $badge = $('.production-full-sync-section .test-mode-badge');
                        if (response.data.enabled) {
                            $badge.removeClass('disabled').addClass('enabled').text('Enabled');
                        } else {
                            $badge.removeClass('enabled').addClass('disabled').text('Disabled');
                        }
                        
                        // Reload page after 2 seconds to show updated status
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $('#production-full-sync-status').html(
                            '<div class="notice notice-error"><p>' + response.data.message + '</p></div>'
                        );
                        $button.text(originalText);
                    }
                },
                error: function() {
                    $('#production-full-sync-status').html(
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
         * Handle production cron enable (deprecated - kept for backward compatibility)
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
            let wasSuccess = false;
            
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
                        wasSuccess = true;
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
                            // If warning (exec not available), change button text but keep enabled
                            $button.text('Schedules Enabled').removeClass('button-primary').addClass('button-secondary').prop('disabled', false);
                        }
                    } else {
                        $('#production-system-cron-status').html(
                            '<div class="notice notice-error"><p>' + response.data.message + '</p></div>'
                        );
                        $button.text(originalText).prop('disabled', false);
                    }
                },
                error: function() {
                    $('#production-system-cron-status').html(
                        '<div class="notice notice-error"><p>Request failed. Please try again.</p></div>'
                    );
                    $button.text(originalText).prop('disabled', false);
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
            
            // Show cleanup/deletion status if available
            if (sync.cleanup_status || sync.status === 'deleting_excess') {
                let cleanupClass = 'info';
                let cleanupIcon = '🔄';
                let cleanupTitle = 'Cleanup';
                
                if (sync.status === 'deleting_excess') {
                    cleanupTitle = 'Deleting Excess Products';
                    cleanupIcon = '🗑️';
                    cleanupClass = 'warning';
                } else if (sync.cleanup_status === 'completed') {
                    cleanupClass = 'success';
                    cleanupIcon = '✓';
                } else if (sync.cleanup_status === 'starting') {
                    cleanupIcon = '🔄';
                } else if (sync.cleanup_status === 'in_progress') {
                    cleanupIcon = '🗑️';
                    cleanupClass = 'warning';
                }
                
                let message = sync.cleanup_message || (sync.status === 'deleting_excess' ? 'Deleting excess products...' : 'Checking for excess products...');
                
                html += '<div class="cleanup-status ' + cleanupClass + '" style="margin-top: 10px; padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px;">';
                html += '<strong>' + cleanupIcon + ' ' + cleanupTitle + ': </strong>';
                html += '<span>' + message + '</span>';
                if (sync.cleanup_deleted !== undefined && sync.cleanup_deleted > 0) {
                    html += ' <span style="color: #dc3545; font-weight: bold;">(' + sync.cleanup_deleted + ' products deleted)</span>';
                }
                if (sync.cleanup_checked !== undefined && sync.cleanup_checked > 0) {
                    html += ' <span style="color: #6c757d;">(' + sync.cleanup_checked + ' checked)</span>';
                }
                html += '</div>';
            }
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
                   .data('original-text', originalText)
                   .html('<span class="bytemash-spinner"></span> Starting...');
            
            // Disable all other sync buttons and store their original text
            $('[data-ajax-action]').each(function() {
                const $btn = $(this);
                if (!$btn.data('original-text')) {
                    $btn.data('original-text', $btn.html());
                }
                $btn.prop('disabled', true);
            });
            
            const isProductSyncAction = actionName === 'manual_sync' || actionName === 'sync_products_incremental';
            cleanupQueuedForCurrentRun = isProductSyncAction ? $('#queue_cleanup_after_product_sync').is(':checked') : false;

            const requestData = {
                action: action,
                nonce: bytemashWooSync.nonce
            };

            if (isProductSyncAction) {
                requestData.cleanup_after_sync = cleanupQueuedForCurrentRun ? 1 : 0;
            }

            $.ajax({
                url: bytemashWooSync.ajax_url,
                type: 'POST',
                data: requestData,
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
                            currentSyncTypeLabel = syncType;
                            startBatchSyncFromQueue(response.data.sync_id, response.data.batch_count, response.data.total, syncType);
                        } else {
                            // No batches to process (e.g., no updates available, or simple sync complete)
                            console.log('ℹ️ Sync completed without batches:', response.data);
                            showSyncMessage('success', response.data.message || 'Sync completed successfully');
                            $('[data-ajax-action]').prop('disabled', false).each(function() {
                                const $btn = $(this);
                                const originalText = $btn.data('original-text') || $btn.text();
                                $btn.html(originalText);
                            });
                            
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
                        cleanupQueuedForCurrentRun = false;
                        $('[data-ajax-action]').prop('disabled', false).each(function() {
                            const $btn = $(this);
                            const originalText = $btn.data('original-text') || $btn.text();
                            $btn.html(originalText);
                        });
                    }
                },
                error: function(xhr) {
                    showSyncMessage('error', 'Sync failed. Please try again. Error: ' + xhr.statusText);
                    cleanupQueuedForCurrentRun = false;
                    $('[data-ajax-action]').prop('disabled', false).each(function() {
                        const $btn = $(this);
                        const originalText = $btn.data('original-text') || $btn.text();
                        $btn.html(originalText);
                    });
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
                'inclusive_brandings_sync': 'This will sync inclusive branding options with progress tracking. Continue?',
                'delete_excess_products': 'This will delete WooCommerce products that are no longer present in the Amrod API. Continue?'
            };
            
            return messages[actionName] || null;
        }

        /**
         * Trigger the excess product cleanup AJAX request with progress tracking.
         *
         * @param {string} contextLabel
         * @param {object} options
         * @returns {Promise}
         */
        function requestExcessCleanup(contextLabel, options = {}) {
            const opts = Object.assign({
                button: null,
                messageTarget: null,
                showStatus: false,
                startText: 'Deleting excess products...'
            }, options);

            if (cleanupRequestInFlight) {
                return Promise.resolve();
            }

            cleanupRequestInFlight = true;
            const $button = opts.button;
            const originalText = $button ? $button.html() : '';
            if ($button) {
                $button.prop('disabled', true)
                    .data('original-text', originalText)
                    .html('<span class="bytemash-spinner"></span> ' + opts.startText);
            }

            const $messageTarget = opts.messageTarget ? $(opts.messageTarget) : $('#sync_message');
            if (opts.showStatus && $messageTarget.length) {
                $messageTarget.html('<div class="info">🧹 ' + opts.startText + '</div>');
            } else {
                showSyncMessage('info', opts.startText);
            }

            return new Promise((resolve, reject) => {
                // First, initiate the cleanup
                $.ajax({
                    url: bytemashWooSync.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'bytemash_delete_excess_products',
                        nonce: bytemashWooSync.nonce,
                        context: contextLabel
                    },
                    success: function(response) {
                        if (response.success && response.data && response.data.sync_id) {
                            // Start batch processing with progress tracking
                            const syncId = response.data.sync_id;
                            processCleanupBatches(syncId, $messageTarget, opts, resolve, reject);
                        } else if (response.success) {
                            // Completed immediately (no products to delete)
                            const message = response.data && response.data.message
                                ? response.data.message
                                : 'Cleanup completed successfully.';

                            if (opts.showStatus && $messageTarget.length) {
                                $messageTarget.html('<div class="success">✅ ' + message + '</div>');
                            } else {
                                showSyncMessage('success', message);
                            }
                            
                            if ($button) {
                                const previousText = $button.data('original-text') || originalText;
                                $button.prop('disabled', false).html(previousText);
                            }
                            cleanupRequestInFlight = false;
                            resolve(response);
                        } else {
                            const errorMessage = response.data && response.data.message
                                ? response.data.message
                                : 'Cleanup failed. Please try again.';
                            if (opts.showStatus && $messageTarget.length) {
                                $messageTarget.html('<div class="error">❌ ' + errorMessage + '</div>');
                            } else {
                                showSyncMessage('error', errorMessage);
                            }
                            
                            if ($button) {
                                const previousText = $button.data('original-text') || originalText;
                                $button.prop('disabled', false).html(previousText);
                            }
                            cleanupRequestInFlight = false;
                            reject(errorMessage);
                        }
                    },
                    error: function(xhr) {
                        const errorMessage = 'Cleanup failed: ' + xhr.statusText;
                        if (opts.showStatus && $messageTarget.length) {
                            $messageTarget.html('<div class="error">❌ ' + errorMessage + '</div>');
                        } else {
                            showSyncMessage('error', errorMessage);
                        }
                        
                        if ($button) {
                            const previousText = $button.data('original-text') || originalText;
                            $button.prop('disabled', false).html(previousText);
                        }
                        cleanupRequestInFlight = false;
                        reject(errorMessage);
                    }
                });
            });
        }
        
        /**
         * Process cleanup batches with progress tracking
         */
        function processCleanupBatches(syncId, $messageTarget, opts, resolve, reject) {
            const batchSize = 50;
            let totalChecked = 0;
            let totalDeleted = 0;
            
            function processNextBatch() {
                $.ajax({
                    url: bytemashWooSync.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'bytemash_process_cleanup_batch',
                        sync_id: syncId,
                        batch_size: batchSize,
                        nonce: bytemashWooSync.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            totalChecked = response.data.checked || 0;
                            totalDeleted = response.data.deleted || 0;
                            
                            // Update progress message
                            const progressMsg = 'Deleting excess products... ' + 
                                totalChecked.toLocaleString() + ' checked, ' + 
                                totalDeleted.toLocaleString() + ' deleted';
                            
                            if (opts.showStatus && $messageTarget.length) {
                                $messageTarget.html('<div class="info">🧹 ' + progressMsg + '</div>');
                            } else {
                                showSyncMessage('info', progressMsg);
                            }
                            
                            if (response.data.done) {
                                // All done
                                const finalMessage = 'Cleanup complete: ' + 
                                    totalChecked.toLocaleString() + ' checked, ' + 
                                    totalDeleted.toLocaleString() + ' deleted';
                                
                                if (opts.showStatus && $messageTarget.length) {
                                    $messageTarget.html('<div class="success">✅ ' + finalMessage + '</div>');
                                } else {
                                    showSyncMessage('success', finalMessage);
                                }
                                
                                if (opts.button) {
                                    const $btn = opts.button;
                                    const previousText = $btn.data('original-text') || '';
                                    $btn.prop('disabled', false).html(previousText);
                                }
                                cleanupRequestInFlight = false;
                                resolve(response);
                            } else {
                                // Continue with next batch
                                setTimeout(processNextBatch, 500);
                            }
                        } else {
                            const errorMessage = response.data && response.data.message
                                ? response.data.message
                                : 'Cleanup failed. Please try again.';
                            
                            if (opts.showStatus && $messageTarget.length) {
                                $messageTarget.html('<div class="error">❌ ' + errorMessage + '</div>');
                            } else {
                                showSyncMessage('error', errorMessage);
                            }
                            
                            if (opts.button) {
                                const $btn = opts.button;
                                const previousText = $btn.data('original-text') || '';
                                $btn.prop('disabled', false).html(previousText);
                            }
                            cleanupRequestInFlight = false;
                            reject(errorMessage);
                        }
                    },
                    error: function(xhr) {
                        const errorMessage = 'Cleanup failed: ' + xhr.statusText;
                        if (opts.showStatus && $messageTarget.length) {
                            $messageTarget.html('<div class="error">❌ ' + errorMessage + '</div>');
                        } else {
                            showSyncMessage('error', errorMessage);
                        }
                        
                        if (opts.button) {
                            const $btn = opts.button;
                            const previousText = $btn.data('original-text') || '';
                            $btn.prop('disabled', false).html(previousText);
                        }
                        cleanupRequestInFlight = false;
                        reject(errorMessage);
                    }
                });
            }
            
            // Start processing batches
            processNextBatch();
        }
        
        /**
         * Start batch sync from database queue (simplest approach)
         */
        let currentSyncId = null;
        let isStopped = false;
        let currentBatchIndex = 0;
        let isProcessingBatch = false; // Lock to prevent parallel processing
        let cleanupQueuedForCurrentRun = false;
        let currentSyncTypeLabel = 'Products';
        let cleanupRequestInFlight = false;
        
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
            
            for (let i = 0; i < batchCount; i++) {
                html += '<div class="batch-item" id="batch_' + i + '" data-batch="' + i + '">';
                html += '<div class="batch-head">';
                html += '<span class="batch-number">Batch ' + (i + 1) + '</span>';
                html += '<span class="batch-status">Waiting...</span>';
                html += '</div>';
                html += '</div>';
            }
            
            html += '</div></div>';
            
            $container.html(html);
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
                    const previousBatchIndex = currentBatchIndex;
                    currentBatchIndex = batch;
                    
                    // Get list of all completed batches from server response
                    const completedBatches = response.data.completed_batches || [];
                    
                    // Mark all completed batches (handles out-of-order completion)
                    completedBatches.forEach(function(completedBatchIndex) {
                        const $completedBatch = $('#batch_' + completedBatchIndex);
                        if ($completedBatch.length && !$completedBatch.hasClass('completed')) {
                            // Only update if not already completed (to preserve detailed status)
                            if (!$completedBatch.hasClass('completed')) {
                                $completedBatch.removeClass('processing').addClass('completed');
                                // If it was just "Waiting...", mark as completed with generic status
                                const $status = $completedBatch.find('.batch-status');
                                if ($status.text() === 'Waiting...' || $status.text().indexOf('Processing...') !== -1) {
                                    $status.text('✓ Completed');
                                }
                            }
                        }
                    });
                    
                    // If server processed a batch out of order, mark any gaps as completed
                    // (they were likely stuck and got reset by the timeout mechanism)
                    if (previousBatchIndex !== null && previousBatchIndex !== undefined && batch > previousBatchIndex + 1) {
                        console.log('⚠️ Batch ' + batch + ' completed, but expected batch ' + (previousBatchIndex + 1) + '. Marking intermediate batches as completed.');
                        for (let i = previousBatchIndex + 1; i < batch; i++) {
                            const $gapBatch = $('#batch_' + i);
                            if ($gapBatch.length && !$gapBatch.hasClass('completed')) {
                                $gapBatch.removeClass('processing').addClass('completed');
                                $gapBatch.find('.batch-status').text('✓ Completed (auto)');
                            }
                        }
                    }
                    
                    // Update batch window FIRST (slide to show current batches if needed)
                    // Safety check: Ensure batch element exists
                    if ($('#batch_' + batch).length === 0) {
                        const batchHtml = '<div class="batch-item" id="batch_' + batch + '" data-batch="' + batch + '">' +
                            '<div class="batch-head">' +
                                '<span class="batch-number">Batch ' + (batch + 1) + '</span>' +
                                '<span class="batch-status">Processing...</span>' +
                            '</div>' +
                        '</div>';
                        $('#batch_list').append(batchHtml);
                    }
                                        
                    // Update UI for completed batch with detailed status
                    const batchErrors = response.data.errors || 0;
                    const batchSkipped = response.data.skipped || 0;
                    const totalSkipped = response.data.total_skipped || 0;
                    const totalErrors = response.data.total_errors || 0;
                    const lastChangedFields = response.data.last_changed_fields || [];
                    const lastProcessingReason = response.data.last_processing_reason || '';
                    const lastSkipReason = response.data.last_skip_reason || '';
                    
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
                    
                    const $batchItem = $('#batch_' + batch);
                    $batchItem.removeClass('processing').addClass('completed').find('.batch-status').text(batchStatusText);

                    // Append meta details (reasons / changed fields) for product sync batches
                    let metaLines = [];
                    if (lastProcessingReason && batchSkipped === 0) {
                        metaLines.push('<strong>Reason:</strong> ' + formatReasonLabel(lastProcessingReason));
                    }
                    if (batchSkipped > 0 && lastSkipReason) {
                        metaLines.push('<strong>Skipped:</strong> ' + formatReasonLabel(lastSkipReason));
                    }
                    if (lastChangedFields.length > 0) {
                        metaLines.push('<strong>Changed:</strong> ' + lastChangedFields.join(', '));
                    }
                    if (metaLines.length > 0) {
                        const metaHtml = '<div class="batch-meta">' + metaLines.join('<br>') + '</div>';
                        const $existingMeta = $batchItem.find('.batch-meta');
                        if ($existingMeta.length) {
                            $existingMeta.html(metaHtml);
                        } else {
                            $batchItem.append(metaHtml);
                        }
                    }
                    
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
                        
                        const finalizeSyncUI = () => {
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
                            
                            if (totalSkipped > 0 && totalSkipped > totalProcessed) {
                                completionMsg += '<br><small style="color: #f0ad4e;">ℹ️ Note: Skipped items are products that don\'t exist in WooCommerce yet. Sync products from Amrod first.</small>';
                            }
                            
                            $('#batch_progress_overall').html('<div class="success">' + completionMsg + '</div>');
                            $('#stop_sync_container').hide();
                            setTimeout(() => location.reload(), 3000);
                        };

                        if (cleanupQueuedForCurrentRun && currentSyncTypeLabel === 'Products') {
                            cleanupQueuedForCurrentRun = false;
                            requestExcessCleanup('post_sync_cleanup', {
                                showStatus: true,
                                messageTarget: '#batch_progress_overall',
                                startText: 'Deleting excess products after sync...'
                            }).then(() => {
                                finalizeSyncUI();
                            }).catch(() => {
                                finalizeSyncUI();
                            });
                            return;
                        }

                        finalizeSyncUI();
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
                        
                        // Re-enable sync buttons and reset their text
                        $('[data-ajax-action]').prop('disabled', false).each(function() {
                            const $btn = $(this);
                            const originalText = $btn.data('original-text') || $btn.text();
                            $btn.html(originalText);
                        });
                        
                        showSyncMessage('success', '🛑 ' + response.data.message + ' You can start a new sync now.');
                    },
                    error: function() {
                        // Even if AJAX fails, clear UI
                        $('#active_syncs').html('<div class="success" style="padding: 20px; text-align: center;">🛑 Sync stopped.</div>');
                        $('#stop_sync_container').hide();
                        $('[data-ajax-action]').prop('disabled', false).each(function() {
                            const $btn = $(this);
                            const originalText = $btn.data('original-text') || $btn.text();
                            $btn.html(originalText);
                        });
                        showSyncMessage('success', '🛑 Sync stopped. You can start a new sync now.');
                    },
                    complete: function() {
                        // Always reset the stop button state
                        $button.prop('disabled', false);
                        $button.html('<span class="dashicons dashicons-no"></span> Stop Sync');
                    }
                });
            } else {
                // No sync ID, just clear UI
                $('#active_syncs').empty();
                $('#stop_sync_container').hide();
                $('[data-ajax-action]').prop('disabled', false).each(function() {
                    const $btn = $(this);
                    const originalText = $btn.data('original-text') || $btn.text();
                    $btn.html(originalText);
                });
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

        function formatReasonLabel(reason) {
            if (!reason) {
                return '';
            }
            try {
                const label = reason.toString().replace(/_/g, ' ');
                return label.charAt(0).toUpperCase() + label.slice(1);
            } catch (err) {
                return reason;
            }
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
         * Cleanup zero prices (YITH compatibility)
         */
        $('#cleanup_zero_prices').on('click', function() {
            if (!confirm('Remove all fake \'0\' prices from products?\n\nThis will allow YITH Request a Quote to work correctly.')) {
                return;
            }
            
            const $button = $(this);
            const $result = $('#cleanup_zero_prices_result');
            
            $button.prop('disabled', true).text('Cleaning...');
            $result.html('');
            
            $.ajax({
                url: bytemashWooSync.ajax_url,
                type: 'POST',
                data: {
                    action: 'bytemash_cleanup_zero_prices',
                    nonce: bytemashWooSync.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $result.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                        showNotice('success', response.data.message);
                    } else {
                        $result.html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                        showNotice('error', response.data.message);
                    }
                    $button.prop('disabled', false).text('Remove Fake Zero Prices');
                },
                error: function(xhr) {
                    const message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message 
                        ? xhr.responseJSON.data.message 
                        : 'Failed to cleanup prices. Please try again.';
                    $result.html('<div class="notice notice-error inline"><p>' + message + '</p></div>');
                    showNotice('error', message);
                    $button.prop('disabled', false).text('Remove Fake Zero Prices');
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

