# Email Notification Upgrade — Audit, Fixes & Implementation Guide

**Project:** Student Attendance Monitoring System (PHP / MySQL / XAMPP)
**Goal delivered:** when a barcode is scanned, the system detects the active
class, records attendance (with correct IN/OUT + on-time/late), and **e-mails the
parent/guardian** a professional notification — without removing the existing SMS
feature or breaking any working module.

Everything below was **tested end-to-end against a real MariaDB 10.11 instance in
strict mode** (schema loaded, migration applied, the live scanner endpoint driven
with simulated scans). Results are quoted in the testing section.

---

## 1. Complete summary of fixes

| # | Problem | Severity | Fix |
|---|---------|----------|-----|
| 1 | **No e-mail capability anywhere.** Only SMS (iProgSMS) existed; `parents` had no e-mail column. | Blocker for the goal | Added `parents.email`, a vendored PHPMailer mailer (`includes/mailer.php`), SMTP config, and an `email_log` table. |
| 2 | **`bind_param` type bug** — `attendance_status` was bound as an **integer** (`'isiissiis'`), so `'on_time'`/`'late'` became `0`. On the dev's non-strict XAMPP this silently stored `''` (visible in the seed dump); on a strict server it throws *“Data truncated”*. | High (data correctness) | Corrected type string to `'isiisssis'`. Attendance status is now stored correctly. |
| 3 | **`scanned_by` enum mismatch** — code writes `'student_terminal'` but the enum only allowed `('teacher','student')`, so it stored `''`. | Medium (data correctness) | Widened the enum to include `student_terminal` / `teacher_terminal`. |
| 4 | **Duplicate-scan bug** — the cooldown only guarded `in`; a barcode reader’s double-fire wrote a bogus `out` ~1s later (seed rows 4–5, 6–7…). | Medium | Replaced with a two-directional debounce (`SCAN_DEBOUNCE_SECONDS`, default 10s). |
| 5 | **SMS could fatal the whole scan** — if cURL is unavailable the scan died before recording attendance or e-mailing. | Medium (robustness) | Guarded `sendSmsNotification()` with `function_exists('curl_init')`. |
| 6 | No e-mail history / dedup / retry. | Medium | `email_log` table + dedup window + `retryFailedEmails()`. |
| 7 | Seed data left blank `attendance_status` / `scanned_by` values. | Low | Optional, non-destructive `UPDATE`s normalize blanks. |

> **Not changed (intentionally):** the SMS path still works exactly as before
> (including its hard-coded test number `09508760485` and `$useForceTest = true`).
> If you want SMS to go to the real parent phone instead of the test number, set
> `$useForceTest = false` in `scanner/attendance_scanner.php`. Left as-is so as not
> to alter a working demo feature.

> **Pre-existing, out of scope (flagged in your own INTEGRATION_NOTES):** the
> legacy `scheduling/`, `reports/`, and `notification/` pages still connect to
> databases that don’t exist in the dump (`school_db`, `G6_reports_db`,
> `attendance_system_db`). They are unrelated to the scan→email goal and were left
> untouched.

---

## 2. Updated SQL — `database/email_upgrade.sql`

Run this **once**, on the same `student_attendance_system` database, **after** the
main dump. It is **additive and non-destructive** (adds columns/table, widens one
enum, optional data normalization). It deletes nothing.

What it does:

