<?php
/**
 * Plugin Name: Pima Employee API
 * Description: Minimal shortcode plugin that fetches employees by department from an external REST API.
 * Version: 1.0.0
 * Author: Local Dev
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Pima_Employee_API_Plugin
{
    private const BASE_URL = 'http://localhost:5166/api/Database/employees/department/';
    private const TIMEOUT = 10;

    public function __construct()
    {
        add_shortcode('pima_employees_by_department', [$this, 'render_shortcode']);
    }

    public function render_shortcode(array $atts): string
    {
        $atts = shortcode_atts([
            'department' => '',
            'show_form' => 'true',
        ], $atts, 'pima_employees_by_department');

        $department = $this->resolve_department($atts['department']);
        $show_form = filter_var($atts['show_form'], FILTER_VALIDATE_BOOLEAN);

        $output = '';

        if ($show_form) {
            $output .= $this->render_department_form($department);
        }

        if ($department === '') {
            $output .= '<p>Please enter a department name to fetch employees.</p>';
            return $output;
        }

        $response = $this->fetch_employees($department);

        if (is_wp_error($response)) {
            return $output . '<p>Unable to load employees right now. Please try again later.</p>';
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code < 200 || $status_code >= 300) {
            return $output . '<p>The API returned an unexpected response. Please try again later.</p>';
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            return $output . '<p>Received invalid data from the API.</p>';
        }

        return $output . $this->render_employee_output($department, $data);
    }

    private function resolve_department(string $default_from_shortcode): string
    {
        if (isset($_GET['department'])) {
            return sanitize_text_field(wp_unslash($_GET['department']));
        }

        return sanitize_text_field($default_from_shortcode);
    }

    private function render_department_form(string $department): string
    {
        $html = '<form method="get" style="margin: 1rem 0;">';
        $html .= '<label for="department-input">Department: </label>';
        $html .= '<input id="department-input" type="text" name="department" value="' . esc_attr($department) . '" placeholder="Finance" />';
        $html .= '<button type="submit">Load Employees</button>';
        $html .= '</form>';

        return $html;
    }

    private function fetch_employees(string $department)
    {
        $endpoint = rtrim(self::BASE_URL, '/') . '/' . rawurlencode($department);

        return wp_remote_get($endpoint, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);
    }

    private function render_employee_output(string $department, array $data): string
    {
        $count = count($data);
        $html = '<h3>Employees in ' . esc_html($department) . '</h3>';
        $html .= '<p>Found ' . esc_html((string) $count) . ' employee(s).</p>';

        if ($count === 0) {
            return $html . '<p>No employees found.</p>';
        }

        $html .= '<ul>';
        foreach ($data as $row) {
            if (is_array($row)) {
                $employee_name = isset($row['name']) ? (string) $row['name'] : wp_json_encode($row);
            } else {
                $employee_name = (string) $row;
            }

            $html .= '<li>' . esc_html($employee_name) . '</li>';
        }
        $html .= '</ul>';

        return $html;
    }

}

new Pima_Employee_API_Plugin();
