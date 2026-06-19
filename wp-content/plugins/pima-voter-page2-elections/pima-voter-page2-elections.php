<?php
/**
 * Plugin Name: Pima Voter Page 2 Elections
 * Description: Renders Page 2 election actions using session voter ID and online election APIs.
 * Version: 1.0.0
 * Author: Local Dev
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Pima_Voter_Page2_Elections_Plugin
{
    private const VOTER_RECORD_API_URL = 'https://pcro-sqlapi-dev.azurewebsites.net/api/Database/GetVoterRecord';
    private const EARLY_BALLOT_API_URL = 'https://pcro-sqlapi-dev.azurewebsites.net/api/Database/GetElectionEarlyBallot';
    private const ONLINE_ELECTION_INFO_API_URL = 'https://pcro-sqlapi-dev.azurewebsites.net/api/Database/GetOnlineElectionInfo';
    private const TIMEOUT = 20;
    private const SESSION_VOTER_ID_KEY = 'pima_voter_id';
    private const SHORTCODE = 'pima_voter_page2_elections';
    private const REQUEST_PARAMS_SHORTCODE = 'pima_request_ballot_params';
    private const REQUEST_BALLOT_PAGE_SLUG = 'request-ballot';

    public function __construct()
    {
        add_action('init', [$this, 'ensure_session'], 1);
        add_shortcode(self::SHORTCODE, [$this, 'render_shortcode']);
        add_shortcode(self::REQUEST_PARAMS_SHORTCODE, [$this, 'render_request_ballot_params']);
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
        $this->ensure_session();

        $voter_id = isset($_SESSION[self::SESSION_VOTER_ID_KEY])
            ? preg_replace('/\D+/', '', (string) $_SESSION[self::SESSION_VOTER_ID_KEY])
            : '';

        if ($voter_id === '') {
            return '<p>No voter ID found in session.</p>';
        }

        $voter_record = $this->get_voter_record($voter_id);
        $voter_summary_html = '';

        if (is_wp_error($voter_record)) {
            $voter_summary_html = '<p style="color:red;">Could not load voter record: '
                . esc_html($voter_record->get_error_message())
                . '</p>';
        } else {
            $voter_summary_html = $this->render_voter_summary($voter_record);
        }

        $current_early_ballot_elections = $this->get_early_ballot_elections();
        if (is_wp_error($current_early_ballot_elections)) {
            return $voter_summary_html . '<p style="color:red;">Could not load current early ballot elections: '
                . esc_html($current_early_ballot_elections->get_error_message())
                . '</p>';
        }

        $my_elections = $this->get_online_election_info($voter_id);
        if (is_wp_error($my_elections)) {
            return $voter_summary_html . '<p style="color:red;">Could not load your election information: '
                . esc_html($my_elections->get_error_message())
                . '</p>';
        }

        if (empty($my_elections)) {
            return $voter_summary_html . '<p>No election records found for this voter.</p>';
        }

        $early_ballot_by_election_no = [];
        foreach ($current_early_ballot_elections as $early_ballot_election) {
            $election_no = isset($early_ballot_election['election_no']) ? (int) $early_ballot_election['election_no'] : 0;
            if ($election_no > 0) {
                $early_ballot_by_election_no[$election_no] = $early_ballot_election;
            }
        }

        $now_ts = current_time('timestamp');
        $rows_html = [];

        foreach ($my_elections as $election_row) {
            $election_no = isset($election_row['election_no']) ? (int) $election_row['election_no'] : 0;
            if ($election_no <= 0 || !isset($early_ballot_by_election_no[$election_no])) {
                continue;
            }

            $early_ballot_election = $early_ballot_by_election_no[$election_no];
            $election_name = isset($election_row['election_Name']) ? (string) $election_row['election_Name'] : ('Election #' . $election_no);
            $early_ballot_end_raw = isset($early_ballot_election['earlyBallot_End_DT'])
                ? (string) $early_ballot_election['earlyBallot_End_DT']
                : '';

            $early_ballot_end_ts = strtotime($early_ballot_end_raw);
            if ($early_ballot_end_ts === false) {
                continue;
            }

            $formatted_end = wp_date('F j, Y g:i A', $early_ballot_end_ts);
            $action_html = '';

            if ($now_ts <= $early_ballot_end_ts) {
                $request_ballot_url = add_query_arg(
                    [
                        'voterId' => $voter_id,
                        'electionNo' => $election_no,
                    ],
                    $this->get_request_ballot_url()
                );

                $action_html = '<a href="' . esc_url($request_ballot_url) . '" '
                    . 'style="display:inline-block;padding:8px 14px;border-radius:4px;background:#0073aa;color:#fff;text-decoration:none;font-weight:600;">'
                    . 'Request'
                    . '</a>';
            } else {
                $action_html = '<strong>Online Ballot Request is not available after '
                    . esc_html($formatted_end)
                    . '</strong>';
            }

            $rows_html[] = '<tr>'
                . '<td style="padding:8px;border-bottom:1px solid #ddd;">' . esc_html($election_name) . '</td>'
                . '<td style="padding:8px;border-bottom:1px solid #ddd;white-space:nowrap;">' . esc_html((string) $election_no) . '</td>'
                . '<td style="padding:8px;border-bottom:1px solid #ddd;">' . $action_html . '</td>'
                . '</tr>';
        }

        if (empty($rows_html)) {
            return $voter_summary_html . '<p>No matching current early ballot elections were found for this voter.</p>';
        }

        return $voter_summary_html
            . '<div style="margin:1rem 0;">'
            . '<h3 style="margin:0 0 0.75rem;">Available Elections</h3>'
            . '<table style="width:100%;border-collapse:collapse;max-width:900px;">'
            . '<thead>'
            . '<tr>'
            . '<th style="text-align:left;padding:8px;border-bottom:2px solid #bbb;">Election</th>'
            . '<th style="text-align:left;padding:8px;border-bottom:2px solid #bbb;">Election No</th>'
            . '<th style="text-align:left;padding:8px;border-bottom:2px solid #bbb;">Action</th>'
            . '</tr>'
            . '</thead>'
            . '<tbody>' . implode('', $rows_html) . '</tbody>'
            . '</table>'
            . '</div>';
    }

    private function get_voter_record(string $voter_id)
    {
        $url = add_query_arg(
            [
                'voterId' => $voter_id,
            ],
            self::VOTER_RECORD_API_URL
        );

        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Accept' => 'text/plain',
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('pima_voter_record_http_error', 'Unexpected status code: ' . $code);
        }

        $payload = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($payload) || !isset($payload['item1']) || !is_array($payload['item1'])) {
            return new WP_Error('pima_voter_record_data_error', 'Invalid API payload for voter record.');
        }

        return $payload['item1'];
    }

    private function render_voter_summary(array $record): string
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

        return '<div style="margin:1rem 0;padding:1rem;border:1px solid #ddd;border-radius:6px;max-width:520px;background:#f9f9f9;">'
            . '<h3 style="margin:0 0 0.75rem;">Voter Record</h3>'
            . '<table style="width:100%;border-collapse:collapse;">'
            . '<tr><td style="padding:6px 10px 6px 0;font-weight:bold;white-space:nowrap;">Voter ID</td><td style="padding:6px 0;">' . esc_html((string) $voter_id) . '</td></tr>'
            . '<tr><td style="padding:6px 10px 6px 0;font-weight:bold;">Precinct Part</td><td style="padding:6px 0;">' . esc_html((string) $precinct_part) . '</td></tr>'
            . '<tr><td style="padding:6px 10px 6px 0;font-weight:bold;">Email Ballot</td><td style="padding:6px 0;">' . esc_html($is_email_ballot_text) . '</td></tr>'
            . '<tr><td style="padding:6px 10px 6px 0;font-weight:bold;">Modified Date</td><td style="padding:6px 0;">' . esc_html((string) $modified_date) . '</td></tr>'
            . '</table>'
            . '</div>';
    }

    public function render_request_ballot_params(): string
    {
        $voter_id = isset($_GET['voterId']) ? preg_replace('/\D+/', '', (string) wp_unslash($_GET['voterId'])) : '';
        $election_no = isset($_GET['electionNo']) ? preg_replace('/\D+/', '', (string) wp_unslash($_GET['electionNo'])) : '';

        if ($voter_id === '' || $election_no === '') {
            return '<p>Missing request parameters.</p>';
        }

        return '<div style="margin:1rem 0;padding:1rem;border:1px solid #ddd;border-radius:6px;max-width:520px;background:#f9f9f9;">'
            . '<p style="margin:0 0 0.5rem;"><strong>Voter ID:</strong> ' . esc_html($voter_id) . '</p>'
            . '<p style="margin:0;"><strong>Election No:</strong> ' . esc_html($election_no) . '</p>'
            . '</div>';
    }

    private function get_early_ballot_elections()
    {
        $response = wp_remote_get(self::EARLY_BALLOT_API_URL, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Accept' => 'text/plain',
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('pima_early_ballot_http_error', 'Unexpected status code: ' . $code);
        }

        $payload = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($payload) || !isset($payload['item1']) || !is_array($payload['item1'])) {
            return new WP_Error('pima_early_ballot_data_error', 'Invalid API payload for early ballot elections.');
        }

        return $payload['item1'];
    }

    private function get_online_election_info(string $voter_id)
    {
        $url = add_query_arg(
            [
                'voterId' => $voter_id,
            ],
            self::ONLINE_ELECTION_INFO_API_URL
        );

        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Accept' => 'text/plain',
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('pima_online_election_http_error', 'Unexpected status code: ' . $code);
        }

        $payload = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($payload) || !isset($payload['item1']) || !is_array($payload['item1'])) {
            return new WP_Error('pima_online_election_data_error', 'Invalid API payload for online election info.');
        }

        return $payload['item1'];
    }

    private function get_request_ballot_url(): string
    {
        $page = get_page_by_path(self::REQUEST_BALLOT_PAGE_SLUG);
        $url = $page instanceof WP_Post
            ? get_permalink($page)
            : home_url('/' . self::REQUEST_BALLOT_PAGE_SLUG . '/');

        return is_string($url) && $url !== '' ? $url : home_url('/');
    }
}

new Pima_Voter_Page2_Elections_Plugin();
