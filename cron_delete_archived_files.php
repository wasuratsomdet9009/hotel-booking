<?php
// FILEX: hotel_booking/cron_delete_archived_files.php
require_once __DIR__ . '/bootstrap.php'; // For DB connection ($pdo)
date_default_timezone_set('Asia/Bangkok');

error_log("[CronDeleteOldUploads] Script started at " . date('Y-m-d H:i:s'));

$three_months_ago = (new DateTime('now', new DateTimeZone('Asia/Bangkok')))
                        ->modify('-3 months')
                        ->format('Y-m-d H:i:s');

$files_deleted_count = 0;
$records_deleted_count = 0;
$groups_deleted_count = 0;
$errors_count = 0;

try {
    $pdo->beginTransaction();

    // Step 1: Find all receipts from booking_groups linked to old archives.
    $sql_find_receipts = "
        SELECT 
            r.id as receipt_id, 
            r.receipt_path, 
            r.booking_group_id
        FROM booking_group_receipts r
        JOIN (
            SELECT DISTINCT booking_group_id 
            FROM archives 
            WHERE archived_at < :three_months_ago 
              AND booking_group_id IS NOT NULL
        ) AS old_groups ON r.booking_group_id = old_groups.booking_group_id
    ";
    
    $stmt_receipts = $pdo->prepare($sql_find_receipts);
    $stmt_receipts->execute([':three_months_ago' => $three_months_ago]);
    $receipts_to_delete = $stmt_receipts->fetchAll(PDO::FETCH_ASSOC);

    if (empty($receipts_to_delete)) {
        error_log("[CronDeleteOldUploads] No receipts found linked to archives older than 3 months.");
        echo "No receipts found for cleanup.\n";
        $pdo->commit(); // Commit to finish transaction even if nothing to do.
        exit;
    }

    $receiptBaseDir = __DIR__ . '/../uploads/receipts/';
    $receipt_ids_to_delete_from_db = [];

    // Step 2: Delete physical files
    foreach ($receipts_to_delete as $receipt) {
        if (empty($receipt['receipt_path'])) continue;

        $file_path = $receiptBaseDir . $receipt['receipt_path'];
        if (file_exists($file_path)) {
            if (@unlink($file_path)) {
                error_log("[CronDeleteOldUploads] Successfully deleted file: {$file_path}");
                $files_deleted_count++;
                $receipt_ids_to_delete_from_db[] = $receipt['receipt_id'];
            } else {
                error_log("[CronDeleteOldUploads] FAILED to delete file: {$file_path}");
                $errors_count++;
            }
        } else {
            error_log("[CronDeleteOldUploads] File not found (already deleted?): {$file_path}");
            $receipt_ids_to_delete_from_db[] = $receipt['receipt_id']; // Mark for DB deletion anyway
        }
    }

    // Step 3: Delete records from booking_group_receipts
    if (!empty($receipt_ids_to_delete_from_db)) {
        $placeholders = implode(',', array_fill(0, count($receipt_ids_to_delete_from_db), '?'));
        $deleteSql = "DELETE FROM booking_group_receipts WHERE id IN ({$placeholders})";
        $deleteStmt = $pdo->prepare($deleteSql);
        if ($deleteStmt->execute($receipt_ids_to_delete_from_db)) {
            $records_deleted_count = $deleteStmt->rowCount();
            error_log("[CronDeleteOldUploads] Successfully deleted {$records_deleted_count} records from booking_group_receipts.");
        } else {
            error_log("[CronDeleteOldUploads] FAILED to execute DELETE statement for booking_group_receipts.");
            $errors_count++;
        }
    }

    // Step 4: Delete orphaned booking_groups (groups linked to old archives that now have no bookings or receipts)
    $sql_delete_orphaned_groups = "
        DELETE bg FROM booking_groups bg
        WHERE bg.id IN (
            SELECT DISTINCT booking_group_id FROM archives WHERE archived_at < :three_months_ago AND booking_group_id IS NOT NULL
        )
        AND NOT EXISTS (SELECT 1 FROM bookings WHERE booking_group_id = bg.id)
        AND NOT EXISTS (SELECT 1 FROM booking_group_receipts WHERE booking_group_id = bg.id)
    ";
    $stmt_delete_orphaned = $pdo->prepare($sql_delete_orphaned_groups);
    $stmt_delete_orphaned->execute([':three_months_ago' => $three_months_ago]);
    $groups_deleted_count = $stmt_delete_orphaned->rowCount();
    if ($groups_deleted_count > 0) {
        error_log("[CronDeleteOldUploads] Successfully deleted {$groups_deleted_count} orphaned booking_groups.");
    }
    
    $pdo->commit();

    $summaryMessage = "[CronDeleteOldUploads] Script finished. Files deleted: {$files_deleted_count}. Receipt records deleted: {$records_deleted_count}. Orphaned groups deleted: {$groups_deleted_count}. Errors: {$errors_count}.";
    error_log($summaryMessage);
    echo $summaryMessage . "\n";

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $errorMessage = "[CronDeleteOldUploads] Critical PDOException: " . $e->getMessage();
    error_log($errorMessage);
    echo $errorMessage . "\n";
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $errorMessage = "[CronDeleteOldUploads] Critical Exception: " . $e->getMessage();
    error_log($errorMessage);
    echo $errorMessage . "\n";
}

exit;
?>