<?php

ERROR_REPORTING(E_ALL);
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action === 'report') {
        // Reporting on Postfix queue
        echo "<pre>";
        echo shell_exec('whoami');
        echo "\n";
        echo shell_exec('id');
        echo "\n";
        $qshape = shell_exec('/usr/sbin/qshape');
        $qshapeDeferred = shell_exec('/usr/sbin/qshape deferred');
        // Display the queue reports
        // echo "qshape Report:\n";
        // echo $qshape;
        $qshape = explode("\n", $qshape);
        // $qshape = array_filter($qshape);
        foreach ($qshape as $key => $value) {
            //remove multiple spaces
            $value = preg_replace('/\s+/', ' ', $value);
            $qshape[$key] = explode("\t", $value);
            // $qshape[$key] = array_filter($qshape[$key]);
        }
        $qshapeDeferred = explode("\n", $qshapeDeferred);
        // $qshapeDeferred = array_filter($qshapeDeferred);
        foreach ($qshapeDeferred as $key => $value) {
            //remove multiple spaces
            $value = preg_replace('/\s+/', ' ', $value);
            $qshapeDeferred[$key] = explode(" ", $value);
            // $qshapeDeferred[$key] = array_filter($qshapeDeferred[$key]);
        }
        echo "\nqshape deferred Report:\n";
        print_r($qshapeDeferred);
        echo "</pre>";
    } elseif ($action === 'clear') {
        // Clear the Postfix queue
        $clearQueueResult = shell_exec('/usr/sbin/postsuper -d ALL');

        echo "Postfix Queue Cleared:\n";
        echo $clearQueueResult;
    } else {
        echo "Invalid action or no action specified.";
    }
} else {
    echo "No action specified.";
}