<?php
/**
 * Plugin Name: Pima Employee Sealed Redirect
 * Description: Checks employee active status by email. If active, shows "your records are sealed" in red. If inactive, stores email in session and redirects.
 * Version: 1.0.0
 * Author: Local Dev
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Pima_Employee_Sealed_Redirect_Plugin
{
    private const API_URL = 'http://localhost:5166/api/Database/employees';
    private const TIMEOUT = 15;

    private const NONCE_ACTION = 'pima_employee_sealed_redirect';
    private const NONCE_NAME = 'pima_employee_sealed_nonce';
    private const SUBMIT_NAME = 'pima_employee_sealed_submit';

    private const SESSION_EMAIL_KEY = 'pima_employee_session_email';
    private const SESSION_FLASH_KEY = 'pima_employee_session_flash';

    public function __construct()
    {
        add_action('init', [$this, 'maybe_start_session'], 1);
        add_action('init', [$this, 'handle_form_submission'], 20);

        add_shortcode('pima_employee_sealed_form', [$this, 'render_form_shortcode']);
        add_shortcode('pima_employee_session_email', [$this, 'render_session_email_shortcode']);
    }

    public function maybe_start_session(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public function handle_form_submission(): void
    {
        if (!isset($_POST[self::SUBMIT_NAME])) {
            return;
        }

        if (
            !isset($_POST[self::NONCE_NAME]) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)
        ) {
            $this->set_flash('<p style="color:red;">Security check failed. Please try again.</p>');
            $this->redirect_to_return_url();
        }

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        if ($email === '' || !is_email($email)) {
            $this->set_flash('<p style="color:red;">Please enter a valid email address.</p>');
            $this->redirect_to_return_url();
        }

        $url = add_query_arg('email', rawurlencode($email), self::API_URL);

        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Accept' => 'text/plain',
            ],
        ]);

        if (is_wp_error($response)) {
            $this->set_flash('<p style="color:red;">Error contacting API: ' . esc_html($response->get_error_message()) . '</p>');
            $this->redirect_to_return_url();
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        if ($status_code < 200 || $status_code >= 300) {
            $this->set_flash('<p style="color:red;">API returned unexpected status: ' . esc_html((string) $status_code) . '</p>');
            $this->redirect_to_return_url();
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data) || empty($data) || !is_array($data[0])) {
            $this->set_flash('<p style="color:red;">No employee record found for that email.</p>');
            $this->redirect_to_return_url();
        }

        $is_active = (bool) ($data[0]['isActive'] ?? false);

        if ($is_active) {
            $this->set_flash('<p style="color:red;font-weight:bold;">your records are sealed</p>');
            $this->redirect_to_return_url();
        }

        $_SESSION[self::SESSION_EMAIL_KEY] = $email;

        $redirect_url = isset($_POST['redirect_url']) ? esc_url_raw(wp_unslash($_POST['redirect_url'])) : '';
        if ($redirect_url === '') {
            $redirect_url = home_url('/');
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    public function render_form_shortcode(array $atts): string
    {
        $atts = shortcode_atts([
            'redirect_url' => home_url('/'),
            'button_text' => 'Check Employee Status',
        ], $atts, 'pima_employee_sealed_form');

        $flash_html = $this->consume_flash();

        ob_start();
        ?>
        <div style="max-width:420px;margin:1rem 0;">
            <?php echo $flash_html; ?>
            <form method="post">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                <input type="hidden" name="redirect_url" value="<?php echo esc_attr($atts['redirect_url']); ?>" />
                <input type="hidden" name="return_url" value="<?php echo esc_attr($this->current_page_url()); ?>" />

                <p>
                    <label for="pima_employee_email"><strong>Email:</strong></label><br />
                    <input
                        id="pima_employee_email"
                        type="email"
                        name="email"
                        required
                        placeholder="john.smith@pima.com"
                        style="width:100%;padding:6px;box-sizing:border-box;"
                    />
                </p>

                <p>
                    <button type="submit" name="<?php echo esc_attr(self::SUBMIT_NAME); ?>" value="1" style="padding:8px 14px;cursor:pointer;">
                        <?php echo esc_html($atts['button_text']); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    public function render_session_email_shortcode(): string
    {
        $email = isset($_SESSION[self::SESSION_EMAIL_KEY]) ? (string) $_SESSION[self::SESSION_EMAIL_KEY] : '';

        if ($email === '') {
            return '<p>No email found in session.</p>';
        }

        return '<p>Email from session: <strong>' . esc_html($email) . '</strong></p>';
    }

    private function set_flash(string $html): void
    {
        $_SESSION[self::SESSION_FLASH_KEY] = $html;
    }

    private function consume_flash(): string
    {
        if (!isset($_SESSION[self::SESSION_FLASH_KEY])) {
            return '';
        }

        $html = (string) $_SESSION[self::SESSION_FLASH_KEY];
        unset($_SESSION[self::SESSION_FLASH_KEY]);

        return $html;
    }

    private function redirect_to_return_url(): void
    {
        $return_url = isset($_POST['return_url']) ? esc_url_raw(wp_unslash($_POST['return_url'])) : '';
        if ($return_url === '') {
            $return_url = home_url('/');
        }

        wp_safe_redirect($return_url);
        exit;
    }

    private function current_page_url(): string
    {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';

        if ($host === '') {
            return home_url('/');
        }

        return $scheme . $host . $uri;
    }
}

new Pima_Employee_Sealed_Redirect_Plugin();
