<?php
/**
 * ACSO Consulting - Contact Form Handler
 * Receives POST with name, email, company, phone, context.
 * Sends admin notification and sender confirmation.
 */

// ── Config ──────────────────────────────────────────────────────────────
define('ADMIN_EMAIL', 'marco@acsoconsulting.com');
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
$rateDir = sys_get_temp_dir() . '/acso_rate/';
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
$name     = str_replace(["\r", "\n", "\t"], '', trim($_POST['name']     ?? ''));
$email    = trim($_POST['email']    ?? '');
$company  = str_replace(["\r", "\n", "\t"], '', trim($_POST['company']  ?? ''));
$phone    = str_replace(["\r", "\n", "\t"], '', trim($_POST['phone']    ?? ''));
$product  = str_replace(["\r", "\n", "\t"], '', trim($_POST['product']  ?? ''));
$presence = str_replace(["\r", "\n", "\t"], '', trim($_POST['presence'] ?? ''));
$extract  = !empty($_POST['extract']);
$context  = trim($_POST['context'] ?? '');

$lang = strtolower(trim($_POST['lang'] ?? 'de'));
if (!in_array($lang, ['de', 'it'], true)) $lang = 'de';

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
$adminSubject = "[" . strtoupper($lang) . "] Anfrage über acsoconsulting.com: $company ($name)";

$adminBody = "Neue Anfrage über acsoconsulting.com (Sprache: $lang).\n\n"
    . "Name: $name\n"
    . "Email: $email\n"
    . "Company: $company\n"
    . "Phone: " . ($phone ?: 'not provided') . "\n"
    . "Produkt: " . ($product ?: '-') . "\n"
    . "Präsenz BG: " . ($presence ?: '-') . "\n"
    . "TED-Auszug angefordert: " . ($extract ? 'JA' : 'nein') . "\n"
    . "Zeit: $ts\n\n"
    . "Nachricht:\n"
    . "----------\n"
    . ($context ?: '-') . "\n"
    . "----------\n\n"
    . "Antworten Sie direkt auf diese E-Mail, um $name zu erreichen.\n";

$adminHeaders = "From: ACSO Consulting <marco@acsoconsulting.com>\r\n"
    . "Reply-To: $name <$email>\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n";

@mail(ADMIN_EMAIL, $adminSubject, $adminBody, $adminHeaders);

// ── Confirmation email to sender ───────────────────────────────────────
if ($lang === 'it') {
    $senderSubject = "Richiesta ricevuta - ACSO Consulting";

    $senderBody = "Gentile $name,\n\n"
        . "la Sua richiesta è arrivata. Viene ricontattato entro un giorno lavorativo.\n\n"
        . "Come promesso sulla pagina, ecco subito gli estremi per verificare la società "
        . "prima ancora di parlarci:\n\n"
        . "  Denominazione: ACSO Consulting EOOD\n"
        . "  Numero di registrazione (ЕИК): 201054736\n"
        . "  Sede: Sofia, Bulgaria\n"
        . "  Registro di commercio: https://portal.registryagency.bg/CR/en/Reports/VerificationPersonOrg\n\n"
        . "Il registro è pubblico, gratuito e consultabile in inglese: cerchi per numero di "
        . "registrazione e trova denominazione, sede, forma giuridica e amministratore. "
        . "È l'equivalente della visura camerale.\n\n"
        . ($extract
            ? "Ha chiesto anche l'estratto TED gratuito sui Suoi codici CPV. Arriva insieme alla "
              . "prima risposta. Se ha i codici a portata di mano, li scriva rispondendo a questa "
              . "e-mail; altrimenti si ricavano da quello che costruisce.\n\n"
            : "")
        . "Se nel frattempo Le viene in mente altro, risponda pure a questa e-mail.\n\n"
        . "Cordiali saluti\n"
        . "ACSO Consulting\n"
        . "acsoconsulting.com\n";
} else {
    $senderSubject = "Ihre Anfrage ist angekommen - ACSO Consulting";

    $senderBody = "Guten Tag $name,\n\n"
        . "vielen Dank für Ihre Nachricht. Wir haben Ihre Anfrage erhalten und melden uns innerhalb eines Werktags bei Ihnen.\n\n"
        . "Falls Ihnen in der Zwischenzeit noch etwas einfällt, antworten Sie einfach auf diese E-Mail.\n\n"
        . "Mit besten Grüßen\n"
        . "ACSO Consulting\n"
        . "acsoconsulting.com\n";
}

$senderHeaders = "From: ACSO Consulting <marco@acsoconsulting.com>\r\n"
    . "Reply-To: marco@acsoconsulting.com\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n";

@mail($email, $senderSubject, $senderBody, $senderHeaders);

// ── Success ────────────────────────────────────────────────────────────
echo json_encode(['success' => true, 'message' => 'Your message has been sent.']);
