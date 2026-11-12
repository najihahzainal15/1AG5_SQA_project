<?php
$pageTitle = 'Equipment';
$activePage = 'eq';

// 1. Include the navigation (this opens the layout)
require_once '../includes/navigation.php';

// Fetch data (Using PDO as per your provided code)
$stmt = $pdo->query("SELECT e.*, s.supplier_name FROM equipment e LEFT JOIN suppliers s ON e.supplier_id = s.supplier_id");
$equipment = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- 2. Your Page Content -->
<div class="hd">
    <div>
        <h1>Equipment <span class="vo">View only</span></h1>
        <div class="sub">Manage equipment details, specifications, categories, rental rates and selling prices.</div>
    </div>
    <!-- In a real app, you might want to hide this button if the user is not an Admin -->
    <button class="btn">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path d="M12 5v14M5 12h14" />
        </svg> New Equipment
    </button>
</div>

<!-- Search & Filter Toolbar (Matches Prototype) -->
<div class="tb">
    <div class="sw">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"></circle>
            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
        </svg>
        <input type="text" class="in" placeholder="Search equipment...">
    </div>
    <div class="sp"></div> <!-- Spacer to push dropdowns to the right -->
    <select class="in">
        <option value="">All Category</option>
        <option value="Pocket Series">Pocket Series</option>
        <option value="Action Camera">Action Camera</option>
        <option value="Accessories">Accessories</option>
    </select>
    <select class="in">
        <option value="">All Status</option>
        <option value="Available">Available</option>
        <option value="Rented">Rented</option>
        <option value="Reserved">Reserved</option>
        <option value="Maintenance">Maintenance</option>
    </select>
</div>

<!-- Data Table -->
<div class="tw">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Category</th>
                <th>Rate / day</th>
                <th>Selling price</th>
                <th>Supplier</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($equipment) > 0): ?>
                <?php foreach ($equipment as $e): ?>
                    <tr class="m">
                        <td><span class="id">EQ-<?= str_pad($e['equipment_id'], 3, '0', STR_PAD_LEFT) ?></span></td>
                        <!-- FIXED: Changed $e['name'] to $e['equipment_name'] -->
                        <td><?= htmlspecialchars($e['equipment_name']) ?></td>
                        <td><?= htmlspecialchars($e['category']) ?></td>
                        <td>RM <?= number_format($e['rental_rate']) ?></td>
                        <td>RM <?= number_format($e['purchase_price']) ?></td>
                        <td><?= htmlspecialchars($e['supplier_name'] ?? 'N/A') ?></td>
                        <td>
                            <?php
                            // Map status to CSS badge classes
                            $status = strtolower($e['equipment_status']);
                            $badge = 'S'; // Default grey

                            if ($status == 'available') $badge = 'G'; // Green
                            if ($status == 'reserved') $badge = 'Y';  // Yellow
                            if ($status == 'rented') $badge = 'B';    // Blue
                            if ($status == 'maintenance') $badge = 'R'; // Red (or change to Y if you prefer)
                            ?>
                            <span class="bd <?= $badge ?>"><?= ucfirst($status) ?></span>
                        </td>
                        <td style="text-align:right">
                            <!-- Edit Icon -->
                            <button class="ic" title="Edit">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                    <path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z" />
                                </svg>
                            </button>
                            <!-- Delete Icon -->
                            <button class="ic x" title="Delete">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                    <path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                </svg>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="8" class="emp">No equipment records found in the database.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- 3. Close the layout manually at the very bottom -->
</div> <!-- Close .card -->
</main>
</body>

</html>