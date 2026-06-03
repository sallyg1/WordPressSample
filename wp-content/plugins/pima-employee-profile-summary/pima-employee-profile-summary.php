<?php
/**
 * Plugin Name: Pima Employee Profile Summary
 * Description: Reads employee email from session and fetches profile summary from API.
 * Version: 1.0.0
 * Author: Local Dev
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Pima_Employee_Profile_Summary_Plugin
{
    private const PROFILE_SUMMARY_URL = 'http://localhost:5166/api/Database/employees/profile-summary';
    private const TIMEOUT = 15;
    private const SESSION_EMAIL_KEY = 'pima_employee_session_email';

    public function __construct()
    {
        add_action('init', [$this, 'maybe_start_session'], 1);
        add_shortcode('pima_employee_profile_summary', [$this, 'render_profile_summary_shortcode']);
    }

    public function maybe_start_session(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public function render_profile_summary_shortcode(): string
    {
        $email = isset($_SESSION[self::SESSION_EMAIL_KEY]) ? (string) $_SESSION[self::SESSION_EMAIL_KEY] : '';

        if ($email === '') {
            return '<p>No email found in session.</p>';
        }

        $url = add_query_arg('email', rawurlencode($email), self::PROFILE_SUMMARY_URL);

        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Accept' => 'text/plain',
            ],
        ]);

        if (is_wp_error($response)) {
            return '<p style="color:red;">Error contacting profile summary API: '
                . esc_html($response->get_error_message()) . '</p>';
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        if ($status_code < 200 || $status_code >= 300) {
            return '<p style="color:red;">Profile summary API returned status: '
                . esc_html((string) $status_code) . '</p>';
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            return '<pre style="white-space:pre-wrap;">' . esc_html(wp_json_encode($decoded, JSON_PRETTY_PRINT)) . '</pre>';
        }

        return '<pre style="white-space:pre-wrap;">' . esc_html($body) . '</pre>';
    }
}

new Pima_Employee_Profile_Summary_Plugin();
