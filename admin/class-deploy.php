<?php
/**
 * SEWN Connect Deploy Manager
 *
 * Workbench-only admin panel: push to GitHub canonical, manage per-site auto-update.
 *
 * TWO deployment models:
 *   LOCAL  — site on same server, CLI git pull (legacy, VPS sites)
 *   REMOTE — site on any server, REST ping → site self-updates from GitHub
 *
 * Remote sites run a self-update check via sewn-connect/v1/deploy/pull endpoint
 * (registered by this plugin on every install, gated by shared deploy secret).
 *
 * @package Startempire_Wire_Network_Connect
 */

if ( ! defined( 'WPINC' ) ) die;

class SEWN_Connect_Deploy {

    /** Option key: deploy site registry */
    const SITES_KEY = 'sewn_connect_deploy_sites';

    /** Option key: workbench flag (only true on the dev/push site) */
    const WORKBENCH_KEY = 'sewn_connect_is_workbench';

    /** Option key: auto-update enabled (set per-site, checked by self-update cron) */
    const AUTOUPDATE_KEY = 'sewn_connect_auto_update';

    /** Option key: deploy secret (shared between workbench ↔ remote site) */
    const SECRET_KEY = 'sewn_connect_deploy_secret';

    /** Option key: last known plugin version/commit on this site */
    const VERSION_KEY = 'sewn_connect_deploy_version';

    /** GitHub repo for canonical source */
    const GITHUB_REPO = 'Startempire-Wire/Startempire-Wire-Network-Connect';
    const GITHUB_URL  = 'https://github.com/Startempire-Wire/Startempire-Wire-Network-Connect';

    /** CLI script for local deploys */
    const DEPLOY_SCRIPT = '/usr/local/bin/sewn-connect-deploy';

    private bool $is_workbench;

    public function __construct() {
        $this->is_workbench = (bool) get_option( self::WORKBENCH_KEY, false );

        // ─── Workbench-only: admin panel + push/deploy handlers ───
        if ( $this->is_workbench ) {
            add_action( 'admin_menu', [ $this, 'add_submenu' ] );
            add_action( 'admin_init', [ $this, 'register_settings' ] );
            add_action( 'admin_post_sewn_push_canonical', [ $this, 'handle_push' ] );
            add_action( 'admin_post_sewn_deploy_sites', [ $this, 'handle_deploy' ] );
            add_action( 'admin_post_sewn_toggle_site', [ $this, 'handle_toggle_site' ] );
            add_action( 'admin_post_sewn_add_deploy_site', [ $this, 'handle_add_site' ] );
            add_action( 'admin_post_sewn_remove_deploy_site', [ $this, 'handle_remove_site' ] );
        }

        // ─── All sites: register REST endpoint for remote deploy ping ───
        add_action( 'rest_api_init', [ $this, 'register_deploy_endpoint' ] );

        // ─── All sites: daily self-update cron (if auto-update enabled) ───
        add_action( 'sewn_connect_self_update', [ $this, 'self_update_from_github' ] );
        if ( ! wp_next_scheduled( 'sewn_connect_self_update' ) && get_option( self::AUTOUPDATE_KEY, false ) ) {
            wp_schedule_event( time(), 'daily', 'sewn_connect_self_update' );
        }
    }

    // ═══════════════════════════════════════════════════════════
    // REST ENDPOINT — Any site can receive a deploy ping
    // ═══════════════════════════════════════════════════════════

    public function register_deploy_endpoint(): void {
        // POST /sewn-connect/v1/deploy/pull — trigger self-update from GitHub
        register_rest_route( 'sewn-connect/v1', '/deploy/pull', [
            'methods'  => 'POST',
            'callback' => [ $this, 'rest_deploy_pull' ],
            'permission_callback' => [ $this, 'verify_deploy_secret' ],
        ]);

        // GET /sewn-connect/v1/deploy/status — report current version + auto-update state
        register_rest_route( 'sewn-connect/v1', '/deploy/status', [
            'methods'  => 'GET',
            'callback' => [ $this, 'rest_deploy_status' ],
            'permission_callback' => [ $this, 'verify_deploy_secret' ],
        ]);
    }

    public function verify_deploy_secret( \WP_REST_Request $request ): bool {
        $secret = get_option( self::SECRET_KEY, '' );
        if ( empty( $secret ) ) return false;

        $provided = $request->get_header( 'X-SEWN-Deploy-Secret' )
                 ?? $request->get_param( 'deploy_secret' )
                 ?? '';

        return hash_equals( $secret, $provided );
    }

