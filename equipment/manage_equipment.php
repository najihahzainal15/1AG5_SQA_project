<?php
$pageTitle = 'Equipment';
$activePage = 'eq';
// 1. Include the navigation (this opens the layout)
require_once '../includes/navigation.php';

// Fetch data
$stmt = $pdo->query("SELECT e.*, s.supplier_name FROM equipment e LEFT JOIN suppliers s ON e.supplier_id = s.supplier_id");
$equipment = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- 2. Your Page Content -->
<div class="hd">
    <div>
        <h1>Equipment <span class="vo">View only</span></h1>
        <div class="sub">Manage equipment details, specifications, categories, rental rates and purchase prices.</div>
    </div>
    <button class="btn">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path d="M12 5v14M5 12h14" />
        </svg> New Equipment
    </button>
</div>

<div class="tw">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Category</th>
                <th>Rate / day</th>
                <th>Purchase Price</th>
                <th>Supplier</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($equipment as $e): ?>
                <tr class="m">
                    <td><span class="id">EQ-<?= str_pad($e['equipment_id'], 3, '0', STR_PAD_LEFT) ?></span></td>
                    <td><?= htmlspecialchars($e['name']) ?></td>
                    <td><?= htmlspecialchars($e['category']) ?></td>
                    <td>RM <?= number_format($e['rental_rate']) ?></td>
                    <td>RM <?= number_format($e['purchase_price']) ?></td>
                    <td><?= htmlspecialchars($e['supplier_name'] ?? 'N/A') ?></td>
                    <td>
                        <?php
                        $badge = 'S';
                        if ($e['equipment_status'] == 'Available') $badge = 'G';
                        if ($e['equipment_status'] == 'Reserved') $badge = 'Y';
                        if ($e['equipment_status'] == 'Rented') $badge = 'B';
                        if ($e['equipment_status'] == 'Maintenance') $badge = 'Y';
                        ?>
                        <span class="bd <?= $badge ?>"><?= $e['equipment_status'] ?></span>
                    </td>
                    <td style="text-align:right">
                        <button class="ic"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z" />
                            </svg></button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- 3. Close the layout manually at the very bottom -->
</div> <!-- Close .card -->
</main>
</body>

</html>