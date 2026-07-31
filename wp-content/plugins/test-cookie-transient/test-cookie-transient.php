<?php
/**
 * Plugin Name: Voter Dashboard: information and elections page
 * Description: using session voter ID and online election APIs to display the voter's information, elections, districts.
 * If the voter is eligible to vote in an election, a "Online Request Ballot" button will appear; otherwise, a message saying "You are ineligible" will be displayed.
 * Version: 1.0.0
 * Author: My Ton
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Voter_Dashboard_Info_Elect_Dist_Plugin
{
    //This is for prod dev
/*  private const VOTER_RECORD_API_URL = 'https://pcro-sqlapi-dev.azurewebsites.net/api/Database/GetVoterRecord';
    private const EARLY_BALLOT_API_URL = 'https://pcro-sqlapi-dev.azurewebsites.net/api/Database/GetElectionEarlyBallot';
    private const VOTER_DISTRICTS_API_URL = 'https://pcro-sqlapi-dev.azurewebsites.net/api/Database/VoterDistrict/';
    private const ONLINE_ELECTION_INFO_API_URL = 'https://pcro-sqlapi-dev.azurewebsites.net/api/Database/GetOnlineElectionInfo';
*/

    //this is for staging
    private const VOTER_RECORD_API_URL = 'https://pcro-sqlapi-dev-staging-hsg0b6ceauhgdpbx.westus2-01.azurewebsites.net/api/Database/GetVoterRecord';
    private const EARLY_BALLOT_API_URL = 'https://pcro-sqlapi-dev-staging-hsg0b6ceauhgdpbx.westus2-01.azurewebsites.net/api/Database/GetElectionEarlyBallot';
    private const VOTER_DISTRICTS_API_URL = 'https://pcro-sqlapi-dev-staging-hsg0b6ceauhgdpbx.westus2-01.azurewebsites.net/api/Database/VoterDistrict';
    private const ONLINE_ELECTION_INFO_API_URL = 'https://pcro-sqlapi-dev-staging-hsg0b6ceauhgdpbx.westus2-01.azurewebsites.net/api/Database/GetOnlineElectionInfo';

    private const TIMEOUT = 25;
    private const SESSION_VOTER_ID_KEY = 'voter_id';
    private const SHORTCODE = 'Voter_Dashboard_Info_Elec_Dist';
    private const REQUEST_BALLOT_PAGE_SLUG = 'online-request-ballot';
    private const BALLOT_INFO_VOTING_HISTORY_PAGE_SLUG = 'ballot-info-voting-history';
    private $status;

    public function __construct()
    {
        add_shortcode(self::SHORTCODE, [$this, 'render_shortcode']);
    }

    public function render_shortcode(): string
    {
        $voter_id = class_exists('Voter_Dashboard_Login_Plugin')
            ? Voter_Dashboard_Login_Plugin::get_voter_id_from_token()
            : false;

        if ($voter_id === false) {
            return '<p>No voter ID found in session.</p>';
        }

        $voter_id = (string) $voter_id;

        if ($voter_id === '' || $voter_id === '0') {
            return '<p>No voter ID found in session.</p>';
        }

        //My information section
        $voter_record = $this->get_voter_record($voter_id);

        $voter_summary_html = '';

        if (is_wp_error($voter_record)) {
            $voter_summary_html = '<p style="color:red;">Could not load voter record: '
                . esc_html($voter_record->get_error_message())
                . '</p>';
        } else {
            $voter_summary_html = $this->render_voter_summary($voter_record);
        }

        //My Elections section
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
            return $voter_summary_html . '<div style="margin:1rem 0;padding:1rem;border:1px solid #ddd;border-radius:6px;background:#f9f9f9;">'
            . '<span style="font-weight: bold; font-size:24px">My Elections</span><br/>'
            .'<span><i> There are no current active elections. Be Registered and Ready for the next by reviewing your information above! </i></span>'
            .'</div>';
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
        $row_content = '';
        $found = false;
        if( count($my_elections) == 1 and $my_elections[0]['election_no']==1){

            $row_content ='<div style="margin:1rem 0;padding:1rem;border:1px solid #ddd;border-radius:6px;background:#f9f9f9;">'
            .'<span style="font-weight: bold; font-size:24px">My Elections</span><br />'
            .'<span><i>There are no current active elections. Be Registered and Ready for the next by reviewing your information above!</i></span>'
            .'</div>';

             return $voter_summary_html . wp_kses_post($row_content);

        }
        else {
            $row_content = '<span style="font-weight: bold; font-size:24px">My Elections</span><br/>'
                        .'<span><i> Elections are "open" in our database 93 days prior to Election Day and won\'t appear below until then. </i></span>';

            $rows_html[] =
                    '<tr> <td colspan="3" style="padding:8px;border-bottom:1px solid #ddd;">' . wp_kses_post($row_content) . '</td></tr>';

            foreach ($my_elections as $election_row){

                $election_no = isset($election_row['election_no']) ? (int) $election_row['election_no'] : 0;
                $election_name = isset($election_row['election_Name']) ? (string) $election_row['election_Name'] : '';
                $action_html = '';

                $isEligible= $election_row['is_Eligible']?? null;


                if ($election_no <= 0 || !isset($early_ballot_by_election_no[$election_no])) {

                    //not found
                     if (str_contains(strtoupper($election_name),'2025 CITY OF TUCSON PRIMARY ELECTION')){
                        if($isEligible==true)
                            $action_html = '<strong>You are eligible for this election, however, it is conducted by the City of Tucson. Please call 520-791-3221</strong>';
                        else
                            $action_html = '<strong>You are outside the district boundaries for this election. Please call 520-724-4330 if you believe this is an error.</strong>';
                    }
                    elseif(str_contains(strtolower($election_row['eligibility_Message']),'outside district')){
                        $action_html = '<strong>You are ineligible for the ' .$election_name
                        .'due to being outside district boundaries. Please call 520-724-4330 if you need further assistance.</strong>';
                    }
                    else {
                      /*comment out to test.  Need to add back
                        $action_html ='<strong> Online Ballot Request is not available.</strong>';
                     */

                     /* add to test request ballot page.  Will need to delete these codes*/
                        $request_ballot_url = add_query_arg(
                            [
                                'voterId' => $voter_id,
                                'electionNo' => $election_no,
                            ],
                                $this->get_request_ballot_url()
                        );
                        $action_html = '<a href="' . esc_url($request_ballot_url) . '" '
                            . 'style="display:inline-block;padding:8px 14px;border-radius:4px;background:#483491;color:#fff;text-decoration:none;font-weight:600;">'
                            . 'Online Ballot Request'
                            . '</a>';
                        /* End: add to test request ballot page.  Will need to delete these codes*/
                    }
                }
                else{ //found

                    $early_ballot_election = $early_ballot_by_election_no[$election_no];
                    $early_ballot_end_raw = isset($early_ballot_election['earlyBallot_End_DT'])
                        ? (string) $early_ballot_election['earlyBallot_End_DT']
                        : '';
                    $early_ballot_end_ts = strtotime($early_ballot_end_raw);

                    $formatted_end = wp_date('F j, Y g:i A', $early_ballot_end_ts);

                    if($isEligible==true) {
                        if (strtolower((string) $this->status) === 'na') {
                            $action_html ='<strong>The status of your registration needs attention. Please call us for assistance at 520-724-4330 </strong>';
                        }
                        else{
                            if ($now_ts <= $early_ballot_end_ts) {
                                $request_ballot_url = add_query_arg(
                                    [
                                        'voterId' => $voter_id,
                                        'electionNo' => $election_no,
                                    ],
                                        $this->get_request_ballot_url()
                                );

                                $action_html = '<a href="' . esc_url($request_ballot_url) . '" '
                                    . 'style="display:inline-block;padding:8px 14px;border-radius:4px;background:#483491;color:#fff;text-decoration:none;font-weight:600;">'
                                    . 'Online Ballot Request'
                                    . '</a>';
                            } else {
                                /*comment out to test.  Need to add back
                                $action_html = '<strong>Online Ballot Request is not available after '
                                    . esc_html($formatted_end)
                                    . '</strong>';
                                */
                                /* add to test request ballot page.  Will need to delete these codes*/
                                $request_ballot_url = add_query_arg(
                                    [
                                        'voterId' => $voter_id,
                                        'electionNo' => $election_no,
                                    ],
                                        $this->get_request_ballot_url()
                                );
                                $action_html = '<a href="' . esc_url($request_ballot_url) . '" '
                                    . 'style="display:inline-block;padding:8px 14px;border-radius:4px;background:#483491;color:#fff;text-decoration:none;font-weight:600;">'
                                    . 'Online Ballot Request'
                                    . '</a>';
                                 /* End: add to test request ballot page.  Will need to delete these codes*/

                            }
                        }
                    }
                    else{//not eligible
                        if (strtolower((string) $this->status) === 'i') {

                            if (str_contains(strtolower($election_row['eligibility_Message']),'outside district')){
                                $action_html = '<strong>The status of your registration needs attention. Please call us for assistance at 520-724-4330</strong>';
                            }
                            elseif(str_contains(strtolower($election_row['eligibility_Message']),'registered after cut-off')){
                                $action_html ='<strong>You are ineligible for the ' . esc_html($election_name)
                                .' due to registering after the voter registration deadline. Please call 520-724-4330 if you need further assistance </strong>';
                            }
                            //new cases for Marion
                            elseif (strtolower((string) $this->status) === 'party not eligible') {
                                $action_html = '<strong>You are not registered to a participating political party.</strong>';
                            }
                            elseif (strtolower((string) $this->status) === 'n' && $election_row['is_Federal'] === 'false') {
                                $action_html = '<strong>You are registered as a Federal Only voter and this is a local election.</strong>';
                            }
                            else
                                $action_html = '<strong> The status of your registration needs attention. Please call us for assistance at 520-724-4330.</strong>';
                        }
                        else{
                                $action_html = '<strong>The status of your registration needs attention. Please call us for assistance at 520-724-4330. </strong>';
                        }
                    }
                }

                $rows_html[] = '<tr>'
                    . '<td style="padding:8px;border-bottom:1px solid #ddd;">My upcoming election is: </td>'
                    . '<td style="padding:8px;border-bottom:1px solid #ddd;">' . esc_html($election_name) . '</td>'
                    . '</tr> <tr>'
                    . '<td style="padding:8px;border-bottom:1px solid #ddd;">Last day to request a ballot by mail for this election is: </td>'
                    . '<td style="padding:8px;border-bottom:1px solid #ddd;white-space:nowrap;">' . esc_html($formatted_end) . '</td>'
                    . '</tr> <tr>'
                    . '<td style="padding:8px;border-bottom:1px solid #ddd;white-space:nowrap;">' . esc_html($election_name) . '</td>'
                    . '<td style="padding:8px;border-bottom:1px solid #ddd;">' . $action_html . '</td>'
                    . '</tr>';
            }
        }

        if (empty($rows_html)) {
            return $voter_summary_html . '<div style="margin:1rem 0;padding:1rem;border:1px solid #ddd;border-radius:6px;background:#f9f9f9;">'
            .'<p>No matching current early ballot elections were found for this voter.</p>'
            .'</div>';
        }

        $voter_summary_html .=
            '<div style="margin:1rem 0;padding:1rem;border:1px solid #ddd;border-radius:6px;background:#f9f9f9;">'
            . '<table style="width:100%;border-collapse:collapse;max-width:900px;">'
            . '<tbody>' . implode('', $rows_html) . '</tbody>'
            . '</table>'
            . '</div>';

        //My districts section
        $my_districts= $this->get_voter_districts($voter_id);

        //my voting history
        //does not have voter id on url
       $my_voting_history_url = $this->get_voting_history_url();

        $action_html = '<a href="' . esc_url($my_voting_history_url) . '" '
        . 'style="display:inline-block;padding:8px 14px;border-radius:4px;background:#483491;color:#fff;text-decoration:none;font-weight:600;">'
        . 'Voting History'
        . '</a>';

        return $voter_summary_html . $my_districts . $action_html;
    }
    //end render_shortcode()

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
    // end get_voter_record

    private function render_voter_summary(array $record): string
    {
        $full_name= $record['full_Name'] ?? '';
        $voter_id = $record['voter_Id'] ?? '';
        $party= $record['party'] ?? '';
        $residential_Address = ($record['residential_Address_1'] ?? '') . ' '. ($record['residential_Address_2'] ?? '');
        $mailing_Address = ($record['mailing_Address_1'] ?? '') . ' '. ($record['mailing_Address_2'] ?? '');
        $uocava = strtolower($record['is__UOCAVA'] ?? '');
        $email = $record['email'] ?? '';
        $aevl = $record['is_Aevl'] ?? null;
        $registration_Date =$record['registration_Date'] ?? '';

        $precinct_part = $record['precinct_Part'] ?? '';
        $is_email_ballot = $record['is_Email_Ballot'] ?? null;
        $modified_date = $record['modified_Date'] ?? '';
        $this->status = $record['status'] ?? '';

        $uocava_is_Email_ballot ='';
        $uocava_is_Not_Email_ballot ='';

        $is_email_ballot_text = 'N/A';
        if (is_bool($is_email_ballot)) {
            $is_email_ballot_text = $is_email_ballot ? 'Yes' : 'No';
        }

        $uocava_text ='I\'m not on UOCAVA';
        if($uocava =='o')
        {
            $uocava_is_Email_ballot ='Yes, I am registered as a Overseas voter. I will receive my ballot by ' . $email;
            $uocava_is_Not_Email_ballot ='Yes, I am registered as a Overseas voter.';

            if (is_bool($is_email_ballot)) {
                $uocava_text = $is_email_ballot ?  $uocava_is_Email_ballot  :  $uocava_is_Not_Email_ballot;
             }
        }
        elseif($uocava =='m')
        {
            $uocava_is_Email_ballot ='Yes, I am registered as a Military voter. I will receive my ballot by ' . $email;
            $uocava_is_Not_Email_ballot ='Yes, I am registered as a Military voter.';

            if (is_bool($is_email_ballot)) {
                $uocava_text = $is_email_ballot ?  $uocava_is_Email_ballot  :  $uocava_is_Not_Email_ballot;
             }
        }

        $yes_aevl = 'Yes, I will automatically receive a Mail Ballot for every election I am eligible';
        $not_aevl ='No, I will vote in person or request a Mail Ballot for each Election';
        $aevl_text ='N/A';
        if(is_bool($aevl))
        {
            $aevl_text = $aevl ? $yes_aevl :   $not_aevl;
        }


        if (is_string($modified_date) && $modified_date !== '') {
            $timestamp = strtotime($modified_date);
            if ($timestamp !== false) {
                $modified_date = gmdate('Y-m-d H:i:s', $timestamp) . ' UTC';
            }
        }

        return '<div style="margin:1rem 0;padding:1rem;border:1px solid #ddd;border-radius:6px;background:#f9f9f9;">'
            . '<span style="font-weight: bold; font-size:24px">My Information</span><br/>'
            . '<table style="width:100%;border-collapse:collapse;">'
            . '<tr><td style="padding:6px 10px 6px 0;font-weight:bold; width: 35%; font-size: 16px;">Name</td><td style="padding:6px 0;">' . esc_html((string) $full_name) . '</td></tr>'
            . '<tr><td style="padding:6px 10px 6px 0;font-weight:bold; width: 35%; font-size: 16px;">Voter ID</td><td style="padding:6px 0;">' . esc_html((string) $voter_id) . '</td></tr>'
            . '<tr><td style="padding:6px 10px 6px 0;font-weight:bold; width: 35%; font-size: 16px;">Registration Status</td><td style="padding:6px 0;">' . esc_html((string)  $this->status) . '</td></tr>'
            . '<tr><td style="padding:6px 10px 6px 0;font-weight:bold; width: 35%; font-size: 16px;">Political Party</td><td style="padding:6px 0;">' . esc_html((string) $party) . '</td></tr>'
            . '<tr><td style="padding:6px 10px 6px 0;font-weight:bold; width: 35%; font-size: 16px;">Residential Address</td><td style="padding:6px 0;">' . esc_html((string) $residential_Address) . '</td></tr>'
            . '<tr><td style="padding:6px 10px 6px 0;font-weight:bold; width: 35%; font-size: 16px;">Mailing Address</td><td style="padding:6px 0;">' . esc_html((string) $mailing_Address) . '</td></tr>'
            . '<tr><td style="padding:6px 10px 6px 0;font-weight:bold; width: 35%; font-size: 16px;">Registered under the Uniformed and Overseas Citizens Absentee Voting Act (UOCAVA)</td><td style="padding:6px 0;">' . esc_html( $uocava_text) . '</td></tr>'
            . '<tr><td style="padding:6px 10px 6px 0;font-weight:bold; width: 35%; font-size: 16px;">On Active Early Voter List (AEVL)</td><td style="padding:6px 0;">' . esc_html( $aevl_text) . '</td></tr>'
            . '<tr><td style="padding:6px 10px 6px 0;font-weight:bold; width: 35%; font-size: 16px;">Registered Since</td><td style="padding:6px 0;">' . esc_html((string) $registration_Date) . '</td></tr>'
            . '<tr><td style="padding:6px 10px 6px 0;font-weight:bold; width: 35%; font-size: 16px;">Precinct Part</td><td style="padding:6px 0;">' . esc_html((string) $precinct_part) . '</td></tr>'
            . '<tr><td style="padding:6px 10px 6px 0;font-weight:bold; width: 35%; font-size: 16px;">Email Ballot</td><td style="padding:6px 0;">' . esc_html($is_email_ballot_text) . '</td></tr>'
            . '<tr><td style="padding:6px 10px 6px 0;font-weight:bold; width: 35%; font-size: 16px;">Modified Date</td><td style="padding:6px 0;">' . esc_html((string) $modified_date) . '</td></tr>'
            . '</table>'
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

    private function get_voting_history_url(): string
    {
        $page = get_page_by_path(self::BALLOT_INFO_VOTING_HISTORY_PAGE_SLUG);
        $url = $page instanceof WP_Post
            ? get_permalink($page)
            : home_url('/' . self::BALLOT_INFO_VOTING_HISTORY_PAGE_SLUG . '/');

        return is_string($url) && $url !== '' ? $url : home_url('/');
    }

   private function get_voter_districts(string $voter_id)
    {
         $url = add_query_arg(
            [
                'voterId' => $voter_id,
            ],
            self::VOTER_DISTRICTS_API_URL
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
            return new WP_Error('pima_voter_districts_http_error', 'Unexpected status code: ' . $code);
        }

        $payload = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($payload) || !isset($payload['item1']) || !is_array($payload['item1'])) {
            return new WP_Error('pima_voter_districts_data_error', 'Invalid API payload for voter districts.');
        }

       return $this->render_voter_district_output($voter_id, $payload);
    }

    private function render_voter_district_output(string $voter_id, array $data): string
    {
        $rows = [];
        if (isset($data['item1']) && is_array($data['item1'])) {
            $rows = $data['item1'];
        }

        $html ='<div style="margin:1rem 0;padding:1rem;border:1px solid #ddd;border-radius:6px;background:#f9f9f9;">'
               . '<span style="font-weight: bold; font-size:24px">Voter District</span><br/>';

        $html .= '<p>Found ' . esc_html((string) count($rows)) . ' district record(s).</p>';

        if (count($rows) === 0) {
            return $html . '<p>No district records found for this voter ID.</p>';
        }

        $html .= '<div style="overflow-x:auto; margin-top:12px;">';
        $html .= '<table style="border-collapse:collapse; width:100%; min-width:680px; background:#fff; border:1px solid #e5e7eb;">';
        $html .= '<thead><tr style="background:#f8fafc;">';
        $html .= '<th style="text-align:left; padding:10px; border:1px solid #e5e7eb;">Office Type</th>';
        $html .= '<th style="text-align:left; padding:10px; border:1px solid #e5e7eb;">Code</th>';
        $html .= '<th style="text-align:left; padding:10px; border:1px solid #e5e7eb;">Jurisdiction Name</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $office_type = isset($row['office_Type']) ? (string) $row['office_Type'] : '';
            $code = isset($row['code']) ? (string) $row['code'] : '';
            $jurisdiction_name = isset($row['jurisdiction_Name']) ? (string) $row['jurisdiction_Name'] : '';

            $html .= '<tr>';
            $html .= '<td style="padding:10px; border:1px solid #e5e7eb;">' . esc_html($office_type) . '</td>';
            $html .= '<td style="padding:10px; border:1px solid #e5e7eb;">' . esc_html($code) . '</td>';
            $html .= '<td style="padding:10px; border:1px solid #e5e7eb;">' . esc_html($jurisdiction_name) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div> </div>';

        if (!empty($data['item2']) || !empty($data['item3'])) {
            $html .= '<p>Note: Additional response items (item2/item3) were present.</p>';
        }

        return $html;
    }

}

new Voter_Dashboard_Info_Elect_Dist_Plugin();
