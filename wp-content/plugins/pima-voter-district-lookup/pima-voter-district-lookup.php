<?php
/**
 * Plugin Name: Pima Voter District Lookup
 * Description: Two-step voter lookup — validate voter to get Voter ID, then fetch district assignments.
 * Version: 1.0.0
 * Author: Local Dev
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Pima_Voter_District_Lookup_Plugin
{
    private const API_VALIDATE  = 'https://localhost:7124/api/Database/ValidateVoterReturnVoterId';
    private const API_DISTRICTS = 'https://localhost:7124/api/Database/VoterDistrict';
    private const TIMEOUT       = 15;
    private const NONCE         = 'pima_voter_district_lookup';

    private const BTN_VALIDATE  = 'btn_validate';
    private const BTN_DISTRICTS = 'btn_districts';

    public function __construct()
    {
        add_shortcode('pima_voter_district_form', [$this, 'render']);
    }

    public function render(): string
    {
        $voter_id      = 0;
        $voter_message = '';
        $districts_html = '';

        if (isset($_POST[self::BTN_VALIDATE])) {
            [$voter_id, $voter_message] = $this->validate_voter();
        } elseif (isset($_POST[self::BTN_DISTRICTS])) {
            $voter_id       = $this->posted_voter_id();
            $districts_html = $this->fetch_districts($voter_id);
        }

        return $this->form()
            . $voter_message
            . ($voter_id > 0 ? $this->districts_step($voter_id) : '')
            . $districts_html;
    }

    /** @return array{0:int,1:string} [voterId, html message] */
    private function validate_voter(): array
    {
        if (!check_admin_referer(self::NONCE, '_nonce')) {
            return [0, $this->error('Security check failed. Please try again.')];
        }

        $first = isset($_POST['firstName']) ? sanitize_text_field(wp_unslash($_POST['firstName'])) : '';
        $last  = isset($_POST['lastName'])  ? sanitize_text_field(wp_unslash($_POST['lastName']))  : '';
        $dob   = isset($_POST['dob'])       ? sanitize_text_field(wp_unslash($_POST['dob']))       : '';
        $azId  = isset($_POST['azId'])      ? sanitize_text_field(wp_unslash($_POST['azId']))      : '';
        $ssn   = isset($_POST['ssn'])       ? sanitize_text_field(wp_unslash($_POST['ssn']))       : '';

        if ($first === '' || $last === '' || $dob === '' || $azId === '' || $ssn === '') {
            return [0, $this->error('All fields are required.')];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
            return [0, $this->error('Date of birth must be in YYYY-MM-DD format.')];
        }

        $url = add_query_arg([
            'firstName' => $first,
            'lastName'  => $last,
            'dob'       => $dob,
            'azId'      => $azId,
            'ssn'       => $ssn,
        ], self::API_VALIDATE);

        $data = $this->get_json($url);
        if (is_string($data)) {
            return [0, $data]; // error html
        }

        $info         = $data['item1'] ?? [];
        $voter_id     = (int) ($info['returnVoterId'] ?? 0);
        $return_code  = (int) ($info['returnCode']    ?? -1);
        $message      = $info['returnMessage'] ?? null;
        $confidential = (int) ($info['isConfidential'] ?? 0);

        return [$voter_id, $this->render_voter_card($voter_id, $return_code, $message, $confidential)];
    }

    private function fetch_districts(int $voter_id): string
    {
        if (!check_admin_referer(self::NONCE, '_nonce')) {
            return $this->error('Security check failed. Please try again.');
        }
        if ($voter_id <= 0) {
            return $this->error('Missing voter ID for district lookup.');
        }

        $url = self::API_DISTRICTS . '/' . rawurlencode((string) $voter_id);
        $data = $this->get_json($url);
        if (is_string($data)) {
            return $data;
        }

        $districts = $data['item1'] ?? [];
        if (!is_array($districts) || empty($districts)) {
            return '<p>No district assignments found.</p>';
        }

        return $this->render_districts($districts);
    }

    private function posted_voter_id(): int
    {
        return isset($_POST['voterId']) ? (int) $_POST['voterId'] : 0;
    }

    /**
     * Performs a GET, validates HTTP + JSON. Returns decoded array on success
     * or an HTML error string on failure.
     *
     * @return array<string, mixed>|string
     */
    private function get_json(string $url)
    {
        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'headers' => ['Accept' => 'application/json'],
            'sslverify' => false, // local dev cert (https://localhost:7124)
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
        return $data;
    }

    private function render_voter_card(int $voter_id, int $return_code, ?string $message, int $confidential): string
    {
        ob_start(); ?>
        <div style="background:#f0f7f0;border:1px solid #c3dfc3;border-radius:6px;
                    padding:1rem 1.25rem;margin:1rem 0;max-width:420px;font-family:sans-serif;">
            <h3 style="margin:0 0 0.75rem;color:#2a6a2a;">Voter Lookup Result</h3>
            <table style="width:100%;border-collapse:collapse;">
                <tr>
                    <td style="padding:5px 10px 5px 0;font-weight:bold;">Voter ID</td>
                    <td><?php echo esc_html($voter_id > 0 ? (string) $voter_id : 'Not found'); ?></td>
                </tr>
                <tr>
                    <td style="padding:5px 10px 5px 0;font-weight:bold;">Return Code</td>
                    <td><?php echo esc_html((string) $return_code); ?></td>
                </tr>
                <?php if ($message !== null && $message !== '') : ?>
                <tr>
                    <td style="padding:5px 10px 5px 0;font-weight:bold;">Message</td>
                    <td><?php echo esc_html($message); ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td style="padding:5px 10px 5px 0;font-weight:bold;">Confidential</td>
                    <td><?php echo $confidential ? '<span style="color:red;">Yes</span>' : 'No'; ?></td>
                </tr>
            </table>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /** @param array<int, array<string, mixed>> $districts */
    private function render_districts(array $districts): string
    {
        ob_start(); ?>
        <div style="margin:1rem 0;font-family:sans-serif;">
            <h3 style="margin:0 0 0.5rem;">District Assignments (<?php echo count($districts); ?>)</h3>
            <div style="overflow-x:auto;">
                <table style="width:100%;max-width:640px;border-collapse:collapse;font-size:0.9rem;">
                    <thead>
                        <tr style="background:#e8e8e8;">
                            <th style="border:1px solid #ccc;padding:8px 12px;text-align:left;">Office Type</th>
                            <th style="border:1px solid #ccc;padding:8px 12px;text-align:left;">Code</th>
                            <th style="border:1px solid #ccc;padding:8px 12px;text-align:left;">Jurisdiction Name</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($districts as $i => $d) : ?>
                            <tr style="background:<?php echo ($i % 2 === 0) ? '#fff' : '#fafafa'; ?>;">
                                <td style="border:1px solid #ccc;padding:8px 12px;"><?php echo esc_html($d['office_Type']       ?? ''); ?></td>
                                <td style="border:1px solid #ccc;padding:8px 12px;"><?php echo esc_html($d['code']              ?? ''); ?></td>
                                <td style="border:1px solid #ccc;padding:8px 12px;"><?php echo esc_html($d['jurisdiction_Name'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function districts_step(int $voter_id): string
    {
        ob_start(); ?>
        <form method="post" style="margin:0.5rem 0 1.5rem;font-family:sans-serif;">
            <?php wp_nonce_field(self::NONCE, '_nonce'); ?>
            <input type="hidden" name="voterId" value="<?php echo esc_attr((string) $voter_id); ?>" />
            <button type="submit" name="<?php echo esc_attr(self::BTN_DISTRICTS); ?>" value="1"
                    style="padding:8px 16px;cursor:pointer;background:#1f4e79;color:#fff;border:none;border-radius:4px;">
                Get District Assignments for Voter ID <?php echo esc_html((string) $voter_id); ?>
            </button>
        </form>
        <?php
        return (string) ob_get_clean();
    }

    private function form(): string
    {
        $first = isset($_POST['firstName']) ? sanitize_text_field(wp_unslash($_POST['firstName'])) : '';
        $last  = isset($_POST['lastName'])  ? sanitize_text_field(wp_unslash($_POST['lastName']))  : '';
        $dob   = isset($_POST['dob'])       ? sanitize_text_field(wp_unslash($_POST['dob']))       : '';
        $azId  = isset($_POST['azId'])      ? sanitize_text_field(wp_unslash($_POST['azId']))      : '';

        ob_start(); ?>
        <form method="post" style="max-width:420px;margin:1rem 0;font-family:sans-serif;">
            <?php wp_nonce_field(self::NONCE, '_nonce'); ?>

            <p>
                <label for="pvd_first"><strong>First Name:</strong></label><br />
                <input type="text" id="pvd_first" name="firstName" required value="<?php echo esc_attr($first); ?>"
                       style="width:100%;padding:6px;box-sizing:border-box;" />
            </p>
            <p>
                <label for="pvd_last"><strong>Last Name:</strong></label><br />
                <input type="text" id="pvd_last" name="lastName" required value="<?php echo esc_attr($last); ?>"
                       style="width:100%;padding:6px;box-sizing:border-box;" />
            </p>
            <p>
                <label for="pvd_dob"><strong>Date of Birth:</strong></label><br />
                <input type="date" id="pvd_dob" name="dob" required value="<?php echo esc_attr($dob); ?>"
                       style="width:100%;padding:6px;box-sizing:border-box;" />
            </p>
            <p>
                <label for="pvd_az"><strong>Arizona Voter ID:</strong></label><br />
                <input type="text" id="pvd_az" name="azId" required placeholder="e.g. D05043049" value="<?php echo esc_attr($azId); ?>"
                       style="width:100%;padding:6px;box-sizing:border-box;" />
            </p>
            <p>
                <label for="pvd_ssn"><strong>SSN (last 4 digits):</strong></label><br />
                <input type="password" id="pvd_ssn" name="ssn" required maxlength="4" pattern="\d{4}" placeholder="••••"
                       style="width:100%;padding:6px;box-sizing:border-box;" />
            </p>
            <p>
                <button type="submit" name="<?php echo esc_attr(self::BTN_VALIDATE); ?>" value="1"
                        style="padding:8px 20px;cursor:pointer;background:#2a6a2a;color:#fff;border:none;border-radius:4px;">
                    Get Voter ID
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
}

new Pima_Voter_District_Lookup_Plugin();
