<?php
// Initialize session securely (QAS-2: Session Integrity)
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

// Define Variables & Strict Role Check (QAS-2: Access Control)
$pageTitle = 'Equipment';
$activePage = 'eq';

// Server-side validation: Block if not logged in
if (!isset($_SESSION['role']) || !isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$isAdmin = ($_SESSION['role'] === 'admin');
$errors = [];
$success_msg = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function getDailyRateForDays(array $equipment, int $days): float
{
    $r1_3 = (float)($equipment['rental_rate'] ?? 0);
    $r4_6 = ($equipment['rate_4_6'] !== null && $equipment['rate_4_6'] !== '') ? (float)$equipment['rate_4_6'] : $r1_3;
    $r7_9 = ($equipment['rate_7_9'] !== null && $equipment['rate_7_9'] !== '') ? (float)$equipment['rate_7_9'] : $r4_6;
    $r10p = ($equipment['rate_10_plus'] !== null && $equipment['rate_10_plus'] !== '') ? (float)$equipment['rate_10_plus'] : $r7_9;

    if ($days >= 10) return $r10p;
    if ($days >= 7)  return $r7_9;
    if ($days >= 4)  return $r4_6;
    return $r1_3;
}

function getAccessoryRate(array $item, int $days = 1, bool $withCamera = false): float
{
    $type = $item['pricing_type'] ?? 'daily';
    $baseRate = (float)($item['rental_rate'] ?? 0);
    $camRate = (isset($item['with_camera_rate']) && $item['with_camera_rate'] !== '' && $item['with_camera_rate'] !== null)
        ? (float)$item['with_camera_rate']
        : $baseRate;

    if ($type === 'flat') {
        return $baseRate;
    }

    $rate = $withCamera ? $camRate : $baseRate;
    return $rate * max(1, $days);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verify CSRF Token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Security Error: CSRF token validation failed. Action blocked.");
    }

    // Enforce RBAC on Backend (Bypass Testing Prevention)
    if (!$isAdmin) {
        die("Security Error: Unauthorized access. Only administrators can modify equipment.");
    }

    if (isset($_POST['action']) && ($_POST['action'] == 'add' || $_POST['action'] == 'edit')) {
        // Sanitize Inputs
        $name = trim($_POST['equipment_name']);
        $brand = trim($_POST['brand']);
        $category = $_POST['category'];
        $serial = trim($_POST['serial_number']);
        $rental = $_POST['rental_rate']; // 1-3 days rate / day (or standalone daily / flat rate)
        $pricing_type = ($category === 'Accessories' && isset($_POST['pricing_type']) && $_POST['pricing_type'] === 'flat') ? 'flat' : 'daily';
        $with_camera_rate = ($category === 'Accessories' && $pricing_type === 'daily' && isset($_POST['with_camera_rate']) && $_POST['with_camera_rate'] !== '')
            ? $_POST['with_camera_rate']
            : null;
        $rate_4_6 = ($category !== 'Accessories' && isset($_POST['rate_4_6']) && $_POST['rate_4_6'] !== '') ? $_POST['rate_4_6'] : null;
        $rate_7_9 = ($category !== 'Accessories' && isset($_POST['rate_7_9']) && $_POST['rate_7_9'] !== '') ? $_POST['rate_7_9'] : null;
        $rate_10_plus = ($category !== 'Accessories' && isset($_POST['rate_10_plus']) && $_POST['rate_10_plus'] !== '') ? $_POST['rate_10_plus'] : null;
        $price = $_POST['purchase_price'];
        $supplier = $_POST['supplier_id'];
        $status = $_POST['equipment_status'];
        $specs = trim($_POST['specifications']);
        $id = $_POST['equipment_id'] ?? null;

        // BACKEND VALIDATION (QAS-1 & QAS-3)
        if (empty($name)) $errors[] = "Equipment name is required.";
        if (empty($brand)) $errors[] = "Brand is required.";
        if (empty($serial)) $errors[] = "Serial number is required.";
        if (!is_numeric($rental) || $rental < 0) $errors[] = "Rental rate must be a positive number.";
        if ($with_camera_rate !== null && (!is_numeric($with_camera_rate) || $with_camera_rate < 0)) {
            $errors[] = "With-camera rate must be a positive number.";
        }
        foreach (['4–6 days' => $rate_4_6, '7–9 days' => $rate_7_9, '10+ days' => $rate_10_plus] as $tierLabel => $tierVal) {
            if ($tierVal !== null && (!is_numeric($tierVal) || $tierVal < 0)) {
                $errors[] = "Rental rate for $tierLabel must be a positive number.";
            }
        }
        if (!is_numeric($price) || $price < 0) $errors[] = "Purchase Price must be a positive number.";
        if (empty($supplier)) $errors[] = "Please select a supplier.";

        // QAS-1: Check for duplicate serial number
        if (empty($errors)) {
            $dupCheck = $pdo->prepare("SELECT equipment_id FROM equipment WHERE serial_number = ? AND equipment_id != ?");
            $dupCheck->execute([$serial, $id ?? 0]);
            if ($dupCheck->rowCount() > 0) {
                $errors[] = "The serial number '$serial' already exists in the system.";
            }
        }

        if (empty($errors)) {
            if ($_POST['action'] == 'add') {
                $stmt = $pdo->prepare("
                    INSERT INTO equipment (
                        equipment_name, brand, category, serial_number,
                        rental_rate, pricing_type, with_camera_rate,
                        rate_4_6, rate_7_9, rate_10_plus,
                        purchase_price, supplier_id, equipment_status, specifications
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $name,
                    $brand,
                    $category,
                    $serial,
                    $rental,
                    $pricing_type,
                    $with_camera_rate,
                    $rate_4_6,
                    $rate_7_9,
                    $rate_10_plus,
                    $price,
                    $supplier,
                    $status,
                    $specs
                ]);
                $success_msg = "Equipment added successfully.";
            } else {
                $stmt = $pdo->prepare("
                    UPDATE equipment SET
                        equipment_name=?, brand=?, category=?, serial_number=?,
                        rental_rate=?, pricing_type=?, with_camera_rate=?,
                        rate_4_6=?, rate_7_9=?, rate_10_plus=?,
                        purchase_price=?, supplier_id=?, equipment_status=?, specifications=?
                    WHERE equipment_id=?
                ");
                $stmt->execute([
                    $name,
                    $brand,
                    $category,
                    $serial,
                    $rental,
                    $pricing_type,
                    $with_camera_rate,
                    $rate_4_6,
                    $rate_7_9,
                    $rate_10_plus,
                    $price,
                    $supplier,
                    $status,
                    $specs,
                    $id
                ]);
                $success_msg = "Equipment updated successfully.";
            }
        }
    }

    if (isset($_POST['action']) && $_POST['action'] == 'delete') {
        $id = $_POST['equipment_id'];

        $checkStmt = $pdo->prepare("SELECT equipment_status FROM equipment WHERE equipment_id = ?");
        $checkStmt->execute([$id]);
        $eq = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($eq && $eq['equipment_status'] !== 'available') {
            $errors[] = "Action failed: The selected equipment is not 'Available' and cannot be deleted.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM equipment WHERE equipment_id = ?");
            $stmt->execute([$id]);
            $success_msg = "Equipment deleted successfully.";
        }
    }
}

