<?php
/**
 * SEWN Connect REST API
 * 
 * Exposes Ring Leader content on this WordPress site's REST API.
 * The Chrome Extension and frontend themes consume these endpoints.
 * 
 * Namespace: sewn-connect/v1
 * 
 * Endpoints:
 *   GET  /content/{type}     - Tier-gated content (posts, events, podcasts, directory, activity)
 *   GET  /network/stats      - Network statistics
 *   GET  /network/members    - Network members
 *   GET  /member/me          - Current member info
 *   GET  /member/scoreboard  - Current member's scoreboard
 *   POST /auth/exchange      - Exchange WP auth for Ring Leader JWT
 *   GET  /health             - Ring Leader connection status
 */

if (!defined('WPINC')) die;

class SEWN_Connect_REST_API {

    private $client;

    public function __construct($client = null) {
        $this->client = $client ?? new SEWN_Connect_Ring_Leader_Client();
    }

    public function register_endpoints() {
        $ns = 'sewn-connect/v1';

        // Content endpoints (public, tier-filtered by Ring Leader)
        register_rest_route($ns, '/content/(?P<type>[a-z]+)', [
            'methods'  => 'GET',
            'callback' => [$this, 'handle_content'],
            'permission_callback' => '__return_true',
            'args' => [
                'type'     => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'page'     => ['default' => 1, 'sanitize_callback' => 'absint'],
                'per_page' => ['default' => 10, 'sanitize_callback' => 'absint'],
            ],
        ]);

        // Network stats (public)
        register_rest_route($ns, '/network/stats', [
            'methods'  => 'GET',
            'callback' => [$this, 'handle_network_stats'],
            'permission_callback' => '__return_true',
        ]);

        // Network members (public, tier-filtered)
        register_rest_route($ns, '/network/members', [
            'methods'  => 'GET',
            'callback' => [$this, 'handle_network_members'],
            'permission_callback' => '__return_true',
            'args' => [
                'page' => ['default' => 1, 'sanitize_callback' => 'absint'],
            ],
        ]);

        // Member info (authenticated)
        register_rest_route($ns, '/member/me', [
            'methods'  => 'GET',
            'callback' => [$this, 'handle_member_me'],
            'permission_callback' => [$this, 'check_logged_in'],
        ]);

        // Member scoreboard (authenticated)
        register_rest_route($ns, '/member/scoreboard', [
            'methods'  => 'GET',
            'callback' => [$this, 'handle_member_scoreboard'],
            'permission_callback' => [$this, 'check_logged_in'],
        ]);

        // Auth exchange (WP token → Ring Leader JWT)
        register_rest_route($ns, '/auth/exchange', [
            'methods'  => 'POST',
            'callback' => [$this, 'handle_auth_exchange'],
            'permission_callback' => [$this, 'check_logged_in'],
        ]);

        // SSO redirect: browser-based login → Ring Leader JWT → redirect to surface
        register_rest_route($ns, '/auth/sso', [
            'methods'  => 'GET',
            'callback' => [$this, 'handle_sso'],
            'permission_callback' => '__return_true',
        ]);

        // Health check (public)
        register_rest_route($ns, '/health', [
            'methods'  => 'GET',
            'callback' => [$this, 'handle_health'],
            'permission_callback' => '__return_true',
        ]);

        // Legacy endpoints (backward compat with old REST API class)
        register_rest_route($ns, '/user/(?P<id>\d+)', [
            'methods'  => 'GET',
            'callback' => [$this, 'handle_user'],
            'permission_callback' => [$this, 'check_logged_in'],
            'args' => ['id' => ['sanitize_callback' => 'absint']],
        ]);

        register_rest_route($ns, '/user/(?P<id>\d+)/friends', [
            'methods'  => 'GET',
            'callback' => [$this, 'handle_user_friends'],
            'permission_callback' => [$this, 'check_logged_in'],
            'args' => ['id' => ['sanitize_callback' => 'absint']],
        ]);
    }

    // ─── Content ────────────────────────────────────────────────

    public function handle_content($request) {
        $type     = $request['type'];
        $page     = $request->get_param('page') ?: 1;
        $per_page = $request->get_param('per_page') ?: 10;

        $valid_types = ['posts', 'events', 'podcasts', 'directory', 'activity'];
        if (!in_array($type, $valid_types)) {
            return new WP_REST_Response(['error' => 'Invalid content type', 'valid' => $valid_types], 400);
        }

        $method = "get_{$type}";
        $data = $this->client->$method($page, $per_page);

        if (isset($data['error'])) {
            return new WP_REST_Response($data, 502);
        }

        return new WP_REST_Response($data, 200);
    }

    // ─── Network ────────────────────────────────────────────────

    public function handle_network_stats($request) {
        $data = $this->client->get_network_stats();
        if (isset($data['error'])) {
            return new WP_REST_Response($data, 502);
        }
        return new WP_REST_Response($data, 200);
    }

    public function handle_network_members($request) {
        $page = $request->get_param('page') ?: 1;
        $data = $this->client->get_network_members($page);
        if (isset($data['error'])) {
            return new WP_REST_Response($data, 502);
        }
        return new WP_REST_Response($data, 200);
    }

    // ─── Member ─────────────────────────────────────────────────

    public function handle_member_me($request) {
        $data = $this->client->get_member_info();
        if (isset($data['error'])) {
            return new WP_REST_Response($data, 502);
        }
        return new WP_REST_Response($data, 200);
    }

