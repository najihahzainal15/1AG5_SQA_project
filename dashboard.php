<?php
$pageTitle = 'Dashboard';
$activePage = 'dash';

require_once 'includes/navigation.php';

$total_eq = $pdo->query("SELECT COUNT(*) FROM equipment")->fetchColumn();

$status_counts = ['available' => 0, 'rented' => 0, 'reserved' => 0, 'maintenance' => 0];
$eq_status_query = $pdo->query("SELECT equipment_status, COUNT(*) as count FROM equipment GROUP BY equipment_status");
while ($row = $eq_status_query->fetch(PDO::FETCH_ASSOC)) {
    $status = strtolower($row['equipment_status']);
    if (isset($status_counts[$status])) {
        $status_counts[$status] = $row['count'];
    }
}

$active_suppliers = $pdo->query("SELECT COUNT(*) FROM suppliers WHERE supplier_status = 'active'")->fetchColumn();

$total_rental_value = $pdo->query("SELECT SUM(total_cost) FROM rentals WHERE rental_status != 'Cancelled'")->fetchColumn();
if (!$total_rental_value) $total_rental_value = 0;

$cat_query = $pdo->query("SELECT category, COUNT(*) as count FROM equipment GROUP BY category ORDER BY count DESC");
$categories = $cat_query->fetchAll(PDO::FETCH_ASSOC);

$rental_status_query = $pdo->query("SELECT rental_status, COUNT(*) as count FROM rentals GROUP BY rental_status");
$rental_status_counts = ['active' => 0, 'returned' => 0, 'overdue' => 0, 'cancelled' => 0];
$total_rentals = 0;

while ($row = $rental_status_query->fetch(PDO::FETCH_ASSOC)) {
    $status = strtolower($row['rental_status']);
    $count = $row['count'];
    $total_rentals += $count;

    // Normalize status names for display
    if ($status == 'active' || $status == 'rented') {
        $rental_status_counts['active'] += $count;
    } elseif ($status == 'returned' || $status == 'completed') {
        $rental_status_counts['returned'] += $count;
    } elseif ($status == 'overdue') {
        $rental_status_counts['overdue'] += $count;
    } elseif ($status == 'cancelled') {
        $rental_status_counts['cancelled'] += $count;
    }
}

