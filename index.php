<?php
$host = preg_replace('/^mail\./i', '', $_SERVER["SERVER_NAME"]);
header("Location: https://www.".$host."/");
die();

?>
