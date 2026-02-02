<?php
/**
 * Plugin Name:     Startempire Wire Network Connect
 * Plugin URI:      https://startempirewire.com
 * Description:     Connects WordPress sites to the Startempire Wire Network via Ring Leader. Distributes tier-gated content, auth tokens, and network stats to member sites and the Chrome Extension.
 * Author:          Philoveracity Design
 * Author URI:      https://philoveracity.com
 * Text Domain:     startempire-wire-network-connect
 * Domain Path:     /languages
 * Version:         0.3.0
 * Requires PHP:    8.0
 *
 * @package         Startempire_Wire_Network_Connect
 */

if (!defined('WPINC')) die;

define('SEWN_CONNECT_VERSION', '0.3.0');
define('SEWN_CONNECT_PATH', plugin_dir_path(__FILE__));
define('SEWN_CONNECT_URL', plugin_dir_url(__FILE__));

// ─── Autoload Classes ───────────────────────────────────────────

require_once SEWN_CONNECT_PATH . 'inc/class-ring-leader-client.php';
require_once SEWN_CONNECT_PATH . 'inc/class-rest-api.php';
require_once SEWN_CONNECT_PATH . 'public/class-startempire-wire-network-connect-public.php';

if (is_admin()) {
    require_once SEWN_CONNECT_PATH . 'admin/class-admin.php';
}

// ─── Main Plugin Class ─────────────────────────────────────────

class Startempire_Wire_Network_Connect {

    private $client;
    private $rest_api;
    private $public;
    private $admin;

    public function __construct() {
        $this->client   = new SEWN_Connect_Ring_Leader_Client();
        $this->rest_api = new SEWN_Connect_REST_API($this->client);
        $this->public   = new Startempire_Wire_Network_Connect_Public();
        
        if (is_admin()) {
            $this->admin = new SEWN_Connect_Admin();
        }
    }

    public function activate() {
        // Set defaults
        add_option('sewn_connect_ring_leader_url', 'https://startempirewire.network/wp-json/sewn/v1');
        add_option('sewn_connect_cache_ttl', 300);
        add_option('sewn_connect_scoreboard_url', 'https://wins.wirebot.chat');
        add_option('sewn_connect_wirebot_url', 'https://helm.wirebot.chat');
        
        flush_rewrite_rules();
    }

    public function deactivate() {
        // Flush caches
        $this->client->flush_cache();
        flush_rewrite_rules();
    }

    public function run() {
        // REST API endpoints
        add_action('rest_api_init', [$this->rest_api, 'register_endpoints']);

        // Frontend assets
        add_action('wp_enqueue_scripts', [$this->public, 'enqueue_scripts']);
        add_action('wp_enqueue_scripts', [$this->public, 'enqueue_styles']);

        // BuddyBoss integration
        add_action('bp_after_member_header', [$this->public, 'add_connect_button']);

        // CORS for extension access
        add_action('rest_api_init', [$this, 'add_cors_headers']);

        // Shortcodes
        add_shortcode('sewn_network_stats', [$this, 'shortcode_network_stats']);
        add_shortcode('sewn_content_feed', [$this, 'shortcode_content_feed']);
    }

    /**
     * Allow Chrome Extension to access this site's REST API
     */
    public function add_cors_headers() {
        // Allow extension origin
        add_filter('rest_pre_serve_request', function($value) {
            $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
            
            // Allow Chrome extension origins and known domains
            $allowed = [
                'chrome-extension://',
                'https://startempirewire.com',
                'https://startempirewire.network',
                'https://wins.wirebot.chat',
            ];

            foreach ($allowed as $allowed_origin) {
                if (strpos($origin, $allowed_origin) === 0 || $origin === $allowed_origin) {
                    header('Access-Control-Allow-Origin: ' . $origin);
                    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
                    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-SEWN-Token');
                    break;
                }
            }

            return $value;
        });
    }

    // ─── Shortcodes ─────────────────────────────────────────────

    /**
     * [sewn_network_stats] — Display network statistics
     */
    public function shortcode_network_stats($atts) {
        $stats = $this->client->get_network_stats();
        if (isset($stats['error'])) {
            return '<div class="sewn-error">Unable to load network stats</div>';
        }

        $members = intval($stats['total_members'] ?? 0);
        $tiers = count($stats['membership_tiers'] ?? []);

        return sprintf(
            '<div class="sewn-network-stats">'
            . '<div class="sewn-stat"><span class="sewn-stat-value">%d</span><span class="sewn-stat-label">Members</span></div>'
            . '<div class="sewn-stat"><span class="sewn-stat-value">%d</span><span class="sewn-stat-label">Tiers</span></div>'
            . '</div>',
            $members, $tiers
        );
    }

    /**
     * [sewn_content_feed type="posts" count="5"] — Display content feed
     */
    public function shortcode_content_feed($atts) {
        $atts = shortcode_atts([
            'type'  => 'posts',
            'count' => 5,
        ], $atts);

        $method = "get_{$atts['type']}";
        if (!method_exists($this->client, $method)) {
            return '<div class="sewn-error">Invalid content type</div>';
        }

        $data = $this->client->$method(1, intval($atts['count']));
        if (isset($data['error'])) {
            return '<div class="sewn-error">Unable to load content</div>';
        }

        $items = $data['data'] ?? $data;
        if (empty($items) || !is_array($items)) {
            return '<div class="sewn-empty">No content available</div>';
        }

        $html = '<div class="sewn-content-feed">';
        foreach ($items as $item) {
            $title = esc_html($item['title']['rendered'] ?? $item['title'] ?? 'Untitled');
            $link  = esc_url($item['link'] ?? '#');
            $date  = isset($item['date']) ? date('M j', strtotime($item['date'])) : '';
            $html .= sprintf(
                '<div class="sewn-feed-item"><a href="%s" target="_blank">%s</a><span class="sewn-feed-date">%s</span></div>',
                $link, $title, $date
            );
        }
        $html .= '</div>';

        return $html;
    }
}

// ─── Initialize ─────────────────────────────────────────────────

$startempire_wire_network_connect = new Startempire_Wire_Network_Connect();
register_activation_hook(__FILE__, [$startempire_wire_network_connect, 'activate']);
register_deactivation_hook(__FILE__, [$startempire_wire_network_connect, 'deactivate']);
$startempire_wire_network_connect->run();
