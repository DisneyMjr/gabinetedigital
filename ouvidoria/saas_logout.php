<?php
session_start();
session_destroy();
header("Location: saas_login.php");
exit;
?>