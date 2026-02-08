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

    /**
     * Add connect / friendship button on BuddyBoss member profile headers.
     * Hooked to bp_after_member_header.
     *
     * Behaviour:
     *  - Not logged in → no button
     *  - Viewing own profile → no button
     *  - Already friends → "Connected ✓" badge
     *  - Friendship pending → "Request Sent" disabled state
     *  - Otherwise → "Connect" button that sends a BP friendship request
     *    AND records the connection in the SEWN network via REST
     */
    public function add_connect_button() {
        if (!is_user_logged_in()) return;
        if (!function_exists('bp_displayed_user_id')) return;

        $displayed_id = bp_displayed_user_id();
        $current_id   = get_current_user_id();

        // Don't show on own profile
        if (!$displayed_id || $displayed_id === $current_id) return;

        // Determine friendship state via BuddyBoss
        $state = 'connect'; // default
        if (function_exists('friends_check_friendship_status')) {
            $status = friends_check_friendship_status($current_id, $displayed_id);
            if ($status === 'is_friend') {
                $state = 'connected';
            } elseif ($status === 'pending') {
                $state = 'pending';
            } elseif ($status === 'awaiting_response') {
                $state = 'awaiting';
            }
        }

        $nonce = wp_create_nonce('sewn_connect_' . $displayed_id);
        $display_name = bp_core_get_user_displayname($displayed_id);

        echo '<div class="sewn-connect-btn-wrap">';

        switch ($state) {
            case 'connected':
                echo '<span class="sewn-connect-badge sewn-connected">';
                echo '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> ';
                echo 'Connected</span>';
                break;

            case 'pending':
                echo '<span class="sewn-connect-badge sewn-pending">Request Sent</span>';
                break;

            case 'awaiting':
                // They sent us a request — show Accept / Ignore
                $accept_url = wp_nonce_url(
                    bp_loggedin_user_domain() . bp_get_friends_slug() . '/requests/accept/' . $displayed_id,
                    'friends_accept_friendship'
                );
                echo '<a href="' . esc_url($accept_url) . '" class="sewn-connect-btn sewn-accept">Accept Request</a>';
                break;

            default:
                // Send friendship request via BP AJAX
                if (function_exists('bp_get_add_friend_button')) {
                    // Use BP's native button — it handles AJAX, nonces, and state
                    bp_add_friend_button($displayed_id);
                } else {
                    // Fallback: manual button with SEWN REST endpoint
                    echo '<a href="#" class="sewn-connect-btn sewn-connect-trigger" '
                        . 'data-user-id="' . esc_attr($displayed_id) . '" '
                        . 'data-nonce="' . esc_attr($nonce) . '" '
                        . 'data-name="' . esc_attr($display_name) . '">'
                        . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
                        . '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/>'
                        . '<line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg> '
                        . 'Connect</a>';
                }
                break;
        }

        echo '</div>';

        // Inline styles for the connect button (minimal, design-system aligned)
        static $styles_printed = false;
        if (!$styles_printed) {
            $styles_printed = true;
            echo '<style>
            .sewn-connect-btn-wrap { margin-top: 0.75rem; }
            .sewn-connect-badge {
                display: inline-flex; align-items: center; gap: 0.35rem;
                padding: 0.4rem 0.85rem; border-radius: 20px;
                font-size: 0.78rem; font-weight: 600; font-family: inherit;
            }
            .sewn-connected {
                background: rgba(50,162,193,0.1); color: var(--sew-primary, #32a2c1);
            }
            .sewn-pending {
                background: rgba(10,36,52,0.06); color: #888;
            }
            .sewn-connect-btn, .sewn-connect-trigger {
                display: inline-flex; align-items: center; gap: 0.4rem;
                padding: 0.45rem 1rem; border-radius: 20px;
                background: var(--sew-primary, #32a2c1); color: #fff;
                font-size: 0.8rem; font-weight: 600; text-decoration: none;
                border: none; cursor: pointer; font-family: inherit;
                transition: opacity 0.2s;
            }
            .sewn-connect-btn:hover, .sewn-connect-trigger:hover { opacity: 0.85; color: #fff; }
            .sewn-accept {
                background: var(--sew-coral, #F06B6B);
            }
            /* Style BP native friend button to match */
            .sewn-connect-btn-wrap .generic-button a,
            .sewn-connect-btn-wrap .generic-button button {
                display: inline-flex !important; align-items: center !important; gap: 0.4rem !important;
                padding: 0.45rem 1rem !important; border-radius: 20px !important;
                background: var(--sew-primary, #32a2c1) !important; color: #fff !important;
                font-size: 0.8rem !important; font-weight: 600 !important;
                text-decoration: none !important; border: none !important;
                cursor: pointer !important; transition: opacity 0.2s !important;
            }
            .sewn-connect-btn-wrap .generic-button a:hover { opacity: 0.85 !important; }
            </style>';
        }
    }
}
