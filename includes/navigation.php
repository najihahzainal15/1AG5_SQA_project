<?php
// Ensure database is connected
require_once __DIR__ . '/../config/db_connection.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>LensTrack · <?= $pageTitle ?? 'Dashboard' ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/1AG5_SQA_project/assets/style.css">
</head>

<body>
    <!-- Sidebar Navigation -->
    <aside>
        <div class="logo">
            <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 8l-9-5-9 5v8l9 5 9-5zM3 8l9 5 9-5M12 13v8" />
            </svg>
            <div>LensTrack<small>Rental Inventory</small></div>
        </div>

        <nav>
            <button class="<?= $activePage == 'dash' ? 'on' : '' ?>" onclick="window.location.href='../dashboard.php'">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z" />
                </svg>
                Dashboard
            </button>
            <button class="<?= $activePage == 'eq' ? 'on' : '' ?>" onclick="window.location.href='/1AG5_SQA_project/equipment/manage_equipment.php'">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3z" />
                    <circle cx="12" cy="13" r="3.5" />
                </svg>
                Equipment
            </button>
            <button class="<?= $activePage == 'sup' ? 'on' : '' ?>" onclick="window.location.href='/1AG5_SQA_project/suppliers/manage_suppliers.php'">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M1 6h13v11H1zM14 10h4l3 3v4h-7z" />
                    <circle cx="5.5" cy="18" r="2" />
                    <circle cx="17.5" cy="18" r="2" />
                </svg>
                Suppliers
            </button>
            <button class="<?= $activePage == 'rt' ? 'on' : '' ?>" onclick="window.location.href='/1AG5_SQA_project/rentals/manage_rentals.php'">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 5h18v16H3zM3 10h18M8 3v4M16 3v4" />
                </svg>
                Rentals
            </button>
            <button class="<?= $activePage == 'mt' ? 'on' : '' ?>" onclick="window.location.href='/1AG5_SQA_project/maintenance/manage_maintenance.php'">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.8-3.8a6 6 0 0 1-7.9 7.9l-6.9 6.9a2.1 2.1 0 0 1-3-3l6.9-6.9a6 6 0 0 1 7.9-7.9z" />
                </svg>
                Returns & Maintenance
            </button>
        </nav>

        <div class="usr">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="8" r="4" />
                <path d="M4 21a8 8 0 0 1 16 0" />
            </svg>
            <div>
                <b><?= htmlspecialchars($_SESSION['full_name'] ?? 'Staff Name') ?></b>

                <!-- Displays 'Administrator' if role is admin, otherwise 'Staff' -->
                <span>
                    <?= (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') ? 'Administrator' : 'Staff' ?>
                </span>
            </div>
        </div>

        <button class="out" onclick="window.location.href='/1AG5_SQA_project/auth/logout.php'">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" />
            </svg>
            Logout
        </button>
    </aside>

    <!-- Main Content Area -->
    <main>
        <div class="card">