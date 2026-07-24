<?php
/**
 * Plugin Name: Pima Voter Info API
 * Description: Validates voter information and displays voter ID and district assignments from the Pima REST API.
 * Version: 1.0.0
 * Author: Local Dev
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Pima_Voter_Info_API_Plugin
{
    private const API_URL     = 'https://pcro-sqlapi-dev.azurewebsites.net/api/Database/ValidateVoterInfoReturnVoterIdDistricts';
    private const TIMEOUT     = 15;
    private const NONCE_ACTION = 'pima_voter_info_action';
    private const NONCE_NAME   = 'pima_voter_info_nonce';

    public function __construct()
    {
        add_shortcode('pima_voter_info_form', [$this, 'render_shortcode']);
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
        return isset($_POST['pima_voter_info_submit']);
    }

    private function handle_submission(): string
    {
        if (
            !isset($_POST[self::NONCE_NAME]) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)
        ) {
            return '<p style="color:red;">Security check failed. Please try again.</p>';
        }

        $first_name = isset($_POST['firstName']) ? sanitize_text_field(wp_unslash($_POST['firstName'])) : '';
        $last_name  = isset($_POST['lastName'])  ? sanitize_text_field(wp_unslash($_POST['lastName']))  : '';
        $dob        = isset($_POST['dob'])        ? sanitize_text_field(wp_unslash($_POST['dob']))        : '';
        $az_id      = isset($_POST['azId'])       ? sanitize_text_field(wp_unslash($_POST['azId']))       : '';
        $ssn        = isset($_POST['ssn'])        ? sanitize_text_field(wp_unslash($_POST['ssn']))        : '';

        if ($first_name === '' || $last_name === '' || $dob === '') {
            return '<p style="color:red;">First name, last name, and date of birth are required.</p>';
        }

        if ($az_id === '' && $ssn === '') {
            return '<p style="color:red;">Please enter either an Arizona Voter ID or the last 4 digits of your SSN.</p>';
        }

        // Validate DOB format (YYYY-MM-DD)
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
            return '<p style="color:red;">Date of birth must be in YYYY-MM-DD format.</p>';
        }

        if ($ssn !== '' && !preg_match('/^\d{4}$/', $ssn)) {
            return '<p style="color:red;">SSN must be exactly 4 digits.</p>';
        }

        $url = add_query_arg(
            [
                'firstName' => rawurlencode($first_name),
                'lastName'  => rawurlencode($last_name),
                'dob'       => rawurlencode($dob),
                'azId'      => rawurlencode($az_id),
                'ssn'       => rawurlencode($ssn),
            ],
            self::API_URL
        );

        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'headers' => ['Accept' => 'text/plain'],
        ]);

        if (is_wp_error($response)) {
            return '<p style="color:red;">Error contacting the API: '
                . esc_html($response->get_error_message()) . '</p>';
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return '<p style="color:red;">The API returned an unexpected status: ' . esc_html($code) . '</p>';
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data) || !isset($data['item1'])) {
            return '<p style="color:red;">Received invalid data from the API.</p>';
        }

        return $this->render_results($data);
    }

    private function render_results(array $data): string
    {
        $voter_info   = $data['item1']['voterInfo']   ?? [];
        $district_info = $data['item1']['districtInfo'] ?? [];
        $item2        = $data['item2'] ?? null;

        ob_start();
        ?>
        <div style="margin:1.5rem 0;font-family:sans-serif;">

            <!-- Voter Info Card -->
            <div style="background:#f0f7f0;border:1px solid #c3dfc3;border-radius:6px;padding:1rem 1.25rem;margin-bottom:1.25rem;max-width:420px;">
                <h3 style="margin:0 0 0.75rem;color:#2a6a2a;">Voter Lookup Result</h3>
                <?php if (!empty($voter_info)) : ?>
                    <?php
                    $return_code = isset($voter_info['returnCode']) ? (int) $voter_info['returnCode'] : -1;
                    $voter_id    = isset($voter_info['returnVoterId']) ? (int) $voter_info['returnVoterId'] : 0;
                    $confidential = isset($voter_info['isConfidential']) ? (int) $voter_info['isConfidential'] : 0;
                    $message     = !empty($voter_info['returnMessage']) ? $voter_info['returnMessage'] : null;
                    ?>
                    <table style="width:100%;border-collapse:collapse;">
                        <tr>
                            <td style="padding:5px 10px 5px 0;font-weight:bold;white-space:nowrap;">Voter ID</td>
                            <td style="padding:5px 0;"><?php echo esc_html($voter_id > 0 ? $voter_id : 'Not found'); ?></td>
                        </tr>
                        <tr>
                            <td style="padding:5px 10px 5px 0;font-weight:bold;">Return Code</td>
                            <td style="padding:5px 0;"><?php echo esc_html($return_code); ?></td>
                        </tr>
                        <?php if ($message !== null) : ?>
                        <tr>
                            <td style="padding:5px 10px 5px 0;font-weight:bold;">Message</td>
                            <td style="padding:5px 0;"><?php echo esc_html($message); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td style="padding:5px 10px 5px 0;font-weight:bold;">Confidential</td>
                            <td style="padding:5px 0;"><?php echo $confidential ? '<span style="color:red;">Yes</span>' : 'No'; ?></td>
                        </tr>
                        <?php if ($item2 !== null) : ?>
                        <tr>
                            <td style="padding:5px 10px 5px 0;font-weight:bold;">Status Code</td>
                            <td style="padding:5px 0;"><?php echo esc_html($item2); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                <?php else : ?>
                    <p style="color:red;margin:0;">No voter info returned.</p>
                <?php endif; ?>
            </div>

            <!-- District Info Table -->
            <?php if (!empty($district_info)) : ?>
                <h3 style="margin:0 0 0.5rem;">District Assignments (<?php echo count($district_info); ?>)</h3>
                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:0.9rem;">
                        <thead>
                            <tr style="background:#e8e8e8;">
                                <th style="border:1px solid #ccc;padding:8px 12px;text-align:left;">Office Type</th>
                                <th style="border:1px solid #ccc;padding:8px 12px;text-align:left;">Code</th>
                                <th style="border:1px solid #ccc;padding:8px 12px;text-align:left;">Jurisdiction Name</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($district_info as $i => $district) : ?>
                                <tr style="background:<?php echo ($i % 2 === 0) ? '#fff' : '#fafafa'; ?>;">
                                    <td style="border:1px solid #ccc;padding:8px 12px;"><?php echo esc_html($district['office_Type'] ?? ''); ?></td>
                                    <td style="border:1px solid #ccc;padding:8px 12px;"><?php echo esc_html($district['code'] ?? ''); ?></td>
                                    <td style="border:1px solid #ccc;padding:8px 12px;"><?php echo esc_html($district['jurisdiction_Name'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <p>No district assignments found.</p>
            <?php endif; ?>

        </div>
        <?php
        return ob_get_clean();
    }

    private function render_form(): string
    {
        ob_start();
        ?>
        <form method="post" style="max-width:420px;margin:1rem 0;">
            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>

            <p>
                <label for="firstName"><strong>First Name:</strong></label><br />
                <input type="text" id="firstName" name="firstName" required
                       style="width:100%;padding:6px;box-sizing:border-box;" />
            </p>
            <p>
                <label for="lastName"><strong>Last Name:</strong></label><br />
                <input type="text" id="lastName" name="lastName" required
                       style="width:100%;padding:6px;box-sizing:border-box;" />
            </p>
            <p>
                <label for="dob"><strong>Date of Birth:</strong></label><br />
                <input type="date" id="dob" name="dob" required
                       style="width:100%;padding:6px;box-sizing:border-box;" />
            </p>
            <p style="margin:1rem 0 0.5rem;font-style:italic;">
                Enter <strong>either</strong> your Arizona Voter ID <strong>or</strong> the last 4 digits of your SSN.
            </p>
            <p>
                <label for="azId"><strong>Arizona Voter ID:</strong></label><br />
                <input type="text" id="azId" name="azId"
                       placeholder="e.g. D05043049"
                       style="width:100%;padding:6px;box-sizing:border-box;" />
            </p>
            <p>
                <label for="ssn"><strong>SSN (last 4 digits):</strong></label><br />
                <input type="password" id="ssn" name="ssn"
                       maxlength="4" pattern="\d{4}" placeholder="••••"
                       style="width:100%;padding:6px;box-sizing:border-box;" />
            </p>
            <p>
                <button type="button" id="pima-open-modal-btn"
                        style="padding:8px 20px;cursor:pointer;">
                    Validate Voter
                </button>
            </p>

            <!-- Hidden submit button for form submission -->
            <button type="submit" name="pima_voter_info_submit" value="1"
                    id="pima-hidden-submit" style="display:none;"></button>
        </form>

        <!-- Modal Popup -->
        <div id="pima-modal-overlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:8px;padding:2rem;max-width:400px;width:90%;box-shadow:0 4px 20px rgba(0,0,0,0.3);text-align:center;">
                <p style="margin:0 0 1.5rem;font-size:1.1rem;">Testing</p>
                <div style="display:flex;gap:1rem;justify-content:center;">
                    <button type="button" id="pima-modal-cancel"
                            style="padding:8px 20px;cursor:pointer;background:#ccc;border:1px solid #999;border-radius:4px;">
                        Cancel
                    </button>
                    <button type="button" id="pima-modal-process"
                            style="padding:8px 20px;cursor:pointer;background:#0073aa;color:#fff;border:none;border-radius:4px;font-weight:600;">
                        Process
                    </button>
                </div>
            </div>
        </div>

        <script>
        (function() {
            var overlay = document.getElementById('pima-modal-overlay');
            var openBtn = document.getElementById('pima-open-modal-btn');
            var cancelBtn = document.getElementById('pima-modal-cancel');
            var processBtn = document.getElementById('pima-modal-process');
            var hiddenSubmit = document.getElementById('pima-hidden-submit');

            openBtn.addEventListener('click', function() {
                overlay.style.display = 'flex';
            });

            cancelBtn.addEventListener('click', function() {
                overlay.style.display = 'none';
            });

            processBtn.addEventListener('click', function() {
                overlay.style.display = 'none';
                hiddenSubmit.click();
            });

            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) {
                    overlay.style.display = 'none';
                }
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}

new Pima_Voter_Info_API_Plugin();
