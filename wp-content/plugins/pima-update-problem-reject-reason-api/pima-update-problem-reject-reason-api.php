<?php
/**
 * Plugin Name: Pima Update Problem Reject Reason API
 * Description: Sends a PATCH request to update problem reject reason by ID and modifiedBy.
 * Version: 1.0.0
 * Author: Local Dev
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Pima_Update_Problem_Reject_Reason_Plugin
{
    private const API_URL = 'https://pcro-sqlapi-dev.azurewebsites.net/api/Database/updateProblemRejectReason';
    private const NONCE_ACTION = 'pima_update_problem_reject_reason_action';
    private const NONCE_NAME = 'pima_update_problem_reject_reason_nonce';

    public function __construct()
    {
        add_shortcode('pima_update_problem_reject_reason_form', [$this, 'render_shortcode']);
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
        return isset($_POST['pima_update_problem_reject_reason_submit']);
    }

    private function handle_submission(): string
    {
        if (
            !isset($_POST[self::NONCE_NAME]) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)
        ) {
            return '<p style="color:red;">Security check failed. Please try again.</p>';
        }

        $id = isset($_POST['problem_id']) ? absint($_POST['problem_id']) : 0;
        $modified_by = isset($_POST['modified_by'])
            ? sanitize_text_field(wp_unslash($_POST['modified_by']))
            : '';
        $reject_reason = isset($_POST['reject_reason'])
            ? sanitize_text_field(wp_unslash($_POST['reject_reason']))
            : '';

        if ($id <= 0) {
            return '<p style="color:red;">Please enter a valid numeric ID.</p>';
        }

        if ($modified_by === '') {
            return '<p style="color:red;">Please enter modifiedBy.</p>';
        }

        if ($reject_reason === '') {
            return '<p style="color:red;">Please enter a reject reason.</p>';
        }

        $url = add_query_arg([
            'id' => $id,
            'modifiedBy' => $modified_by,
        ], self::API_URL);

        $response = wp_remote_request($url, [
            'method' => 'PATCH',
            'timeout' => 20,
            'headers' => [
                'accept' => '*/*',
                'Content-Type' => 'application/json',
            ],
            // The API expects a JSON string value, not an object.
            'body' => wp_json_encode($reject_reason),
        ]);

        if (is_wp_error($response)) {
            return '<p style="color:red;">Error contacting the API: '
                . esc_html($response->get_error_message()) . '</p>';
        }

        $status = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);

        if ($status >= 200 && $status < 300) {
            $decoded = json_decode($raw_body, true);

            if (!is_array($decoded)) {
                return '<p style="color:green;font-weight:bold;">Updated reject reason for ID '
                    . esc_html((string) $id) . '.</p>';
            }

            $reason_id = isset($decoded['rejecT_REASON_ID']) ? (string) $decoded['rejecT_REASON_ID'] : (string) $id;
            $reason_text = isset($decoded['rejecT_REASON']) ? (string) $decoded['rejecT_REASON'] : $reject_reason;
            $response_modified_by = isset($decoded['modifieD_BY']) ? (string) $decoded['modifieD_BY'] : $modified_by;
            $response_modified_date = isset($decoded['modifieD_DATE']) ? (string) $decoded['modifieD_DATE'] : '';

            $message = '<p style="color:green;font-weight:bold;">Updated reject reason successfully.</p>';
            $message .= '<p><strong>Reason ID:</strong> ' . esc_html($reason_id) . '<br />';
            $message .= '<strong>Reject Reason:</strong> ' . esc_html($reason_text) . '<br />';
            $message .= '<strong>Modified By:</strong> ' . esc_html($response_modified_by);

            if ($response_modified_date !== '') {
                $message .= '<br /><strong>Modified Date:</strong> ' . esc_html($response_modified_date);
            }

            $message .= '</p>';

            return $message;
        }

        return '<p style="color:red;">Update failed. API returned status '
            . esc_html((string) $status) . '. Response: '
            . esc_html($raw_body) . '</p>';
    }

    private function render_form(): string
    {
        ob_start();
        ?>
        <form method="post">
            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
            <p>
                <label for="problem_id"><strong>ID:</strong></label><br />
                <input type="number" id="problem_id" name="problem_id" min="1" required style="width:200px;padding:6px;" />
            </p>
            <p>
                <label for="modified_by"><strong>Modified By:</strong></label><br />
                <input type="text" id="modified_by" name="modified_by" required style="width:280px;padding:6px;" />
            </p>
            <p>
                <label for="reject_reason"><strong>Reject Reason:</strong></label><br />
                <input type="text" id="reject_reason" name="reject_reason" required style="width:350px;padding:6px;" />
            </p>
            <p>
                <button type="submit" name="pima_update_problem_reject_reason_submit" value="1" style="padding:8px 16px;cursor:pointer;">
                    Update Reject Reason
                </button>
            </p>
        </form>
        <?php
        return ob_get_clean();
    }
}

new Pima_Update_Problem_Reject_Reason_Plugin();