$supStmt = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers ORDER BY supplier_name");
$suppliers = $supStmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->query("SELECT e.*, s.supplier_name FROM equipment e LEFT JOIN suppliers s ON e.supplier_id = s.supplier_id");
$equipment = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$equipment) $equipment = [];

require_once '../includes/navigation.php';
?>

<div class="hd">
    <div>
        <h1>Equipment <?= $isAdmin ? '' : '<span class="vo">View only</span>' ?></h1>
        <div class="sub">Manage equipment details, specifications, categories, tiered rental rates, accessory pricing, and purchase prices.</div>
    </div>
    <?php if ($isAdmin): ?>
        <button class="btn" onclick="openAddModal()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <path d="M12 5v14M5 12h14" />
            </svg> New Equipment
        </button>
    <?php endif; ?>
</div>

<!-- Contextual Feedback Alerts (QAS-3) -->
<?php if (!empty($errors)): ?>
    <div style="background: #fee2e2; color: #991b1b; padding: 15px; margin-bottom: 20px; border-radius: 5px; border: 1px solid #fecaca;">
        <strong>Please fix the following errors:</strong>
        <ul style="margin-top: 5px; margin-bottom: 0; padding-left: 20px;">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!empty($success_msg)): ?>
    <div style="background: #dcfce7; color: #166534; padding: 15px; margin-bottom: 20px; border-radius: 5px; border: 1px solid #bbf7d0;">
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

        <input type="text" id="searchInput" class="in" placeholder="Search equipment...">
    </div>
    <div class="sp"></div>

    <select id="categoryFilter" class="in">
        <option value="">All Category</option>
        <option value="Camera Body">Camera Body</option>
        <option value="Audio">Audio</option>
        <option value="Accessories">Accessories</option>
    </select>

    <select id="statusFilter" class="in">
        <option value="">All Status</option>
        <option value="available">Available</option>
        <option value="rented">Rented</option>
        <option value="reserved">Reserved</option>
        <option value="maintenance">Maintenance</option>
    </select>
