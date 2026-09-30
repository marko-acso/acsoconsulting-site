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
// The referer must match on an origin boundary, otherwise
// https://acsoconsulting.com.evil.example/ satisfies a bare prefix test.
// Both headers absent passes: privacy proxies and strict corporate gateways
// strip Referer, and a same-origin POST sends no Origin. Losing an industrial
// buyer to a stripped header costs more than the CSRF this would stop, and the
// form only ever sends mail to a fixed address.
$originOk = ($origin === '' && $referer === '');
foreach ($allowed as $a) {
    if ($origin === $a) { $originOk = true; break; }
    if ($referer === $a || str_starts_with($referer, $a . '/')) { $originOk = true; break; }
}
if (!$originOk) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request origin.']);
    exit;
}

// ── Language ───────────────────────────────────────────────────────────
// Resolved before anything that can fail, so every early exit answers in the
// language the visitor is reading. The page renders these strings into its own
// error box, so an English sentence on the Italian page is a visible defect.
// Posting lang[]=x instead of lang=x would make strtolower throw on PHP 8.
$langRaw = $_POST['lang'] ?? '';
$lang    = is_string($langRaw) ? strtolower(trim($langRaw)) : '';
if (!in_array($lang, ['de', 'it', 'en'], true)) $lang = 'de';

$SENT = [
    'de' => 'Ihre Nachricht wurde gesendet.',
    'it' => 'Il Suo messaggio è stato inviato.',
    'en' => 'Your message has been sent.',
][$lang];

// ── Rate limiting (5 per IP per hour) ──────────────────────────────────
// Kept out of the shared system temp dir: there the filename is guessable by
// any other tenant, who could pre-poison a bucket or plant a symlink.
$rateDir = __DIR__ . '/../acso_rate/';
if (!is_dir($rateDir) && !@mkdir($rateDir, 0700, true) && !is_dir($rateDir)) {
    $rateDir = sys_get_temp_dir() . '/acso_rate/';
    if (!is_dir($rateDir)) @mkdir($rateDir, 0700, true);
}
// A bucket is only pruned when the same IP posts again, so without this sweep
// the directory grows one file per visitor for the life of the server, and the
// privacy notice cannot honestly say the IP digest is gone after an hour.
if (random_int(1, 20) === 1) {
    foreach (glob($rateDir . '*.json') ?: [] as $stale) {
        if (@filemtime($stale) < time() - 3600) @unlink($stale);
    }
}
$rateFile = $rateDir . md5($_SERVER['REMOTE_ADDR'] ?? '0') . '.json';
// A truncated or half-written bucket decodes to null, and array_filter(null)
// is a TypeError on PHP 8, which would take the whole endpoint down.
$rateRaw  = is_file($rateFile) ? json_decode((string) file_get_contents($rateFile), true) : [];
$rateData = array_values(array_filter(is_array($rateRaw) ? $rateRaw : [],
    fn($t) => is_numeric($t) && time() - $t < 3600));
if (count($rateData) >= 5) {
    echo json_encode(['success' => false, 'message' => [
        'de' => 'Zu viele Anfragen von dieser Verbindung. Bitte versuchen Sie es später noch einmal.',
        'it' => 'Troppe richieste da questa connessione. La preghiamo di riprovare più tardi.',
        'en' => 'Too many submissions from this connection. Please try again later.',
    ][$lang]]);
    exit;
}

// ── Anti-bot: honeypot ─────────────────────────────────────────────────
// Answers exactly like a success so a bot learns nothing, and a human whose
// browser autofilled the hidden field is not shown a transport error: the
// client throws on any response that is not 2xx with a JSON content type.
if (!empty($_POST['website'])) {
    echo json_encode(['success' => true, 'message' => $SENT]);
    exit;
}

// ── Collect & validate fields ──────────────────────────────────────────
// Posting name[]=x instead of name=x would make trim() throw on PHP 8.
function postStr(string $key): string {
    $v = $_POST[$key] ?? '';
    return is_string($v) ? trim($v) : '';
}
function postLine(string $key): string {
    return str_replace(["\r", "\n", "\t"], '', postStr($key));
}

$name     = postLine('name');
$email    = postStr('email');
$company  = postLine('company');
$phone    = postLine('phone');
$product  = postLine('product');
$presence = postLine('presence');
$extract  = !empty($_POST['extract']);
$context  = postStr('context');
$country  = postLine('country');

