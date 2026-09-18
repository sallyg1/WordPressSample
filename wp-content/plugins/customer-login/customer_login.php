<?php

/**
 * Plugin Name: Voter Dashboard Login
 * Description: Validates voter information and redirects to the voter dashboard page.
 * Version: 1.5.0
 * Author: My Ton
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Voter_Dashboard_Login_Plugin
{
    private const API_URL     = 'https://pcro-sqlapi-dev.azurewebsites.net/api/Database/ValidateVoterInfoReturnVoterIdDistricts';
    private const TIMEOUT     = 20;
    private const NONCE_ACTION = 'voter_dashboard_login_action';
    private const NONCE_NAME   = 'voter_dashboard_login_nonce';
    private const COOKIE_NAME  = 'pima_voter_token';
    private const TRANSIENT_PREFIX = 'pima_voter_';
    private const TOKEN_TTL    = 60; // 30 minutes
    private const PAGE2_SLUG = 'voter-dashboard-info-elec';
    private const LOGIN_SLUG  = 'voter_dashboard_login';
    private const LOGOUT_ACTION = 'voter_dashboard_logout_action';
    private string $login_error = '';

    public function __construct()
    {
        add_shortcode('voter_dashboard_login_form', [$this, 'render_shortcode']);
        add_shortcode('voter_session_voter_id', [$this, 'render_session_voter_id']);
        add_action('init', [$this, 'handle_logout']);
        add_action('wp_body_open', [$this, 'auto_render_header']);
        add_action('template_redirect', [$this, 'prepare_page']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_session_script']);
        add_action('wp_ajax_pima_voter_session', [$this, 'handle_session']);
        add_action('wp_ajax_nopriv_pima_voter_session', [$this, 'handle_session']);
    }

    /**
     * Handle logout: clear transient + cookie, redirect to login page.
     */
    public function handle_logout(): void
    {
        if (!isset($_GET['voter_logout']) || $_GET['voter_logout'] !== '1') {
            return;
        }

        if (
            !isset($_GET['_wpnonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), self::LOGOUT_ACTION)
        ) {
            return;
        }

        $token = self::token();
        if ($token !== '') {
            delete_transient(self::TRANSIENT_PREFIX . $token);
        }
        self::cookie('', time() - 3600);

        wp_safe_redirect(self::login_url());
        exit;
    }

    /**
     * Automatically render the header bar on all pages (via wp_body_open hook).
     * Skips the login page. Only shows when voter is logged in.
     */
    public function auto_render_header(): void
    {
        global $post;
        if ($post instanceof WP_Post && $post->post_name === self::LOGIN_SLUG) {
            return;
        }

        $voter_id = self::get_voter_id_from_token();
        if ($voter_id === false) {
            return;
        }

        $logout_url = wp_nonce_url(
            add_query_arg('voter_logout', '1', home_url($_SERVER['REQUEST_URI'])),
            self::LOGOUT_ACTION
        );

        echo '<div style="background:#0073aa;color:#fff;padding:10px 20px;display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">' .
            '<span style="font-weight:600;">Voter Dashboard</span>' .
            '<a href="' . esc_url($logout_url) . '" style="background:#fff;color:#0073aa;padding:6px 16px;border-radius:4px;text-decoration:none;font-weight:600;font-size:0.9rem;">Logout</a>' .
            '</div>';
    }

    private static function idle_seconds(): int
    {
        return max(10, (int) apply_filters('pima_voter_idle_seconds', self::TOKEN_TTL));
    }

    private static function token(): string
    {
        $token = $_COOKIE[self::COOKIE_NAME] ?? '';
        return is_string($token) && preg_match('/^[a-zA-Z0-9]{32}$/D', $token) ? $token : '';
    }

    private static function session()
    {
        $token = self::token();
        $session = $token !== '' ? get_transient(self::TRANSIENT_PREFIX . $token) : false;
        if (!is_array($session) || !isset($session['voter_id'], $session['expires_at'], $session['csrf'])
            || !is_string($session['csrf']) || $session['expires_at'] <= microtime(true)) {
            return false;
        }
        return $session;
    }

    private static function cookie(string $token, int $expires): void
    {
        setcookie(self::COOKIE_NAME, $token, [
            'expires' => $expires, 'path' => '/', 'secure' => is_ssl(),
            'httponly' => true, 'samesite' => 'Strict',
        ]);
    }

    private static function login_url(): string
    {
        $login_page = get_page_by_path(self::LOGIN_SLUG);
        return $login_page instanceof WP_Post
            ? get_permalink($login_page)
            : home_url('/' . self::LOGIN_SLUG . '/');
    }

    public function prepare_page(): void
    {
        if (!is_page([self::LOGIN_SLUG, self::PAGE2_SLUG])) {
            return;
        }
        nocache_headers();
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (is_page(self::LOGIN_SLUG) && $this->is_form_submitted()) {
            $this->login_error = $this->handle_submission();
        }
        if (is_page(self::PAGE2_SLUG) && self::session() === false) {
            self::cookie('', time() - 3600);
            wp_safe_redirect(self::login_url());
            exit;
        }
    }

    public function enqueue_session_script(): void
    {
        if (!is_page(self::PAGE2_SLUG)) {
            return;
        }
        $session = self::session();
        if ($session === false) {
            return;
        }
        wp_enqueue_script('pima-voter-session', plugins_url('session.js', __FILE__), [], '1.5.0', false);
        wp_add_inline_script('pima-voter-session', 'window.pimaVoterSession = ' . wp_json_encode([
            'ajaxUrl' => admin_url('admin-ajax.php'), 'loginUrl' => self::login_url(),
            'csrf' => $session['csrf'],
            'remainingMs' => max(0, ($session['expires_at'] - microtime(true)) * 1000),
        ]) . ';', 'before');
    }

    public function handle_session(): void
    {
        global $wpdb;
        nocache_headers();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_send_json_error(null, 405);
        }
        $token = self::token();
        $lock = substr('pima_' . hash('sha256', $token), 0, 64);
        if ($token === '') {
            wp_send_json_error(null, 401);
        }
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)', $lock)) !== 1) {
            wp_send_json_error(null, 503);
        }
        $status = 200;
        $result = [];
        try {
            $session = self::session();
            $csrf = $_POST['csrf'] ?? '';
            $operation = $_POST['operation'] ?? 'status';
            if ($session === false) {
                delete_transient(self::TRANSIENT_PREFIX . $token);
                self::cookie('', time() - 3600);
                $status = 401;
            } elseif (!is_string($csrf) || !hash_equals($session['csrf'], $csrf)) {
                $status = 403;
            } elseif ($operation === 'logout') {
                delete_transient(self::TRANSIENT_PREFIX . $token);
                self::cookie('', time() - 3600);
            } elseif ($operation === 'activity' || $operation === 'status') {
                if ($operation === 'activity') {
                    $age = $_POST['ageMs'] ?? 0;
                    $age = is_scalar($age) && is_numeric($age) ? min(5000, max(0, (float) $age)) : 0;
                    $session['expires_at'] = max($session['expires_at'], microtime(true) - $age / 1000 + self::idle_seconds());
                    set_transient(self::TRANSIENT_PREFIX . $token, $session, self::idle_seconds());
                    self::cookie($token, (int) ceil($session['expires_at']));
                }
                $result = ['remainingMs' => max(0, ($session['expires_at'] - microtime(true)) * 1000)];
            } else {
                $status = 400;
            }
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
        if ($status !== 200) {
            wp_send_json_error(null, $status);
        }
        wp_send_json_success($result);
    }

    /**
     * Store voter ID in a WordPress transient and set a secure cookie with the lookup token.
     */
    private function store_voter_token(int $voter_id): void
    {
        $token = wp_generate_password(32, false);
        $expires = microtime(true) + self::idle_seconds();
        set_transient(self::TRANSIENT_PREFIX . $token, [
            'voter_id' => $voter_id, 'expires_at' => $expires,
            'csrf' => wp_generate_password(32, false),
        ], self::idle_seconds());
        self::cookie($token, (int) ceil($expires));
    }

    /**
     * Retrieve voter ID from transient via the cookie token.
     *
     * @return int|false  Voter ID on success, false if expired/missing.
     */
    public static function get_voter_id_from_token()
    {
        $session = self::session();
        return $session !== false ? (int) $session['voter_id'] : false;
    }

    public function render_shortcode(): string
    {
        $output = $this->login_error;

        $output .= $this->render_form();

        return $output;
    }

    private function is_form_submitted(): bool
    {
        return isset($_POST['voter_dashboard_login_submit']);
    }

    private function handle_submission(): string
    {
        if (
            !isset($_POST[self::NONCE_NAME]) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)
        ) {
            return '<p style="color:red;">Security check failed. Please try again.</p>';
        }

        $first_name = sanitize_text_field(wp_unslash($_POST['firstName'] ?? ''));
        $last_name  = sanitize_text_field(wp_unslash($_POST['lastName'] ?? ''));
        $dob        = sanitize_text_field(wp_unslash($_POST['dob'] ?? ''));
        $az_id      = sanitize_text_field(wp_unslash($_POST['azId'] ?? ''));
        $ssn        = sanitize_text_field(wp_unslash($_POST['ssn'] ?? ''));

        if ($first_name === '' || $last_name === '' || $dob === '') {
            return '<p style="color:red;">First name, last name and date of birth are required.</p>';
        }

        if ($az_id === '' && $ssn === '') {
            return '<p style="color:red;">Please enter either your Arizona Voter ID or the last 4 digits of your SSN.</p>';
        }

        $dob_valid = DateTime::createFromFormat('Y-m-d', $dob);
        $errors = DateTime::getLastErrors();
        if ($dob_valid === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
             return '<p style="color:red;">Date of birth must be in YYYY-MM-DD format.</p>';
        }

        $url = add_query_arg(
            [
                'firstName' => $first_name,
                'lastName'  => $last_name,
                'dob'       => $dob,
                'azId'      => $az_id,
                'ssn'       => $ssn,
            ],
            self::API_URL
        );

       $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Accept' => 'text/plain',
            ],
        ]);

        if (is_wp_error($response)) {
            return '<p style="color:red;">Error contacting the API: ' . esc_html($response->get_error_message()) . '</p>';
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return '<p style="color:red;">The API returned an unexpected status: ' . esc_html((string) $code) . '</p>';
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data) || !isset($data['item1']) || !is_array($data['item1'])) {
            return '<p style="color:red;">Received invalid data from the API.</p>';
        }

        return $this->render_results($data);
    }

    private function render_results(array $data): string
    {
        if (!isset($data['item1']['voterInfo']) || !is_array($data['item1']['voterInfo'])) {
              return '<p style="color:red;">Invalid API structure.</p>';
        }

        $voter_info = $data['item1']['voterInfo'] ?? [];

        $voter_id     = (int)($voter_info['returnVoterId'] ?? 0);
        $confidential = !empty($voter_info['isConfidential']);

        if ($confidential) {
            return '<p style="color:red;font-weight:bold;">Your records are sealed.</p>';
        }

        $is_valid = array_key_exists('isValid', $voter_info)
            ? filter_var($voter_info['isValid'], FILTER_VALIDATE_BOOLEAN)
            : $voter_id > 0;

        if ($is_valid) {
            $this->store_voter_token($voter_id);
            return $this->redirect_to_page2();
        }

        return '<p style="color:red;">Invalid information. Please try again.</p>';
    }

    private function redirect_to_page2(): string
    {
        $page = get_page_by_path(self::PAGE2_SLUG);

        $url = $page instanceof WP_Post
            ? get_permalink($page)
            : home_url('/' . self::PAGE2_SLUG . '/');

        if (is_string($url) && $url !== '') {
            wp_safe_redirect($url);
            exit;
        }

        return '<p style="color:red;">Could not determine redirect page URL.</p>';
    }

    public function render_session_voter_id(): string
    {
        $voter_id = self::get_voter_id_from_token();

        if ($voter_id === false) {
            return '<p>No voter ID found in session.</p>';
        }

        return '<p><strong>Voter ID:</strong> ' . esc_html((string) $voter_id) . '</p>';
    }

    private function render_form(): string
    {
        ob_start();
        ?>
        <form method="post" style="max-width:420px;margin:1rem 0;">
            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>

            <p>
                <label for="firstName"><strong>First Name:</strong></label><br/>
                <input type="text" id="firstName" name="firstName" required style="width:100%;padding:6px;" />
            </p>

            <p>
                <label for="lastName"><strong>Last Name:</strong></label><br/>
                <input type="text" id="lastName" name="lastName" required style="width:100%;padding:6px;" />
            </p>

            <p>
                <label for="dob"><strong>Date of Birth:</strong></label><br/>
                <input type="date" id="dob" name="dob" required style="width:100%;padding:6px;" />
            </p>

            <p>
                <label for="azId"><strong>Arizona Voter ID:</strong></label><br/>
                <input type="text" id="azId" name="azId" style="width:100%;padding:6px;" placeholder="e.g. D05043049" />
            </p>

            <p>
                <label for="ssn"><strong>SSN (last 4):</strong></label><br/>
                <input type="password" id="ssn" name="ssn" maxlength="4" pattern="[0-9]{4}" style="width:100%;padding:6px;" placeholder="...." />
            </p>

            <p>
                <button type="button" id="pima-open-modal-btn" value="1" style="padding:8px 20px;">
                    find Voter
                </button>
            </p>
            <input type="hidden" name="voter_dashboard_login_submit" value="1" />
        </form>
        <!-- Modal Popup -->
        <div id="pima-modal-overlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:8px;padding:2rem;max-width:400px;width:90%;box-shadow:0 4px 20px rgba(0,0,0,0.3);text-align:center;">
                <p style="margin:0 0 1.5rem;font-size:1.1rem;">Testing</p>
                <div style="display:flex;gap:1rem;justify-content:center;">
                    <button type="button" id="pima-modal-cancel"
                            style="padding:8px 20px;cursor:pointer;background:#ccc;border:1px solid #999;border-radius:4px;">
                        Cancel
                    </button>
                    <button type="button" id="pima-modal-process"
                            style="padding:8px 20px;cursor:pointer;background:#0073aa;color:#fff;border:none;border-radius:4px;font-weight:600;">
                        Process
                    </button>
                </div>
            </div>
        </div>

        <script>
        (function() {
            var overlay = document.getElementById('pima-modal-overlay');
            var openBtn = document.getElementById('pima-open-modal-btn');
            var cancelBtn = document.getElementById('pima-modal-cancel');
            var processBtn = document.getElementById('pima-modal-process');
            var form = openBtn.closest('form');

            openBtn.addEventListener('click', function() {
                overlay.style.display = 'flex';
            });

            cancelBtn.addEventListener('click', function() {
                overlay.style.display = 'none';
            });

            processBtn.addEventListener('click', function() {
                overlay.style.display = 'none';
                form.submit();
            });

            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) {
                    overlay.style.display = 'none';
                }
            });
        })();
        </script>

        <?php
        return ob_get_clean();
    }
}

new Voter_Dashboard_Login_Plugin();
