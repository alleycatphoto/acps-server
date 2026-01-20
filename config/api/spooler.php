<?php
/**
 * Spooler API
 * Manages the transition of files from the internal spool to the physical printer folder (c:/orders)
 * and monitors the email queue.
 */

header('Content-Type: application/json');

$day_path = "../../photos/" . date("Y/m/d") . "/spool/";                                                                                                                                                                          
$printer_spool = $day_path . "printer/";
$mailer_spool = $day_path . "mailer/";
$physical_printer_path = "c:/orders/"; // Ensure PHP has write access to this path

// Create directories if they don't exist
if (!is_dir($printer_spool)) mkdir($printer_spool, 0777, true);
if (!is_dir($mailer_spool)) mkdir($mailer_spool, 0777, true);

$action = $_GET['action'] ?? 'status';

switch ($action) {
    case 'status':
        // Count files in spool folders
        $printer_queue = array_diff(scandir($printer_spool), array('.', '..'));
        $mailer_queue = array_diff(scandir($mailer_spool), array('.', '..'));
        
        echo json_encode([
            'printer_count' => count($printer_queue),
            'mailer_count' => count($mailer_queue),
            'printer_items' => array_values($printer_queue),
            'mailer_items' => array_values($mailer_queue)
        ]);
        break;

    case 'tick_printer':
        /**
         * Check if physical printer folder is empty.
         * NOTE: We ignore the 'Archive' folder created/used by the printer hardware software.
         */
        $current_printer_files = array_diff(scandir($physical_printer_path), array('.', '..', 'Archive', 'archive'));
        
        if (count($current_printer_files) === 0) {
            $queued_files = array_diff(scandir($printer_spool), array('.', '..'));
            sort($queued_files); // Process oldest first (alphabetical by timestamp/orderid)
            
            if (count($queued_files) > 0) {
                // Peek at first file that is a JPG (ignore the meta .txt files for movement)
                $jpgs = array_filter($queued_files, function($f) { return stripos($f, '.jpg') !== false; });
                
                if (count($jpgs) > 0) {
                    $file_to_move = reset($jpgs);
                    $source = $printer_spool . $file_to_move;
                    $dest = $physical_printer_path . $file_to_move;
                    
                    if (rename($source, $dest)) {
                        echo json_encode(['status' => 'success', 'moved' => $file_to_move]);
                    } else {
                        echo json_encode(['status' => 'error', 'message' => 'Failed to move file']);
                    }
                } else {
                    echo json_encode(['status' => 'idle', 'message' => 'No JPGs in queue']);
                }
            } else {
                echo json_encode(['status' => 'idle', 'message' => 'Queue empty']);
            }
        } else {
            echo json_encode(['status' => 'busy', 'count' => count($current_printer_files)]);
        }
        break;

    case 'retry_print':
        // Logic to move a file from a history folder back into the printer spool
        $filename = $_GET['file'] ?? '';
        // implementation for re-spooling
        break;

    case 'trigger_mail':
        $order_id = $_GET['order_id'] ?? '';
        if ($order_id) {
            // Use your requested command format
            $cmd = "start /B php ../../mailer.php \"$order_id\"";
            pclose(popen($cmd, "r"));
            echo json_encode(['status' => 'triggered', 'order' => $order_id]);
        }
        break;
}