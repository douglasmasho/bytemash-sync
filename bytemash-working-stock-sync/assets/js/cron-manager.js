/**
 * Cron Manager JavaScript
 * 
 * Handles AJAX interactions for the cron manager admin interface
 */

jQuery(document).ready(function($) {
    
    // Toggle test mode
    $('#toggle-test-mode').on('click', function() {
        var button = $(this);
        var originalText = button.text();
        
        button.prop('disabled', true).text('Processing...');
        
        $.ajax({
            url: bytemashCron.ajax_url,
            type: 'POST',
            data: {
                action: 'bytemash_toggle_test_mode',
                nonce: bytemashCron.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#test-mode-status').html(
                        '<div class="notice notice-success"><p>' + response.data.message + '</p></div>'
                    );
                    
                    // Update button text
                    if (response.data.test_mode) {
                        button.text('Disable Test Mode');
                    } else {
                        button.text('Enable Test Mode');
                    }
                    
                    // Reload page after 2 seconds to show updated status
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    $('#test-mode-status').html(
                        '<div class="notice notice-error"><p>' + response.data.message + '</p></div>'
                    );
                }
            },
            error: function() {
                $('#test-mode-status').html(
                    '<div class="notice notice-error"><p>Request failed. Please try again.</p></div>'
                );
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });
    
    // Enable system cron
    $('#enable-system-cron').on('click', function() {
        var button = $(this);
        var originalText = button.text();
        
        button.prop('disabled', true).text('Installing...');
        
        $.ajax({
            url: bytemashCron.ajax_url,
            type: 'POST',
            data: {
                action: 'bytemash_enable_system_cron',
                nonce: bytemashCron.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#system-cron-status').html(
                        '<div class="notice notice-success"><p>' + response.data.message + '</p></div>'
                    );
                    button.text('Enabled').prop('disabled', true);
                } else {
                    $('#system-cron-status').html(
                        '<div class="notice notice-error"><p>' + response.data.message + '</p></div>'
                    );
                    button.text(originalText);
                }
            },
            error: function() {
                $('#system-cron-status').html(
                    '<div class="notice notice-error"><p>Request failed. Please try again.</p></div>'
                );
                button.text(originalText);
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });
    
    // Enable hosted pinger
    $('#enable-hosted-pinger').on('click', function() {
        var button = $(this);
        var originalText = button.text();
        
        button.prop('disabled', true).text('Registering...');
        
        $.ajax({
            url: bytemashCron.ajax_url,
            type: 'POST',
            data: {
                action: 'bytemash_enable_hosted_pinger',
                nonce: bytemashCron.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#hosted-pinger-status').html(
                        '<div class="notice notice-success"><p>' + response.data.message + '</p></div>'
                    );
                    button.text('Enabled').prop('disabled', true);
                } else {
                    $('#hosted-pinger-status').html(
                        '<div class="notice notice-error"><p>' + response.data.message + '</p></div>'
                    );
                    button.text(originalText);
                }
            },
            error: function() {
                $('#hosted-pinger-status').html(
                    '<div class="notice notice-error"><p>Request failed. Please try again.</p></div>'
                );
                button.text(originalText);
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });
    
    // Refresh diagnostics
    $('#refresh-diagnostics').on('click', function() {
        var button = $(this);
        var originalText = button.text();
        
        button.prop('disabled', true).text('Refreshing...');
        
        $.ajax({
            url: bytemashCron.ajax_url,
            type: 'POST',
            data: {
                action: 'bytemash_cron_diagnostics',
                nonce: bytemashCron.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Update diagnostics content
                    updateDiagnosticsContent(response.data);
                } else {
                    alert('Failed to refresh diagnostics: ' + response.data.message);
                }
            },
            error: function() {
                alert('Request failed. Please try again.');
            },
            complete: function() {
                button.prop('disabled', false).text(originalText);
            }
        });
    });
    
    // Auto-refresh diagnostics every 30 seconds
    setInterval(function() {
        $.ajax({
            url: bytemashCron.ajax_url,
            type: 'POST',
            data: {
                action: 'bytemash_cron_diagnostics',
                nonce: bytemashCron.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateDiagnosticsContent(response.data);
                }
            }
        });
    }, 30000);
    
    // Helper function to update diagnostics content
    function updateDiagnosticsContent(data) {
        var content = '<table class="form-table">';
        
        // PHP Functions
        content += '<tr><th>PHP Functions</th><td>';
        content += 'exec(): ' + (data.exec_available ? '✅' : '❌') + '<br>';
        content += 'shell_exec(): ' + (data.shell_exec_available ? '✅' : '❌') + '<br>';
        content += 'wp_remote_post(): ' + (data.wp_remote_post_available ? '✅' : '❌') + '</td></tr>';
        
        // System Commands
        content += '<tr><th>System Commands</th><td>';
        content += 'crontab: ' + (data.crontab_available ? '✅' : '❌') + '<br>';
        content += 'wget: ' + (data.wget_available ? '✅' : '❌') + '<br>';
        content += 'WP-CLI: ' + (data.wp_cli_available ? '✅' : '❌') + '</td></tr>';
        
        // Permissions
        content += '<tr><th>Permissions</th><td>';
        content += 'Plugin Dir Writable: ' + (data.plugin_writable ? '✅' : '❌') + '<br>';
        content += 'Uploads Dir Writable: ' + (data.uploads_writable ? '✅' : '❌') + '</td></tr>';
        
        // Network
        content += '<tr><th>Network</th><td>';
        content += 'Loopback Blocked: ' + (data.loopback_blocked ? '❌' : '✅') + '<br>';
        content += 'External Requests: ' + (data.external_requests ? '✅' : '❌') + '</td></tr>';
        
        // Cron Health
        content += '<tr><th>Cron Health</th><td>';
        content += 'Last Ping: ' + data.last_ping + '<br>';
        content += 'Ping Success Rate: ' + data.ping_success_rate + '%</td></tr>';
        
        content += '</table>';
        
        $('#diagnostics-content').html(content);
    }
    
    // Initialize diagnostics on page load
    $('#refresh-diagnostics').trigger('click');
});
