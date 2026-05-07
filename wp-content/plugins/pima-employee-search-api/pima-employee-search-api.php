<?php
/**
 * Plugin Name: Pima Employee Search API
 * Description: Displays all employees and filters by email search using the external REST API.
 * Version: 1.0.0
 * Author: Local Dev
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Pima_Employee_Search_API_Plugin
{
    private const API_URL = 'http://localhost:5166/api/Database/employees';
    private const TIMEOUT = 15;

    public function __construct()
    {
        add_shortcode('pima_employee_search', [$this, 'render_shortcode']);
    }

    public function render_shortcode(): string
    {
        $email_query = $this->get_email_filter();

        $url = self::API_URL;
        if ($email_query !== '') {
            $url = add_query_arg('email', rawurlencode($email_query), self::API_URL);
        }

        $response = wp_remote_get($url, ['timeout' => self::TIMEOUT]);

        $output  = $this->render_search_form($email_query);
        $output .= $this->render_results($response, $email_query);

        return $output;
    }

    private function get_email_filter(): string
    {
        if (isset($_GET['pima_email'])) {
            return sanitize_text_field(wp_unslash($_GET['pima_email']));
        }
        return '';
    }

    private function render_search_form(string $current_value): string
    {
        ob_start();
        ?>
        <form method="get" style="margin:1rem 0;">
            <?php
            // Preserve all other existing GET params (e.g. WordPress page slug)
            foreach ($_GET as $key => $val) {
                if ($key === 'pima_email') {
                    continue;
                }
                echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr(sanitize_text_field(wp_unslash($val))) . '" />';
            }
            ?>
            <label for="pima_email_input"><strong>Search by Email:</strong></label>&nbsp;
            <input
                type="text"
                id="pima_email_input"
                name="pima_email"
                value="<?php echo esc_attr($current_value); ?>"
                placeholder="e.g. gmail.com"
                style="width:250px;padding:6px;"
            />
            &nbsp;
            <button type="submit" style="padding:6px 14px;cursor:pointer;">Search</button>
            <?php if ($current_value !== '') : ?>
                &nbsp;
                <a href="<?php echo esc_url(remove_query_arg('pima_email')); ?>"
                   style="padding:6px 10px;text-decoration:none;">
                    Clear
                </a>
            <?php endif; ?>
        </form>
        <?php
        return ob_get_clean();
    }

    private function render_results($response, string $email_query): string
    {
        if (is_wp_error($response)) {
            return '<p style="color:red;">Error contacting the API: '
                . esc_html($response->get_error_message()) . '</p>';
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return '<p style="color:red;">The API returned an unexpected status: ' . esc_html($code) . '</p>';
        }

        $body      = wp_remote_retrieve_body($response);
        $employees = json_decode($body, true);

        if (!is_array($employees)) {
            return '<p style="color:red;">Received invalid data from the API.</p>';
        }

        if (empty($employees)) {
            $msg = $email_query !== ''
                ? 'No employees found matching <strong>' . esc_html($email_query) . '</strong>.'
                : 'No employees found.';
            return '<p>' . $msg . '</p>';
        }

        return $this->render_table($employees, $email_query, count($employees));
    }

    private function render_table(array $employees, string $email_query, int $total): string
    {
        // Derive column headers dynamically from first row's keys
        $columns = array_keys($employees[0]);

        $heading = $email_query !== ''
            ? 'Results for &ldquo;' . esc_html($email_query) . '&rdquo; &mdash; ' . $total . ' record(s)'
            : 'All Employees &mdash; ' . $total . ' record(s)';

        ob_start();
        ?>
        <p><em><?php echo wp_kses_post($heading); ?></em></p>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.9rem;">
                <thead>
                    <tr style="background:#f0f0f0;">
                        <?php foreach ($columns as $col) : ?>
                            <th style="border:1px solid #ccc;padding:8px 12px;text-align:left;">
                                <?php echo esc_html(ucwords(str_replace('_', ' ', $col))); ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employees as $i => $emp) : ?>
                        <tr style="background:<?php echo ($i % 2 === 0) ? '#fff' : '#fafafa'; ?>;">
                            <?php foreach ($columns as $col) : ?>
                                <td style="border:1px solid #ccc;padding:8px 12px;">
                                    <?php echo esc_html(isset($emp[$col]) ? (string) $emp[$col] : ''); ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
}

new Pima_Employee_Search_API_Plugin();
