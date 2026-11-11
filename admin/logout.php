<?php
/**
 * File: failib/admin/logout.php
 */
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
unset($_SESSION['failib_admin']);
header('Location: login.php');
exit;

