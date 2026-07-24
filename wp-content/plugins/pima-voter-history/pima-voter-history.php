<?php
/**
 * Plugin Name: Pima Voter History
 * Description: Displays paginated voter history (5 rows per page) using the GetVoterHistory API and session voter ID.
 * Version: 1.0.0
 * Author: Local Dev
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Pima_Voter_History_Plugin
{
    private const API_URL      = 'https://pcro-sqlapi-dev.azurewebsites.net/api/Database/GetVoterHistory';
    private const TIMEOUT      = 20;
    private const PER_PAGE     = 5;
    private const SHORTCODE    = 'pima_voter_history';

    public function __construct()
    {
        add_shortcode(self::SHORTCODE, [$this, 'render_shortcode']);
    }

    /* ------------------------------------------------------------------ */
    /*  Shortcode renderer                                                */
    /* ------------------------------------------------------------------ */

    public function render_shortcode(): string
    {
        $raw = $_GET['voter_id'] ?? $_GET['voterId'] ?? '';
        $voter_id = preg_replace('/\D+/', '', (string) $raw);

        if ($voter_id === '') {
            return '<p>No voter ID found in session.</p>';
        }

        $elections = $this->fetch_voter_history($voter_id);

        if (is_wp_error($elections)) {
            return '<p style="color:red;">Could not load voter history: '
                . esc_html($elections->get_error_message())
                . '</p>';
        }

        if (empty($elections)) {
            return '<p>No election history found for this voter.</p>';
        }

        $total       = count($elections);
        $total_pages = (int) ceil($total / self::PER_PAGE);
        $current     = isset($_GET['vhpage']) ? max(1, min((int) $_GET['vhpage'], $total_pages)) : 1;
        $offset      = ($current - 1) * self::PER_PAGE;
        $page_items  = array_slice($elections, $offset, self::PER_PAGE);

        /* Build table rows */
        $rows = '';
        foreach ($page_items as $item) {
            $name = isset($item['election_Name']) ? esc_html((string) $item['election_Name']) : '';
            $date = '';
            if (!empty($item['election_Date'])) {
                $ts = strtotime((string) $item['election_Date']);
                $date = ($ts !== false) ? esc_html(wp_date('F j, Y', $ts)) : esc_html((string) $item['election_Date']);
            }
            $voted = isset($item['voted']) ? esc_html((string) $item['voted']) : '';

            $rows .= '<tr>'
                . '<td style="padding:8px;border-bottom:1px solid #ddd;">' . $name . '</td>'
                . '<td style="padding:8px;border-bottom:1px solid #ddd;white-space:nowrap;">' . $date . '</td>'
                . '<td style="padding:8px;border-bottom:1px solid #ddd;">' . $voted . '</td>'
                . '</tr>';
        }

        /* Build pagination links */
        $pagination = $this->build_pagination($current, $total_pages);

        /* Summary line */
        $first_row = $offset + 1;
        $last_row  = min($offset + self::PER_PAGE, $total);
        $summary   = '<p style="margin:0.5rem 0;font-size:0.9em;color:#555;">Showing '
            . esc_html("$first_row–$last_row")
            . ' of ' . esc_html((string) $total) . ' records</p>';

        return '<div style="margin:1rem 0;">'
            . '<h3 style="margin:0 0 0.75rem;">Voter Election History</h3>'
            . '<table style="width:100%;border-collapse:collapse;max-width:900px;">'
            . '<thead>'
            . '<tr>'
            . '<th style="text-align:left;padding:8px;border-bottom:2px solid #bbb;">Election</th>'
            . '<th style="text-align:left;padding:8px;border-bottom:2px solid #bbb;">Date</th>'
            . '<th style="text-align:left;padding:8px;border-bottom:2px solid #bbb;">Voted</th>'
            . '</tr>'
            . '</thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '</table>'
            . $summary
            . $pagination
            . '</div>';
    }

    /* ------------------------------------------------------------------ */
    /*  Pagination builder                                                */
    /* ------------------------------------------------------------------ */

    private function build_pagination(int $current, int $total_pages): string
    {
        if ($total_pages <= 1) {
            return '';
        }

        $base_url = remove_query_arg('vhpage');
        $raw = $_GET['voter_id'] ?? $_GET['voterId'] ?? '';
        $voter_id = preg_replace('/\D+/', '', (string) $raw);
        if ($voter_id !== '') {
            $base_url = add_query_arg('voterId', $voter_id, $base_url);
        }
        $links    = '';

        /* Previous */
        if ($current > 1) {
            $links .= '<a href="' . esc_url(add_query_arg('vhpage', $current - 1, $base_url)) . '" '
                . 'style="' . $this->page_link_style(false) . '">&laquo; Prev</a> ';
        }

        /* Page numbers */
        for ($i = 1; $i <= $total_pages; $i++) {
            $is_current = ($i === $current);
            $links .= '<a href="' . esc_url(add_query_arg('vhpage', $i, $base_url)) . '" '
                . 'style="' . $this->page_link_style($is_current) . '">'
                . esc_html((string) $i)
                . '</a> ';
        }

        /* Next */
        if ($current < $total_pages) {
            $links .= '<a href="' . esc_url(add_query_arg('vhpage', $current + 1, $base_url)) . '" '
                . 'style="' . $this->page_link_style(false) . '">Next &raquo;</a>';
        }

        return '<div style="margin:0.75rem 0;">' . $links . '</div>';
    }

    private function page_link_style(bool $is_current): string
    {
        $base = 'display:inline-block;padding:6px 12px;margin:0 2px;border-radius:4px;text-decoration:none;font-weight:600;';
        if ($is_current) {
            return $base . 'background:#0073aa;color:#fff;';
        }
        return $base . 'background:#f1f1f1;color:#0073aa;';
    }

    /* ------------------------------------------------------------------ */
    /*  API call                                                          */
    /* ------------------------------------------------------------------ */

    private function fetch_voter_history(string $voter_id)
    {
        $url = add_query_arg(['voterId' => $voter_id], self::API_URL);

        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'headers' => ['Accept' => 'text/plain'],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('pima_voter_history_http', 'Unexpected status code: ' . $code);
        }

        $payload = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($payload) || !isset($payload['item1']) || !is_array($payload['item1'])) {
            return new WP_Error('pima_voter_history_data', 'Invalid API payload.');
        }

        return $payload['item1'];
    }
}

new Pima_Voter_History_Plugin();
