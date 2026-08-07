<?php

/**
 * Plugin Name: Test Voter Dashboard Login
 * Description: Validates voter information and redirects to the voter dashboard page.
 * Version: 1.4.0
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
    private const TOKEN_TTL    = 1800; // 30 minutes
    private const PAGE2_SLUG = 'voter-dashboard-info-elec';
    private const LOGIN_SLUG  = 'voter-dashboard-login';
    private const LOGOUT_ACTION = 'voter_dashboard_logout_action';

    public function __construct()
    {
        add_shortcode('voter_dashboard_login_form', [$this, 'render_shortcode']);
        add_shortcode('voter_session_voter_id', [$this, 'render_session_voter_id']);
        add_action('init', [$this, 'handle_logout']);
        add_action('wp_body_open', [$this, 'auto_render_header']);
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

        // Delete the transient
        $token = isset($_COOKIE[self::COOKIE_NAME])
            ? sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE_NAME]))
            : '';

        if ($token !== '') {
            delete_transient(self::TRANSIENT_PREFIX . $token);
        }

        // Clear the cookie
        setcookie(self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly'  => true,
            'samesite' => 'Strict',
        ]);

        // Redirect to login page
        $login_page = get_page_by_path(self::LOGIN_SLUG);
        $login_url  = $login_page instanceof WP_Post
            ? get_permalink($login_page)
            : home_url('/' . self::LOGIN_SLUG . '/');

        wp_safe_redirect($login_url);
        exit;
    }

    /**
     * Automatically render the header bar on all pages (via wp_body_open hook).
     * Skips the login page. Only shows when voter is logged in.
     */
    public function auto_render_header(): void
    {
        // Don't show on the login page
        global $post;
        if ($post instanceof WP_Post && $post->post_name === self::LOGIN_SLUG) {
            return;
        }

        // Only show if voter is logged in
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



    /**
     * Store voter ID in a WordPress transient and set a secure cookie with the lookup token.
     */
    private function store_voter_token(int $voter_id): void
    {
        $token = wp_generate_password(32, false);
        set_transient(self::TRANSIENT_PREFIX . $token, $voter_id, self::TOKEN_TTL);

        setcookie(self::COOKIE_NAME, $token, [
            'expires'  => time() + self::TOKEN_TTL,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly'  => true,
            'samesite' => 'Strict',
        ]);
    }

    /**
     * Retrieve voter ID from transient via the cookie token.
     *
     * @return int|false  Voter ID on success, false if expired/missing.
     */
    public static function get_voter_id_from_token()
    {
        $token = isset($_COOKIE[self::COOKIE_NAME])
            ? sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE_NAME]))
            : '';

        if ($token === '') {
            return false;
        }

        $voter_id = get_transient(self::TRANSIENT_PREFIX . $token);

        return $voter_id !== false ? (int) $voter_id : false;
    }

    public function render_shortcode(): string
    {
        $output = '';

        if ($this->is_form_submitted()) {
            $output .= $this->handle_submission();
        }

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
        if ($dob_valid === false || $errors['warning_count'] > 0 || $errors['error_count'] > 0) {
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
                 return '<script>window.location.href=' . wp_json_encode($url) . ';</script>'
                . '<noscript><p><a href="' . esc_url($url) . '">Continue</a></p></noscript>';
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
