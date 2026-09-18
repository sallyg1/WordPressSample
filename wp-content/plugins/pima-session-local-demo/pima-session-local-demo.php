<?php
/**
 * Plugin Name: Pima Session Local Demo (DO NOT SHIP)
 * Description: Local-only synthetic voter API responses and session test pages.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

register_activation_hook(__FILE__, function () {
    if (wp_get_environment_type() !== 'local') {
        wp_die('The voter demo requires a local WordPress environment.');
    }
    $pages = [
        'voter-dashboard-login' => ['Voter Login', '[voter_dashboard_login_form]'],
        'voter-dashboard-info-elec' => ['Voter Dashboard', '[pima_local_voter_dashboard]'],
    ];
    $created = [];
    foreach ($pages as $slug => $page) {
        if (!get_page_by_path($slug)) {
            $result = wp_insert_post([
                'post_type' => 'page', 'post_status' => 'publish',
                'post_name' => $slug, 'post_title' => $page[0], 'post_content' => $page[1],
            ], true);
            if (is_wp_error($result)) {
                wp_die(esc_html($result->get_error_message()));
            }
            $created[] = $result;
        }
    }
    update_option('pima_local_demo_page_ids', array_unique(array_merge(
        (array) get_option('pima_local_demo_page_ids', []), $created
    )));
    add_option('pima_local_demo_timeout', 60);
    flush_rewrite_rules();
});

if (wp_get_environment_type() !== 'local') {
    return;
}

add_filter('pima_voter_idle_seconds', function () {
    return max(10, (int) get_option('pima_local_demo_timeout', 60));
});

add_filter('pre_http_request', function ($response, $arguments, $url) {
    $parts = wp_parse_url($url);
    if (($parts['host'] ?? '') !== 'pcro-sqlapi-dev.azurewebsites.net'
        || ($parts['path'] ?? '') !== '/api/Database/ValidateVoterInfoReturnVoterIdDistricts') {
        return $response;
    }
    parse_str($parts['query'] ?? '', $query);
    $valid = ($query['firstName'] ?? '') === 'Demo'
        && ($query['lastName'] ?? '') === 'Voter'
        && ($query['dob'] ?? '') === '1990-01-01'
        && ($query['azId'] ?? '') === 'DEMO001';
    return [
        'headers' => [], 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [],
        'body' => wp_json_encode(['item1' => ['voterInfo' => [
            'returnVoterId' => $valid ? 900001 : 0,
            'isValid' => $valid, 'isConfidential' => false,
        ]]]),
    ];
}, 10, 3);

add_shortcode('pima_local_voter_dashboard', function () {
    if (!class_exists('Voter_Dashboard_Login_Plugin')
        || Voter_Dashboard_Login_Plugin::get_voter_id_from_token() === false) {
        return '<p>Please sign in.</p>';
    }
    return '<section><h2>Demo Voter</h2>'
        . do_shortcode('[voter_session_voter_id]')
        . '<p><strong>Election:</strong> Sample Municipal Election</p>'
        . '<p><strong>District:</strong> Demo District 01</p>'
        . '<p><label for="demo-notes">Notes</label><br><textarea id="demo-notes" rows="5" style="width:100%;max-width:36rem"></textarea></p>'
        . '<p><label><input type="checkbox"> Email reminders</label></p>'
        . '<p><button type="button" data-pima-logout>Log out</button></p></section>';
});