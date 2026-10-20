<?php
$pageTitle = 'Dashboard';
$activePage = 'dash';
require_once 'includes/navigation.php';

// Client Feedback: Change Revenue to Total Rental Value
$total_rental_value = $pdo->query("SELECT SUM(total_cost) FROM rentals WHERE rental_status != 'Cancelled'")->fetchColumn();
?>
<h1>Dashboard</h1>
<div class="kc">
    <b>RM <?= number_format($total_rental_value ?? 0, 2) ?></b>
    <span>Total Rental Value</span>
</div>
<?php ?>