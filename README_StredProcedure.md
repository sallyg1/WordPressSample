<?php
function pima_department_search_form() {
    ob_start();
    ?>
    <form method="GET" action="">
        <?php wp_nonce_field('pima_dept_search', 'pima_nonce', false); ?>
        <label>Department: <input type="text" name="department" required></label>
        <button type="submit">Search</button>
    </form>
    <?php

    if (isset($_GET['department']) && isset($_GET['pima_nonce'])) {
        if (!wp_verify_nonce($_GET['pima_nonce'], 'pima_dept_search')) {
            echo '<p>Security check failed.</p>';
            return ob_get_clean();
        }

        $department = sanitize_text_field($_GET['department']);
        $api_url = 'https://your-api-domain.com/api/database/employees/department/' . rawurlencode($department);

        $response = wp_remote_get($api_url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            echo '<p>Error: Could not reach the API.</p>';
            error_log('API request failed: ' . $response->get_error_message());
            return ob_get_clean();
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code === 200) {
            $employees = json_decode($body, true);

            if (empty($employees)) {
                echo '<p>No employees found in department: ' . esc_html($department) . '</p>';
            } else {
                echo '<h3>Employees in ' . esc_html($department) . '</h3>';
                echo '<table border="1" cellpadding="5">';
                echo '<tr><th>Name</th><th>Email</th><th>Job Title</th><th>Hire Date</th></tr>';
                foreach ($employees as $emp) {
                    echo '<tr>';
                    echo '<td>' . esc_html($emp['firstName'] . ' ' . $emp['lastName']) . '</td>';
                    echo '<td>' . esc_html($emp['email']) . '</td>';
                    echo '<td>' . esc_html($emp['jobTitle']) . '</td>';
                    echo '<td>' . esc_html(date('M j, Y', strtotime($emp['hireDate']))) . '</td>';
                    echo '</tr>';
                }
                echo '</table>';
            }
        } else {
            echo '<p>API error (status ' . intval($status_code) . ').</p>';
            error_log("API returned status $status_code: $body");
        }
    }

    return ob_get_clean();
}
add_shortcode('department_search', 'pima_department_search_form');