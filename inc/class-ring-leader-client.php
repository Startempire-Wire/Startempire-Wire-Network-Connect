<?php
/**
 * Ring Leader API Client
 * 
 * Connects this WordPress site to the Ring Leader plugin on the network hub
 * (startempirewire.network) to fetch tier-gated content, validate auth tokens,
 * and report network statistics.
 * 
 * Per bigpicture.mdx:
 * - Connect reads data FROM Ring Leader to display on member sites
 * - Ring Leader is the single source of truth for content distribution
 * - Auth tokens issued by Ring Leader grant tier-appropriate content access
 */

if (!defined('WPINC')) die;

class SEWN_Connect_Ring_Leader_Client {

    private $ring_leader_url;
    private $api_key;
    private $cache_ttl;

    public function __construct() {
        $this->ring_leader_url = rtrim(get_option('sewn_connect_ring_leader_url', 'https://startempirewire.network/wp-json/sewn/v1'), '/');
        $this->api_key = get_option('sewn_connect_api_key', '');
        $this->cache_ttl = (int) get_option('sewn_connect_cache_ttl', 300); // 5 min default
    }

    /**
     * Make authenticated request to Ring Leader API
     */
    private function request($endpoint, $method = 'GET', $body = null) {
        $url = $this->ring_leader_url . '/' . ltrim($endpoint, '/');
        
        $args = [
            'method'  => $method,
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ];

        if ($this->api_key) {
            $args['headers']['Authorization'] = 'Bearer ' . $this->api_key;
        }

        if ($body && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            return ['error' => $data['error'] ?? "HTTP $code", 'code' => $code];
        }

        return $data;
    }

    /**
     * Get cached or fresh data from Ring Leader
     */
    private function cached_request($cache_key, $endpoint, $ttl = null) {
        $ttl = $ttl ?? $this->cache_ttl;
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }

        $data = $this->request($endpoint);
        
        if (!isset($data['error'])) {
            set_transient($cache_key, $data, $ttl);
        }

        return $data;
    }

    // ─── Content Methods ────────────────────────────────────────

    /**
     * Get tier-gated posts from the parent site via Ring Leader
     */
    public function get_posts($page = 1, $per_page = 10) {
        return $this->cached_request(
            "sewn_rl_posts_{$page}_{$per_page}",
            "content/posts?page={$page}&per_page={$per_page}"
        );
    }

    /**
     * Get events
     */
    public function get_events($page = 1, $per_page = 10) {
        return $this->cached_request(
            "sewn_rl_events_{$page}",
            "content/events?page={$page}&per_page={$per_page}"
        );
    }

    /**
     * Get podcasts
     */
    public function get_podcasts($page = 1, $per_page = 10) {
        return $this->cached_request(
            "sewn_rl_podcasts_{$page}",
            "content/podcasts?page={$page}&per_page={$per_page}"
        );
    }

    /**
     * Get directory listings (GeoDirectory)
     */
    public function get_directory($page = 1, $per_page = 10) {
        return $this->cached_request(
            "sewn_rl_directory_{$page}",
            "content/directory?page={$page}&per_page={$per_page}"
        );
    }

    /**
     * Get BuddyBoss activity feed
     */
    public function get_activity($page = 1, $per_page = 20) {
        return $this->cached_request(
            "sewn_rl_activity_{$page}",
            "content/activity?page={$page}&per_page={$per_page}"
        );
    }

    // ─── Network Methods ────────────────────────────────────────

    /**
     * Get network-wide statistics
     */
    public function get_network_stats() {
        return $this->cached_request('sewn_rl_network_stats', 'network/stats', 600);
    }

    /**
     * Get network members (tier-filtered)
     */
    public function get_network_members($page = 1) {
        return $this->cached_request(
            "sewn_rl_members_{$page}",
            "network/members?page={$page}"
        );
    }

    // ─── Auth Methods ───────────────────────────────────────────

    /**
     * Validate a Ring Leader JWT token
     */
    public function validate_token($token) {
        return $this->request('auth/validate', 'POST', ['token' => $token]);
    }

    /**
     * Exchange a WordPress auth token for a Ring Leader JWT
     */
    public function get_token($wp_token) {
        return $this->request('auth/token', 'POST', ['token' => $wp_token]);
    }

    // ─── Member Methods ─────────────────────────────────────────

    /**
     * Get current member info (requires JWT in api_key)
     */
    public function get_member_info() {
        return $this->request('member/me');
    }

    /**
     * Get member's scoreboard info
     */
    public function get_member_scoreboard() {
        return $this->request('member/scoreboard');
    }

    // ─── Cache Management ───────────────────────────────────────

    /**
     * Flush all Ring Leader caches
     */
    public function flush_cache() {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_sewn_rl_%' OR option_name LIKE '%_transient_timeout_sewn_rl_%'"
        );
    }

    /**
     * Check if Ring Leader is reachable
     */
    public function health_check() {
        $stats = $this->request('network/stats');
        return !isset($stats['error']);
    }
}
