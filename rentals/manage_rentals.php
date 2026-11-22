<?php
// 1. Initialize session securely (same as equipment module)
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

require_once __DIR__ . '/../config/db_connection.php';

// 2. Page variables & login check
$pageTitle = 'Rentals';
$activePage = 'rt';

if (!isset($_SESSION['role']) || !isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Admin: create / read / update / delete.  Staff: create / read / update (no delete).
$isAdmin = ($_SESSION['role'] === 'admin');
$errors = [];
$success_msg = '';
$reopen = null; // posted values to refill the form when validation fails

const RENTAL_STATUSES  = ['Reserved', 'Active', 'Overdue', 'Returned', 'Cancelled'];
const CLOSED_STATUSES  = ['Returned', 'Cancelled'];
const DELIVERY_METHODS = ['pickup' => 'Pickup', 'delivery' => 'Delivery'];

// 3. CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

/**
 * Keep equipment.equipment_status in step with its rentals.
 * Active/Overdue -> rented, Reserved -> reserved, none -> available.
 * Equipment under maintenance (or any other status) is never overwritten.
 */
function syncEquipmentStatus(PDO $pdo, int $equipmentId): void
{
    $cur = $pdo->prepare("SELECT equipment_status FROM equipment WHERE equipment_id = ?");
    $cur->execute([$equipmentId]);
    $status = $cur->fetchColumn();
    if ($status === false || !in_array(strtolower($status), ['available', 'reserved', 'rented'], true)) {
        return;
    }

    $q = $pdo->prepare("SELECT rental_status FROM rentals WHERE equipment_id = ? AND rental_status IN ('Active','Overdue','Reserved')");
    $q->execute([$equipmentId]);
    $open = $q->fetchAll(PDO::FETCH_COLUMN);

    $new = 'available';
    if (array_intersect($open, ['Active', 'Overdue'])) {
        $new = 'rented';
    } elseif ($open) {
        $new = 'reserved';
    }
    $pdo->prepare("UPDATE equipment SET equipment_status = ? WHERE equipment_id = ?")->execute([$new, $equipmentId]);
}

function validDate(string $d): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

// 4. Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Security Error: CSRF token validation failed. Action blocked.");
    }

    $action = $_POST['action'] ?? '';

    // --- ADD or EDIT (admin + staff) ---
    if ($action === 'add' || $action === 'edit') {
        $id        = (int)($_POST['rental_id'] ?? 0);
        $cust      = trim($_POST['cust_name'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $ic        = trim($_POST['ic_passport'] ?? '');
        $equipId   = (int)($_POST['equipment_id'] ?? 0);
        $start     = $_POST['start_date'] ?? '';
        $end       = $_POST['end_date'] ?? '';
        $status    = $_POST['rental_status'] ?? '';
        $method    = $_POST['delivery_method'] ?? '';
        $address   = trim($_POST['delivery_address'] ?? '');

        // BACKEND VALIDATION
        if ($cust === '') $errors[] = "Customer name is required.";
        if ($phone === '') $errors[] = "Phone is required.";
        elseif (!preg_match('/^\+?[0-9][0-9\s\-]{6,18}$/', $phone)) $errors[] = "Phone number format is invalid.";
        if ($email === '') $errors[] = "Email is required.";
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Email address is invalid.";
        if ($ic === '') $errors[] = "IC / Passport number is required.";
        if ($equipId <= 0) $errors[] = "Please select equipment.";
        if (!validDate($start)) $errors[] = "A valid rental start date is required.";
        if (!validDate($end)) $errors[] = "A valid rental end date is required.";
        if (!in_array($status, RENTAL_STATUSES, true)) $errors[] = "Please select a valid status.";

        // Only administrators may cancel a booking (a rental already cancelled can be saved unchanged)
        if (!$isAdmin && $status === 'Cancelled') {
            $prev = '';
            if ($action === 'edit') {
                $p = $pdo->prepare("SELECT rental_status FROM rentals WHERE rental_id = ?");
                $p->execute([$id]);
                $prev = (string)$p->fetchColumn();
            }
            if ($prev !== 'Cancelled') $errors[] = "Only administrators can cancel a booking.";
        }
        if (!isset(DELIVERY_METHODS[$method])) $errors[] = "Please choose Pickup or Delivery.";
        if ($method === 'delivery' && $address === '') $errors[] = "Delivery address is required when the delivery method is Delivery.";
        if ($method === 'pickup') $address = '';

        if (empty($errors) && $end < $start) $errors[] = "End date must be on or after the start date.";

        $rate = null;
        if (empty($errors)) {
            $eq = $pdo->prepare("SELECT rental_rate, equipment_status FROM equipment WHERE equipment_id = ?");
            $eq->execute([$equipId]);
            $equip = $eq->fetch(PDO::FETCH_ASSOC);
            if (!$equip) {
                $errors[] = "Selected equipment does not exist.";
            } else {
                $rate = (float)$equip['rental_rate'];
                $isOpen = !in_array($status, CLOSED_STATUSES, true);

                if ($isOpen) {
                    // Equipment in maintenance cannot be booked
                    if (strtolower($equip['equipment_status']) === 'maintenance') {
                        $errors[] = "This equipment is under maintenance and cannot be booked.";
                    }
                    // Double-booking check
                    $dup = $pdo->prepare("SELECT rental_id FROM rentals
                        WHERE equipment_id = ? AND rental_id != ? AND rental_status NOT IN ('Returned','Cancelled')
                        AND ? <= end_date AND ? >= start_date");
                    $dup->execute([$equipId, $id, $start, $end]);
                    if ($dup->fetch()) {
                        $errors[] = "This equipment is already booked for those dates.";
                    }
                }
            }
        }

        if (empty($errors)) {
            // Total cost = days x daily rate (minimum 1 day), calculated server-side
            $days  = max(1, (int)round((strtotime($end) - strtotime($start)) / 86400));
            $total = $days * $rate;

            try {
                $pdo->beginTransaction();
                $oldEquip = null;

                if ($action === 'add') {
                    $stmt = $pdo->prepare("INSERT INTO rentals (equipment_id, user_id, cust_name, phone, email, ic_passport, start_date, end_date, total_cost, rental_status, delivery_method, delivery_address)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$equipId, $_SESSION['user_id'], $cust, $phone, $email, $ic, $start, $end, $total, $status, $method, $address === '' ? null : $address]);
                    $success_msg = "Rental created successfully.";
                } else {
                    $old = $pdo->prepare("SELECT equipment_id FROM rentals WHERE rental_id = ?");
                    $old->execute([$id]);
                    $oldEquip = $old->fetchColumn();
                    if ($oldEquip === false) throw new RuntimeException("Rental not found.");

                    $stmt = $pdo->prepare("UPDATE rentals SET equipment_id=?, cust_name=?, phone=?, email=?, ic_passport=?, start_date=?, end_date=?, total_cost=?, rental_status=?, delivery_method=?, delivery_address=? WHERE rental_id=?");
                    $stmt->execute([$equipId, $cust, $phone, $email, $ic, $start, $end, $total, $status, $method, $address === '' ? null : $address, $id]);
                    $success_msg = "Rental updated successfully.";
                }

                syncEquipmentStatus($pdo, $equipId);
                if ($oldEquip !== null && (int)$oldEquip !== $equipId) {
                    syncEquipmentStatus($pdo, (int)$oldEquip);
                }
                $pdo->commit();
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $success_msg = '';
                $errors[] = "Could not save the rental. Please try again.";
            }
        }

        if (!empty($errors)) {
            $reopen = [
                'action' => $action,
                'rental_id' => $id,
                'cust_name' => $cust,
                'phone' => $phone,
                'email' => $email,
                'ic_passport' => $ic,
                'equipment_id' => $equipId ?: '',
                'start_date' => $start,
                'end_date' => $end,
                'rental_status' => $status,
                'delivery_method' => $method,
                'delivery_address' => $address
            ];
        }
    }

    // --- DELETE (admin only) ---
    if ($action === 'delete') {
        if (!$isAdmin) {
            die("Security Error: Unauthorized access. Only administrators can delete rentals.");
        }
        $id = (int)($_POST['rental_id'] ?? 0);

        $used = $pdo->prepare("SELECT COUNT(*) FROM maintenance WHERE rental_id = ?");
        $used->execute([$id]);

        if ($used->fetchColumn() > 0) {
            $errors[] = "Action failed: This rental is linked to a return/maintenance record and cannot be deleted.";
        } else {
            $g = $pdo->prepare("SELECT equipment_id FROM rentals WHERE rental_id = ?");
            $g->execute([$id]);
            $equipId = $g->fetchColumn();

            $pdo->prepare("DELETE FROM rentals WHERE rental_id = ?")->execute([$id]);
            if ($equipId !== false) syncEquipmentStatus($pdo, (int)$equipId);
            $success_msg = "Rental deleted successfully.";
        }
    }
}

// 5. Auto-flag overdue: Active rentals whose end date has passed become Overdue
//    (equipment stays 'rented' since Overdue also counts as rented)
date_default_timezone_set('Asia/Kuala_Lumpur');
$pdo->prepare("UPDATE rentals SET rental_status = 'Overdue' WHERE rental_status = 'Active' AND end_date < ?")
    ->execute([date('Y-m-d')]);

// 5a. Auto-activate: Reserved rentals whose start date has arrived become Active
//     (only while the rental period is still running), and equipment becomes 'rented'
$today = date('Y-m-d');
$due = $pdo->prepare("SELECT rental_id, equipment_id FROM rentals WHERE rental_status = 'Reserved' AND start_date <= ? AND end_date >= ?");
$due->execute([$today, $today]);
$dueRows = $due->fetchAll(PDO::FETCH_ASSOC);
if ($dueRows) {
    $act = $pdo->prepare("UPDATE rentals SET rental_status = 'Active' WHERE rental_id = ?");
    foreach ($dueRows as $d) {
        $act->execute([$d['rental_id']]);
        syncEquipmentStatus($pdo, (int)$d['equipment_id']);
    }
}

// 5b. Fetch data
$equipList = $pdo->query("SELECT equipment_id, equipment_name, rental_rate, equipment_status FROM equipment ORDER BY equipment_name")->fetchAll(PDO::FETCH_ASSOC);

$rentals = $pdo->query("SELECT r.*, e.equipment_name, e.rental_rate
    FROM rentals r LEFT JOIN equipment e ON r.equipment_id = e.equipment_id
    ORDER BY r.rental_id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Existing bookings for the instant date-clash check in the form (JS)
$bookings = array_map(fn($r) => [
    'id' => (int)$r['rental_id'],
    'eq' => (int)$r['equipment_id'],
    's' => $r['start_date'],
    'e' => $r['end_date'],
    'st' => $r['rental_status'],
    'code' => 'R-' . str_pad($r['rental_id'], 4, '0', STR_PAD_LEFT)
], $rentals);

$statusBadge = ['Reserved' => 'Y', 'Active' => 'G', 'Overdue' => 'R', 'Returned' => 'P', 'Cancelled' => 'S'];

// 6. Navigation
require_once '../includes/navigation.php';
?>

<!-- 7. HTML Output -->
<div class="hd">
    <div>
        <h1>Rentals</h1>
        <div class="sub">Manage rental bookings: customer details, equipment, rental dates, delivery method, total cost and status.</div>
    </div>
    <button class="btn" onclick="openAddModal()">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path d="M12 5v14M5 12h14" />
        </svg> New Rental
    </button>
</div>

<?php if (!empty($errors) && !$reopen): ?>
    <div style="background: #fee2e2; color: #991b1b; padding: 15px; margin: 16px 0; border-radius: 5px; border: 1px solid #fecaca;">
        <strong>Please fix the following errors:</strong>
        <ul style="margin-top: 5px; margin-bottom: 0; padding-left: 20px;">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!empty($success_msg)): ?>
    <div style="background: #dcfce7; color: #166534; padding: 15px; margin: 16px 0; border-radius: 5px; border: 1px solid #bbf7d0;">
        <?= htmlspecialchars($success_msg) ?>
    </div>
<?php endif; ?>

<!-- Search & Filter Toolbar -->
<div class="tb">
    <div class="sw">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"></circle>
            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
        </svg>
        <input type="text" class="in" id="searchBox" placeholder="Search rentals..." oninput="applyFilters()">
    </div>
    <div class="sp"></div>
    <select class="in" id="filterDelivery" onchange="applyFilters()">
        <option value="">All Delivery</option>
        <?php foreach (DELIVERY_METHODS as $k => $label): ?>
            <option value="<?= $k ?>"><?= $label ?></option>
        <?php endforeach; ?>
    </select>
    <select class="in" id="filterStatus" onchange="applyFilters()">
        <option value="">All Status</option>
        <?php foreach (RENTAL_STATUSES as $s): ?>
            <option value="<?= $s ?>"><?= $s ?></option>
        <?php endforeach; ?>
    </select>
</div>

<!-- Data Table -->
<div class="tw">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Customer</th>
                <th>Phone</th>
                <th>Equipment</th>
                <th>Start</th>
                <th>End</th>
                <th>Delivery</th>
                <th>Total</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($rentals) > 0): ?>
                <?php foreach ($rentals as $r): ?>
                    <?php
                    $rowId = "row-" . $r['rental_id'];
                    $code = 'R-' . str_pad($r['rental_id'], 4, '0', STR_PAD_LEFT);
                    $badge = $statusBadge[$r['rental_status']] ?? 'S';
                    $isDelivery = ($r['delivery_method'] === 'delivery');
                    ?>
                    <tr class="m data-row" data-status="<?= htmlspecialchars($r['rental_status']) ?>" data-delivery="<?= htmlspecialchars($r['delivery_method']) ?>">
                        <td><span class="id"><?= $code ?></span></td>
                        <td><?= htmlspecialchars($r['cust_name']) ?></td>
                        <td><?= htmlspecialchars($r['phone']) ?></td>
                        <td><?= htmlspecialchars($r['equipment_name'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($r['start_date']) ?></td>
                        <td><?= htmlspecialchars($r['end_date']) ?></td>
                        <td><span class="bd <?= $isDelivery ? 'B' : 'S' ?>"><?= $isDelivery ? 'Delivery' : 'Pickup' ?></span></td>
                        <td>RM <?= number_format($r['total_cost'], 2) ?></td>
                        <td><span class="bd <?= $badge ?>"><?= htmlspecialchars($r['rental_status']) ?></span></td>
                        <td style="text-align:right; white-space: nowrap;">
                            <button class="ic" title="Edit" onclick='openEditModal(<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>)'>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                    <path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z" />
                                </svg>
                            </button>
                            <?php if ($isAdmin): ?>
                                <button class="ic x" title="Delete" onclick="initiateDelete(<?= (int)$r['rental_id'] ?>, '<?= $code ?>')">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                        <path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                    </svg>
                                </button>
                            <?php endif; ?>
                            <button class="ic" title="Expand" onclick="toggleRow('<?= $rowId ?>')">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                    <path d="M6 9l6 6 6-6" />
                                </svg>
                            </button>
                        </td>
                    </tr>

                    <!-- Expanded Row -->
                    <tr id="<?= $rowId ?>" class="detail-row" style="display: none; background-color: #f8fafc;">
                        <td colspan="10" style="padding: 15px 20px; border-bottom: 1px solid #e2e8f0;">
                            <div style="display: flex; gap: 40px; align-items: center; font-size: 13px; color: #475569;">
                                <div style="display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; background: #fff; border: 1px solid #cbd5e1; border-radius: 8px;">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="1.8">
                                        <path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3z" />
                                        <circle cx="12" cy="13" r="3.5" />
                                    </svg>
                                </div>
                                <div style="flex: 1;">
                                    <div style="font-weight: 600; margin-bottom: 2px;"><?= htmlspecialchars($r['equipment_name'] ?? 'N/A') ?></div>
                                    <div style="color: #94a3b8; font-size: 12px;">EQ-<?= str_pad($r['equipment_id'], 3, '0', STR_PAD_LEFT) ?></div>
                                </div>
                                <div style="flex: 1;">
                                    <div style="font-weight: 600; margin-bottom: 2px;">IC / Passport</div>
                                    <div style="color: #64748b; font-size: 12px;"><?= htmlspecialchars($r['ic_passport']) ?></div>
                                </div>
                                <div style="flex: 1;">
                                    <div style="font-weight: 600; margin-bottom: 2px;">Email</div>
                                    <div style="color: #64748b; font-size: 12px;"><?= htmlspecialchars($r['email']) ?></div>
                                </div>
                                <div style="flex: 1;">
                                    <div style="font-weight: 600; margin-bottom: 2px;">Rental Rate</div>
                                    <div style="color: #64748b; font-size: 12px;">RM <?= number_format($r['rental_rate'] ?? 0, 2) ?> / day</div>
                                </div>
                                <div style="flex: 2;">
                                    <div style="font-weight: 600; margin-bottom: 2px;"><?= $isDelivery ? 'Delivery address' : 'Pickup point' ?></div>
                                    <div style="color: #64748b; font-size: 12px;"><?= $isDelivery ? htmlspecialchars($r['delivery_address'] ?: 'No address provided.') : 'Store counter' ?></div>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="10" class="emp" style="text-align:center; padding:20px;">No rental records found.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    <div id="noMatch" class="emp" style="display:none; text-align:center; padding:20px;">No records found.</div>
</div>
<div class="pf">Showing <span id="shownCount"><?= count($rentals) ?></span> of <?= count($rentals) ?> records</div>

<!-- ========================================== -->
<!-- MODALS -->
<!-- ========================================== -->

<!-- Add / Edit Modal -->
<div id="formModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:#fff; width:750px; max-width:95%; max-height:92vh; overflow:auto; border-radius:12px; padding:30px; position:relative; font-family: 'Inter', sans-serif; box-shadow: 0 10px 25px rgba(0,0,0,0.1);">

        <h2 id="modalTitle" style="margin-top:0; font-size:22px; font-weight:700; color:#0f172a; margin-bottom:25px;">New Rental</h2>

        <?php if ($reopen && !empty($errors)): ?>
            <div style="background:#fee2e2; color:#991b1b; padding:12px 15px; margin-bottom:20px; border-radius:6px; border:1px solid #fecaca; font-size:13px;">
                <strong>Please fix the following errors:</strong>
                <ul style="margin:5px 0 0; padding-left:20px;">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="manage_rentals.php" id="rentalForm" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="rental_id" id="formId" value="">

            <style>
                #formModal label {
                    font-size: 13px;
                    font-weight: 600;
                    color: #334155;
                    display: block;
                    margin-bottom: 6px;
                }

                #formModal input[type=text],
                #formModal input[type=email],
                #formModal input[type=date],
                #formModal select,
                #formModal textarea {
                    width: 100%;
                    padding: 10px 12px;
                    background-color: #f8fafc;
                    border: 1px solid #cbd5e1;
                    border-radius: 6px;
                    font-size: 14px;
                    color: #0f172a;
                    font-family: 'Inter', sans-serif;
                    transition: border-color 0.2s, box-shadow 0.2s;
                }

                #formModal input:focus,
                #formModal select:focus,
                #formModal textarea:focus {
                    outline: none;
                    border-color: #3b82f6;
                    background-color: #fff;
                    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
                }

                #formModal select {
                    appearance: none;
                    background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%23475569%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.2-5.5-12.8z%22%2F%3E%3C%2Fsvg%3E");
                    background-repeat: no-repeat;
                    background-position: right 12px center;
                    background-size: 10px;
                    padding-right: 30px;
                }

                .seg {
                    display: flex;
                    gap: 12px;
                }

                .seg label {
                    flex: 1;
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    margin: 0;
                    padding: 10px 12px;
                    border: 1px solid #cbd5e1;
                    border-radius: 6px;
                    background: #f8fafc;
                    cursor: pointer;
                    font-weight: 500;
                }

                .seg label:has(input:checked) {
                    border-color: #3b82f6;
                    background: #eff6ff;
                    color: #1d4ed8;
                }

                .seg input {
                    width: auto;
                    margin: 0;
                }
            </style>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom:20px;">
                <div>
                    <label>Customer Name *</label>
                    <input type="text" name="cust_name" id="formCust" maxlength="100">
                </div>
                <div>
                    <label>Phone *</label>
                    <input type="text" name="phone" id="formPhone" maxlength="20" placeholder="e.g. 012-345 6789">
                </div>
                <div>
                    <label>Email *</label>
                    <input type="email" name="email" id="formEmail" maxlength="100">
                </div>
                <div>
                    <label>IC / Passport No. *</label>
                    <input type="text" name="ic_passport" id="formIc" maxlength="50">
                </div>
                <div style="grid-column: 1 / -1;">
                    <label>Equipment *</label>
                    <select name="equipment_id" id="formEquip" onchange="updateTotal()">
                        <option value="">Select...</option>
                        <?php foreach ($equipList as $eq): ?>
                            <option value="<?= (int)$eq['equipment_id'] ?>" data-rate="<?= htmlspecialchars($eq['rental_rate']) ?>">
                                EQ-<?= str_pad($eq['equipment_id'], 3, '0', STR_PAD_LEFT) ?> · <?= htmlspecialchars($eq['equipment_name']) ?> (<?= ucfirst(htmlspecialchars($eq['equipment_status'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Rental Start *</label>
                    <input type="date" name="start_date" id="formStart" onchange="updateTotal()">
                </div>
                <div>
                    <label>Rental End *</label>
                    <input type="date" name="end_date" id="formEnd" onchange="updateTotal()">
                </div>
                <div>
                    <label>Status *</label>
                    <select name="rental_status" id="formStatus" onchange="updateTotal()">
                        <?php foreach (RENTAL_STATUSES as $s): ?>
                            <option value="<?= $s ?>" <?= ($s === 'Cancelled' && !$isAdmin) ? 'disabled' : '' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Estimated Total</label>
                    <div id="totalPreview" style="padding:10px 12px; font-weight:600; color:#0f172a;">—</div>
                </div>
                <div id="dateWarn" style="grid-column: 1 / -1; display:none; background:#fee2e2; color:#991b1b; border:1px solid #fecaca; border-radius:6px; padding:10px 12px; font-size:13px;"></div>
                <div style="grid-column: 1 / -1;">
                    <label>Delivery Method *</label>
                    <div class="seg">
                        <label><input type="radio" name="delivery_method" value="pickup" id="dmPickup" checked onchange="toggleDelivery()"> Pickup (store counter)</label>
                        <label><input type="radio" name="delivery_method" value="delivery" id="dmDelivery" onchange="toggleDelivery()"> Delivery</label>
                    </div>
                </div>
                <div id="addressWrap" style="grid-column: 1 / -1; display:none;">
                    <label>Delivery Address *</label>
                    <textarea name="delivery_address" id="formAddress" rows="3" style="resize:vertical;"></textarea>
                </div>
            </div>

            <div style="font-size:12px; color:#64748b; margin-bottom:22px;">ℹ Total cost is calculated automatically (days × daily rate).</div>

            <div style="text-align:right; display:flex; justify-content:flex-end; gap:12px;">
                <button type="button" onclick="closeModal('formModal')" style="padding:10px 20px; background:#fff; border:1px solid #cbd5e1; color:#334155; border-radius:6px; cursor:pointer; font-weight:600; font-size:14px; font-family: 'Inter', sans-serif;">Cancel</button>
                <button type="submit" style="padding:10px 20px; background:#0f172a; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:600; font-size:14px; font-family: 'Inter', sans-serif;">Save</button>
            </div>
        </form>
    </div>
</div>

<?php if ($isAdmin): ?>
    <!-- Delete Confirmation Modal (admin only) -->
    <div id="deleteModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); z-index:1000; align-items:center; justify-content:center;">
        <div style="background:#fff; width:450px; max-width:95%; border-radius:12px; padding:25px; font-family: 'Inter', sans-serif; box-shadow: 0 10px 25px rgba(0,0,0,0.1);">
            <h2 style="margin-top:0; font-size:20px; font-weight:700; color:#0f172a;">Delete record?</h2>
            <p style="color:#475569; font-size:14px; margin-bottom:25px;"><span id="deleteName" style="font-weight:600; color:#0f172a;"></span> will be permanently removed. This cannot be undone.</p>

            <form method="POST" action="manage_rentals.php">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="rental_id" id="deleteId" value="">
                <div style="display:flex; justify-content:flex-end; gap:12px;">
                    <button type="button" onclick="closeModal('deleteModal')" style="padding:10px 20px; background:#fff; border:1px solid #cbd5e1; border-radius:6px; cursor:pointer; font-weight:600; color:#334155;">Cancel</button>
                    <button type="submit" style="padding:10px 20px; background:#dc2626; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:600;">Delete</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- JavaScript -->
<script>
    const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;

    function toggleRow(rowId) {
        var row = document.getElementById(rowId);
        row.style.display = (row.style.display === "none") ? "table-row" : "none";
    }

    function openModal(id) {
        document.getElementById(id).style.display = 'flex';
    }

    function closeModal(id) {
        document.getElementById(id).style.display = 'none';
    }

    function resetBorders() {
        document.querySelectorAll('#formModal input, #formModal select, #formModal textarea').forEach(function(el) {
            el.style.borderColor = '#cbd5e1';
        });
    }

    function toggleDelivery() {
        var isDelivery = document.getElementById('dmDelivery').checked;
        document.getElementById('addressWrap').style.display = isDelivery ? 'block' : 'none';
    }

    // Existing bookings (used for the instant date-clash warning)
    var BOOKINGS = <?= json_encode($bookings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    // Returns true (and shows a warning) if this equipment is already booked on the chosen dates
    function checkOverlap() {
        var eq = parseInt(document.getElementById('formEquip').value, 10) || 0;
        var s = document.getElementById('formStart').value;
        var e = document.getElementById('formEnd').value;
        var st = document.getElementById('formStatus').value;
        var id = parseInt(document.getElementById('formId').value, 10) || 0;
        var warn = document.getElementById('dateWarn');
        var startEl = document.getElementById('formStart'),
            endEl = document.getElementById('formEnd');

        var hit = null;
        if (eq && s && e && e >= s && st !== 'Returned' && st !== 'Cancelled') {
            hit = BOOKINGS.find(function(b) {
                return b.eq === eq && b.id !== id && b.st !== 'Returned' && b.st !== 'Cancelled' && s <= b.e && e >= b.s;
            }) || null;
        }

        if (hit) {
            warn.textContent = '⚠ This equipment is already booked from ' + hit.s + ' to ' + hit.e + ' (' + hit.code + '). Please choose different dates or another equipment.';
            warn.style.display = 'block';
            startEl.style.borderColor = endEl.style.borderColor = '#dc2626';
        } else {
            warn.style.display = 'none';
            if (startEl.style.borderColor === 'rgb(220, 38, 38)') startEl.style.borderColor = '#cbd5e1';
            if (endEl.style.borderColor === 'rgb(220, 38, 38)') endEl.style.borderColor = '#cbd5e1';
        }
        return !!hit;
    }

    // Live estimate of total cost (server recalculates on save)
    function updateTotal() {
        checkOverlap();
        var opt = document.getElementById('formEquip').selectedOptions[0];
        var s = document.getElementById('formStart').value;
        var e = document.getElementById('formEnd').value;
        var out = document.getElementById('totalPreview');
        if (!opt || !opt.dataset.rate || !s || !e || e < s) {
            out.textContent = '—';
            return;
        }
        var days = Math.max(1, Math.round((new Date(e) - new Date(s)) / 86400000));
        out.textContent = 'RM ' + (days * parseFloat(opt.dataset.rate)).toFixed(2) + ' (' + days + ' day' + (days > 1 ? 's' : '') + ')';
    }

    function fillForm(d) {
        document.getElementById('formId').value = d.rental_id || '';
        document.getElementById('formCust').value = d.cust_name || '';
        document.getElementById('formPhone').value = d.phone || '';
        document.getElementById('formEmail').value = d.email || '';
        document.getElementById('formIc').value = d.ic_passport || '';
        document.getElementById('formEquip').value = d.equipment_id || '';
        document.getElementById('formStart').value = d.start_date || '';
        document.getElementById('formEnd').value = d.end_date || '';
        // Staff cannot choose Cancelled (unless the rental is already cancelled)
        var cancelOpt = document.querySelector('#formStatus option[value="Cancelled"]');
        if (cancelOpt && !IS_ADMIN) cancelOpt.disabled = (d.rental_status !== 'Cancelled');
        document.getElementById('formStatus').value = d.rental_status || 'Reserved';
        document.getElementById('formAddress').value = d.delivery_address || '';
        var isDelivery = d.delivery_method === 'delivery';
        document.getElementById('dmDelivery').checked = isDelivery;
        document.getElementById('dmPickup').checked = !isDelivery;
        toggleDelivery();
        resetBorders();
        updateTotal();
    }

    function openAddModal() {
        document.getElementById('modalTitle').innerText = 'New Rental';
        document.getElementById('formAction').value = 'add';
        fillForm({});
        openModal('formModal');
    }

    function openEditModal(data) {
        document.getElementById('modalTitle').innerText = 'Edit Rental';
        document.getElementById('formAction').value = 'edit';
        fillForm(data);
        openModal('formModal');
    }

    function initiateDelete(id, code) {
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteName').innerText = code;
        openModal('deleteModal');
    }

    // Search + filters (client side)
    function applyFilters() {
        var q = document.getElementById('searchBox').value.toLowerCase();
        var st = document.getElementById('filterStatus').value;
        var dm = document.getElementById('filterDelivery').value;
        var shown = 0;
        document.querySelectorAll('tr.data-row').forEach(function(row) {
            var ok = (!st || row.dataset.status === st) &&
                (!dm || row.dataset.delivery === dm) &&
                (!q || row.innerText.toLowerCase().indexOf(q) !== -1);
            row.style.display = ok ? '' : 'none';
            var detail = row.nextElementSibling;
            if (!ok && detail && detail.classList.contains('detail-row')) detail.style.display = 'none';
            if (ok) shown++;
        });
        document.getElementById('shownCount').textContent = shown;
        document.getElementById('noMatch').style.display = shown ? 'none' : 'block';
    }

    // Inline feedback (client side; server validates again)
    document.getElementById('rentalForm').addEventListener('submit', function(e) {
        var isValid = true;
        var required = ['formCust', 'formPhone', 'formEmail', 'formIc', 'formEquip', 'formStart', 'formEnd'];
        if (document.getElementById('dmDelivery').checked) required.push('formAddress');

        required.forEach(function(id) {
            var el = document.getElementById(id);
            if (!el.value.trim()) {
                el.style.borderColor = '#dc2626';
                isValid = false;
            } else {
                el.style.borderColor = '#cbd5e1';
            }
        });

        var s = document.getElementById('formStart').value,
            en = document.getElementById('formEnd').value;
        if (s && en && en < s) {
            document.getElementById('formEnd').style.borderColor = '#dc2626';
            e.preventDefault();
            alert('End date must be on or after the start date.');
            return;
        }
        if (!isValid) {
            e.preventDefault();
            alert('Please fill in all required fields marked with *.');
            return;
        }
        if (checkOverlap()) {
            e.preventDefault();
        }
    });

    <?php if ($reopen): ?>
            // Validation failed on the server: reopen the form with what the user typed
            (function() {
                var d = <?= json_encode($reopen, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                document.getElementById('modalTitle').innerText = d.action === 'edit' ? 'Edit Rental' : 'New Rental';
                document.getElementById('formAction').value = d.action;
                fillForm(d);
                openModal('formModal');
            })();
    <?php endif; ?>
</script>

</div>
</main>
</body>

</html>