// Which country page the form was submitted from (at, de, it, en). Distinguishes
// the Austrian from the German page, which both post lang=de.
$page = strtolower(postStr('page'));
if (!preg_match('/^[a-z]{2}$/', $page)) $page = '';

$V = [
    'de' => ['name'  => 'Bitte geben Sie Ihren Namen an.',
             'email' => 'Bitte geben Sie eine gültige E-Mail-Adresse an.',
             'company' => 'Bitte geben Sie Ihr Unternehmen an.'],
    'it' => ['name'  => 'Indichi il Suo nome.',
             'email' => 'Indichi un indirizzo e-mail valido.',
             'company' => 'Indichi la Sua azienda.'],
    'en' => ['name'  => 'Please enter your name.',
             'email' => 'Please enter a valid e-mail address.',
             'company' => 'Please enter your company.'],
][$lang];

$errors = [];
if ($name === '')    $errors[] = $V['name'];
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = $V['email'];
if ($company === '') $errors[] = $V['company'];

if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

$ts = date('Y-m-d H:i:s');

// ── Header helpers ─────────────────────────────────────────────────────
// A display name carrying <attacker@example.com> would add a second Reply-To
// address, silently redirecting the reply to a lead. Angle brackets, quotes,
// address separators and control bytes never survive into a header.
// mbstring is not a core extension, so nothing here may depend on it.
function displayName(string $s): string {
    $s = preg_replace('/[<>"\',;:\\\\]|[\x00-\x1F\x7F]/', '', $s);
    // Cut on a character boundary, or a byte-wise cut could emit half a
    // UTF-8 sequence and corrupt the encoded word built from it below.
    if (preg_match('/^.{0,80}/us', $s, $m)) $s = $m[0]; else $s = substr($s, 0, 80);
    return trim($s);
}

// Raw 8-bit bytes are not legal in a header. Returns RFC 2047 encoded words,
// split so none exceeds the 75-character limit.
function encodeHeader(string $s): string {
    if (!preg_match('/[\x80-\xFF]/', $s)) return $s;
    $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
    if ($chars === false) return '=?UTF-8?B?' . base64_encode($s) . '?=';
    $words = [];
    $buf   = '';
    foreach ($chars as $c) {
        if ($buf !== '' && strlen(base64_encode($buf . $c)) > 60) { $words[] = $buf; $buf = ''; }
        $buf .= $c;
    }
    if ($buf !== '') $words[] = $buf;
    return implode("\r\n ", array_map(
        fn($w) => '=?UTF-8?B?' . base64_encode($w) . '?=', $words));
}

// ── Email to admin ─────────────────────────────────────────────────────
$tag = strtoupper($lang) . ($page && $page !== $lang ? '/' . strtoupper($page) : '');
$adminSubject = encodeHeader("[$tag] Anfrage über acsoconsulting.com: $company ($name)");

$adminBody = "Neue Anfrage über acsoconsulting.com (Sprache: $lang, Seite: " . ($page ?: '-') . ").\n\n"
    . "Name: $name\n"
    . "Email: $email\n"
    . "Company: $company\n"
    . "Land: " . ($country ?: '-') . "\n"
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

$replyName = displayName($name);
$replyEnc  = encodeHeader($replyName);
// An encoded word is already a valid phrase and must NOT be quoted: RFC 2047
// section 5 bars encoded words inside a quoted string, so quoting one makes
// every non-ASCII name render as literal =?UTF-8?B?... in the mail client.
$replyPhrase = $replyName === '' ? '' : ($replyEnc === $replyName ? "\"$replyName\" " : "$replyEnc ");

$adminHeaders = "From: ACSO Consulting <marco@acsoconsulting.com>\r\n"
    . "Reply-To: $replyPhrase<$email>\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n";

// If this one fails the lead is lost, so never answer "received". Status stays
// 200: the client treats any non-2xx as a transport error and discards the body,
// which would throw away the localised message below.
if (!@mail(ADMIN_EMAIL, $adminSubject, $adminBody, $adminHeaders)) {
    error_log("ACSO contact form: admin mail() failed at $ts");
    $failMsg = [
        'de' => 'Die Nachricht konnte nicht zugestellt werden. Bitte versuchen Sie es in einigen Minuten erneut.',
        'it' => 'Non è stato possibile inviare il messaggio. La preghiamo di riprovare tra qualche minuto.',
        'en' => 'The message could not be delivered. Please try again in a few minutes.',
    ];
    echo json_encode(['success' => false, 'message' => $failMsg[$lang]]);
    exit;
}

