<?php
/**
 * SEWN Connect Deploy Manager
 *
 * Workbench-only admin panel: push to GitHub canonical, manage per-site auto-pull.
 * Uses wp_options for deploy site registry. Calls sewn-connect-deploy CLI.
 *
 * @package Startempire_Wire_Network_Connect
 */

if ( ! defined( 'WPINC' ) ) die;

class SEWN_Connect_Deploy {

    /** Option key storing the deploy site registry */
    const OPTION_KEY = 'sewn_connect_deploy_sites';

    /** Option key for workbench flag */
    const WORKBENCH_KEY = 'sewn_connect_is_workbench';

    /** CLI deploy script path */
    const DEPLOY_SCRIPT = '/usr/local/bin/sewn-connect-deploy';

    public function __construct() {
        // Only load on workbench
        if ( ! get_option( self::WORKBENCH_KEY, false ) ) return;

        add_action( 'admin_menu', [ $this, 'add_submenu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_post_sewn_push_canonical', [ $this, 'handle_push' ] );
        add_action( 'admin_post_sewn_deploy_sites', [ $this, 'handle_deploy' ] );
        add_action( 'admin_post_sewn_toggle_site', [ $this, 'handle_toggle_site' ] );
        add_action( 'admin_post_sewn_add_deploy_site', [ $this, 'handle_add_site' ] );
        add_action( 'admin_post_sewn_remove_deploy_site', [ $this, 'handle_remove_site' ] );

        // AJAX for live status
        add_action( 'wp_ajax_sewn_deploy_status', [ $this, 'ajax_deploy_status' ] );
    }

    public function add_submenu() {
        add_submenu_page(
            'options-general.php',
            'SEWN Deploy',
            'SEWN Deploy',
            'manage_options',
            'sewn-deploy',
            [ $this, 'render_page' ]
        );
    }

    public function register_settings() {
        register_setting( 'sewn_deploy', self::OPTION_KEY );
        register_setting( 'sewn_deploy', self::WORKBENCH_KEY );
    }

    // ─── Site Registry ──────────────────────────────────────────

    /**
     * Get all deploy sites.
     * Format: [ 'cpanel_user' => [ 'enabled' => bool, 'label' => string, 'last_deploy' => string, 'last_commit' => string ] ]
     */
    public static function get_sites(): array {
        return get_option( self::OPTION_KEY, [] );
    }

    public static function update_sites( array $sites ): void {
        update_option( self::OPTION_KEY, $sites );
    }

    // ─── Git Operations ─────────────────────────────────────────

    private function git_status(): array {
        $plugin_dir = SEWN_CONNECT_PATH;
        $result = [];

        // Current branch
        $result['branch'] = trim( shell_exec( "cd {$plugin_dir} && git branch --show-current 2>/dev/null" ) ?: 'unknown' );

        // Current commit
        $result['commit'] = trim( shell_exec( "cd {$plugin_dir} && git rev-parse --short HEAD 2>/dev/null" ) ?: 'unknown' );
        $result['commit_full'] = trim( shell_exec( "cd {$plugin_dir} && git rev-parse HEAD 2>/dev/null" ) ?: '' );

        // Last commit message
        $result['message'] = trim( shell_exec( "cd {$plugin_dir} && git log -1 --pretty=%s 2>/dev/null" ) ?: '' );

        // Dirty?
        $dirty = trim( shell_exec( "cd {$plugin_dir} && git status --porcelain 2>/dev/null" ) ?: '' );
        $result['dirty'] = ! empty( $dirty );
        $result['dirty_files'] = $dirty ? explode( "\n", $dirty ) : [];

        // Behind/ahead of origin?
        shell_exec( "cd {$plugin_dir} && git fetch origin --quiet 2>/dev/null" );
        $behind = intval( trim( shell_exec( "cd {$plugin_dir} && git rev-list HEAD..origin/main --count 2>/dev/null" ) ?: '0' ) );
        $ahead  = intval( trim( shell_exec( "cd {$plugin_dir} && git rev-list origin/main..HEAD --count 2>/dev/null" ) ?: '0' ) );
        $result['behind'] = $behind;
        $result['ahead']  = $ahead;

        // Latest tag
        $result['tag'] = trim( shell_exec( "cd {$plugin_dir} && git describe --tags --abbrev=0 2>/dev/null" ) ?: 'none' );

        // Recent commits
        $log = shell_exec( "cd {$plugin_dir} && git log --oneline -10 2>/dev/null" ) ?: '';
        $result['log'] = array_filter( explode( "\n", trim( $log ) ) );

        return $result;
    }

    // ─── Handlers ───────────────────────────────────────────────

    public function handle_push() {
        check_admin_referer( 'sewn_push_canonical' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $plugin_dir = SEWN_CONNECT_PATH;
        $output = [];

        // 1. Check for uncommitted changes
        $dirty = trim( shell_exec( "cd {$plugin_dir} && git status --porcelain 2>&1" ) ?: '' );
        if ( ! empty( $dirty ) ) {
            // Auto-commit
            $msg = sanitize_text_field( $_POST['commit_message'] ?? 'chore: workbench auto-commit' );
            shell_exec( "cd {$plugin_dir} && git add -A 2>&1" );
            $commit_out = shell_exec( "cd {$plugin_dir} && git commit -m " . escapeshellarg( $msg ) . " 2>&1" );
            $output[] = "Committed: {$msg}";
            $output[] = $commit_out;
        }

        // 2. Push to origin main
        $push_out = shell_exec( "cd {$plugin_dir} && git push origin main 2>&1" );
        $output[] = $push_out;

        if ( strpos( $push_out, '->') !== false || strpos( $push_out, 'Everything up-to-date' ) !== false ) {
            $output[] = '✅ Pushed to GitHub canonical.';

            // 3. Auto-deploy to enabled sites
            $sites = self::get_sites();
            $enabled = array_filter( $sites, fn( $s ) => ! empty( $s['enabled'] ) );
            if ( ! empty( $enabled ) && file_exists( self::DEPLOY_SCRIPT ) ) {
                $deploy_out = shell_exec( self::DEPLOY_SCRIPT . ' 2>&1' );
                $output[] = $deploy_out;

                // Update last_deploy timestamps
                $commit = trim( shell_exec( "cd {$plugin_dir} && git rev-parse --short HEAD 2>/dev/null" ) ?: '' );
                foreach ( $enabled as $user => &$site ) {
                    $site['last_deploy'] = current_time( 'mysql' );
                    $site['last_commit'] = $commit;
                }
                self::update_sites( array_merge( $sites, $enabled ) );
            }
        } else {
            $output[] = '❌ Push may have failed — check output above.';
        }

        set_transient( 'sewn_deploy_result', implode( "\n", $output ), 60 );
        wp_redirect( admin_url( 'options-general.php?page=sewn-deploy&pushed=1' ) );
        exit;
    }

    public function handle_deploy() {
        check_admin_referer( 'sewn_deploy_sites' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $target = sanitize_text_field( $_POST['deploy_target'] ?? 'all' );

        if ( ! file_exists( self::DEPLOY_SCRIPT ) ) {
            set_transient( 'sewn_deploy_result', '❌ Deploy script not found: ' . self::DEPLOY_SCRIPT, 60 );
            wp_redirect( admin_url( 'options-general.php?page=sewn-deploy&deployed=1' ) );
            exit;
        }

        if ( $target === 'all' ) {
            $out = shell_exec( self::DEPLOY_SCRIPT . ' 2>&1' );
        } else {
            $out = shell_exec( self::DEPLOY_SCRIPT . ' ' . escapeshellarg( $target ) . ' 2>&1' );
        }

        // Update timestamps for deployed sites
        $sites = self::get_sites();
        $commit = trim( shell_exec( "cd " . SEWN_CONNECT_PATH . " && git rev-parse --short HEAD 2>/dev/null" ) ?: '' );
        if ( $target === 'all' ) {
            foreach ( $sites as $user => &$site ) {
                if ( ! empty( $site['enabled'] ) ) {
                    $site['last_deploy'] = current_time( 'mysql' );
                    $site['last_commit'] = $commit;
                }
            }
        } elseif ( isset( $sites[ $target ] ) ) {
            $sites[ $target ]['last_deploy'] = current_time( 'mysql' );
            $sites[ $target ]['last_commit'] = $commit;
        }
        self::update_sites( $sites );

        set_transient( 'sewn_deploy_result', $out, 60 );
        wp_redirect( admin_url( 'options-general.php?page=sewn-deploy&deployed=1' ) );
        exit;
    }

    public function handle_toggle_site() {
        check_admin_referer( 'sewn_toggle_site' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $user    = sanitize_text_field( $_POST['cpanel_user'] ?? '' );
        $enabled = ! empty( $_POST['enabled'] );
        $sites   = self::get_sites();

        if ( isset( $sites[ $user ] ) ) {
            $sites[ $user ]['enabled'] = $enabled;
            self::update_sites( $sites );
            
            // Also update the deploy script's DEPLOY_SITES array
            $this->sync_deploy_script( $sites );
        }

        wp_redirect( admin_url( 'options-general.php?page=sewn-deploy&toggled=1' ) );
        exit;
    }

    public function handle_add_site() {
        check_admin_referer( 'sewn_add_deploy_site' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $user  = sanitize_text_field( $_POST['cpanel_user'] ?? '' );
        $label = sanitize_text_field( $_POST['site_label'] ?? $user );

        if ( empty( $user ) ) {
            wp_redirect( admin_url( 'options-general.php?page=sewn-deploy&error=empty_user' ) );
            exit;
        }

        // Verify the cPanel user exists
        if ( ! is_dir( "/home/{$user}/public_html" ) ) {
            set_transient( 'sewn_deploy_result', "❌ No public_html found for user: {$user}", 60 );
            wp_redirect( admin_url( 'options-general.php?page=sewn-deploy&error=no_user' ) );
            exit;
        }

        $sites = self::get_sites();
        $sites[ $user ] = [
            'enabled'     => true,
            'label'       => $label,
            'last_deploy' => '',
            'last_commit' => '',
            'added'       => current_time( 'mysql' ),
        ];
        self::update_sites( $sites );
        $this->sync_deploy_script( $sites );

        wp_redirect( admin_url( 'options-general.php?page=sewn-deploy&added=1' ) );
        exit;
    }

    public function handle_remove_site() {
        check_admin_referer( 'sewn_remove_deploy_site' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $user  = sanitize_text_field( $_POST['cpanel_user'] ?? '' );
        $sites = self::get_sites();

        if ( isset( $sites[ $user ] ) ) {
            unset( $sites[ $user ] );
            self::update_sites( $sites );
            $this->sync_deploy_script( $sites );
        }

        wp_redirect( admin_url( 'options-general.php?page=sewn-deploy&removed=1' ) );
        exit;
    }

    // ─── Deploy Script Sync ────────────────────────────────────

    /**
     * Rewrite the DEPLOY_SITES array in the CLI script from the DB registry.
     */
    private function sync_deploy_script( array $sites ): void {
        $script_path = self::DEPLOY_SCRIPT;
        if ( ! file_exists( $script_path ) || ! is_writable( $script_path ) ) return;

        $script = file_get_contents( $script_path );

        // Build new DEPLOY_SITES block
        $lines = [ 'DEPLOY_SITES=(' ];
        // Workbench always first
        $lines[] = '  "startempirewire"   # Parent site (workbench — skip pull, it pushes)';
        foreach ( $sites as $user => $site ) {
            if ( $user === 'startempirewire' ) continue; // Already included
            $status = ! empty( $site['enabled'] ) ? 'auto-pull ON' : 'auto-pull OFF';
            $label  = $site['label'] ?? $user;
            $lines[] = sprintf( '  "%s"   # %s (%s)', $user, $label, $status );
        }
        $lines[] = ')';

        $new_block = implode( "\n", $lines );

        // Replace the DEPLOY_SITES block in the script
        $pattern = '/DEPLOY_SITES=\(\n.*?\)/s';
        if ( preg_match( $pattern, $script ) ) {
            $script = preg_replace( $pattern, $new_block, $script );
            file_put_contents( $script_path, $script );
        }
    }

    // ─── AJAX ───────────────────────────────────────────────────

    public function ajax_deploy_status() {
        check_ajax_referer( 'sewn_deploy_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $sites  = self::get_sites();
        $status = [];

        foreach ( $sites as $user => $site ) {
            $plugin_path = "/home/{$user}/public_html/wp-content/plugins/startempire-wire-network-connect";
            $commit = 'N/A';
            $has_git = false;

            if ( is_dir( "{$plugin_path}/.git" ) ) {
                $has_git = true;
                $commit = trim( shell_exec( "cd {$plugin_path} && git rev-parse --short HEAD 2>/dev/null" ) ?: 'error' );
            }

            $status[ $user ] = [
                'enabled'     => ! empty( $site['enabled'] ),
                'has_git'     => $has_git,
                'installed'   => is_dir( $plugin_path ),
                'commit'      => $commit,
                'last_deploy' => $site['last_deploy'] ?? '',
            ];
        }

        wp_send_json_success( $status );
    }

    // ─── Render ─────────────────────────────────────────────────

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $git    = $this->git_status();
        $sites  = self::get_sites();
        $result = get_transient( 'sewn_deploy_result' );
        if ( $result ) delete_transient( 'sewn_deploy_result' );

        $workbench_commit = $git['commit'];

        ?>
        <div class="wrap">
            <h1>⚡ SEWN Connect — Deploy Manager</h1>
            <p class="description">Push from workbench to GitHub canonical, auto-pull to member sites.</p>

            <?php if ( $result ): ?>
                <div class="notice notice-info is-dismissible">
                    <pre style="white-space:pre-wrap;margin:8px 0;font-size:13px;"><?php echo esc_html( $result ); ?></pre>
                </div>
            <?php endif; ?>

            <!-- ─── Git Status ─── -->
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
                                <span style="color:#d63638;">⚠ <?php echo $git['ahead']; ?> commit(s) ahead of GitHub — push needed</span>
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
                                <span style="color:#d63638;">⚠ Uncommitted changes:</span><br>
                                <code style="font-size:12px;"><?php echo esc_html( implode( ', ', $git['dirty_files'] ) ); ?></code>
                            <?php else: ?>
                                <span style="color:#00a32a;">✅ Clean</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <details style="margin-top:12px;">
                    <summary style="cursor:pointer;font-weight:600;">Recent commits (10)</summary>
                    <pre style="background:#f0f0f0;padding:10px;border-radius:4px;font-size:12px;margin-top:8px;"><?php
                        echo esc_html( implode( "\n", $git['log'] ) );
                    ?></pre>
                </details>
            </div>

            <!-- ─── Push to Canonical ─── -->
            <div class="card" style="max-width:900px;padding:16px 20px;margin-top:16px;">
                <h2 style="margin-top:0;">🚀 Push to Canonical</h2>
                <p class="description">Commits any uncommitted changes, pushes to <code>origin/main</code> on GitHub, and deploys to enabled sites.</p>

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
                        $btn_label = $git['dirty'] ? 'Commit & Push to GitHub + Deploy' : 'Push to GitHub + Deploy';
                        $btn_class = ( $git['dirty'] || $git['ahead'] > 0 ) ? 'button-primary' : 'button-secondary';
                        ?>
                        <button type="submit" class="button <?php echo $btn_class; ?>" onclick="return confirm('Push to GitHub and deploy to all enabled sites?');">
                            <?php echo $btn_label; ?>
                        </button>
                        <?php if ( ! $git['dirty'] && $git['ahead'] === 0 ): ?>
                            <span class="description" style="margin-left:8px;">Nothing to push — already in sync.</span>
                        <?php endif; ?>
                    </p>
                </form>
            </div>

            <!-- ─── Deploy Sites Registry ─── -->
            <div class="card" style="max-width:900px;padding:16px 20px;margin-top:16px;">
                <h2 style="margin-top:0;">🌐 Network Sites — Auto-Pull Registry</h2>
                <p class="description">
                    Sites with <strong>auto-pull ON</strong> receive the latest plugin version daily at 3 AM PST, and immediately after each push from this panel.
                </p>

                <table class="widefat striped" style="margin-top:12px;">
                    <thead>
                        <tr>
                            <th>Site</th>
                            <th>cPanel User</th>
                            <th style="text-align:center;">Auto-Pull</th>
                            <th>Current Commit</th>
                            <th>Last Deploy</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Workbench (always first, not toggleable) -->
                        <tr style="background:#f0f6fc;">
                            <td><strong>⚡ Workbench (this site)</strong></td>
                            <td><code>startempirewire</code></td>
                            <td style="text-align:center;">—</td>
                            <td><code><?php echo esc_html( $workbench_commit ); ?></code></td>
                            <td>Pushes, doesn't pull</td>
                            <td>—</td>
                        </tr>

                        <?php if ( empty( $sites ) ): ?>
                            <tr><td colspan="6" style="text-align:center;color:#666;">No member sites registered. Add one below.</td></tr>
                        <?php endif; ?>

                        <?php foreach ( $sites as $user => $site ):
                            if ( $user === 'startempirewire' ) continue;
                            $plugin_path = "/home/{$user}/public_html/wp-content/plugins/startempire-wire-network-connect";
                            $installed   = is_dir( $plugin_path );
                            $has_git     = is_dir( "{$plugin_path}/.git" );
                            $site_commit = $has_git ? trim( shell_exec( "cd {$plugin_path} && git rev-parse --short HEAD 2>/dev/null" ) ?: '?' ) : 'no git';
                            $in_sync     = ( $site_commit === $workbench_commit );
                            $enabled     = ! empty( $site['enabled'] );
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $site['label'] ?? $user ); ?></strong>
                                <?php if ( ! $installed ): ?>
                                    <span style="color:#d63638;"> (not installed)</span>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo esc_html( $user ); ?></code></td>
                            <td style="text-align:center;">
                                <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display:inline;">
                                    <input type="hidden" name="action" value="sewn_toggle_site" />
                                    <input type="hidden" name="cpanel_user" value="<?php echo esc_attr( $user ); ?>" />
                                    <input type="hidden" name="enabled" value="<?php echo $enabled ? '0' : '1'; ?>" />
                                    <?php wp_nonce_field( 'sewn_toggle_site' ); ?>
                                    <button type="submit" class="button button-small" style="min-width:70px;" title="Click to toggle">
                                        <?php echo $enabled ? '🟢 ON' : '🔴 OFF'; ?>
                                    </button>
                                </form>
                            </td>
                            <td>
                                <code style="<?php echo $in_sync ? 'color:#00a32a;' : 'color:#d63638;'; ?>">
                                    <?php echo esc_html( $site_commit ); ?>
                                </code>
                                <?php echo $in_sync ? '' : ' ⚠'; ?>
                            </td>
                            <td>
                                <?php echo $site['last_deploy'] ? esc_html( $site['last_deploy'] ) : '<span style="color:#999;">never</span>'; ?>
                            </td>
                            <td>
                                <!-- Deploy now -->
                                <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display:inline;">
                                    <input type="hidden" name="action" value="sewn_deploy_sites" />
                                    <input type="hidden" name="deploy_target" value="<?php echo esc_attr( $user ); ?>" />
                                    <?php wp_nonce_field( 'sewn_deploy_sites' ); ?>
                                    <button type="submit" class="button button-small" title="Deploy now">⬆ Deploy</button>
                                </form>
                                <!-- Remove -->
                                <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display:inline;margin-left:4px;">
                                    <input type="hidden" name="action" value="sewn_remove_deploy_site" />
                                    <input type="hidden" name="cpanel_user" value="<?php echo esc_attr( $user ); ?>" />
                                    <?php wp_nonce_field( 'sewn_remove_deploy_site' ); ?>
                                    <button type="submit" class="button button-small button-link-delete" onclick="return confirm('Remove <?php echo esc_attr( $user ); ?> from deploy registry?');" title="Remove from registry">✕</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Deploy All -->
                <div style="margin-top:12px;">
                    <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display:inline;">
                        <input type="hidden" name="action" value="sewn_deploy_sites" />
                        <input type="hidden" name="deploy_target" value="all" />
                        <?php wp_nonce_field( 'sewn_deploy_sites' ); ?>
                        <button type="submit" class="button button-secondary">⬆ Deploy All Enabled Sites Now</button>
                    </form>
                </div>
            </div>

            <!-- ─── Add New Site ─── -->
            <div class="card" style="max-width:900px;padding:16px 20px;margin-top:16px;">
                <h2 style="margin-top:0;">➕ Add Network Site</h2>
                <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                    <input type="hidden" name="action" value="sewn_add_deploy_site" />
                    <?php wp_nonce_field( 'sewn_add_deploy_site' ); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="cpanel_user">cPanel Username</label></th>
                            <td>
                                <input type="text" name="cpanel_user" id="cpanel_user" class="regular-text" placeholder="e.g. podcastforge" required />
                                <p class="description">Must match the cPanel account name (directory under /home/)</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="site_label">Site Label</label></th>
                            <td>
                                <input type="text" name="site_label" id="site_label" class="regular-text" placeholder="e.g. Podcast Forge" />
                                <p class="description">Friendly name for the deploy dashboard (optional)</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( 'Add Site', 'primary' ); ?>
                </form>
            </div>

            <!-- ─── Deploy Info ─── -->
            <div class="card" style="max-width:900px;padding:16px 20px;margin-top:16px;">
                <h2 style="margin-top:0;">ℹ️ Deploy Architecture</h2>
                <pre style="background:#f0f0f0;padding:12px;border-radius:4px;font-size:12px;">
Workbench (this site)
  │  git push
  ▼
GitHub Canonical
  https://github.com/Startempire-Wire/Startempire-Wire-Network-Connect
  │
  ├── Daily 3 AM cron ──► All enabled sites (git pull)
  ├── "Push + Deploy" ──► All enabled sites (immediate)
  └── "Deploy" button ──► Individual site (immediate)

CLI: sewn-connect-deploy [site_user]
Cron: /etc/cron.d/sewn-connect-deploy
Log: /var/log/sewn-connect-deploy.log
Script: <?php echo self::DEPLOY_SCRIPT; ?>

.canonical file in each repo identifies role:
  WORKBENCH = develop + push here
  MIRROR = pull only, no commits</pre>
            </div>
        </div>
        <?php
    }
}
