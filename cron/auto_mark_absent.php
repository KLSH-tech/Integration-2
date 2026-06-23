<?php
/**
 * cron/auto_mark_absent.php
 * Automatically marks students as Absent for classes where the grace period (start_time + 15 minutes)
 * has already passed and the student has no attendance record for today.
 * Run this script every 5 minutes via cron / Task Scheduler.
 */

// Load core configuration
require_once __DIR__ . '/../config.php';

// Ensure only command‑line execution (optional but recommended)
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

$pdo = db();

$today = date('Y-m-d');
$now   = date('H:i:s');

// 1. Find all active classes for today whose grace period has passed
//    and that have NOT been logged in auto_absent_log for today.
$sql = "
    SELECT DISTINCT
        c.class_id,
        c.course_code,
        sched.start_time,
        sched.day
    FROM classes c
    INNER JOIN schedules sched ON c.class_id = sched.class_id
    LEFT JOIN auto_absent_log aal ON aal.class_id = c.class_id AND aal.attendance_date = :today
    WHERE sched.day = DAYNAME(:todayDate)
      AND ADDTIME(sched.start_time, '00:15:00') < :now
      AND aal.id IS NULL
";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':today'     => $today,
    ':todayDate' => $today,
    ':now'       => $now
]);
$classesToProcess = $stmt->fetchAll();

if (empty($classesToProcess)) {
    // Nothing to do
    exit(0);
}

$totalInserted = 0;

foreach ($classesToProcess as $class) {
    $classId = $class['class_id'];

    // 2. Get all students enrolled in this class (via student_schedule)
    $studentsStmt = $pdo->prepare("
        SELECT student_id
        FROM student_schedule
        WHERE class_id = ?
    ");
    $studentsStmt->execute([$classId]);
    $allStudents = $studentsStmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($allStudents)) {
        // No students enrolled – log and skip
        $logStmt = $pdo->prepare("
            INSERT INTO auto_absent_log (class_id, attendance_date, students_affected)
            VALUES (?, ?, 0)
        ");
        $logStmt->execute([$classId, $today]);
        continue;
    }

    // 3. Find which of these students already have attendance for today in this class
    $placeholders = implode(',', array_fill(0, count($allStudents), '?'));
    $existingStmt = $pdo->prepare("
        SELECT student_id
        FROM attendance
        WHERE class_id = ?
          AND date = ?
          AND student_id IN ($placeholders)
    ");
    $params = array_merge([$classId, $today], $allStudents);
    $existingStmt->execute($params);
    $existingStudents = $existingStmt->fetchAll(PDO::FETCH_COLUMN);

    $missingStudents = array_diff($allStudents, $existingStudents);

    if (empty($missingStudents)) {
        // All students already marked – log with 0 affected
        $logStmt = $pdo->prepare("
            INSERT INTO auto_absent_log (class_id, attendance_date, students_affected)
            VALUES (?, ?, 0)
        ");
        $logStmt->execute([$classId, $today]);
        continue;
    }

    // 4. Insert absent records for missing students
    $insertStmt = $pdo->prepare("
        INSERT INTO attendance (student_id, class_id, date, time_in, status, created_at)
        VALUES (?, ?, ?, NULL, 'Absent', NOW())
    ");

    foreach ($missingStudents as $studentId) {
        $insertStmt->execute([$studentId, $classId, $today]);
        $totalInserted++;
    }

    // 5. Log this class as processed
    $logStmt = $pdo->prepare("
        INSERT INTO auto_absent_log (class_id, attendance_date, students_affected)
        VALUES (?, ?, ?)
    ");
    $logStmt->execute([$classId, $today, count($missingStudents)]);
}

// Optional: Write summary to error log or console
echo "[" . date('Y-m-d H:i:s') . "] Auto-absent: Inserted $totalInserted records.\n";