</div>

<div class="tw">
    <table id="equipmentTable">
        <thead>
            <tr>
                <th>ID</th>
                <th>Equipment Name</th>
                <th>Category</th>
                <th>Rate</th>
                <th>Supplier</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($equipment) > 0): ?>
                <?php foreach ($equipment as $e): ?>
                    <?php
                    $rowId = "row-" . $e['equipment_id'];
                    $pType = (($e['pricing_type'] ?? 'daily') === 'flat') ? 'flat' : 'daily';
                    $r1_3  = (float)($e['rental_rate'] ?? 0);
                    $hasCamRate = (isset($e['with_camera_rate']) && $e['with_camera_rate'] !== null && $e['with_camera_rate'] !== '');
                    $rCam  = $hasCamRate ? (float)$e['with_camera_rate'] : $r1_3;

                    $r4_6  = (isset($e['rate_4_6']) && $e['rate_4_6'] !== null && $e['rate_4_6'] !== '') ? (float)$e['rate_4_6'] : $r1_3;
                    $r7_9  = (isset($e['rate_7_9']) && $e['rate_7_9'] !== null && $e['rate_7_9'] !== '') ? (float)$e['rate_7_9'] : $r4_6;
                    $r10p  = (isset($e['rate_10_plus']) && $e['rate_10_plus'] !== null && $e['rate_10_plus'] !== '') ? (float)$e['rate_10_plus'] : $r7_9;
                    ?>

                    <tr class="m data-row" data-category="<?= htmlspecialchars($e['category']) ?>" data-status="<?= strtolower($e['equipment_status']) ?>">
                        <td><span class="id">EQ-<?= str_pad($e['equipment_id'], 3, '0', STR_PAD_LEFT) ?></span></td>
                        <td><?= htmlspecialchars($e['equipment_name']) ?></td>
                        <td><?= htmlspecialchars($e['category']) ?></td>
                        <td>
                            <?php if ($pType === 'flat'): ?>
                                <div>RM <?= number_format($r1_3, 2) ?> <span style="color:#64748b; font-size:12px;">(flat)</span></div>
                            <?php else: ?>
                                <div>RM <?= number_format($r1_3, 2) ?> <span style="color:#64748b; font-size:12px;">(<?= $e['category'] === 'Accessories' ? 'daily' : '1–3d' ?>)</span></div>
                                <?php if ($hasCamRate): ?>
                                    <div style="color:#64748b; font-size:11px;">w/ Camera: RM<?= number_format($rCam, 2) ?></div>
                                <?php elseif ($e['category'] !== 'Accessories'): ?>
                                    <div style="color:#64748b; font-size:11px;">
                                        4–6d: RM<?= number_format($r4_6, 0) ?> · 7–9d: RM<?= number_format($r7_9, 0) ?> · 10d+: RM<?= number_format($r10p, 0) ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($e['supplier_name'] ?? 'N/A') ?></td>
                        <td>
                            <?php
                            $status = strtolower($e['equipment_status']);
                            $badge = 'S';
                            if ($status == 'available') $badge = 'G';
                            if ($status == 'reserved') $badge = 'Y';
                            if ($status == 'rented') $badge = 'B';
                            if ($status == 'maintenance') $badge = 'R';
                            ?>
                            <span class="bd <?= $badge ?>"><?= ucfirst($status) ?></span>
                        </td>
                        <td style="text-align:right; white-space: nowrap;">
                            <?php if ($isAdmin): ?>
                                <button class="ic" title="Edit" onclick='openEditModal(<?= htmlspecialchars(json_encode($e), ENT_QUOTES, 'UTF-8') ?>)'>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                        <path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z" />
                                    </svg>
                                </button>
                                <button class="ic x" title="Delete" onclick="initiateDelete(<?= $e['equipment_id'] ?>, '<?= strtolower($e['equipment_status']) ?>')">
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
                    <tr id="<?= $rowId ?>" class="expand-row" style="display: none; background-color: #f8fafc;">
                        <td colspan="7" style="padding: 15px 20px; border-bottom: 1px solid #e2e8f0;">
                            <div style="display: flex; gap: 32px; align-items: flex-start; font-size: 13px; color: #475569; flex-wrap: wrap;">
                                <div style="display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; flex-shrink: 0;">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="1.8">
                                        <path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3z" />
                                        <circle cx="12" cy="13" r="3.5" />
                                    </svg>
                                </div>
                                <div style="flex: 1; min-width: 160px;">
                                    <div style="font-weight: 600; margin-bottom: 2px;"><?= htmlspecialchars($e['brand']) ?> · <?= htmlspecialchars($e['equipment_name']) ?></div>
                                    <div style="color: #94a3b8; font-size: 12px;">SN: <?= htmlspecialchars($e['serial_number'] ?? 'N/A') ?></div>
                                </div>
                                <div style="flex: 1.5; min-width: 180px;">
                                    <div style="font-weight: 600; margin-bottom: 4px;">Pricing Breakdown</div>
                                    <div style="color: #64748b; font-size: 12px; line-height: 1.5;">
                                        <?php if ($pType === 'flat'): ?>
                                            Flat rate: <strong>RM <?= number_format($r1_3, 2) ?></strong>
                                        <?php elseif ($e['category'] === 'Accessories'): ?>
                                            Daily rate: <strong>RM <?= number_format($r1_3, 2) ?></strong>/day<br>
                                            <?php if ($hasCamRate): ?>
                                                With Camera: <strong>RM <?= number_format($rCam, 2) ?></strong>/day
                                            <?php endif; ?>
                                        <?php else: ?>
                                            1–3 days: <strong>RM <?= number_format($r1_3, 2) ?></strong>/day<br>
                                            4–6 days: <strong>RM <?= number_format($r4_6, 2) ?></strong>/day<br>
                                            7–9 days: <strong>RM <?= number_format($r7_9, 2) ?></strong>/day<br>
                                            10+ days: <strong>RM <?= number_format($r10p, 2) ?></strong>/day
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div style="flex: 2; min-width: 200px;">
                                    <div style="font-weight: 600; margin-bottom: 2px;">Specifications & Included Items</div>
                                    <div style="color: #64748b; font-size: 12px; white-space: pre-line;"><?= htmlspecialchars($e['specifications'] ?? 'No specifications provided.') ?></div>
                                </div>
                                <div style="flex: 1; min-width: 130px;">
                                    <div style="font-weight: 600; margin-bottom: 2px;">Purchase Price</div>
                                    <div style="color: #64748b; font-size: 12px;">RM <?= number_format($e['purchase_price'], 2) ?></div>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7" class="emp" style="text-align:center; padding:20px;">No equipment records found.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ========================================== -->