// Only a delivered enquiry consumes quota, so a mail outage cannot lock
// a genuine visitor out for an hour.
$rateData[] = time();
@file_put_contents($rateFile, json_encode($rateData), LOCK_EX);

// ── Confirmation email to sender ───────────────────────────────────────
// This mail goes to an address the submitter chose, so the name field is a
// free text channel into a stranger's inbox under the ACSO From: address.
// Only a plausible name reaches the greeting; anything else is dropped and
// the greeting falls back to a neutral form.
$greet = preg_replace('/\s+/u', ' ', $name);
if (!preg_match('/^[\p{L}\p{M}\.\'\- ]{1,60}$/u', $greet)) $greet = '';
$greetIt = $greet === '' ? 'Gentile Signora, Gentile Signore,' : "Gentile $greet,";
$greetEn = $greet === '' ? 'Dear Sir or Madam,'                : "Dear $greet,";
$greetDe = $greet === '' ? 'Guten Tag,'                        : "Guten Tag $greet,";

if ($lang === 'it') {
    $senderSubject = "Richiesta ricevuta - ACSO Consulting";

    $senderBody = "$greetIt\n\n"
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
} elseif ($lang === 'en') {
    $senderSubject = "Your enquiry has arrived - ACSO Consulting";

    $senderBody = "$greetEn\n\n"
        . "your enquiry has arrived. You will be contacted within one working day.\n\n"
        . "As promised on the page, here are the details to check the company before you have "
        . "even spoken to anyone:\n\n"
        . "  Company name: ACSO Consulting EOOD\n"
        . "  Registration number: 201054736\n"
        . "  Registered office: Sofia, Bulgaria\n"
        . "  Commercial Register: https://portal.registryagency.bg/CR/en/Reports/VerificationPersonOrg\n\n"
        . "The register is public, free and available in English: search by registration number "
        . "and you get the company name, registered office, legal form and managing director.\n\n"
        . ($extract
            ? "You also asked for the free TED extract on your CPV codes. It comes with the first "
              . "reply. If you have the codes to hand, write them in a reply to this e-mail; "
              . "otherwise they are derived from what you build.\n\n"
            : "")
        . "If anything else comes to mind in the meantime, simply reply to this e-mail.\n\n"
        . "Kind regards\n"
        . "ACSO Consulting\n"
        . "acsoconsulting.com\n";
} else {
    $senderSubject = "Ihre Anfrage ist angekommen - ACSO Consulting";

    $senderBody = "$greetDe\n\n"
        . "Ihre Anfrage ist angekommen. Sie hören innerhalb eines Werktags von uns.\n\n"
        . "Wie auf der Seite zugesagt, hier sofort die Angaben, mit denen Sie das Unternehmen "
        . "prüfen können, bevor Sie überhaupt mit jemandem gesprochen haben:\n\n"
        . "  Firmenname: ACSO Consulting EOOD\n"
        . "  Registernummer: 201054736\n"
        . "  Sitz: Sofia, Bulgarien\n"
        . "  Handelsregister: https://portal.registryagency.bg/CR/en/Reports/VerificationPersonOrg\n\n"
        . "Das Register ist öffentlich, kostenlos und auf Englisch abfragbar: Suche nach der "
        . "Registernummer, und Sie sehen Firmenname, Sitz, Rechtsform und Geschäftsführer. "
        . "Es entspricht dem Handelsregisterauszug.\n\n"
        . ($extract
            ? "Sie haben außerdem den kostenlosen TED-Auszug zu Ihren CPV-Codes angefordert. Er kommt "
              . "mit der ersten Antwort. Wenn Sie die Codes zur Hand haben, schreiben Sie sie einfach "
              . "als Antwort auf diese E-Mail; andernfalls werden sie aus dem abgeleitet, was Sie bauen.\n\n"
            : "")
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
echo json_encode(['success' => true, 'message' => $SENT]);
