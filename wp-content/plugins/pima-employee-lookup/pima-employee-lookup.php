<?php
/**
 * Plugin Name: Pima Employee Lookup
 * Description: Looks up employee details or profile summary by email via two REST API endpoints.
 * Version: 1.1.0
 * Author: Local Dev
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Pima_Employee_Lookup_Plugin
{
    private const API_DETAILS = 'http://localhost:5166/api/Database/employees';
    private const API_SUMMARY = 'http://localhost:5166/api/Database/employees/profile-summary';
    private const TIMEOUT     = 15;
    private const NONCE       = 'pima_employee_lookup';

    private const BTN_DETAILS = 'btn_details';
    private const BTN_SUMMARY = 'btn_summary';

    public function __construct()
    {
        add_shortcode('pima_employee_lookup_form', [$this, 'render']);
    }

    public function render(): string
    {
        $result = '';

        if (isset($_POST[self::BTN_DETAILS])) {
            $result = $this->lookup(self::API_DETAILS, 'details');
        } elseif (isset($_POST[self::BTN_SUMMARY])) {
            $result = $this->lookup(self::API_SUMMARY, 'summary');
        }

        return $result . $this->form();
    }

    private function lookup(string $endpoint, string $kind): string
    {
        if (!check_admin_referer(self::NONCE, '_nonce')) {
            return $this->error('Security check failed. Please try again.');
        }

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        if (!is_email($email)) {
            return $this->error('Please enter a valid email address.');
        }

        $url = add_query_arg('email', rawurlencode($email), $endpoint);

        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            return $this->error('Error contacting the API: ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return $this->error('The API returned an unexpected status: ' . $code);
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return $this->error('Received invalid data from the API.');
        }
        if (empty($data)) {
            return '<p style="color:#a15c00;">No employee found for ' . esc_html($email) . '.</p>';
        }

        return $kind === 'summary'
            ? $this->render_cards($data, 'Profile Summary', '#2a6a2a', '#f0f7f0', '#c3dfc3', $this->summary_fields())
            : $this->render_cards($data, 'Employee Details', '#1f4e79', '#f4f8ff', '#c3d4e8', $this->detail_fields());
    }

    /** @return array<string, callable(array):string> */
    private function detail_fields(): array
    {
        return [
            'Employee ID' => fn($e) => (string) ($e['employeeId'] ?? ''),
            'First Name'  => fn($e) => (string) ($e['firstName']  ?? ''),
            'Last Name'   => fn($e) => (string) ($e['lastName']   ?? ''),
            'Email'       => fn($e) => (string) ($e['email']      ?? ''),
            'Phone'       => fn($e) => (string) ($e['phone']      ?? ''),
            'Department'  => fn($e) => (string) ($e['department'] ?? ''),
            'Job Title'   => fn($e) => (string) ($e['jobTitle']   ?? ''),
            'Hire Date'   => fn($e) => $this->fmt_date($e['hireDate']  ?? null),
            'Salary'      => fn($e) => isset($e['salary']) ? '$' . number_format((float) $e['salary'], 2) : '',
            'Active'      => fn($e) => !empty($e['isActive']) ? 'Yes' : 'No',
            'Created At'  => fn($e) => $this->fmt_date($e['createdAt'] ?? null),
            'Updated At'  => fn($e) => $this->fmt_date($e['updatedAt'] ?? null),
        ];
    }

    /** @return array<string, callable(array):string> */
    private function summary_fields(): array
    {
        return [
            'Employee ID'  => fn($e) => (string) ($e['employeeId']  ?? ''),
            'Full Name'    => fn($e) => (string) ($e['fullName']    ?? ''),
            'Initials'     => fn($e) => (string) ($e['initials']    ?? ''),
            'Email'        => fn($e) => (string) ($e['email']       ?? ''),
            'Email Domain' => fn($e) => (string) ($e['emailDomain'] ?? ''),
            'Department'   => fn($e) => (string) ($e['department']  ?? ''),
            'Job Title'    => fn($e) => (string) ($e['jobTitle']    ?? ''),
            'Tenure'       => fn($e) => isset($e['tenureYears']) ? number_format((float) $e['tenureYears'], 2) . ' yrs' : '',
            'Salary Band'  => fn($e) => (string) ($e['salaryBand']  ?? ''),
            'Active'       => fn($e) => !empty($e['isActive']) ? 'Yes' : 'No',
        ];
    }

    /**
     * @param array<int, array<string, mixed>>       $rows
     * @param array<string, callable(array):string>  $fields
     */
    private function render_cards(array $rows, string $title, string $accent, string $bg, string $border, array $fields): string
    {
        ob_start(); ?>
        <div style="margin:1.5rem 0;font-family:sans-serif;">
            <h3 style="margin:0 0 0.75rem;color:<?php echo esc_attr($accent); ?>;">
                <?php echo esc_html($title); ?> (<?php echo count($rows); ?>)
            </h3>
            <?php foreach ($rows as $emp) : ?>
                <div style="background:<?php echo esc_attr($bg); ?>;border:1px solid <?php echo esc_attr($border); ?>;
                            border-radius:6px;padding:1rem 1.25rem;margin-bottom:1rem;max-width:520px;">
                    <table style="width:100%;border-collapse:collapse;font-size:0.95rem;">
                        <?php foreach ($fields as $label => $getter) : ?>
                            <tr>
                                <td style="padding:5px 10px 5px 0;font-weight:bold;white-space:nowrap;vertical-align:top;">
                                    <?php echo esc_html($label); ?>
                                </td>
                                <td style="padding:5px 0;"><?php echo esc_html($getter($emp)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function form(): string
    {
        ob_start(); ?>
        <form method="post" style="max-width:420px;margin:1rem 0;font-family:sans-serif;">
            <?php wp_nonce_field(self::NONCE, '_nonce'); ?>

            <p>
                <label for="pel_email"><strong>Email:</strong></label><br />
                <input type="email" id="pel_email" name="email" required
                       placeholder="e.g. john.smith@pima.com"
                       style="width:100%;padding:6px;box-sizing:border-box;" />
            </p>

            <p style="display:flex;gap:0.75rem;flex-wrap:wrap;">
                <button type="submit" name="<?php echo esc_attr(self::BTN_DETAILS); ?>" value="1"
                        style="padding:8px 16px;cursor:pointer;background:#1f4e79;color:#fff;border:none;border-radius:4px;">
                    Get Employee Details
                </button>
                <button type="submit" name="<?php echo esc_attr(self::BTN_SUMMARY); ?>" value="1"
                        style="padding:8px 16px;cursor:pointer;background:#2a6a2a;color:#fff;border:none;border-radius:4px;">
                    Get Profile Summary
                </button>
            </p>
        </form>
        <?php
        return (string) ob_get_clean();
    }

    private function error(string $msg): string
    {
        return '<p style="color:red;">' . esc_html($msg) . '</p>';
    }

    private function fmt_date(?string $value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }
        $ts = strtotime($value);
        return $ts === false ? $value : gmdate('Y-m-d H:i', $ts);
    }
}

new Pima_Employee_Lookup_Plugin();
