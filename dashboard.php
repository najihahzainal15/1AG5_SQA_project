<?php
$pageTitle = 'Dashboard';
$activePage = 'dash';

// 1. Include the navigation (this opens the <body>, <main>, and .card)
require_once 'includes/navigation.php';

// Client Feedback: Change Revenue to Total Rental Value
$total_rental_value = $pdo->query("SELECT SUM(total_cost) FROM rentals WHERE rental_status != 'Cancelled'")->fetchColumn();
?>

<!-- 2. Your Page Content -->
<h1>Dashboard</h1>
<div class="kc">
    <b>RM <?= number_format($total_rental_value ?? 0, 2) ?></b>
    <span>Total Rental Value</span>
</div>

<!-- 3. Close the layout manually at the very bottom (Just like you did in manage_equipment.php) -->
</div> <!-- Close .card -->
</main>
</body>

</html>