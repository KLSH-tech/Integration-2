<?php
// ============================================================================
// scanner/attendance_sync.php
// ----------------------------------------------------------------------------
// Bridges the barcode scanner to the teacher portal.
//
// The scanner records raw scans into `attendance_logs`, but every teacher
// portal page (dashboard, attendance, reports, student record, instructor
// record, disputes) reads from the `attendance` summary table. This function
// mirrors each scan into that table so all of those sections fill in
// automatically right after a student scans.
//
// Grain: ONE row per (student, class, day).
//   • action 'in'  -> sets time_in + status (Present / Late) on first entry
//   • action 'out' -> stamps time_out on the same row
//
// HOW TO WIRE IT IN (attendance_scanner.php):
//   1) Near the top, beside your other requires:
//          require_once __DIR__ . '/attendance_sync.php';
//   2) Right AFTER the attendance_logs INSERT (after `$logId = (int) $conn->insert_id;`):
//          syncAttendanceSummary(
//              $conn,
//              $studentPk,
//              $classId,
//              (int) ($activeSchedule['teacher_id'] ?? 0),
//              $action,
//              $isLate,
//              $now
//          );
// ============================================================================

if (!function_exists('syncAttendanceSummary')) {
    function syncAttendanceSummary(
        mysqli   $conn,
        int      $studentId,
        int      $classId,
        int      $teacherId,
        string   $action,      // 'in' | 'out'
        bool     $isLate,
        DateTime $now
    ): void {
        // Never let a hiccup here break attendance logging or the e-mail step.
        try {
            $date    = $now->format('Y-m-d');
            $timeNow = $now->format('H:i:s');
            $status  = $isLate ? 'Late' : 'Present';

            // Today's row for this student in this class (if any)
            $sel = $conn->prepare(
                "SELECT attendance_id FROM attendance
                 WHERE student_id = ? AND class_id = ? AND `date` = ? LIMIT 1"
            );
            $sel->bind_param('iis', $studentId, $classId, $date);
            $sel->execute();
            $row = $sel->get_result()->fetch_assoc();
            $sel->close();

            if ($action === 'in') {
                if (!$row) {
                    // First entry today -> create the daily record
                    $ins = $conn->prepare(
                        "INSERT INTO attendance
                            (student_id, class_id, teacher_id, `date`, time_in, status)
                         VALUES (?, ?, ?, ?, ?, ?)"
                    );
                    $ins->bind_param('iiisss', $studentId, $classId, $teacherId, $date, $timeNow, $status);
                    $ins->execute();
                    $ins->close();
                } else {
                    // Already has a row -> keep the original time_in, refresh status
                    $aid = (int) $row['attendance_id'];
                    $upd = $conn->prepare(
                        "UPDATE attendance
                         SET time_in = COALESCE(time_in, ?), status = ?
                         WHERE attendance_id = ?"
                    );
                    $upd->bind_param('ssi', $timeNow, $status, $aid);
                    $upd->execute();
                    $upd->close();
                }
            } else { // 'out'
                if ($row) {
                    $aid = (int) $row['attendance_id'];
                    $upd = $conn->prepare(
                        "UPDATE attendance SET time_out = ? WHERE attendance_id = ?"
                    );
                    $upd->bind_param('si', $timeNow, $aid);
                    $upd->execute();
                    $upd->close();
                } else {
                    // Out without a matching in (edge case) -> still record it
                    $ins = $conn->prepare(
                        "INSERT INTO attendance
                            (student_id, class_id, teacher_id, `date`, time_out, status)
                         VALUES (?, ?, ?, ?, ?, 'Present')"
                    );
                    $ins->bind_param('iiiss', $studentId, $classId, $teacherId, $date, $timeNow);
                    $ins->execute();
                    $ins->close();
                }
            }
        } catch (Throwable $ex) {
            error_log('syncAttendanceSummary failed: ' . $ex->getMessage());
        }
    }
}