<?php
/**
 * Plugin Name: Pima Employee Create API
 * Description: Collects employee details from users and sends a POST request to create a new employee.
 * Version: 1.0.0
 * Author: Local Dev
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Pima_Employee_Create_API_Plugin
{
    private const API_URL = 'http://localhost:5166/api/Database/employees';
    private const NONCE_ACTION = 'pima_employee_create_action';
    private const NONCE_NAME = 'pima_employee_create_nonce';

    public function __construct()
    {
        add_shortcode('pima_employee_create_form', [$this, 'render_shortcode']);
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
        return isset($_POST['pima_employee_create_submit']);
    }

    private function handle_submission(): string
    {
        if (!isset($_POST[self::NONCE_NAME]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)) {
            return '<p>Security check failed. Please try again.</p>';
        }

        $payload = $this->build_payload_from_post();

        if (is_wp_error($payload)) {
            return '<p>' . esc_html($payload->get_error_message()) . '</p>';
        }

        $response = wp_remote_post(self::API_URL, [
            'timeout' => 20,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return '<p>Unable to contact the API. Please try again later.</p>';
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code >= 200 && $status_code < 300) {
            return '<p>Employee created successfully.</p>';
        }

        return '<p>API error (' . esc_html((string) $status_code) . '). Response: ' . esc_html($body) . '</p>';
    }

    private function build_payload_from_post()
    {
        $first_name = $this->get_post_string('firstName');
        $last_name = $this->get_post_string('lastName');
        $email = $this->get_post_string('email');
        $phone = $this->get_post_string('phone');
        $department = $this->get_post_string('department');
        $job_title = $this->get_post_string('jobTitle');
        $hire_date = $this->get_post_string('hireDate');
        $salary_raw = $this->get_post_string('salary');
        $is_active = isset($_POST['isActive']);

        if ($first_name === '' || $last_name === '' || $email === '' || $department === '' || $job_title === '' || $hire_date === '' || $salary_raw === '') {
            return new WP_Error('missing_required', 'Please fill all required fields.');
        }

        if (!is_numeric($salary_raw)) {
            return new WP_Error('invalid_salary', 'Salary must be numeric.');
        }

        $now_utc = gmdate('c');

        return [
            'firstName' => $first_name,
            'lastName' => $last_name,
            'email' => $email,
            'phone' => $phone,
            'department' => $department,
            'jobTitle' => $job_title,
            'hireDate' => $hire_date,
            'salary' => (float) $salary_raw,
            'isActive' => $is_active,
            'createdAt' => $now_utc,
            'updatedAt' => $now_utc,
        ];
    }

    private function get_post_string(string $key): string
    {
        if (!isset($_POST[$key])) {
            return '';
        }

        return sanitize_text_field(wp_unslash($_POST[$key]));
    }

    private function render_form(): string
    {
        $html = '<form method="post" style="display:grid; gap:0.75rem; max-width:560px;">';
        $html .= wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME, true, false);
        $html .= $this->render_input('First Name', 'firstName', 'text', true);
        $html .= $this->render_input('Last Name', 'lastName', 'text', true);
        $html .= $this->render_input('Email', 'email', 'email', true);
        $html .= $this->render_input('Phone', 'phone', 'text', false);
        $html .= $this->render_input('Department', 'department', 'text', true);
        $html .= $this->render_input('Job Title', 'jobTitle', 'text', true);
        $html .= $this->render_input('Hire Date (ISO 8601)', 'hireDate', 'text', true, gmdate('c'));
        $html .= $this->render_input('Salary', 'salary', 'number', true, '0', '0.01');

        $checked = isset($_POST['isActive']) ? ' checked' : '';
        $html .= '<label><input type="checkbox" name="isActive" value="1"' . $checked . '> Is Active</label>';

        $html .= '<button type="submit" name="pima_employee_create_submit" value="1">Create Employee</button>';
        $html .= '</form>';

        return $html;
    }

    private function render_input(string $label, string $name, string $type, bool $required, string $default = '', string $step = ''): string
    {
        $value = isset($_POST[$name]) ? sanitize_text_field(wp_unslash($_POST[$name])) : $default;
        $required_attr = $required ? ' required' : '';
        $step_attr = $step !== '' ? ' step="' . esc_attr($step) . '"' : '';

        return '<label>' . esc_html($label) . ': <input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . $required_attr . $step_attr . '></label>';
    }
}

new Pima_Employee_Create_API_Plugin();
