<?php
/**
 * Plugin Name: Pima Employee Update LastName API
 * Description: Accepts an employee ID and a new last name, then sends a PATCH request to update the record.
 * Version: 1.0.0
 * Author: Local Dev
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Pima_Employee_Update_LastName_Plugin
{
    private const API_BASE_URL = 'http://localhost:5166/api/Database/employees/';
    private const NONCE_ACTION = 'pima_employee_update_lastname_action';
    private const NONCE_NAME = 'pima_employee_update_lastname_nonce';

    public function __construct()
    {
        add_shortcode('pima_employee_update_lastname_form', [$this, 'render_shortcode']);
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
        return isset($_POST['pima_employee_update_lastname_submit']);
    }

    private function handle_submission(): string
    {
        if (
            !isset($_POST[self::NONCE_NAME]) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)
        ) {
            return '<p style="color:red;">Security check failed. Please try again.</p>';
        }

        $employee_id = isset($_POST['employee_id'])
            ? absint($_POST['employee_id'])
            : 0;

        $lastname = isset($_POST['lastname'])
            ? sanitize_text_field(wp_unslash($_POST['lastname']))
            : '';

        if ($employee_id <= 0) {
            return '<p style="color:red;">Please enter a valid numeric Employee ID.</p>';
        }

        if (empty($lastname)) {
            return '<p style="color:red;">Please enter a last name.</p>';
        }

        $url = self::API_BASE_URL . $employee_id . '/lastname';

        $response = wp_remote_request($url, [
            'method'  => 'PATCH',
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($lastname),
        ]);

        if (is_wp_error($response)) {
            return '<p style="color:red;">Error contacting the API: '
                . esc_html($response->get_error_message()) . '</p>';
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code >= 200 && $code < 300) {
            return '<p style="color:green;font-weight:bold;">Employee ID '
                . esc_html($employee_id) . ' last name updated to &ldquo;'
                . esc_html($lastname) . '&rdquo;.</p>';
        }

        return '<p style="color:red;">Failed to update Employee ID '
            . esc_html($employee_id) . '. API returned status ' . esc_html($code) . '.</p>';
    }

    private function render_form(): string
    {
        ob_start();
        ?>
        <form method="post">
            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
            <p>
                <label for="employee_id"><strong>Employee ID:</strong></label><br />
                <input type="number" id="employee_id" name="employee_id" min="1" required
                       style="width:200px;padding:6px;" />
            </p>
            <p>
                <label for="lastname"><strong>New Last Name:</strong></label><br />
                <input type="text" id="lastname" name="lastname" required
                       style="width:300px;padding:6px;" />
            </p>
            <p>
                <button type="submit" name="pima_employee_update_lastname_submit" value="1"
                        style="padding:8px 16px;cursor:pointer;">
                    Update Last Name
                </button>
            </p>
        </form>
        <?php
        return ob_get_clean();
    }
}

new Pima_Employee_Update_LastName_Plugin();