1. `ALTER TABLE parents ADD COLUMN email VARCHAR(150)` — parents can now be e-mailed.
2. `ALTER TABLE attendance_logs ADD COLUMN email_sent TINYINT(1)` — tracks e-mail beside `sms_sent`.
3. Widens `attendance_logs.scanned_by` enum to include `student_terminal`,`teacher_terminal` (fix #3).
4. Creates `email_log` (history + retry) with FKs to `students` and `attendance_logs`.
5. Backfills `parents.email` from `students.email` where missing (so the demo can actually send).
6. Optional hygiene `UPDATE`s for the old blank enum values (fix #7).

**How to run (phpMyAdmin):** select the `student_attendance_system` database →
**Import** → choose `database/email_upgrade.sql` → Go. (Or **SQL** tab → paste → Go.)

---

## 3. File-by-file modifications

### New files
| Path | Purpose |
|------|---------|
| `includes/mailer.php` | PHPMailer/SMTP wrapper: `sendMailSMTP()`, `buildAttendanceEmail()` (responsive HTML template), `notifyParentByEmail()` (recipient resolution + dedup + logging), `retryFailedEmails()`. |
| `includes/PHPMailer/PHPMailer.php`, `SMTP.php`, `Exception.php` | Vendored PHPMailer 6.9.3 — **no Composer needed**. |
| `database/email_upgrade.sql` | The additive migration above. |
| `scanner/test_email.php` | Admin-only SMTP diagnostic page (sends a sample e-mail, shows the exact SMTP error if any). |
| `email_preview.html` | Open in a browser to see the e-mail design (bonus, not used at runtime). |

### Edited files

**`includes/config.php`** — added a centralized config block:
- `MAIL_ENABLED`, `MAIL_TEST_MODE`, `SMTP_HOST/PORT/SECURE/AUTH/USER/PASS`,
  `MAIL_FROM`, `MAIL_FROM_NAME`, `MAIL_REPLY_TO`, `MAIL_FALLBACK_TO_STUDENT`,
  `MAIL_DEDUP_SECONDS`, `MAIL_TIMEOUT`.
- `SCAN_DEBOUNCE_SECONDS` (fix #4).
- **You must edit `SMTP_USER` / `SMTP_PASS` / `MAIL_FROM`** (see §4).

**`scanner/attendance_scanner.php`** — surgical edits only:
- `require_once .../includes/mailer.php` at the top.
- `getStudentProfile()` query now also selects `p.email AS parent_email`.
- `sendSmsNotification()` guarded with `function_exists('curl_init')` (fix #5).
- Two-directional debounce replaces the in-only 30s guard (fix #4).
- `bind_param` type string fixed `'isiissiis'` → `'isiisssis'` (fix #2).
- After the INSERT: capture `insert_id`, call `notifyParentByEmail()` for **both**
  IN and OUT, then `UPDATE attendance_logs SET email_sent, notification_sent`.
- JSON response gains `email_sent`, `email_status`, `email_to`.
- Front-end: a new `📧 EMAIL` badge + a “please wait” duplicate message.

---

## 4. Step-by-step implementation guide

1. **Back up first.** Export your database in phpMyAdmin and copy the project folder.
2. **Apply the project files.** Replace your project with this updated folder (or copy
   the new files + the edited `includes/config.php` and `scanner/attendance_scanner.php`).
   PHPMailer is already inside `includes/PHPMailer/`.
3. **Run the migration.** Import `database/email_upgrade.sql` (see §2).
4. **Enable OpenSSL in PHP.** In XAMPP open `php.ini`, ensure `extension=openssl`
   and `extension=curl` are uncommented, then restart Apache. (Both are on by default
   in recent XAMPP.)
5. **Configure SMTP** in `includes/config.php`:
   - Use a dedicated Gmail account → enable **2-Step Verification** → create an
     **App Password** (16 chars). Put the address in `SMTP_USER` + `MAIL_FROM`,
     the app password in `SMTP_PASS`. Keep `SMTP_PORT=587`, `SMTP_SECURE='tls'`.
   - For another provider, set `SMTP_HOST/PORT/SECURE` accordingly.
6. **Test SMTP** before relying on the scanner: log in as admin and open
   `scanner/test_email.php`, send yourself a test. Fix any error it reports.
7. **Add real parent e-mails** in Profiles (or via SQL). The migration backfilled
   parent e-mail from the student e-mail so you have something to test with.
8. **Scan.** Open the scanner, scan an enrolled student during an active class.
   You should see the green/blue **EMAIL SENT** badge and the parent receives the
   message. Set `MAIL_TEST_MODE=false` for real sends (it’s already false by default).

---

## 5. Testing checklist

These were **executed and passed** in a strict-mode MariaDB during development:

- [x] Schema + migration import with **no errors**; `email_log` created, enum widened, `email_sent` added, 6 parent e-mails backfilled.
- [x] **Scan IN** during an active class → `success`, `action=in`, correct subject/instructor, attendance row written.
- [x] **Late detection** → status stored as `late` when past grace (proves the bind-fix).
- [x] **On-time** → status stored as `on_time` (no longer blank).
- [x] **IN → OUT toggle** → second scan (after debounce) records `out` with goodbye message.
- [x] **E-mail on IN and OUT** → two `email_log` rows, both routed to the **parent** address.
- [x] **Parent-first recipient resolution** (falls back to student e-mail only if no parent e-mail).
- [x] **Debounce** → immediate re-scan returns `duplicate` / “please wait”.
- [x] **Unknown barcode** → `not_found` (graceful).
- [x] **No active class** → friendly “no class scheduled” response (graceful).
- [x] **No fatals** on a clean run; `scanned_by` stored as `student_terminal`; `email_sent`/`notification_sent` flags set.

Things to verify in **your** environment (needs your live SMTP):
- [ ] `scanner/test_email.php` delivers to a real inbox.
- [ ] A real scan delivers to a real parent inbox (check Spam on first send).

---

## 6. Remaining optional improvements

- **Turn off the SMS forced-test number** (`$useForceTest = false`) once you’re ready
  to send SMS to real parent phones, or retire SMS entirely if you only want e-mail.
- **Cron retry**: schedule a small script that calls `retryFailedEmails()` so any
  message that failed (e.g. SMTP hiccup) is re-sent automatically.
- **Migrate the legacy subsystems** (`scheduling/`, `reports/`, `notification/`) onto
  the single `student_attendance_system` DB so those admin pages work (the compat
  views in `database/compat_views.sql` are the intended bridge).
- **Sunday classes**: the original `schedules.day` enum has Mon–Sat only. Add `Sunday`
  if weekend classes are ever needed (the migration does *not* change this in your data).
- **Queue e-mails** (write to `email_log` as `pending`, send via cron) if scan volume
  ever gets high, so the SMTP round-trip never delays the scanner UI.
