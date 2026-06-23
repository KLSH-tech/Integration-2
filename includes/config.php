<?php
// ============================================================================
// includes/config.php — Centralized configuration (Phase 1 foundation)
// The ONLY place that defines DB credentials, app constants, and the SMS key.
// ============================================================================

// ── Database (ONE database for the whole app) ───────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'student_attendance_system');
define('DB_USER', 'root');
define('DB_PASS', '');
define('SMS_SENDER_NAME', 'SCBSIT');  // Gamitin ang na-approve na sender name

// ── Application ──────────────────────────────────────────────────────────────
define('APP_NAME', 'Student Attendance Monitoring System');

// ── BASE_URL — AUTO-DETECTED so it works no matter what the folder is called ─
// (e.g. "Integration _ Final", "sams", anything — spaces handled too).
// It maps the project root (the parent of /includes) onto the web document root.
(function () {
    $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $docRoot = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'));
    $appRoot = str_replace('\\', '/', dirname(__DIR__));   // project root = parent of /includes
    $path    = '';
    if ($docRoot !== '' && stripos($appRoot, $docRoot) === 0) {
        $path = substr($appRoot, strlen($docRoot));        // e.g. "/Integration _ Final"
    }
    $path = str_replace('%2F', '/', rawurlencode($path));  // encode spaces, keep slashes
    define('BASE_URL', $scheme . '://' . $host . $path);
})();

// ── Attendance rules ─────────────────────────────────────────────────────────
define('LATE_WINDOW_MINUTES', 15);
// Ignore a re-scan of the SAME student in the SAME class within this many
// seconds. Barcode readers often fire twice in <1s; without this debounce the
// second read flips IN→OUT and creates a bogus "time out" record (you can see
// this in the seed data). Applies to BOTH directions.
define('SCAN_DEBOUNCE_SECONDS', 10);

// ── SMS (iProgSMS) — moved OUT of scanner source code ───────────────────────
define('SMS_API_URL',   'https://www.iprogsms.com/api/v1/sms_messages');
define('SMS_API_TOKEN', 'f698db38ba9a33f90c9c7b6266bdd4123f0b04db');

// ── EMAIL (PHPMailer + SMTP) — parent/guardian attendance notifications ──────
// HOW TO SET UP (Gmail example — the easiest for a capstone demo):
//   1. Use a Gmail account dedicated to the system.
//   2. Enable 2-Step Verification on that Google account.
//   3. Create an "App Password" (Google Account → Security → App passwords).
//      It is a 16-character code like "abcd efgh ijkl mnop".
//   4. Put that account's address in SMTP_USER and the 16-char code in SMTP_PASS
//      (remove the spaces). NEVER use your normal Gmail login password here.
// For other providers just change SMTP_HOST / SMTP_PORT / SMTP_SECURE.
define('MAIL_ENABLED',  true);                  // master on/off switch for email
define('MAIL_TEST_MODE', false);                // true = log only, do NOT actually send
define('SMTP_HOST',     'smtp.gmail.com');
define('SMTP_PORT',     587);                    // 587 = STARTTLS (recommended), 465 = SSL
define('SMTP_SECURE',   'tls');                  // 'tls' for 587, 'ssl' for 465
define('SMTP_AUTH',     true);
define('SMTP_USER',     'exampleponce0@gmail.com');          // <-- CHANGE ME
define('SMTP_PASS',     'nimr nobq wgua mhyq');          // <-- CHANGE ME (Gmail App Password)
define('MAIL_FROM',     'exampleponce0@gmail.com');          // usually same as SMTP_USER
define('MAIL_FROM_NAME', APP_NAME);
define('MAIL_REPLY_TO',  'youraccount@gmail.com');
// If a parent has no e-mail on file, optionally fall back to the student's own
// e-mail address (handy for testing). Set false to skip when no parent e-mail.
define('MAIL_FALLBACK_TO_STUDENT', true);
// Don't send two e-mails for the same student+schedule+action within this many
// seconds (stops a double-scan from spamming the inbox).
define('MAIL_DEDUP_SECONDS', 120);
// SMTP socket timeout (seconds). Kept low so a slow mail server can't freeze the
// scanner UI for too long.
define('MAIL_TIMEOUT', 12);

// ── Timezone ─────────────────────────────────────────────────────────────────
date_default_timezone_set('Asia/Manila');

