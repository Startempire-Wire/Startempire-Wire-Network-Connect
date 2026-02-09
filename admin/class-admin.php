<?php
/**
 * SEWN Connect Admin Settings
 * 
 * Settings page for configuring Ring Leader connection.
 */

if (!defined('WPINC')) die;

class SEWN_Connect_Admin {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function add_menu() {
        add_options_page(
            'SEWN Connect',
            'SEWN Connect',
            'manage_options',
            'sewn-connect',
            [$this, 'render_page']
        );
    }

    public function register_settings() {
        register_setting('sewn_connect', 'sewn_connect_ring_leader_url');
        register_setting('sewn_connect', 'sewn_connect_api_key');
        register_setting('sewn_connect', 'sewn_connect_cache_ttl');
        register_setting('sewn_connect', 'sewn_connect_scoreboard_url');
        register_setting('sewn_connect', 'sewn_connect_wirebot_url');
        register_setting('sewn_connect', 'sewn_connect_overlay_enabled');
        register_setting('sewn_connect', 'sewn_connect_deploy_secret');
        register_setting('sewn_connect', 'sewn_connect_auto_update');
    }

    public function render_page() {
        if (!current_user_can('manage_options')) return;

        $overlay_on = get_option('sewn_connect_overlay_enabled', '1');
        $rl_url = get_option('sewn_connect_ring_leader_url', 'https://startempirewire.network/wp-json/sewn/v1');
        $api_key = get_option('sewn_connect_api_key', '');
        $cache_ttl = get_option('sewn_connect_cache_ttl', 300);
        $sb_url = get_option('sewn_connect_scoreboard_url', 'https://wins.wirebot.chat');
        $wb_url = get_option('sewn_connect_wirebot_url', 'https://helm.wirebot.chat');
        $deploy_secret = get_option('sewn_connect_deploy_secret', '');
        $auto_update = get_option('sewn_connect_auto_update', '');

        // Health check
        require_once SEWN_CONNECT_PATH . 'inc/class-ring-leader-client.php';
        $client = new SEWN_Connect_Ring_Leader_Client();
        $healthy = $client->health_check();
        $stats = $healthy ? $client->get_network_stats() : null;

        ?>
        <div class="wrap">
            <h1>⚡ Startempire Wire Network Connect</h1>
            
            <div style="background: <?php echo $healthy ? '#d4edda' : '#f8d7da'; ?>; border: 1px solid <?php echo $healthy ? '#c3e6cb' : '#f5c6cb'; ?>; padding: 12px; border-radius: 4px; margin: 16px 0;">
                <strong>Ring Leader Status:</strong> 
                <?php if ($healthy): ?>
                    ✅ Connected
                    <?php if ($stats): ?>
                        — <?php echo intval($stats['total_members']); ?> members, 
                        <?php echo count($stats['membership_tiers'] ?? []); ?> tiers
                    <?php endif; ?>
                <?php else: ?>
                    ❌ Disconnected — check Ring Leader URL and API key
                <?php endif; ?>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('sewn_connect'); ?>
                
                <table class="form-table">
                    <tr>
                        <th>Wirebot Overlay Widget</th>
                        <td>
                            <label>
                                <input type="checkbox" name="sewn_connect_overlay_enabled" value="1" <?php checked($overlay_on, '1'); ?> />
                                Enable floating ⚡ button + overlay panel on frontend
                            </label>
                            <p class="description">Shows Wirebot chat, scoreboard summary, and network stats to logged-in users.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Ring Leader API URL</th>
                        <td>
                            <input type="url" name="sewn_connect_ring_leader_url" value="<?php echo esc_attr($rl_url); ?>" class="regular-text" />
                            <p class="description">Full URL to Ring Leader REST API (e.g., https://startempirewire.network/wp-json/sewn/v1)</p>
                        </td>
                    </tr>
                    <tr>
                        <th>API Key</th>
                        <td>
                            <input type="password" name="sewn_connect_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" />
                            <p class="description">Ring Leader JWT or API key for authenticated requests</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Cache TTL (seconds)</th>
                        <td>
                            <input type="number" name="sewn_connect_cache_ttl" value="<?php echo esc_attr($cache_ttl); ?>" min="0" max="86400" />
                            <p class="description">How long to cache Ring Leader responses (0 = no caching)</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Scoreboard URL</th>
                        <td>
                            <input type="url" name="sewn_connect_scoreboard_url" value="<?php echo esc_attr($sb_url); ?>" class="regular-text" />
                            <p class="description">Wirebot Scoreboard URL (wins.wirebot.chat)</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Wirebot Gateway URL</th>
                        <td>
                            <input type="url" name="sewn_connect_wirebot_url" value="<?php echo esc_attr($wb_url); ?>" class="regular-text" />
                            <p class="description">Wirebot AI gateway URL (helm.wirebot.chat)</p>
                        </td>
                    </tr>
                </table>

                <h2 style="margin-top:24px;">🔄 Plugin Auto-Update</h2>
                <table class="form-table">
                    <tr>
                        <th>Deploy Secret</th>
                        <td>
                            <input type="text" name="sewn_connect_deploy_secret" value="<?php echo esc_attr($deploy_secret); ?>" class="regular-text code" />
                            <p class="description">
                                Shared secret for remote deploy pings. The workbench generates this when adding your site.<br>
                                Paste it here to allow the workbench to trigger plugin updates on this site.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>Daily Auto-Update</th>
                        <td>
                            <label>
                                <input type="checkbox" name="sewn_connect_auto_update" value="1" <?php checked($auto_update, '1'); ?> />
                                Automatically check GitHub for updates daily (independent of workbench)
                            </label>
                            <p class="description">When enabled, this site checks GitHub for new versions every 24 hours and self-updates.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <h2>API Endpoints</h2>
            <table class="widefat striped" style="max-width: 800px;">
                <thead>
                    <tr><th>Endpoint</th><th>Method</th><th>Auth</th><th>Description</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>/sewn-connect/v1/content/{type}</code></td><td>GET</td><td>Public</td><td>Tier-gated content (posts/events/podcasts/directory/activity)</td></tr>
                    <tr><td><code>/sewn-connect/v1/network/stats</code></td><td>GET</td><td>Public</td><td>Network statistics</td></tr>
                    <tr><td><code>/sewn-connect/v1/network/members</code></td><td>GET</td><td>Public</td><td>Network members</td></tr>
                    <tr><td><code>/sewn-connect/v1/member/me</code></td><td>GET</td><td>Auth</td><td>Current member info</td></tr>
                    <tr><td><code>/sewn-connect/v1/member/scoreboard</code></td><td>GET</td><td>Auth</td><td>Member's scoreboard data</td></tr>
                    <tr><td><code>/sewn-connect/v1/auth/exchange</code></td><td>POST</td><td>Auth</td><td>Exchange WP auth → Ring Leader JWT</td></tr>
                    <tr><td><code>/sewn-connect/v1/health</code></td><td>GET</td><td>Public</td><td>Ring Leader connection status</td></tr>
                    <tr><td><code>/sewn-connect/v1/deploy/status</code></td><td>GET</td><td>Deploy Secret</td><td>Current plugin version + auto-update state</td></tr>
                    <tr><td><code>/sewn-connect/v1/deploy/pull</code></td><td>POST</td><td>Deploy Secret</td><td>Trigger self-update from GitHub</td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }
}