    /**
     * POST /sewn-connect/v1/deploy/pull
     * Remote trigger: download latest from GitHub and replace plugin files.
     */
    public function rest_deploy_pull( \WP_REST_Request $request ): \WP_REST_Response {
        $result = $this->self_update_from_github();
        return new \WP_REST_Response( $result, $result['success'] ? 200 : 500 );
    }

    /**
     * GET /sewn-connect/v1/deploy/status
     * Report current plugin state for the workbench dashboard.
     */
    public function rest_deploy_status( \WP_REST_Request $request ): \WP_REST_Response {
        $plugin_dir = SEWN_CONNECT_PATH;
        $has_git    = is_dir( $plugin_dir . '/.git' );
        $commit     = $has_git
            ? trim( shell_exec( "cd " . escapeshellarg( $plugin_dir ) . " && git rev-parse --short HEAD 2>/dev/null" ) ?: '' )
            : '';

        return new \WP_REST_Response([
            'site_url'    => home_url(),
            'version'     => SEWN_CONNECT_VERSION ?? 'unknown',
            'commit'      => $commit ?: get_option( self::VERSION_KEY, 'unknown' ),
            'has_git'     => $has_git,
            'auto_update' => (bool) get_option( self::AUTOUPDATE_KEY, false ),
            'php_version' => PHP_VERSION,
            'wp_version'  => get_bloginfo( 'version' ),
            'checked_at'  => current_time( 'c' ),
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // SELF-UPDATE — Works on any server (no cPanel/SSH needed)
    // ═══════════════════════════════════════════════════════════

    /**
     * Download latest plugin zip from GitHub and replace files.
     * Works via git pull (if .git exists) or GitHub zip download (if not).
     */
    public function self_update_from_github(): array {
        $plugin_dir = SEWN_CONNECT_PATH;

        // Method 1: git pull (if repo exists)
        if ( is_dir( $plugin_dir . '/.git' ) ) {
            $before = trim( shell_exec( "cd " . escapeshellarg( $plugin_dir ) . " && git rev-parse --short HEAD 2>/dev/null" ) ?: '' );
            $output = shell_exec( "cd " . escapeshellarg( $plugin_dir ) . " && git fetch origin main 2>&1 && git reset --hard origin/main 2>&1" );
            $after  = trim( shell_exec( "cd " . escapeshellarg( $plugin_dir ) . " && git rev-parse --short HEAD 2>/dev/null" ) ?: '' );

            $updated = ( $before !== $after );
            update_option( self::VERSION_KEY, $after );

            return [
                'success' => true,
                'method'  => 'git',
                'before'  => $before,
                'after'   => $after,
                'updated' => $updated,
                'output'  => $output,
            ];
        }

        // Method 2: GitHub zip download (no git on server)
        $zip_url = 'https://github.com/' . self::GITHUB_REPO . '/archive/refs/heads/main.zip';
        $tmp_zip = wp_tempnam( 'sewn-connect-update.zip' );

        $download = download_url( $zip_url, 60 );
        if ( is_wp_error( $download ) ) {
            return [ 'success' => false, 'method' => 'zip', 'error' => $download->get_error_message() ];
        }

        // Extract
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        global $wp_filesystem;

        $unzip_dir = $wp_filesystem->wp_content_dir() . 'upgrade/sewn-connect-update/';
        $result    = unzip_file( $download, $unzip_dir );
        @unlink( $download );

        if ( is_wp_error( $result ) ) {
            return [ 'success' => false, 'method' => 'zip', 'error' => $result->get_error_message() ];
        }

        // The zip extracts to a subdirectory named after the repo
        $extracted_dir = $unzip_dir . 'Startempire-Wire-Network-Connect-main/';
        if ( ! is_dir( $extracted_dir ) ) {
            // Try finding it
            $dirs = glob( $unzip_dir . '*', GLOB_ONLYDIR );
            $extracted_dir = ! empty( $dirs ) ? $dirs[0] . '/' : '';
        }

        if ( empty( $extracted_dir ) || ! is_dir( $extracted_dir ) ) {
            $wp_filesystem->delete( $unzip_dir, true );
            return [ 'success' => false, 'method' => 'zip', 'error' => 'Extracted directory not found' ];
        }

        // Copy files over (preserving any local-only files like .canonical)
        $copied = copy_dir( $extracted_dir, $plugin_dir );
        $wp_filesystem->delete( $unzip_dir, true );

        if ( is_wp_error( $copied ) ) {
            return [ 'success' => false, 'method' => 'zip', 'error' => $copied->get_error_message() ];
        }

        // Read version from the freshly copied plugin file
        $plugin_data = get_plugin_data( $plugin_dir . 'startempire-wire-network-connect.php', false, false );
        $new_version = $plugin_data['Version'] ?? 'unknown';
        update_option( self::VERSION_KEY, $new_version );

        return [
            'success' => true,
            'method'  => 'zip',
            'version' => $new_version,
            'updated' => true,
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // WORKBENCH — Admin page + handlers (only on dev site)
    // ═══════════════════════════════════════════════════════════

    public function add_submenu(): void {
        add_submenu_page(
            'options-general.php',
            'SEWN Deploy',
            'SEWN Deploy',
            'manage_options',
            'sewn-deploy',
            [ $this, 'render_page' ]
        );
    }

    public function register_settings(): void {
        register_setting( 'sewn_deploy', self::SITES_KEY );
        register_setting( 'sewn_deploy', self::WORKBENCH_KEY );
    }

    // ─── Site Registry ──────────────────────────────────────────

    /**
     * Get all deploy sites.
     *
     * Format: [
     *   'site-slug' => [
     *     'url'          => 'https://example.com',
     *     'label'        => 'My Site',
     *     'type'         => 'local' | 'remote',
     *     'enabled'      => bool,
     *     'deploy_secret'=> string (for remote sites),
     *     'local_path'   => string (for local sites, optional override),
     *     'last_deploy'  => datetime string,
     *     'last_commit'  => short hash,
     *     'last_status'  => array from /deploy/status,
     *     'added'        => datetime string,
     *   ]
     * ]
     */
    public static function get_sites(): array {
        return get_option( self::SITES_KEY, [] );
    }

    public static function update_sites( array $sites ): void {
        update_option( self::SITES_KEY, $sites );
    }

    // ─── Git Operations (workbench) ─────────────────────────────

    private function git_status(): array {
        $dir = escapeshellarg( SEWN_CONNECT_PATH );
        $r   = [];

        $r['branch']      = trim( shell_exec( "cd {$dir} && git branch --show-current 2>/dev/null" ) ?: 'unknown' );
        $r['commit']      = trim( shell_exec( "cd {$dir} && git rev-parse --short HEAD 2>/dev/null" ) ?: 'unknown' );
        $r['commit_full'] = trim( shell_exec( "cd {$dir} && git rev-parse HEAD 2>/dev/null" ) ?: '' );
        $r['message']     = trim( shell_exec( "cd {$dir} && git log -1 --pretty=%s 2>/dev/null" ) ?: '' );

        $dirty = trim( shell_exec( "cd {$dir} && git status --porcelain 2>/dev/null" ) ?: '' );
        $r['dirty']       = ! empty( $dirty );
        $r['dirty_files'] = $dirty ? explode( "\n", $dirty ) : [];

        shell_exec( "cd {$dir} && git fetch origin --quiet 2>/dev/null" );
        $r['behind'] = intval( trim( shell_exec( "cd {$dir} && git rev-list HEAD..origin/main --count 2>/dev/null" ) ?: '0' ) );
        $r['ahead']  = intval( trim( shell_exec( "cd {$dir} && git rev-list origin/main..HEAD --count 2>/dev/null" ) ?: '0' ) );
        $r['tag']    = trim( shell_exec( "cd {$dir} && git describe --tags --abbrev=0 2>/dev/null" ) ?: 'none' );

        $log = shell_exec( "cd {$dir} && git log --oneline -10 2>/dev/null" ) ?: '';
        $r['log'] = array_filter( explode( "\n", trim( $log ) ) );

        return $r;
    }

    // ─── Deploy: Local Site ─────────────────────────────────────

    private function deploy_local( string $slug, array &$site ): string {
        // Determine plugin path: explicit override or standard WP location
        if ( ! empty( $site['local_path'] ) ) {
            $path = $site['local_path'];
        } else {
            // Try CLI deploy script for local sites (handles git clone/pull, ownership)
            if ( file_exists( self::DEPLOY_SCRIPT ) ) {
                $out = shell_exec( self::DEPLOY_SCRIPT . ' ' . escapeshellarg( $slug ) . ' 2>&1' );
                $site['last_deploy'] = current_time( 'mysql' );
                $site['last_commit'] = trim( shell_exec( "cd " . escapeshellarg( SEWN_CONNECT_PATH ) . " && git rev-parse --short HEAD 2>/dev/null" ) ?: '' );
                return $out ?: 'Deploy script returned no output.';
            }
            return "❌ No local_path configured and CLI script not found.";
        }

        // Direct git pull if path specified
        if ( is_dir( "{$path}/.git" ) ) {
            $out = shell_exec( "cd " . escapeshellarg( $path ) . " && git fetch origin main 2>&1 && git reset --hard origin/main 2>&1" );
            $commit = trim( shell_exec( "cd " . escapeshellarg( $path ) . " && git rev-parse --short HEAD 2>/dev/null" ) ?: '' );
            $site['last_deploy'] = current_time( 'mysql' );
            $site['last_commit'] = $commit;
            return $out ?: "Updated to {$commit}";
        }

        return "❌ Path {$path} has no .git — cannot pull.";
    }

    // ─── Deploy: Remote Site ────────────────────────────────────

    private function deploy_remote( string $slug, array &$site ): string {
        $url    = rtrim( $site['url'] ?? '', '/' );
        $secret = $site['deploy_secret'] ?? '';

        if ( empty( $url ) || empty( $secret ) ) {
            return "❌ Missing URL or deploy secret for {$slug}.";
        }

        // POST to /sewn-connect/v1/deploy/pull
        $response = wp_remote_post( "{$url}/wp-json/sewn-connect/v1/deploy/pull", [
            'timeout' => 60,
            'headers' => [
                'Content-Type'         => 'application/json',
                'X-SEWN-Deploy-Secret' => $secret,
            ],
            'body' => wp_json_encode([ 'source' => 'workbench' ]),
        ]);

        if ( is_wp_error( $response ) ) {
            return "❌ {$slug}: " . $response->get_error_message();
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        $site['last_deploy'] = current_time( 'mysql' );

        if ( $code === 200 && ! empty( $body['success'] ) ) {
            $commit_or_ver = $body['after'] ?? $body['version'] ?? '?';
            $site['last_commit'] = $commit_or_ver;
            $method  = $body['method'] ?? '?';
            $updated = ! empty( $body['updated'] ) ? 'updated' : 'already current';
            return "✅ {$slug}: {$method} → {$commit_or_ver} ({$updated})";
        }

        $error = $body['error'] ?? "HTTP {$code}";
        return "❌ {$slug}: {$error}";
    }

    // ─── Fetch Remote Status ────────────────────────────────────

    private function fetch_remote_status( array $site ): ?array {
        $url    = rtrim( $site['url'] ?? '', '/' );
        $secret = $site['deploy_secret'] ?? '';

        if ( empty( $url ) || empty( $secret ) ) return null;

        $response = wp_remote_get( "{$url}/wp-json/sewn-connect/v1/deploy/status", [
            'timeout' => 10,
            'headers' => [ 'X-SEWN-Deploy-Secret' => $secret ],
        ]);

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return null;
        }

        return json_decode( wp_remote_retrieve_body( $response ), true );
    }

    // ─── Handlers ───────────────────────────────────────────────

    public function handle_push(): void {
        check_admin_referer( 'sewn_push_canonical' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $dir    = escapeshellarg( SEWN_CONNECT_PATH );
        $output = [];

        // 1. Auto-commit if dirty
        $dirty = trim( shell_exec( "cd {$dir} && git status --porcelain 2>&1" ) ?: '' );
        if ( ! empty( $dirty ) ) {
            $msg = sanitize_text_field( $_POST['commit_message'] ?? 'chore: workbench update' );
            shell_exec( "cd {$dir} && git add -A 2>&1" );
            $output[] = shell_exec( "cd {$dir} && git commit -m " . escapeshellarg( $msg ) . " 2>&1" );
        }

        // 2. Push
        $push_out = shell_exec( "cd {$dir} && git push origin main 2>&1" );
        $output[] = $push_out;

        $pushed = ( strpos( $push_out, '->' ) !== false || strpos( $push_out, 'Everything up-to-date' ) !== false );

        if ( $pushed ) {
            $output[] = '✅ Pushed to GitHub canonical.';

            // 3. Deploy to all enabled sites
            $sites   = self::get_sites();
            $commit  = trim( shell_exec( "cd {$dir} && git rev-parse --short HEAD 2>/dev/null" ) ?: '' );
            $changed = false;

            foreach ( $sites as $slug => &$site ) {
                if ( empty( $site['enabled'] ) ) continue;

                if ( ( $site['type'] ?? 'local' ) === 'remote' ) {
                    $output[] = $this->deploy_remote( $slug, $site );
                } else {
                    $output[] = $this->deploy_local( $slug, $site );
                }
                $changed = true;
            }
            unset( $site );

            if ( $changed ) {
                self::update_sites( $sites );
            }
        } else {
            $output[] = '❌ Push may have failed — check output above.';
        }

        set_transient( 'sewn_deploy_result', implode( "\n", array_filter( $output ) ), 120 );
        wp_redirect( admin_url( 'options-general.php?page=sewn-deploy&pushed=1' ) );
        exit;
    }

    public function handle_deploy(): void {
        check_admin_referer( 'sewn_deploy_sites' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $target = sanitize_text_field( $_POST['deploy_target'] ?? 'all' );
        $sites  = self::get_sites();
        $output = [];

        if ( $target === 'all' ) {
            foreach ( $sites as $slug => &$site ) {
                if ( empty( $site['enabled'] ) ) continue;
                if ( ( $site['type'] ?? 'local' ) === 'remote' ) {
                    $output[] = $this->deploy_remote( $slug, $site );
                } else {
                    $output[] = $this->deploy_local( $slug, $site );
                }
            }
            unset( $site );
        } elseif ( isset( $sites[ $target ] ) ) {
            $site = &$sites[ $target ];
            if ( ( $site['type'] ?? 'local' ) === 'remote' ) {
                $output[] = $this->deploy_remote( $target, $site );
            } else {
                $output[] = $this->deploy_local( $target, $site );
            }
            unset( $site );
        } else {
            $output[] = "❌ Unknown site: {$target}";
        }

        self::update_sites( $sites );
        set_transient( 'sewn_deploy_result', implode( "\n", $output ), 120 );
        wp_redirect( admin_url( 'options-general.php?page=sewn-deploy&deployed=1' ) );
        exit;
    }

    public function handle_toggle_site(): void {
        check_admin_referer( 'sewn_toggle_site' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $slug    = sanitize_key( $_POST['site_slug'] ?? '' );
        $enabled = ! empty( $_POST['enabled'] );
        $sites   = self::get_sites();

        if ( isset( $sites[ $slug ] ) ) {
            $sites[ $slug ]['enabled'] = $enabled;
            self::update_sites( $sites );
        }

        wp_redirect( admin_url( 'options-general.php?page=sewn-deploy&toggled=1' ) );
        exit;
    }

    public function handle_add_site(): void {
        check_admin_referer( 'sewn_add_deploy_site' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $url   = esc_url_raw( $_POST['site_url'] ?? '' );
        $label = sanitize_text_field( $_POST['site_label'] ?? '' );
        $type  = in_array( $_POST['site_type'] ?? '', [ 'local', 'remote' ], true ) ? $_POST['site_type'] : 'remote';

        if ( empty( $url ) ) {
            set_transient( 'sewn_deploy_result', '❌ Site URL is required.', 60 );
            wp_redirect( admin_url( 'options-general.php?page=sewn-deploy&error=1' ) );
            exit;
        }

        // Generate slug from URL
        $slug = sanitize_key( str_replace( [ 'https://', 'http://', 'www.', '.' ], [ '', '', '', '-' ], $url ) );

        // Generate a deploy secret for this site
        $secret = wp_generate_password( 48, false );

        $sites = self::get_sites();
        $sites[ $slug ] = [
            'url'           => $url,
            'label'         => $label ?: $slug,
            'type'          => $type,
            'enabled'       => true,
            'deploy_secret' => $secret,
            'local_path'    => sanitize_text_field( $_POST['local_path'] ?? '' ),
            'last_deploy'   => '',
            'last_commit'   => '',
            'last_status'   => null,
            'added'         => current_time( 'mysql' ),
        ];
        self::update_sites( $sites );

        set_transient( 'sewn_deploy_result',
            "✅ Added {$label} ({$type})\n\n"
            . "Deploy secret for this site (set in their SEWN Connect settings):\n"
            . $secret,
            120
        );
        wp_redirect( admin_url( 'options-general.php?page=sewn-deploy&added=1' ) );
        exit;
    }

    public function handle_remove_site(): void {
        check_admin_referer( 'sewn_remove_deploy_site' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $slug  = sanitize_key( $_POST['site_slug'] ?? '' );
        $sites = self::get_sites();

        if ( isset( $sites[ $slug ] ) ) {
            $label = $sites[ $slug ]['label'] ?? $slug;
            unset( $sites[ $slug ] );
            self::update_sites( $sites );
            set_transient( 'sewn_deploy_result', "Removed {$label} from deploy registry.", 60 );
        }

        wp_redirect( admin_url( 'options-general.php?page=sewn-deploy&removed=1' ) );
        exit;
    }

    // ═══════════════════════════════════════════════════════════
    // RENDER
    // ═══════════════════════════════════════════════════════════

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $git    = $this->git_status();
        $sites  = self::get_sites();
        $result = get_transient( 'sewn_deploy_result' );
        if ( $result ) delete_transient( 'sewn_deploy_result' );

        $wb_commit = $git['commit'];

        ?>
        <div class="wrap">
            <h1>⚡ SEWN Connect — Deploy Manager</h1>
            <p class="description">Push from workbench → GitHub canonical → auto-update across the network.</p>

            <?php if ( $result ): ?>
                <div class="notice notice-info is-dismissible">
                    <pre style="white-space:pre-wrap;margin:8px 0;font-size:13px;"><?php echo esc_html( $result ); ?></pre>
                </div>
            <?php endif; ?>

            <!-- ═══ Git Status ═══ -->
            <div class="card" style="max-width:900px;padding:16px 20px;">
                <h2 style="margin-top:0;">📦 Workbench Git Status</h2>
                <table class="widefat" style="max-width:600px;">
                    <tr><th style="width:140px;">Branch</th><td><code><?php echo esc_html( $git['branch'] ); ?></code></td></tr>
                    <tr><th>Commit</th><td><code><?php echo esc_html( $git['commit'] ); ?></code> — <?php echo esc_html( $git['message'] ); ?></td></tr>
                    <tr><th>Tag</th><td><code><?php echo esc_html( $git['tag'] ); ?></code></td></tr>
                    <tr>
                        <th>Sync</th>
                        <td>
                            <?php if ( $git['ahead'] > 0 ): ?>
                                <span style="color:#d63638;">⚠ <?php echo $git['ahead']; ?> commit(s) ahead — push needed</span>
                            <?php elseif ( $git['behind'] > 0 ): ?>
                                <span style="color:#dba617;">⚠ <?php echo $git['behind']; ?> commit(s) behind GitHub</span>
                            <?php else: ?>
                                <span style="color:#00a32a;">✅ In sync with GitHub</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Working Tree</th>
                        <td>
                            <?php if ( $git['dirty'] ): ?>
                                <span style="color:#d63638;">⚠ Uncommitted:</span>
                                <code style="font-size:12px;"><?php echo esc_html( implode( ', ', $git['dirty_files'] ) ); ?></code>
                            <?php else: ?>
                                <span style="color:#00a32a;">✅ Clean</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <details style="margin-top:12px;">
                    <summary style="cursor:pointer;font-weight:600;">Recent commits (10)</summary>
                    <pre style="background:#f0f0f0;padding:10px;border-radius:4px;font-size:12px;margin-top:8px;"><?php echo esc_html( implode( "\n", $git['log'] ) ); ?></pre>
                </details>
            </div>

            <!-- ═══ Push to Canonical ═══ -->
            <div class="card" style="max-width:900px;padding:16px 20px;margin-top:16px;">
                <h2 style="margin-top:0;">🚀 Push to Canonical</h2>
                <p class="description">Commits changes → pushes to GitHub → deploys to all enabled sites (local + remote).</p>
                <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                    <input type="hidden" name="action" value="sewn_push_canonical" />
                    <?php wp_nonce_field( 'sewn_push_canonical' ); ?>
                    <?php if ( $git['dirty'] ): ?>
                        <p>
                            <label><strong>Commit message:</strong></label><br>
                            <input type="text" name="commit_message" value="chore: workbench update" style="width:500px;" />
                        </p>
                    <?php endif; ?>
                    <p>
                        <?php
                        $btn_label = $git['dirty'] ? 'Commit & Push + Deploy All' : 'Push + Deploy All';
                        $btn_class = ( $git['dirty'] || $git['ahead'] > 0 ) ? 'button-primary' : 'button-secondary';
                        ?>
                        <button type="submit" class="button <?php echo $btn_class; ?>" onclick="return confirm('Push to GitHub and deploy to all enabled sites?');">
                            <?php echo $btn_label; ?>
                        </button>
                        <?php if ( ! $git['dirty'] && $git['ahead'] === 0 ): ?>
                            <span class="description" style="margin-left:8px;">Nothing to push.</span>
                        <?php endif; ?>
                    </p>
                </form>
            </div>

            <!-- ═══ Site Registry ═══ -->
            <div class="card" style="max-width:900px;padding:16px 20px;margin-top:16px;">
                <h2 style="margin-top:0;">🌐 Network Sites</h2>
                <p class="description">
                    <strong>Local</strong> = same server (git pull via CLI).
                    <strong>Remote</strong> = any server (REST ping → site self-updates from GitHub).
                </p>

                <table class="widefat striped" style="margin-top:12px;">
                    <thead>
                        <tr>
                            <th>Site</th>
                            <th style="text-align:center;">Type</th>
                            <th style="text-align:center;">Auto-Update</th>
                            <th>Version / Commit</th>
                            <th>Last Deploy</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Workbench row -->
                        <tr style="background:#f0f6fc;">
                            <td>
                                <strong>⚡ <?php echo esc_html( home_url() ); ?></strong><br>
                                <span class="description">Workbench (this site)</span>
                            </td>
                            <td style="text-align:center;"><code>workbench</code></td>
                            <td style="text-align:center;">—</td>
                            <td><code><?php echo esc_html( $wb_commit ); ?></code></td>
                            <td>Pushes, doesn't pull</td>
                            <td>—</td>
                        </tr>

                        <?php if ( empty( $sites ) ): ?>
                            <tr><td colspan="6" style="text-align:center;color:#666;">No sites registered yet.</td></tr>
                        <?php endif; ?>

                        <?php foreach ( $sites as $slug => $site ):
                            $enabled  = ! empty( $site['enabled'] );
                            $is_local = ( $site['type'] ?? 'local' ) === 'local';
                            $site_url = $site['url'] ?? '';

                            // Get current commit/version
                            $site_ver = '?';
                            if ( $is_local ) {
                                // Check local filesystem
                                $lp = $site['local_path'] ?? '';
                                if ( $lp && is_dir( "{$lp}/.git" ) ) {
                                    $site_ver = trim( shell_exec( "cd " . escapeshellarg( $lp ) . " && git rev-parse --short HEAD 2>/dev/null" ) ?: '?' );
                                } elseif ( ! empty( $site['last_commit'] ) ) {
                                    $site_ver = $site['last_commit'];
                                }
                            } else {
                                $site_ver = $site['last_commit'] ?? $site['last_status']['commit'] ?? '?';
                            }

                            $in_sync = ( $site_ver === $wb_commit );
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $site['label'] ?? $slug ); ?></strong><br>
                                <a href="<?php echo esc_url( $site_url ); ?>" target="_blank" style="font-size:12px;color:#666;">
                                    <?php echo esc_html( $site_url ); ?>
                                </a>
                            </td>
                            <td style="text-align:center;">
                                <code><?php echo $is_local ? 'local' : 'remote'; ?></code>
                            </td>
                            <td style="text-align:center;">
                                <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display:inline;">
                                    <input type="hidden" name="action" value="sewn_toggle_site" />
                                    <input type="hidden" name="site_slug" value="<?php echo esc_attr( $slug ); ?>" />
                                    <input type="hidden" name="enabled" value="<?php echo $enabled ? '0' : '1'; ?>" />
                                    <?php wp_nonce_field( 'sewn_toggle_site' ); ?>
                                    <button type="submit" class="button button-small" style="min-width:70px;">
                                        <?php echo $enabled ? '🟢 ON' : '🔴 OFF'; ?>
                                    </button>
                                </form>
                            </td>
                            <td>
                                <code style="<?php echo $in_sync ? 'color:#00a32a;' : 'color:#d63638;'; ?>">
                                    <?php echo esc_html( $site_ver ); ?>
                                </code>
                                <?php echo $in_sync ? '' : ' ⚠'; ?>
                            </td>
                            <td><?php echo $site['last_deploy'] ? esc_html( $site['last_deploy'] ) : '<span style="color:#999;">never</span>'; ?></td>
                            <td>
                                <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display:inline;">
                                    <input type="hidden" name="action" value="sewn_deploy_sites" />
                                    <input type="hidden" name="deploy_target" value="<?php echo esc_attr( $slug ); ?>" />
                                    <?php wp_nonce_field( 'sewn_deploy_sites' ); ?>
                                    <button type="submit" class="button button-small">⬆ Deploy</button>
                                </form>
                                <?php if ( ! $is_local ): ?>
                                    <span class="description" style="font-size:11px;margin-left:4px;" title="Secret: <?php echo esc_attr( substr( $site['deploy_secret'] ?? '', 0, 8 ) . '…' ); ?>">🔑</span>
                                <?php endif; ?>
                                <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display:inline;margin-left:4px;">
                                    <input type="hidden" name="action" value="sewn_remove_deploy_site" />
                                    <input type="hidden" name="site_slug" value="<?php echo esc_attr( $slug ); ?>" />
                                    <?php wp_nonce_field( 'sewn_remove_deploy_site' ); ?>
                                    <button type="submit" class="button button-small button-link-delete" onclick="return confirm('Remove this site?');">✕</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div style="margin-top:12px;">
                    <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display:inline;">
                        <input type="hidden" name="action" value="sewn_deploy_sites" />
                        <input type="hidden" name="deploy_target" value="all" />
                        <?php wp_nonce_field( 'sewn_deploy_sites' ); ?>
                        <button type="submit" class="button button-secondary">⬆ Deploy All Enabled Sites</button>
                    </form>
                </div>
            </div>

            <!-- ═══ Add Site ═══ -->
            <div class="card" style="max-width:900px;padding:16px 20px;margin-top:16px;">
                <h2 style="margin-top:0;">➕ Add Network Site</h2>
                <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                    <input type="hidden" name="action" value="sewn_add_deploy_site" />
                    <?php wp_nonce_field( 'sewn_add_deploy_site' ); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="site_url">Site URL</label></th>
                            <td>
                                <input type="url" name="site_url" id="site_url" class="regular-text" placeholder="https://example.com" required />
                                <p class="description">Full URL of the WordPress site.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="site_label">Label</label></th>
                            <td>
                                <input type="text" name="site_label" id="site_label" class="regular-text" placeholder="e.g. Podcast Forge" />
                            </td>
                        </tr>
                        <tr>
                            <th>Deploy Type</th>
                            <td>
                                <label style="margin-right:16px;">
                                    <input type="radio" name="site_type" value="remote" checked /> <strong>Remote</strong>
                                    <span class="description">— Any server. Updates via REST API ping → GitHub zip download.</span>
                                </label><br>
                                <label>
                                    <input type="radio" name="site_type" value="local" /> <strong>Local</strong>
                                    <span class="description">— Same server. Updates via git pull on the filesystem.</span>
                                </label>
                            </td>
                        </tr>
                        <tr class="sewn-local-only" style="display:none;">
                            <th><label for="local_path">Local Plugin Path</label></th>
                            <td>
                                <input type="text" name="local_path" id="local_path" class="regular-text code" placeholder="/home/user/public_html/wp-content/plugins/startempire-wire-network-connect" />
                                <p class="description">Absolute path to the plugin directory on this server. Optional — falls back to CLI deploy script.</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( 'Add Site & Generate Deploy Secret', 'primary' ); ?>
                </form>
                <script>
                document.querySelectorAll('input[name="site_type"]').forEach(r => {
                    r.addEventListener('change', () => {
                        document.querySelector('.sewn-local-only').style.display =
                            document.querySelector('input[name="site_type"]:checked').value === 'local' ? '' : 'none';
                    });
                });
                </script>
            </div>

            <!-- ═══ Setup Instructions ═══ -->
            <div class="card" style="max-width:900px;padding:16px 20px;margin-top:16px;">
                <h2 style="margin-top:0;">📋 Remote Site Setup</h2>
                <p>For sites on <strong>other servers</strong> (not this VPS):</p>
                <ol>
                    <li>Install the SEWN Connect plugin on the remote site (upload zip or <code>git clone</code>).</li>
                    <li>In the remote site's <strong>Settings → SEWN Connect</strong>, paste the <strong>deploy secret</strong> shown when you add the site here.</li>
                    <li>The remote site exposes <code>/wp-json/sewn-connect/v1/deploy/pull</code> — this workbench pings that endpoint to trigger an update.</li>
                    <li>The remote site downloads the latest code from GitHub (via git pull or zip download) — no SSH/cPanel access needed.</li>
                    <li>Optionally, the remote site can enable <strong>daily self-update</strong> via its own cron — independent of this workbench.</li>
                </ol>

                <h3>Architecture</h3>
                <pre style="background:#f0f0f0;padding:12px;border-radius:4px;font-size:12px;">
Workbench (<?php echo esc_html( home_url() ); ?>)
  │  git push origin main
  ▼
GitHub (<?php echo self::GITHUB_URL; ?>)
  │
  ├─► Local sites:  CLI git pull (same server)
  │
  └─► Remote sites: POST /sewn-connect/v1/deploy/pull
                       │
                       ▼
                     Remote WP site downloads from GitHub
                     (git pull if .git exists, or zip download)

Each site also has optional daily self-update cron
(checks GitHub independently, no workbench ping needed)</pre>
            </div>
        </div>
        <?php
    }
}
