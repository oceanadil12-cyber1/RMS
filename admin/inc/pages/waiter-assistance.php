<?php
/**
 * Waiter Assistance: live view of active "Call the Waiter" requests.
 *
 * Polls every 5 seconds for active requests and shows them with a live timer.
 * Staff can resolve/dismiss each request once the waiter has attended.
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

function admin_page_waiter_assistance(): void
{
    $body = page_head('Waiter Assistance',
        'Active requests from dine-in guests. Requests disappear once resolved.')

    . '<div id="waiter-app" data-api="' . h(admin_url('', ['api' => 'waiter'])) . '">'
    . '<div id="waiter-list" class="waiter-grid">'
    . '<p class="muted" id="waiter-empty">No active requests. When a guest calls for a waiter, it will appear here.</p>'
    . '</div>'
    . '</div>';

    admin_render('Waiter Assistance', $body, 'waiter-assistance', ['waiter-assistance.js']);
}
