<?php
/**
 * POST api/contact.php  (JSON)  — contact messages and reservation requests.
 *
 * { "type": "contact"|"reservation", "name", "email", "phone", ... }
 * Saves every submission to the contacts or reservations table and emails
 * config notify_email when it is set.
 */
require_once __DIR__ . '/lib.php';

$in = read_form();
$type = ($in['type'] ?? '') === 'reservation' ? 'reservation' : 'contact';

$name  = clean_field($in['name'] ?? '', 80);
$email = clean_field($in['email'] ?? '', 120);
$phone = clean_field($in['phone'] ?? '', 30);
if ($name === '') fail('Please enter your name.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Please enter a valid email address.');

if ($type === 'reservation') {
    $date   = clean_field($in['date'] ?? '', 10);
    $time   = clean_field($in['time'] ?? '', 10);
    $guests = clean_field($in['guests'] ?? '', 10);
    $area   = clean_field($in['area'] ?? '', 40);
    $notes  = clean_field($in['notes'] ?? '', 1000);
    if (strlen(preg_replace('/\D/', '', $phone)) < 10) fail('Please enter a valid phone number.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < date('Y-m-d')) fail('Please choose a date from today onward.');
    if ($time === '' || $guests === '') fail('Please choose a time and party size.');
    $row = ['name' => $name, 'email' => $email, 'phone' => $phone, 'reservation_date' => $date, 'reservation_time' => $time, 'guests' => $guests, 'area' => $area, 'notes' => $notes, 'ip' => client_ip()];
    $saved = save_row('reservations', $row);
    $mailed = notify("Reservation request: $name, $date $time ($guests)", $row, $email);
} else {
    $subject = clean_field($in['subject'] ?? 'general', 40);
    $message = clean_field($in['message'] ?? '', 3000);
    if ($message === '') fail('Please enter a message.');
    $row = ['name' => $name, 'email' => $email, 'phone' => $phone, 'subject' => $subject, 'message' => $message, 'ip' => client_ip()];
    $saved = save_row('contacts', $row);
    $mailed = notify("Website message ($subject) from $name", $row, $email);
}

if (!$saved && !$mailed) fail('Sorry, we could not send that right now. Please call us at (954) 565-2526.', 500);
send_json(['ok' => true]);