    public function handle_member_scoreboard($request) {
        $data = $this->client->get_member_scoreboard();
        if (isset($data['error'])) {
            return new WP_REST_Response($data, 502);
        }
        return new WP_REST_Response($data, 200);
    }

    // ─── Auth ───────────────────────────────────────────────────

    public function handle_auth_exchange($request) {
        $user = wp_get_current_user();
        if (!$user || !$user->ID) {
            return new WP_REST_Response(['error' => 'Not authenticated'], 401);
        }

        // Generate a WP application password or use existing token
        $token = wp_generate_password(64, false, false);
        update_user_meta($user->ID, 'sewn_connect_token', $token);
        update_user_meta($user->ID, 'sewn_connect_token_expiry', time() + 86400);

        // Exchange with Ring Leader
        $rl_data = $this->client->get_token($token);
        
        if (isset($rl_data['error'])) {
            return new WP_REST_Response([
                'error' => 'Ring Leader exchange failed',
                'detail' => $rl_data['error'],
                'wp_token' => $token, // Fallback: give WP token for direct use
            ], 502);
        }

        return new WP_REST_Response([
            'jwt'  => $rl_data['token'],
            'tier' => $rl_data['tier'] ?? 'free',
            'user' => [
                'id'           => $user->ID,
                'display_name' => $user->display_name,
                'email'        => $user->user_email,
            ],
        ], 200);
    }

    // ─── SSO ───────────────────────────────────────────────────
    // Browser flow: user is logged into .com → redirect to any surface with JWT
    // GET /sewn-connect/v1/auth/sso?redirect_uri=https://wins.wirebot.chat

    public function handle_sso($request) {
        $redirect_uri = $request->get_param('redirect_uri') ?? '';

        // Whitelist allowed redirect targets
        $allowed = [
            'https://wins.wirebot.chat',
            'https://helm.wirebot.chat',
            'https://startempirewire.network',
        ];
        $valid = false;
        foreach ($allowed as $prefix) {
            if (str_starts_with($redirect_uri, $prefix)) { $valid = true; break; }
        }
        if (!$valid) {
            return new WP_REST_Response(['error' => 'Invalid redirect_uri'], 400);
        }

        // Check WP cookie auth
        $user = wp_get_current_user();
        if (!$user || !$user->ID) {
            // Not logged in → redirect to wp-login.php, then back here
            $login_url = wp_login_url(rest_url('sewn-connect/v1/auth/sso') . '?redirect_uri=' . urlencode($redirect_uri));
            header('Location: ' . $login_url, true, 302);
            exit;
        }

        // User is logged in. Build user data and request JWT from Ring Leader.
        $user_data = [
            'user_id'      => $user->ID,
            'username'     => $user->user_login,
            'email'        => $user->user_email,
            'display_name' => $user->display_name,
            'roles'        => $user->roles,
            'is_admin'     => in_array('administrator', $user->roles, true),
            'url'          => $user->user_url,
            'registered'   => $user->user_registered,
            'description'  => get_user_meta($user->ID, 'description', true),
            'avatar_url'   => get_avatar_url($user->ID, ['size' => 96]),
        ];

        // Call Ring Leader /auth/issue with internal key
        $rl_url = rtrim(get_option('sewn_connect_ring_leader_url', 'https://startempirewire.network/wp-json/sewn/v1'), '/');
        $internal_key = get_option('sewn_connect_internal_key', '');

        $response = wp_remote_post($rl_url . '/auth/issue', [
            'headers' => [
                'Content-Type'       => 'application/json',
                'X-SEWN-Internal-Key' => $internal_key,
            ],
            'body'    => wp_json_encode($user_data),
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            return new WP_REST_Response(['error' => 'Ring Leader unreachable: ' . $response->get_error_message()], 502);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200 || empty($body['token'])) {
            return new WP_REST_Response([
                'error' => 'JWT issuance failed',
                'detail' => $body['error'] ?? 'Unknown error',
            ], 502);
        }

        // Redirect to surface with JWT in URL fragment (not query — fragments don't hit server logs)
        $jwt = $body['token'];
        $user_json = urlencode(wp_json_encode($body['user']));
        $separator = str_contains($redirect_uri, '#') ? '&' : '#';
        $final_url = $redirect_uri . '/auth/callback' . $separator . 'token=' . $jwt . '&user=' . $user_json;

        header('Location: ' . $final_url, true, 302);
        exit;
    }

    // ─── Health ─────────────────────────────────────────────────

    public function handle_health($request) {
        $healthy = $this->client->health_check();
        return new WP_REST_Response([
            'status'           => $healthy ? 'connected' : 'disconnected',
            'ring_leader_url'  => get_option('sewn_connect_ring_leader_url', ''),
            'cache_ttl'        => (int) get_option('sewn_connect_cache_ttl', 300),
        ], $healthy ? 200 : 503);
    }

    // ─── Legacy (backward compat) ───────────────────────────────

    public function handle_user($request) {
        $user = get_userdata($request['id']);
        if (!$user) {
            return new WP_REST_Response(['error' => 'User not found'], 404);
        }
        return new WP_REST_Response([
            'id'           => $user->ID,
            'display_name' => $user->display_name,
            'email'        => $user->user_email,
            'registered'   => $user->user_registered,
        ], 200);
    }

    public function handle_user_friends($request) {
        $friends = function_exists('friends_get_friend_user_ids') 
            ? friends_get_friend_user_ids($request['id']) 
            : [];
        return new WP_REST_Response(['friends' => $friends], 200);
    }

    // ─── Permissions ────────────────────────────────────────────

    public function check_logged_in() {
        return is_user_logged_in();
    }
}