$due_query = $pdo->query("
    SELECT r.cust_name, r.end_date, e.equipment_name, e.equipment_id 
    FROM rentals r 
    JOIN equipment e ON r.equipment_id = e.equipment_id 
    WHERE r.rental_status IN ('active', 'Active', 'Rented', 'rented') 
    AND e.equipment_status != 'available'
    ORDER BY r.end_date ASC LIMIT 5
");
$due_rentals = $due_query->fetchAll(PDO::FETCH_ASSOC);

$maint_query = $pdo->query("
    SELECT m.maintenance_id, m.condition_found, m.maintenance_status, e.equipment_name, e.equipment_id 
    FROM maintenance m 
    JOIN equipment e ON m.equipment_id = e.equipment_id 
    WHERE m.maintenance_status NOT IN ('Completed', 'completed', 'Fixed') 
    ORDER BY m.date ASC LIMIT 3
");
$open_maintenance = $maint_query->fetchAll(PDO::FETCH_ASSOC);

$recent_rentals_query = $pdo->query("
    SELECT r.*, e.equipment_name 
    FROM rentals r 
    JOIN equipment e ON r.equipment_id = e.equipment_id 
    ORDER BY r.rental_id DESC LIMIT 5
");
$recent_rentals = $recent_rentals_query->fetchAll(PDO::FETCH_ASSOC);

function getStatusBadge($status)
{
    $s = strtolower($status);
    if ($s == 'available' || $s == 'active' || $s == 'completed') return 'background:#dcf3e1; color:#1e8a3c;';
    if ($s == 'reserved' || $s == 'due today' || $s == 'in progress') return 'background:#fdf0c4; color:#b7800a;';
    if ($s == 'rented') return 'background:#dbe8fd; color:#1f5fd1;';
    if ($s == 'maintenance' || $s == 'cancelled') return 'background:#fde0e0; color:#d12a2a;';
    return 'background:#e4e6ee; color:#5b6182;';
}

$colors = ['#141b4d', '#1f5fd1', '#1e8a3c', '#eab308', '#ea580c', '#9333ea'];
$cat_data = [];
$cat_total = 0;
foreach ($categories as $c) {
    $cat_total += $c['count'];
}
foreach ($categories as $i => $cat) {
    $pct = $cat_total > 0 ? ($cat['count'] / $cat_total) * 100 : 0;
    $cat_data[] = [
        'name' => $cat['category'],
        'count' => $cat['count'],
        'pct' => $pct,
        'color' => $colors[$i % count($colors)]
    ];
}
$dash_accumulated = 0;
foreach ($cat_data as &$cd) {
    $dash_length = ($cd['pct'] / 100) * 314.159; // 2 * pi * r (r=50)
    $cd['dasharray'] = $dash_length . " " . (314.159 - $dash_length);
    $cd['dashoffset'] = -$dash_accumulated;
    $dash_accumulated += $dash_length;
}
unset($cd);
?>

<div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 20px;">
    <div>
        <h1 style="margin-bottom: 5px; font-size: 25px; font-weight: 700; color: var(--tx);">Dashboard</h1>
        <div style="font-size: 13px; color: var(--mut);">Welcome back, <?= htmlspecialchars($_SESSION['full_name'] ?? 'Staff') ?> · <?= date('l, j F Y') ?></div>
    </div>
</div>

<!-- Top KPIs -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 15px; margin-bottom: 25px;">
    <div style="background:var(--card); border:1px solid var(--bd); border-radius:10px; padding:20px; display:flex; align-items:center; gap:15px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
        <div style="width:45px; height:45px; border-radius:10px; background:var(--in); display:flex; align-items:center; justify-content:center; color:var(--tx);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3z" />
                <circle cx="12" cy="13" r="3.5" />
            </svg>
        </div>
        <div><b style="display:block; font-size:24px; font-weight:700; color:var(--tx); line-height:1.2;"><?= $total_eq ?></b><span style="font-size:12px; color:var(--mut); font-weight:500;">Total equipment</span></div>
    </div>

    <div style="background:var(--card); border:1px solid var(--bd); border-radius:10px; padding:20px; display:flex; align-items:center; gap:15px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
        <div style="width:45px; height:45px; border-radius:10px; background:var(--in); display:flex; align-items:center; justify-content:center; color:var(--tx);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
                <polyline points="3.27 6.96 12 12.01 20.73 6.96" />
                <line x1="12" y1="22.08" x2="12" y2="12" />
            </svg>
        </div>
        <div><b style="display:block; font-size:24px; font-weight:700; color:var(--tx); line-height:1.2;"><?= $status_counts['available'] ?></b><span style="font-size:12px; color:var(--mut); font-weight:500;">Available</span></div>
    </div>

    <div style="background:var(--card); border:1px solid var(--bd); border-radius:10px; padding:20px; display:flex; align-items:center; gap:15px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
        <div style="width:45px; height:45px; border-radius:10px; background:var(--in); display:flex; align-items:center; justify-content:center; color:var(--tx);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
                <line x1="16" y1="2" x2="16" y2="6" />
                <line x1="8" y1="2" x2="8" y2="6" />
                <line x1="3" y1="10" x2="21" y2="10" />
            </svg>
        </div>
        <div><b style="display:block; font-size:24px; font-weight:700; color:var(--tx); line-height:1.2;"><?= $status_counts['rented'] ?></b><span style="font-size:12px; color:var(--mut); font-weight:500;">Currently rented</span></div>
    </div>

    <div style="background:var(--card); border:1px solid var(--bd); border-radius:10px; padding:20px; display:flex; align-items:center; gap:15px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
        <div style="width:45px; height:45px; border-radius:10px; background:var(--in); display:flex; align-items:center; justify-content:center; color:var(--tx);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.8-3.8a6 6 0 0 1-7.9 7.9l-6.9 6.9a2.1 2.1 0 0 1-3-3l6.9-6.9a6 6 0 0 1 7.9-7.9z" />
            </svg>
        </div>
        <div><b style="display:block; font-size:24px; font-weight:700; color:var(--tx); line-height:1.2;"><?= $status_counts['maintenance'] ?></b><span style="font-size:12px; color:var(--mut); font-weight:500;">Under maintenance</span></div>
    </div>

    <div style="background:var(--card); border:1px solid var(--bd); border-radius:10px; padding:20px; display:flex; align-items:center; gap:15px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
        <div style="width:45px; height:45px; border-radius:10px; background:var(--in); display:flex; align-items:center; justify-content:center; color:var(--tx);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <path d="M1 6h13v11H1zM14 10h4l3 3v4h-7z" />
                <circle cx="5.5" cy="18" r="2" />
                <circle cx="17.5" cy="18" r="2" />
            </svg>
        </div>
        <div><b style="display:block; font-size:24px; font-weight:700; color:var(--tx); line-height:1.2;"><?= $active_suppliers ?></b><span style="font-size:12px; color:var(--mut); font-weight:500;">Active suppliers</span></div>
    </div>

    <div style="background:var(--card); border:1px solid var(--bd); border-radius:10px; padding:20px; display:flex; align-items:center; gap:15px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
        <div style="width:45px; height:45px; border-radius:10px; background:var(--in); display:flex; align-items:center; justify-content:center; color:var(--tx);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <line x1="12" y1="1" x2="12" y2="23" />
                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
            </svg>
        </div>
        <div>
            <b style="display:block; font-size:20px; font-weight:700; color:var(--tx); line-height:1.2;">RM <?= number_format($total_rental_value, 2) ?></b>
            <span style="font-size:12px; color:var(--mut); font-weight:500;">Total Rental Value</span>
        </div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">

    <div style="display: flex; flex-direction: column; gap: 20px;">

        <!-- Rental Status Breakdown -->
        <div style="background:var(--card); border:1px solid var(--bd); border-radius:10px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
            <h3 style="margin-top:0; font-size:16px; font-weight:700; color:var(--tx); margin-bottom:20px;">Rental Status Overview</h3>

            <div style="display: flex; flex-direction: column; gap: 15px;">
                <!-- Active -->
                <div>
                    <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 5px; color: var(--tx);">
                        <span style="font-weight: 500;">Active / Rented</span>
                        <span style="font-weight: 600;"><?= $rental_status_counts['active'] ?> (<?= $total_rentals > 0 ? round(($rental_status_counts['active'] / $total_rentals) * 100) : 0 ?>%)</span>
                    </div>
                    <div style="width: 100%; background: var(--in); height: 8px; border-radius: 4px; overflow: hidden;">
                        <div style="width: <?= $total_rentals > 0 ? ($rental_status_counts['active'] / $total_rentals) * 100 : 0 ?>%; background: #1f5fd1; height: 100%;"></div>
                    </div>
                </div>

                <!-- Returned -->
                <div>
                    <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 5px; color: var(--tx);">
                        <span style="font-weight: 500;">Returned / Completed</span>
                        <span style="font-weight: 600;"><?= $rental_status_counts['returned'] ?> (<?= $total_rentals > 0 ? round(($rental_status_counts['returned'] / $total_rentals) * 100) : 0 ?>%)</span>
                    </div>
                    <div style="width: 100%; background: var(--in); height: 8px; border-radius: 4px; overflow: hidden;">
                        <div style="width: <?= $total_rentals > 0 ? ($rental_status_counts['returned'] / $total_rentals) * 100 : 0 ?>%; background: #1e8a3c; height: 100%;"></div>
                    </div>
                </div>

                <!-- Overdue -->
                <div>
                    <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 5px; color: var(--tx);">
                        <span style="font-weight: 500;">Overdue</span>
                        <span style="font-weight: 600;"><?= $rental_status_counts['overdue'] ?> (<?= $total_rentals > 0 ? round(($rental_status_counts['overdue'] / $total_rentals) * 100) : 0 ?>%)</span>
                    </div>
                    <div style="width: 100%; background: var(--in); height: 8px; border-radius: 4px; overflow: hidden;">
                        <div style="width: <?= $total_rentals > 0 ? ($rental_status_counts['overdue'] / $total_rentals) * 100 : 0 ?>%; background: #eab308; height: 100%;"></div>
                    </div>
                </div>

                <!-- Cancelled -->
                <div>
                    <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 5px; color: var(--tx);">
                        <span style="font-weight: 500;">Cancelled</span>
                        <span style="font-weight: 600;"><?= $rental_status_counts['cancelled'] ?> (<?= $total_rentals > 0 ? round(($rental_status_counts['cancelled'] / $total_rentals) * 100) : 0 ?>%)</span>
                    </div>
                    <div style="width: 100%; background: var(--in); height: 8px; border-radius: 4px; overflow: hidden;">
                        <div style="width: <?= $total_rentals > 0 ? ($rental_status_counts['cancelled'] / $total_rentals) * 100 : 0 ?>%; background: #d12a2a; height: 100%;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Donut Chart: Equipment by Category -->
        <div style="background:var(--card); border:1px solid var(--bd); border-radius:10px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
            <h3 style="margin-top:0; font-size:16px; font-weight:700; color:var(--tx); margin-bottom:20px;">Equipment by Category</h3>
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 20px;">
                <!-- SVG Donut -->
                <svg width="160" height="160" viewBox="0 0 120 120" style="flex-shrink: 0; transform: rotate(-90deg);">
                    <circle cx="60" cy="60" r="50" fill="none" stroke="#e3e6ee" stroke-width="20" />
                    <?php foreach ($cat_data as $cd): ?>
                        <circle cx="60" cy="60" r="50" fill="none" stroke="<?= $cd['color'] ?>" stroke-width="20"
                            stroke-dasharray="<?= $cd['dasharray'] ?>"
                            stroke-dashoffset="<?= $cd['dashoffset'] ?>"
                            style="transition: stroke-dasharray 0.5s ease;" />
                    <?php endforeach; ?>
                    <text x="60" y="60" fill="#141b4d" font-size="18" font-weight="700" text-anchor="middle" dominant-baseline="central" transform="rotate(90 60 60)"><?= $total_eq ?></text>
                    <text x="60" y="75" fill="#6b7194" font-size="10" text-anchor="middle" dominant-baseline="central" transform="rotate(90 60 60)">Total</text>
                </svg>

                <!-- Legend -->
                <div style="display: flex; flex-direction: column; gap: 10px; width: 100%;">
                    <?php foreach ($cat_data as $cd): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; font-size: 13px; color: var(--tx);">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="width: 10px; height: 10px; border-radius: 50%; background-color: <?= $cd['color'] ?>; display: inline-block;"></span>
                                <span style="font-weight: 500;"><?= htmlspecialchars($cd['name']) ?></span>
                            </div>
                            <span style="font-weight: 600; color: var(--mut);"><?= $cd['count'] ?> (<?= round($cd['pct']) ?>%)</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- RIGHT COLUMN: Lists -->
    <div style="display: flex; flex-direction: column; gap: 20px;">

        <!-- Rentals Due -->
        <div style="background:var(--card); border:1px solid var(--bd); border-radius:10px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
            <h3 style="margin-top:0; font-size:16px; font-weight:700; color:var(--tx); margin-bottom:20px;">Rentals due for return</h3>
            <?php if (count($due_rentals) > 0): ?>
                <?php foreach ($due_rentals as $dr):
                    $end = new DateTime($dr['end_date']);
                    $today = new DateTime(date('Y-m-d'));
                    $diff = $today->diff($end)->days;
                    $is_overdue = $end < $today;

                    if ($is_overdue) {
                        $badge_text = "Overdue";
                        $badge_style = getStatusBadge('cancelled');
                    } elseif ($diff == 0) {
                        $badge_text = "Due today";
                        $badge_style = getStatusBadge('reserved');
                    } else {
                        $badge_text = "In " . $diff . " days";
                        $badge_style = getStatusBadge('rented');
                    }
                ?>
                    <div style="display:flex; justify-content:space-between; align-items:center; padding:15px 0; border-bottom:1px solid var(--bd);">
                        <div>
                            <b style="display:block; font-size:14px; color:var(--tx); margin-bottom:2px;"><?= htmlspecialchars($dr['cust_name']) ?></b>
                            <span style="font-size:12px; color:var(--mut);">EQ-<?= str_pad($dr['equipment_id'], 3, '0', STR_PAD_LEFT) ?> · <?= htmlspecialchars($dr['equipment_name']) ?> · ends <?= htmlspecialchars($dr['end_date']) ?></span>
                        </div>
                        <div style="padding:4px 10px; border-radius:20px; font-size:11px; font-weight:600; <?= $badge_style ?>"><?= $badge_text ?></div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="font-size:13px; color:var(--mut);">No active rentals due at the moment.</div>
            <?php endif; ?>
        </div>

        <!-- Open Maintenance -->
        <div style="background:var(--card); border:1px solid var(--bd); border-radius:10px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
            <h3 style="margin-top:0; font-size:16px; font-weight:700; color:var(--tx); margin-bottom:20px;">Open maintenance</h3>
            <?php if (count($open_maintenance) > 0): ?>
                <?php foreach ($open_maintenance as $om): ?>
                    <div style="display:flex; justify-content:space-between; align-items:center; padding:15px 0; border-bottom:1px solid var(--bd);">
                        <div>
                            <b style="display:block; font-size:14px; color:var(--tx); margin-bottom:2px;">EQ-<?= str_pad($om['equipment_id'], 3, '0', STR_PAD_LEFT) ?> · <?= htmlspecialchars($om['equipment_name']) ?></b>
                            <span style="font-size:12px; color:var(--mut);"><?= htmlspecialchars($om['condition_found']) ?></span>
                        </div>
                        <div style="padding:4px 10px; border-radius:20px; font-size:11px; font-weight:600; <?= getStatusBadge('in progress') ?>"><?= htmlspecialchars($om['maintenance_status']) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="font-size:13px; color:var(--mut);">No open maintenance records.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Recent Rentals -->
<div style="background:var(--card); border:1px solid var(--bd); border-radius:10px; padding:20px; margin-top:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
    <h3 style="margin-top:0; font-size:16px; font-weight:700; color:var(--tx); margin-bottom:20px;">Recent Rentals</h3>
    <table style="width:100%; border-collapse:collapse; font-size:13px;">
        <thead>
            <tr>
                <th style="text-align:left; padding:10px; border-bottom:1px solid var(--bd); color:var(--mut); font-weight:500;">ID</th>
                <th style="text-align:left; padding:10px; border-bottom:1px solid var(--bd); color:var(--mut); font-weight:500;">Customer</th>
                <th style="text-align:left; padding:10px; border-bottom:1px solid var(--bd); color:var(--mut); font-weight:500;">Equipment</th>
                <th style="text-align:left; padding:10px; border-bottom:1px solid var(--bd); color:var(--mut); font-weight:500;">Dates</th>
                <th style="text-align:left; padding:10px; border-bottom:1px solid var(--bd); color:var(--mut); font-weight:500;">Total Value</th>
                <th style="text-align:left; padding:10px; border-bottom:1px solid var(--bd); color:var(--mut); font-weight:500;">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($recent_rentals) > 0): ?>
                <?php foreach ($recent_rentals as $rr): ?>
                    <tr>
                        <td style="padding:12px 10px; border-bottom:1px solid var(--row);">RN-<?= str_pad($rr['rental_id'], 3, '0', STR_PAD_LEFT) ?></td>
                        <td style="padding:12px 10px; border-bottom:1px solid var(--row);"><?= htmlspecialchars($rr['cust_name']) ?></td>
                        <td style="padding:12px 10px; border-bottom:1px solid var(--row);"><?= htmlspecialchars($rr['equipment_name']) ?></td>
                        <td style="padding:12px 10px; border-bottom:1px solid var(--row);"><?= htmlspecialchars($rr['start_date']) ?> to <?= htmlspecialchars($rr['end_date']) ?></td>
                        <td style="padding:12px 10px; border-bottom:1px solid var(--row);">RM <?= number_format($rr['total_cost'], 2) ?></td>
                        <td style="padding:12px 10px; border-bottom:1px solid var(--row);"><span style="padding:4px 10px; border-radius:20px; font-size:11px; font-weight:600; <?= getStatusBadge($rr['rental_status']) ?>"><?= htmlspecialchars($rr['rental_status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6" style="text-align:center; color:var(--mut); padding:20px;">No rental records found.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

</div>
</main>
</body>

</html>