<!-- MODALS -->
<!-- ========================================== -->

<!-- Add / Edit Modal -->
<div id="formModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); z-index:1000; align-items:center; justify-content:center; overflow-y:auto; padding:20px 0;">
    <div style="background:#fff; width:750px; max-width:95%; border-radius:12px; padding:30px; position:relative; font-family: 'Inter', sans-serif; box-shadow: 0 10px 25px rgba(0,0,0,0.1); max-height:90vh; overflow-y:auto;">

        <h2 id="modalTitle" style="margin-top:0; font-size:22px; font-weight:700; color:#0f172a; margin-bottom:25px; font-family: 'Inter', sans-serif;">New · Equipment</h2>

        <form method="POST" action="manage_equipment.php" id="equipmentForm">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="equipment_id" id="formId" value="">

            <style>
                #formModal label {
                    font-size: 13px;
                    font-weight: 600;
                    color: #334155;
                    font-family: 'Inter', sans-serif;
                    display: block;
                    margin-bottom: 6px;
                }

                #formModal input,
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
                    box-sizing: border-box;
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
            </style>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom:20px;">
                <div>
                    <label>Equipment Name *</label>
                    <input type="text" name="equipment_name" id="formName" required maxlength="100">
                </div>
                <div>
                    <label>Brand *</label>
                    <input type="text" name="brand" id="formBrand" required maxlength="50">
                </div>
                <div>
                    <label>Category *</label>
                    <select name="category" id="formCategory" required onchange="updatePricingFields()">
                        <option value="Camera Body">Camera Body</option>
                        <option value="Audio">Audio</option>
                        <option value="Accessories">Accessories</option>
                    </select>
                </div>
                <div>
                    <label>Serial Number *</label>
                    <input type="text" name="serial_number" id="formSerial" required maxlength="50">
                </div>

                <div>
                    <label id="baseRateLabel">1–3 Days Rate (RM / day) *</label>
                    <input type="number" step="0.01" min="0" name="rental_rate" id="formRental" required>
                </div>

                <div id="accessoryTypeGroup" style="display:none;">
                    <label>Pricing Type *</label>
                    <select name="pricing_type" id="formPricingType" onchange="updatePricingFields()">
                        <option value="daily">Daily Rate</option>
                        <option value="flat">Flat (One-off)</option>
                    </select>
                </div>

                <div id="withCameraGroup" style="display:none;">
                    <label>With Camera Rate (RM / day)</label>
                    <input type="number" step="0.01" min="0" name="with_camera_rate" id="formWithCameraRate" placeholder="Optional (w/ camera)">
                </div>

                <div class="tier-field">
                    <label>4–6 Days Rate (RM / day)</label>
                    <input type="number" step="0.01" min="0" name="rate_4_6" id="formRate4_6" placeholder="Defaults to 1–3d rate">
                </div>
                <div class="tier-field">
                    <label>7–9 Days Rate (RM / day)</label>
                    <input type="number" step="0.01" min="0" name="rate_7_9" id="formRate7_9" placeholder="Defaults to 4–6d rate">
                </div>
                <div class="tier-field">
                    <label>10+ Days Rate (RM / day)</label>
                    <input type="number" step="0.01" min="0" name="rate_10_plus" id="formRate10Plus" placeholder="Defaults to 7–9d rate">
                </div>

                <div>
                    <label>Purchase Price (RM) *</label>
                    <input type="number" step="0.01" min="0" name="purchase_price" id="formPrice" required>
                </div>
                <div>
                    <label>Supplier *</label>
                    <select name="supplier_id" id="formSupplier" required>
                        <option value="">Select...</option>
                        <?php foreach ($suppliers as $sup): ?>
                            <option value="<?= $sup['supplier_id'] ?>"><?= htmlspecialchars($sup['supplier_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Status *</label>
                    <select name="equipment_status" id="formStatus" required>
                        <option value="available">Available</option>
                        <option value="rented">Rented</option>
                        <option value="reserved">Reserved</option>
                        <option value="maintenance">Maintenance</option>
                    </select>
                </div>
            </div>

            <div style="margin-bottom:25px;">
                <label>Specifications & Included Items</label>
                <textarea name="specifications" id="formSpecs" rows="4" style="resize:vertical;"></textarea>
            </div>

            <div style="text-align:right; display:flex; justify-content:flex-end; gap:12px;">
                <button type="button" onclick="closeModal('formModal')" style="padding:10px 20px; background:#fff; border:1px solid #cbd5e1; color:#334155; border-radius:6px; cursor:pointer; font-weight:600; font-size:14px; font-family: 'Inter', sans-serif;">Cancel</button>
                <button type="submit" style="padding:10px 20px; background:#0f172a; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:600; font-size:14px; font-family: 'Inter', sans-serif;">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:#fff; width:450px; border-radius:12px; padding:25px; font-family: 'Inter', sans-serif; box-shadow: 0 10px 25px rgba(0,0,0,0.1);">
        <h2 style="margin-top:0; font-size:20px; font-weight:700; color:#0f172a;">Delete record?</h2>
        <p style="color:#475569; font-size:14px; margin-bottom:25px;"><span id="deleteName" style="font-weight:600; color:#0f172a;"></span> will be permanently removed. This cannot be undone.</p>

        <form method="POST" action="manage_equipment.php">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="equipment_id" id="deleteId" value="">
            <div style="display:flex; justify-content:flex-end; gap:12px;">
                <button type="button" onclick="closeModal('deleteModal')" style="padding:10px 20px; background:#fff; border:1px solid #cbd5e1; border-radius:6px; cursor:pointer; font-weight:600; color:#334155;">Cancel</button>
                <button type="submit" style="padding:10px 20px; background:#dc2626; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:600;">Delete</button>
            </div>
        </form>
    </div>
</div>

<!-- Cannot Delete Modal (Pic 2) -->
<div id="errorModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:#fff; width:500px; border-radius:12px; padding:25px; font-family: 'Inter', sans-serif; box-shadow: 0 10px 25px rgba(0,0,0,0.1);">
        <h2 style="margin-top:0; font-size:20px; font-weight:700; color:#0f172a;">Delete record?</h2>
        <p style="color:#475569; font-size:14px; margin-bottom:25px;">This record is still used by Rentals and cannot be deleted. Remove or reassign those records first.</p>
        <div style="text-align:right;">
            <button type="button" onclick="closeModal('errorModal')" style="padding:10px 20px; background:#fff; border:1px solid #0f172a; border-radius:6px; cursor:pointer; color:#0f172a; font-weight:600;">Close</button>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script>
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

    function updatePricingFields() {
        const category = document.getElementById('formCategory').value;
        const pricingType = document.getElementById('formPricingType').value;
        const isAccessory = (category === 'Accessories');

        document.getElementById('accessoryTypeGroup').style.display = isAccessory ? 'block' : 'none';
        document.getElementById('withCameraGroup').style.display = (isAccessory && pricingType === 'daily') ? 'block' : 'none';

        document.querySelectorAll('.tier-field').forEach(el => {
            el.style.display = isAccessory ? 'none' : 'block';
        });

        const baseLabel = document.getElementById('baseRateLabel');
        if (!isAccessory) {
            baseLabel.innerText = '1–3 Days Rate (RM / day) *';
        } else if (pricingType === 'flat') {
            baseLabel.innerText = 'Flat Rate (RM) *';
        } else {
            baseLabel.innerText = 'Daily Rate (RM / day) *';
        }
    }

    function openAddModal() {
        document.getElementById('modalTitle').innerText = 'New Equipment';
        document.getElementById('formAction').value = 'add';
        document.getElementById('equipmentForm').reset();
        document.getElementById('formId').value = '';
        document.getElementById('formPricingType').value = 'daily';

        var inputs = document.querySelectorAll('#formModal input, #formModal select, #formModal textarea');
        inputs.forEach(input => input.style.borderColor = '#cbd5e1');

        updatePricingFields();
        openModal('formModal');
    }

    function openEditModal(data) {
        document.getElementById('modalTitle').innerText = 'Edit Equipment';
        document.getElementById('formAction').value = 'edit';

        document.getElementById('formId').value = data.equipment_id;
        document.getElementById('formName').value = data.equipment_name;
        document.getElementById('formBrand').value = data.brand;
        document.getElementById('formSerial').value = data.serial_number;
        document.getElementById('formPricingType').value = data.pricing_type || 'daily';
        document.getElementById('formRental').value = data.rental_rate ?? '';
        document.getElementById('formWithCameraRate').value = data.with_camera_rate ?? '';
        document.getElementById('formRate4_6').value = data.rate_4_6 ?? '';
        document.getElementById('formRate7_9').value = data.rate_7_9 ?? '';
        document.getElementById('formRate10Plus').value = data.rate_10_plus ?? '';
        document.getElementById('formPrice').value = data.purchase_price;
        document.getElementById('formSpecs').value = data.specifications || '';

        const categorySelect = document.getElementById('formCategory');
        let catExists = false;
        for (let i = 0; i < categorySelect.options.length; i++) {
            if (categorySelect.options[i].value === data.category) {
                catExists = true;
                break;
            }
        }
        if (!catExists && data.category) {
            let newOpt = new Option(data.category, data.category);
            categorySelect.add(newOpt);
        }
        categorySelect.value = data.category;

        document.getElementById('formStatus').value = data.equipment_status;
        document.getElementById('formSupplier').value = data.supplier_id;

        var inputs = document.querySelectorAll('#formModal input, #formModal select, #formModal textarea');
        inputs.forEach(input => input.style.borderColor = '#cbd5e1');

        updatePricingFields();
        openModal('formModal');
    }

    function initiateDelete(id, status) {
        if (status !== 'available') {
            openModal('errorModal');
        } else {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteName').innerText = 'EQ-' + String(id).padStart(3, '0');
            openModal('deleteModal');
        }
    }

    // ==========================================
    // SEARCH & FILTER SCRIPT
    // ==========================================
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('searchInput');
        const categoryFilter = document.getElementById('categoryFilter');
        const statusFilter = document.getElementById('statusFilter');
        const tableRows = document.querySelectorAll('.data-row');

        function filterTable() {
            const searchTerm = searchInput.value.toLowerCase();
            const categoryTerm = categoryFilter.value.toLowerCase();
            const statusTerm = statusFilter.value.toLowerCase();

            tableRows.forEach(row => {
                const rowText = row.innerText.toLowerCase();
                const rowCategory = row.getAttribute('data-category').toLowerCase();
                const rowStatus = row.getAttribute('data-status').toLowerCase();

                const matchesSearch = rowText.includes(searchTerm);
                const matchesCategory = categoryTerm === "" || rowCategory === categoryTerm;
                const matchesStatus = statusTerm === "" || rowStatus === statusTerm;

                if (matchesSearch && matchesCategory && matchesStatus) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';

                    const nextRow = row.nextElementSibling;
                    if (nextRow && nextRow.classList.contains('expand-row')) {
                        nextRow.style.display = 'none';
                    }
                }
            });
        }

        searchInput.addEventListener('keyup', filterTable);
        categoryFilter.addEventListener('change', filterTable);
        statusFilter.addEventListener('change', filterTable);
    });

    document.getElementById('equipmentForm').addEventListener('submit', function(e) {
        let isValid = true;
        const inputs = ['formName', 'formBrand', 'formSerial', 'formRental', 'formPrice', 'formSupplier'];

        inputs.forEach(id => {
            let input = document.getElementById(id);
            if (!input.value.trim()) {
                input.style.borderColor = '#dc2626';
                isValid = false;
            } else {
                input.style.borderColor = '#cbd5e1';
            }
        });

        if (!isValid) {
            e.preventDefault();
            alert('Please fill in all required fields marked with *.');
        }
    });
</script>

</div>
</main>
</body>

</html>