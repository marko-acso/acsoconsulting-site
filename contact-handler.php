<?php
/**
 * ACSO Consulting — BDS Contact Form Handler
 * Receives POST with name, email, company, phone, context.
 * Sends admin notification and sender confirmation.
 */

// ── Config ──────────────────────────────────────────────────────────────
define('ADMIN_EMAIL', 'info@acsoconsulting.com');
define('SITE_NAME',   'ACSO Consulting');

header('Content-Type: application/json; charset=utf-8');

// ── Origin check ───────────────────────────────────────────────────────
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$allowed = [
    'https://acsoconsulting.com',
    'https://www.acsoconsulting.com',
    'http://acsoconsulting.com',
    'http://www.acsoconsulting.com',
];
$originOk = false;
foreach ($allowed as $a) {
    if ($origin === $a || str_starts_with($referer, $a)) { $originOk = true; break; }
}
if (!$originOk) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request origin.']);
    exit;
}

// ── Rate limiting (5 per IP per hour) ──────────────────────────────────
$rateDir = sys_get_temp_dir() . '/acso_bds_rate/';
if (!is_dir($rateDir)) mkdir($rateDir, 0755, true);
$rateFile = $rateDir . md5($_SERVER['REMOTE_ADDR'] ?? '0') . '.json';
$rateData = file_exists($rateFile) ? json_decode(file_get_contents($rateFile), true) : [];
$rateData = array_values(array_filter($rateData, fn($t) => time() - $t < 3600));
if (count($rateData) >= 5) {
    echo json_encode(['success' => false, 'message' => 'Too many submissions. Please try again later.']);
    exit;
}
$rateData[] = time();
file_put_contents($rateFile, json_encode($rateData), LOCK_EX);

// ── Anti-bot: honeypot ─────────────────────────────────────────────────
if (!empty($_POST['website'])) {
    http_response_code(204);
    exit;
}

// ── Collect & validate fields ──────────────────────────────────────────
$name    = str_replace(["\r", "\n", "\t"], '', trim($_POST['name']    ?? ''));
$email   = trim($_POST['email']   ?? '');
$company = str_replace(["\r", "\n", "\t"], '', trim($_POST['company'] ?? ''));
$phone   = str_replace(["\r", "\n", "\t"], '', trim($_POST['phone']   ?? ''));
$context = trim($_POST['context'] ?? '');

$errors = [];
if ($name === '')    $errors[] = 'Name is required.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
if ($company === '') $errors[] = 'Company is required.';

if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

$ts = date('Y-m-d H:i:s');

// ── Email to admin ─────────────────────────────────────────────────────
$adminSubject = "BDS inquiry from $company ($name)";

$adminBody = "New BDS inquiry.\n\n"
    . "Name: $name\n"
    . "Email: $email\n"
    . "Company: $company\n"
    . "Phone: " . ($phone ?: 'not provided') . "\n"
    . "Time: $ts\n\n"
    . "Context:\n"
    . "----------\n"
    . ($context ?: '—') . "\n"
    . "----------\n\n"
    . "Reply directly to this email to respond to $name.\n";

$adminHeaders = "From: ACSO BDS <info@acsoconsulting.com>\r\n"
    . "Reply-To: $name <$email>\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n";

@mail(ADMIN_EMAIL, $adminSubject, $adminBody, $adminHeaders);

// ── Confirmation email to sender ───────────────────────────────────────
$senderSubject = "We received your message — ACSO Consulting";

$senderBody = "Dear $name,\n\n"
    . "Thank you for reaching out. We received your inquiry and will be in touch within one business day.\n\n"
    . "If anything else comes to mind in the meantime, feel free to reply to this email.\n\n"
    . "Warm regards,\n"
    . "ACSO Consulting\n"
    . "acsoconsulting.com\n";

$senderHeaders = "From: ACSO Consulting <info@acsoconsulting.com>\r\n"
    . "Reply-To: info@acsoconsulting.com\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n";

@mail($email, $senderSubject, $senderBody, $senderHeaders);

// ── Success ────────────────────────────────────────────────────────────
echo json_encode(['success' => true, 'message' => 'Your message has been sent.']);
