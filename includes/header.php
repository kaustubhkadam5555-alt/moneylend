<?php
/**
 * MoneyLend - Reusable Page Header
 * 
 * Includes HTML head, meta tags, Google Fonts, Bootstrap 5 CDN,
 * Font Awesome 6 CDN, and custom application styles.
 */

// Ensure configuration and auth helpers are loaded
if (!defined('APP_NAME')) {
    require_once __DIR__ . '/../config/config.php';
}
if (!function_exists('is_logged_in')) {
    require_once __DIR__ . '/auth.php';
}

$pageTitle = $pageTitle ?? 'Dashboard';
$currentUser = current_user() ?? [
    'name' => 'Admin User',
    'email' => 'admin@moneylend.local',
    'role' => 'admin'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MoneyLend - Simple Lending. Smarter Tracking. A lightweight loan and borrower management system.">
    <meta name="author" content="MoneyLend">
    
    <title><?php echo htmlspecialchars($pageTitle . ' — ' . APP_NAME); ?></title>

    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">

    <!-- Font Awesome 6 CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <!-- MoneyLend Custom Stylesheet -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">
</head>
<body>
