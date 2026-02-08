<?php
/**
 * Connect Plugin — Public/Frontend
 * 
 * Enqueues the SEWN overlay widget on member sites.
 * The overlay provides Wirebot chat, scoreboard summary, and network stats.
 */
class Startempire_Wire_Network_Connect_Public {

    public function __construct() {
    }

    /**
     * Enqueue overlay JS + pass config to frontend
     */
    public function enqueue_scripts() {
        // Only load if overlay is enabled (default: enabled)
        if (!get_option('sewn_connect_overlay_enabled', '1')) {
            return;
        }

        // Don't load in admin
        if (is_admin()) return;

        $script_path = plugin_dir_path(__FILE__) . '../assets/js/sewn-overlay.js';
        $version = file_exists($script_path) ? filemtime($script_path) : '0.2.0';

        wp_enqueue_script(
            'sewn-overlay',
            plugin_dir_url(__FILE__) . '../assets/js/sewn-overlay.js',
            [],
            $version,
            true
        );

        // Pass config to JS
        $config = [
            'ringLeaderUrl' => rtrim(get_option('sewn_connect_ring_leader_url', 'https://startempirewire.network/wp-json/sewn/v1'), '/'),
            'scoreboardUrl' => rtrim(get_option('sewn_connect_scoreboard_url', 'https://wins.wirebot.chat'), '/'),
            'siteName'      => get_bloginfo('name') ?: 'Startempire Wire',
            'logoUrl'       => self::get_site_logo_url(),
            'nonce'         => wp_create_nonce('wp_rest'),
            'ajaxUrl'       => rest_url('sewn-connect/v1/auth/exchange'),
            'userId'        => get_current_user_id(),
        ];

        wp_localize_script('sewn-overlay', 'sewnConnect', $config);
    }

    /**
     * Get the site logo URL for the overlay panel header.
     * Tries: custom logo → site icon → fallback to SEW white logo.
     */
    private static function get_site_logo_url() {
        // Try WP custom logo first
        $custom_logo_id = get_theme_mod('custom_logo');
        if ($custom_logo_id) {
            $url = wp_get_attachment_image_url($custom_logo_id, 'medium');
            if ($url) return $url;
        }
        // Fallback: trimmed white header logo
        return home_url('/wp-content/uploads/2024/02/sew-logo-white-header.png');
    }

    /**
     * Enqueue overlay styles (minimal — most styles are inline in JS)
     */
    public function enqueue_styles() {
        // Styles are injected by the JS overlay for zero-FOUC
    }
}
