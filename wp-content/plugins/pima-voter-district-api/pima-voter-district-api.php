<?php
/**
 * Plugin Name: Pima Voter District API
 * Description: Looks up voter district data by voterId from the VoterDistrict API endpoint.
 * Version: 1.0.0
 * Author: Local Dev
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Pima_Voter_District_API_Plugin
{
    private const BASE_URL = 'https://pcro-sqlapi-dev.azurewebsites.net/api/Database/VoterDistrict/';
    private const TIMEOUT = 20;

    public function __construct()
    {
        add_shortcode('pima_voter_district_lookup', [$this, 'render_shortcode']);
    }

    public function render_shortcode(array $atts): string
    {
        $atts = shortcode_atts([
            'voter_id' => '',
            'show_form' => 'true',
        ], $atts, 'pima_voter_district_lookup');

        $voter_id = $this->resolve_voter_id($atts['voter_id']);
        $show_form = filter_var($atts['show_form'], FILTER_VALIDATE_BOOLEAN);

        $output = '';

        if ($show_form) {
            $output .= $this->render_lookup_form($voter_id);
        }

        if ($voter_id === '') {
            return $output . '<p>Please enter a voter ID to load district details.</p>';
        }

        $response = $this->fetch_voter_district($voter_id);

        if (is_wp_error($response)) {
            return $output . '<p>Unable to load voter district data right now. Please try again later.</p>';
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code < 200 || $status_code >= 300) {
            return $output . '<p>The API returned an unexpected response (status ' . esc_html((string) $status_code) . ').</p>';
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            return $output . '<p>Received invalid JSON from the API.</p>';
        }

        return $output . $this->render_voter_district_output($voter_id, $data);
    }

    private function resolve_voter_id(string $default_from_shortcode): string
    {
        if (isset($_GET['voterId'])) {
            return sanitize_text_field(wp_unslash($_GET['voterId']));
        }

        return sanitize_text_field($default_from_shortcode);
    }

    private function render_lookup_form(string $voter_id): string
    {
        $html = '<form method="get" style="margin: 1rem 0;">';
        $html .= '<label for="voter-id-input">Voter ID: </label>';
        $html .= '<input id="voter-id-input" type="text" name="voterId" value="' . esc_attr($voter_id) . '" placeholder="1938053" required />';
        $html .= '<button type="submit">Lookup Districts</button>';
        $html .= '</form>';

        return $html;
    }

    private function fetch_voter_district(string $voter_id)
    {
        $endpoint = rtrim(self::BASE_URL, '/') . '/' . rawurlencode($voter_id);

        return wp_remote_get($endpoint, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);
    }

    private function render_voter_district_output(string $voter_id, array $data): string
    {
        $rows = [];
        if (isset($data['item1']) && is_array($data['item1'])) {
            $rows = $data['item1'];
        }

        $html = '<h3>Voter District Details</h3>';
        $html .= '<p>Voter ID: ' . esc_html($voter_id) . '</p>';
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

        $html .= '</tbody></table></div>';

        if (!empty($data['item2']) || !empty($data['item3'])) {
            $html .= '<p>Note: Additional response items (item2/item3) were present.</p>';
        }

        return $html;
    }
}

new Pima_Voter_District_API_Plugin();
