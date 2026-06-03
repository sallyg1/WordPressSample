<?php
/**
 * Plugin Name: Pima Voter Record Lookup
 * Description: Looks up a voter record by voter ID and displays selected fields.
 * Version: 1.0.0
 * Author: Local Dev
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Pima_Voter_Record_Lookup_Plugin
{
    private const API_URL = 'https://pcro-sqlapi-dev.azurewebsites.net/api/Database/GetVoterRecord';
    private const TIMEOUT = 20;
    private const NONCE_ACTION = 'pima_voter_record_lookup_action';
    private const NONCE_NAME = 'pima_voter_record_lookup_nonce';
    private const SESSION_VOTER_ID_KEY = 'pima_voter_id';
    private const PAGE2_SLUG = 'voterdashboard';

    public function __construct()
    {
        add_action('init', [$this, 'ensure_session'], 1);
        add_shortcode('pima_voter_record_lookup_form', [$this, 'render_shortcode']);
        add_shortcode('pima_voter_session_voter_id', [$this, 'render_session_voter_id']);
    }

    public function ensure_session(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        if (!headers_sent()) {
            session_start();
        }
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
        return isset($_POST['pima_voter_record_lookup_submit']);
    }

    private function handle_submission(): string
    {
        if (
            !isset($_POST[self::NONCE_NAME]) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)
        ) {
            return '<p style="color:red;">Security check failed. Please try again.</p>';
        }

        $voter_id_raw = isset($_POST['voterId']) ? wp_unslash($_POST['voterId']) : '';
        $voter_id = preg_replace('/\D+/', '', (string) $voter_id_raw);

        if ($voter_id === '') {
            return '<p style="color:red;">Please enter a valid voter ID.</p>';
        }

        $url = self::API_URL . '?voterId=' . rawurlencode((string) $voter_id);

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

        $record = $data['item1'];

        $is_confidential = !empty($record['is_Confidential']);
        if ($is_confidential) {
            return '<p style="color:red;font-weight:bold;">Your records are sealed.</p>';
        }

        $voter_id_from_api = isset($record['voter_Id']) ? (int) $record['voter_Id'] : 0;
        $is_valid = false;

        if (array_key_exists('isValid', $record)) {
            $is_valid = filter_var($record['isValid'], FILTER_VALIDATE_BOOLEAN);
        } else {
            $is_valid = $voter_id_from_api > 0;
        }

        if ($is_valid) {
            $_SESSION[self::SESSION_VOTER_ID_KEY] = (string) $voter_id_from_api;
            return $this->redirect_to_page2();
        }

        return '<p style="color:red;">Invalid information. Please try again.</p>';
    }

    private function redirect_to_page2(): string
    {
        $page = get_page_by_path(self::PAGE2_SLUG);
        $url = $page instanceof WP_Post ? get_permalink($page) : home_url('/' . self::PAGE2_SLUG . '/');

        if (is_string($url) && $url !== '') {
            return '<script>window.location.href=' . wp_json_encode($url) . ';</script>'
                . '<noscript><p><a href="' . esc_url($url) . '">Continue</a></p></noscript>';
        }

        return '<p style="color:red;">Could not determine redirect page URL.</p>';
    }

    public function render_session_voter_id(): string
    {
        $this->ensure_session();

        $session_voter_id = $_SESSION[self::SESSION_VOTER_ID_KEY] ?? '';
        if ($session_voter_id === '') {
            return '<p>No voter ID found in session.</p>';
        }

        return '<p><strong>Voter ID:</strong> ' . esc_html((string) $session_voter_id) . '</p>';
    }

    private function render_results(array $record): string
    {
        $voter_id = $record['voter_Id'] ?? '';
        $precinct_part = $record['precinct_Part'] ?? '';
        $is_email_ballot = $record['is_Email_Ballot'] ?? null;
        $modified_date = $record['modified_Date'] ?? '';

        $is_email_ballot_text = 'N/A';
        if (is_bool($is_email_ballot)) {
            $is_email_ballot_text = $is_email_ballot ? 'Yes' : 'No';
        }

        if (is_string($modified_date) && $modified_date !== '') {
            $timestamp = strtotime($modified_date);
            if ($timestamp !== false) {
                $modified_date = gmdate('Y-m-d H:i:s', $timestamp) . ' UTC';
            }
        }

        ob_start();
        ?>
        <div style="margin:1rem 0;padding:1rem;border:1px solid #ddd;border-radius:6px;max-width:520px;background:#f9f9f9;">
            <h3 style="margin:0 0 0.75rem;">Voter Record</h3>
            <table style="width:100%;border-collapse:collapse;">
                <tr>
                    <td style="padding:6px 10px 6px 0;font-weight:bold;white-space:nowrap;">Voter ID</td>
                    <td style="padding:6px 0;"><?php echo esc_html((string) $voter_id); ?></td>
                </tr>
                <tr>
                    <td style="padding:6px 10px 6px 0;font-weight:bold;">Precinct Part</td>
                    <td style="padding:6px 0;"><?php echo esc_html((string) $precinct_part); ?></td>
                </tr>
                <tr>
                    <td style="padding:6px 10px 6px 0;font-weight:bold;">Email Ballot</td>
                    <td style="padding:6px 0;"><?php echo esc_html($is_email_ballot_text); ?></td>
                </tr>
                <tr>
                    <td style="padding:6px 10px 6px 0;font-weight:bold;">Modified Date</td>
                    <td style="padding:6px 0;"><?php echo esc_html((string) $modified_date); ?></td>
                </tr>
            </table>
        </div>
        <?php

        return ob_get_clean();
    }

    private function render_form(): string
    {
        ob_start();
        ?>
        <form method="post" style="max-width:520px;margin:1rem 0;">
            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>

            <p>
                <label for="voterId"><strong>Voter ID:</strong></label><br />
                <input
                    type="text"
                    id="voterId"
                    name="voterId"
                    required
                    inputmode="numeric"
                    pattern="\d+"
                    placeholder="e.g. 2384347"
                    style="width:100%;padding:8px;box-sizing:border-box;"
                />
            </p>

            <p>
                <button type="submit" name="pima_voter_record_lookup_submit" value="1" style="padding:8px 18px;cursor:pointer;">
                    Look Up Voter
                </button>
            </p>
        </form>
        <?php

        return ob_get_clean();
    }
}

new Pima_Voter_Record_Lookup_Plugin();
