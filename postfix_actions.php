<?php

ERROR_REPORTING(E_ALL);
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action === 'report') {
        // Reporting on Postfix queue
        $qshape = shell_exec('qshape');
        $qshapeDeferred = shell_exec('qshape deferred');
        // Display the queue reports
        echo "qshape Report:\n";
        echo $qshape;
        echo "\nqshape deferred Report:\n";
        echo $qshapeDeferred;
    } elseif ($action === 'clear') {
        // Clear the Postfix queue
        $clearQueueResult = shell_exec('postsuper -d ALL');

        echo "Postfix Queue Cleared:\n";
        echo $clearQueueResult;
    } else {
        echo "Invalid action or no action specified.";
    }
} else {
    echo "No action specified.";
}