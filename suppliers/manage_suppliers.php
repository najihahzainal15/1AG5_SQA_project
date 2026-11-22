<?php
require_once __DIR__ . '/../config/db_connection.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$isAdmin = (($_SESSION['role'] ?? '') === 'admin');
$managePage = 'manage_suppliers.php';
$formError = '';
$openSupplierModal = false;

$formData = [
    'supplier_id' => '',
    'supplier_name' => '',
    'contact_person' => '',
    'phone' => '',
    'email' => '',
    'address' => '',
    'supplier_status' => 'active'
];

/* ADD / EDIT SUPPLIER */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['supplier_action'])) {
    if (!$isAdmin) {
        http_response_code(403);
        exit('Access denied. Admin only.');
    }

    $action = $_POST['supplier_action'];

    $formData = [
        'supplier_id' => filter_var($_POST['supplier_id'] ?? '', FILTER_VALIDATE_INT) ?: '',
        'supplier_name' => trim($_POST['supplier_name'] ?? ''),
        'contact_person' => trim($_POST['contact_person'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'supplier_status' => $_POST['supplier_status'] ?? 'active'
    ];

    $openSupplierModal = true;

    if (!in_array($action, ['add', 'update'], true)) {
        $formError = 'Invalid form action.';
    } elseif (
        $formData['supplier_name'] === '' ||
        $formData['contact_person'] === '' ||
        $formData['phone'] === '' ||
        $formData['email'] === ''
    ) {
        $formError = 'Please fill in all required fields.';
    } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $formError = 'Please enter a valid email address.';
    } elseif (!in_array($formData['supplier_status'], ['active', 'inactive'], true)) {
        $formError = 'Invalid supplier status.';
    } elseif ($action === 'update' && (!$formData['supplier_id'] || $formData['supplier_id'] <= 0)) {
        $formError = 'Invalid supplier record.';
    } else {
        try {
            if ($action === 'add') {
                $stmt = $pdo->prepare("
                    INSERT INTO suppliers
                    (supplier_name, contact_person, phone, email, address, supplier_status)
                    VALUES (:name, :contact, :phone, :email, :address, :status)
                ");

                $stmt->execute([
                    ':name' => $formData['supplier_name'],
                    ':contact' => $formData['contact_person'],
                    ':phone' => $formData['phone'],
                    ':email' => $formData['email'],
                    ':address' => $formData['address'] !== '' ? $formData['address'] : null,
                    ':status' => $formData['supplier_status']
                ]);

                header("Location: {$managePage}?success=added");
                exit;
            }

            $stmt = $pdo->prepare("
                UPDATE suppliers
                SET supplier_name = :name,
                    contact_person = :contact,
                    phone = :phone,
                    email = :email,
                    address = :address,
                    supplier_status = :status
                WHERE supplier_id = :id
            ");

            $stmt->execute([
                ':name' => $formData['supplier_name'],
                ':contact' => $formData['contact_person'],
                ':phone' => $formData['phone'],
                ':email' => $formData['email'],
                ':address' => $formData['address'] !== '' ? $formData['address'] : null,
                ':status' => $formData['supplier_status'],
                ':id' => $formData['supplier_id']
            ]);

            header("Location: {$managePage}?success=updated");
            exit;
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $formError = 'Unable to save supplier. Please check the information and try again.';
        }
    }
}

/* FETCH SUPPLIERS */
$stmt = $pdo->query("
    SELECT
        s.*,
        (
            SELECT COUNT(*)
            FROM equipment e
            WHERE e.supplier_id = s.supplier_id
        ) AS equipment_count
    FROM suppliers s
    ORDER BY s.supplier_id DESC
");
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* FETCH EQUIPMENT FOR EXPANDABLE LIST */
$equipmentStmt = $pdo->query("
    SELECT
        equipment_id,
        supplier_id,
        equipment_name,
        brand,
        category,
        rental_rate,
        purchase_price,
        equipment_status
    FROM equipment
    ORDER BY equipment_name ASC
");
$allEquipment = $equipmentStmt->fetchAll(PDO::FETCH_ASSOC);

$equipmentBySupplier = [];
foreach ($allEquipment as $item) {
    $equipmentBySupplier[$item['supplier_id']][] = $item;
}

$pageTitle = 'Manage Suppliers';
$activePage = 'sup';
require_once __DIR__ . '/../includes/navigation.php';
?>

<div class="hd">
    <div>
        <h1>Manage Suppliers</h1>
        <div class="sub">Manage supplier information, contact details and supplier status.</div>
    </div>

    <?php if ($isAdmin): ?>
        <button class="btn" type="button" onclick="openAddSupplierModal()">
            + Add Supplier
        </button>
    <?php endif; ?>
</div>

<?php if (isset($_GET['success'])): ?>
    <div style="color:green; margin-bottom:15px;">
        <?php
        if ($_GET['success'] === 'added') {
            echo 'Supplier successfully added.';
        } elseif ($_GET['success'] === 'updated') {
            echo 'Supplier successfully updated.';
        } elseif ($_GET['success'] === 'deleted') {
            echo 'Supplier successfully deleted.';
        }
        ?>
    </div>
<?php endif; ?>

<?php if (isset($_GET['error'])): ?>
    <div style="color:red; margin-bottom:15px;">
        <?php
        $errors = [
            'invalid' => 'Invalid supplier information.',
            'notfound' => 'Supplier record not found.',
            'linked' => 'Unable to delete supplier. Please reassign linked equipment first.',
            'failed' => 'Something went wrong. Please try again.'
        ];
        echo htmlspecialchars($errors[$_GET['error']] ?? 'An error occurred.');
        ?>
    </div>
<?php endif; ?>

<?php if ($formError !== ''): ?>
    <div class="supplier-form-error"><?= htmlspecialchars($formError) ?></div>
<?php endif; ?>

<div class="tb">
    <div class="sw">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"></circle>
            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
        </svg>
        <input type="text" class="in" id="supplierSearch" placeholder="Search suppliers...">
    </div>

    <div class="sp"></div>

    <select class="in" id="statusFilter">
        <option value="">All Status</option>
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
    </select>
</div>

<div class="tw">
    <table class="supplier-table">
        <thead>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Contact</th>
            <th>Phone</th>
            <th>Email</th>
            <th>Items</th>
            <th>Status</th>
            <?php if ($isAdmin): ?><th>Actions</th><?php endif; ?>
        </tr>
        </thead>

        <tbody>
        <?php if (!empty($suppliers)): ?>
            <?php foreach ($suppliers as $s): ?>
                <?php
                $supplierId = (int)$s['supplier_id'];
                $linkedEquipment = $equipmentBySupplier[$supplierId] ?? [];
                $status = strtolower($s['supplier_status'] ?? '');
                $badge = $status === 'active' ? 'G' : ($status === 'inactive' ? 'R' : 'S');
                ?>
                <tr class="m supplier-row"
                    data-status="<?= htmlspecialchars($status) ?>"
                    data-supplier-id="<?= $supplierId ?>">
                    <td>
                        <span class="id">SUP-<?= str_pad($supplierId, 3, '0', STR_PAD_LEFT) ?></span>
                    </td>
                    <td><?= htmlspecialchars($s['supplier_name']) ?></td>
                    <td><?= htmlspecialchars($s['contact_person']) ?></td>
                    <td><?= htmlspecialchars($s['phone']) ?></td>
                    <td><?= htmlspecialchars($s['email'] ?? 'N/A') ?></td>

                    <td>
                        <button type="button"
                                class="expand-btn"
                                id="expand-button-<?= $supplierId ?>"
                                onclick="toggleSupplierEquipment(<?= $supplierId ?>)"
                                aria-expanded="false">
                            <span><?= (int)$s['equipment_count'] ?></span>
                            <span class="expand-arrow" id="expand-icon-<?= $supplierId ?>">›</span>
                        </button>
                    </td>

                    <td>
                        <span class="bd <?= $badge ?>"><?= ucfirst(htmlspecialchars($status)) ?></span>
                    </td>

                    <?php if ($isAdmin): ?>
                        <td style="text-align:right; white-space:nowrap;">
                            <button class="ic" title="Edit" type="button"
                                onclick="openEditSupplierModal(this)"
                                data-id="<?= $supplierId ?>"
                                data-name="<?= htmlspecialchars($s['supplier_name'], ENT_QUOTES, 'UTF-8') ?>"
                                data-contact="<?= htmlspecialchars($s['contact_person'], ENT_QUOTES, 'UTF-8') ?>"
                                data-phone="<?= htmlspecialchars($s['phone'], ENT_QUOTES, 'UTF-8') ?>"
                                data-email="<?= htmlspecialchars($s['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                data-address="<?= htmlspecialchars($s['address'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                data-status="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="1.8">
                                    <path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>
                                </svg>
                            </button>

                            <button class="ic x" title="Delete" type="button"
                                onclick="openDeleteModal(this)"
                                data-id="<?= $supplierId ?>"
                                data-name="<?= htmlspecialchars($s['supplier_name'], ENT_QUOTES, 'UTF-8') ?>"
                                data-equipment="<?= (int)$s['equipment_count'] ?>">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="1.8">
                                    <path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                </svg>
                            </button>
                        </td>
                    <?php endif; ?>
                </tr>

                <tr id="equipment-row-<?= $supplierId ?>"
                    class="supplier-equipment-row" style="display:none;">
                    <td colspan="<?= $isAdmin ? 8 : 7 ?>">
                        <div class="equipment-list">
                            <?php if (!empty($linkedEquipment)): ?>
                                <?php foreach ($linkedEquipment as $item): ?>
                                    <div class="equipment-item">
                                        <div class="equipment-icon">
                                            <svg width="25" height="25" viewBox="0 0 24 24" fill="none"
                                                 stroke="currentColor" stroke-width="1.7">
                                                <path d="M14 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9"/>
                                                <circle cx="12" cy="12" r="3"/>
                                                <path d="M16 4h5M18.5 1.5v5"/>
                                            </svg>
                                        </div>

                                        <div class="equipment-name">
                                            <strong><?= htmlspecialchars($item['equipment_name']) ?></strong>
                                            <small>EQ-<?= str_pad($item['equipment_id'], 3, '0', STR_PAD_LEFT) ?></small>
                                        </div>

                                        <div class="equipment-detail">
                                            <small>Category</small>
                                            <strong><?= htmlspecialchars($item['category'] ?? 'N/A') ?></strong>
                                        </div>

                                        <div class="equipment-detail">
                                            <small>Rental rate</small>
                                            <strong>RM <?= number_format((float)$item['rental_rate'], 2) ?></strong>
                                        </div>

                                        <div class="equipment-detail">
                                            <small>Purchase price</small>
                                            <strong>RM <?= number_format((float)$item['purchase_price'], 2) ?></strong>
                                        </div>

                                        <div class="equipment-detail">
                                            <small>Status</small>
                                            <strong><?= htmlspecialchars(ucfirst($item['equipment_status'])) ?></strong>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="no-equipment">No equipment linked to this supplier.</div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>

            <tr id="noResults" style="display:none;">
                <td colspan="<?= $isAdmin ? 8 : 7 ?>" class="emp">No matching suppliers found.</td>
            </tr>
        <?php else: ?>
            <tr>
                <td colspan="<?= $isAdmin ? 8 : 7 ?>" class="emp">No supplier records found in the database.</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<style>
.supplier-table{width:100%}
.expand-btn{display:inline-flex;align-items:center;justify-content:center;gap:10px;min-width:55px;padding:5px 9px;border:0;border-radius:20px;background:#eef0f7;color:#252b55;cursor:pointer;font-size:14px;font-weight:600}
.expand-btn:hover{background:#e1e5f0}
.expand-arrow{display:inline-block;font-size:22px;line-height:14px;transition:transform .2s ease}
.expand-btn[aria-expanded="true"] .expand-arrow{transform:rotate(90deg)}
.supplier-equipment-row>td{padding:0!important;background:#f5f6fa;border-left:3px solid #252b55}
.equipment-list{display:flex;flex-direction:column;width:100%}
.equipment-item{display:grid;grid-template-columns:45px minmax(180px,2fr) minmax(90px,1fr) minmax(100px,1fr) minmax(110px,1fr) minmax(100px,1fr);align-items:center;gap:12px;padding:14px 18px;border-bottom:1px solid #e1e3eb;background:#f5f6fa}
.equipment-item:last-child{border-bottom:0}
.equipment-icon{width:42px;height:42px;display:flex;align-items:center;justify-content:center;background:#fff;border:1px solid #e0e2eb;border-radius:8px;color:#85899e}
.equipment-name{display:flex;flex-direction:column;gap:5px;min-width:0}
.equipment-name strong{color:#252b55;font-size:13px;font-weight:600}
.equipment-name small{color:#777d91;font-size:12px}
.equipment-detail{display:flex;flex-direction:column;gap:5px}
.equipment-detail small{color:#777d91;font-size:11px;font-weight:500}
.equipment-detail strong{color:#454b68;font-size:12px;font-weight:600}
.no-equipment{padding:20px;color:#777d91;font-size:13px}
.supplier-form-error{margin-bottom:15px;padding:10px 13px;border-radius:6px;background:#fee2e2;color:#991b1b;font-size:14px}

/* ADD / EDIT SUPPLIER MODAL */
.supplier-modal-overlay{display:none;position:fixed;inset:0;z-index:10000;background:rgba(20,25,65,.58);align-items:center;justify-content:center;padding:20px}
.supplier-modal{width:100%;max-width:720px;max-height:90vh;overflow-y:auto;background:#fff;border-radius:10px;padding:25px 28px;box-shadow:0 12px 40px rgba(0,0,0,.22)}
.supplier-modal h2{margin:0 0 22px;color:#172044;font-size:20px;font-weight:700}
.supplier-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px 18px}
.supplier-field{min-width:0}
.supplier-field label{display:block;margin-bottom:7px;color:#46516a;font-size:13px;font-weight:600}
.supplier-field input,.supplier-field select,.supplier-field textarea{box-sizing:border-box;width:100%;border:1px solid #dfe3eb;border-radius:5px;background:#f0f1f5;color:#27324b;padding:10px 11px;font:inherit;font-size:13px;outline:none}
.supplier-field input,.supplier-field select{height:39px}
.supplier-field textarea{min-height:82px;resize:vertical}
.supplier-field input:focus,.supplier-field select:focus,.supplier-field textarea:focus{border-color:#8795bd;background:#fff}
.supplier-field.full-width{grid-column:1/-1}
.supplier-modal-actions{display:flex;justify-content:flex-end;gap:9px;margin-top:20px}
.supplier-modal-cancel,.supplier-modal-save{min-width:68px;padding:9px 16px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer}
.supplier-modal-cancel{background:#fff;border:1px solid #aeb4c2;color:#4b5568}
.supplier-modal-save{background:#171f4b;border:1px solid #171f4b;color:#fff}
.supplier-modal-save:hover{background:#252f68}
body.modal-open{overflow:hidden}

/* DELETE MODAL */
.delete-overlay{display:none;position:fixed;inset:0;background:rgba(20,25,65,.55);z-index:10001;align-items:center;justify-content:center;padding:20px}
.delete-modal{background:#fff;width:100%;max-width:450px;padding:25px;border-radius:12px;box-shadow:0 10px 35px rgba(0,0,0,.2)}
.delete-modal h3{color:#141b4d;font-size:20px;margin:0 0 15px}
.delete-modal p{color:#6b7194;font-size:14px;line-height:1.6}
.delete-warning{margin-top:12px}
.delete-actions{display:flex;justify-content:flex-end;align-items:center;gap:10px;margin-top:25px}
.delete-actions form{margin:0}
.cancel-btn,.confirm-delete-btn{padding:10px 18px;border-radius:6px;cursor:pointer;font-weight:600}
.cancel-btn{background:#fff;border:1px solid #a0a4b5;color:#444}
.confirm-delete-btn{background:#dc2626;border:0;color:#fff}
.confirm-delete-btn:hover{background:#b91c1c}
@media(max-width:700px){
    .supplier-modal{padding:20px;max-height:92vh}
    .supplier-form-grid{grid-template-columns:1fr}
    .supplier-field.full-width{grid-column:auto}
}
@media(max-width:900px){
    .equipment-list{overflow-x:auto}
    .equipment-item{min-width:750px}
}
</style>

<?php if ($isAdmin): ?>
<!-- ADD / EDIT MODAL -->
<div id="supplierModal" class="supplier-modal-overlay"
     onclick="if(event.target===this) closeSupplierModal()">
    <div class="supplier-modal" role="dialog" aria-modal="true" aria-labelledby="supplierModalTitle">
        <h2 id="supplierModalTitle">New · Suppliers</h2>

        <form method="POST" action="<?= htmlspecialchars($managePage) ?>" id="supplierForm">
            <input type="hidden" name="supplier_action" id="supplierAction" value="add">
            <input type="hidden" name="supplier_id" id="supplierId" value="">

            <div class="supplier-form-grid">
                <div class="supplier-field">
                    <label for="supplierName">Supplier name *</label>
                    <input type="text" id="supplierName" name="supplier_name"
                           maxlength="150" required>
                </div>

                <div class="supplier-field">
                    <label for="contactPerson">Contact person *</label>
                    <input type="text" id="contactPerson" name="contact_person"
                           maxlength="100" required>
                </div>

                <div class="supplier-field">
                    <label for="supplierPhone">Phone *</label>
                    <input type="tel" id="supplierPhone" name="phone"
                           maxlength="20" required>
                </div>

                <div class="supplier-field">
                    <label for="supplierEmail">Email *</label>
                    <input type="email" id="supplierEmail" name="email"
                           maxlength="255" required>
                </div>

                <div class="supplier-field">
                    <label for="supplierStatus">Status *</label>
                    <select id="supplierStatus" name="supplier_status" required>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>

                <div class="supplier-field full-width">
                    <label for="supplierAddress">Address</label>
                    <textarea id="supplierAddress" name="address"
                              placeholder="Enter supplier address"></textarea>
                </div>
            </div>

            <div class="supplier-modal-actions">
                <button type="button" class="supplier-modal-cancel"
                        onclick="closeSupplierModal()">Cancel</button>
                <button type="submit" class="supplier-modal-save">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- DELETE MODAL -->
<div id="deleteModal" class="delete-overlay">
    <div class="delete-modal">
        <h3>Delete record?</h3>
        <p id="deleteMessage"></p>
        <p id="equipmentWarning" class="delete-warning"></p>

        <div class="delete-actions">
            <button type="button" class="cancel-btn" onclick="closeDeleteModal()">Cancel</button>
            <form method="POST" action="delete_supplier.php">
                <input type="hidden" name="supplier_id" id="deleteSupplierId">
                <button type="submit" class="confirm-delete-btn">Delete</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function toggleSupplierEquipment(supplierId) {
    const row = document.getElementById('equipment-row-' + supplierId);
    const button = document.getElementById('expand-button-' + supplierId);
    if (!row || !button) return;

    const isHidden = row.style.display === 'none' || row.style.display === '';
    row.style.display = isHidden ? 'table-row' : 'none';
    button.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
}

function filterSuppliers() {
    const searchInput = document.getElementById('supplierSearch');
    const statusInput = document.getElementById('statusFilter');
    if (!searchInput || !statusInput) return;

    const searchValue = searchInput.value.toLowerCase().trim();
    const selectedStatus = statusInput.value.toLowerCase();
    const rows = document.querySelectorAll('.supplier-row');
    let visibleCount = 0;

    rows.forEach(row => {
        const matchesSearch = row.textContent.toLowerCase().includes(searchValue);
        const matchesStatus = selectedStatus === '' || row.dataset.status === selectedStatus;
        const supplierId = row.dataset.supplierId;
        const equipmentRow = document.getElementById('equipment-row-' + supplierId);
        const expandButton = document.getElementById('expand-button-' + supplierId);

        if (matchesSearch && matchesStatus) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
            if (equipmentRow) equipmentRow.style.display = 'none';
            if (expandButton) expandButton.setAttribute('aria-expanded', 'false');
        }
    });

    const noResults = document.getElementById('noResults');
    if (noResults) noResults.style.display = visibleCount === 0 ? '' : 'none';
}

document.getElementById('supplierSearch')?.addEventListener('input', filterSuppliers);
document.getElementById('statusFilter')?.addEventListener('change', filterSuppliers);

function openAddSupplierModal() {
    document.getElementById('supplierForm').reset();
    document.getElementById('supplierAction').value = 'add';
    document.getElementById('supplierId').value = '';
    document.getElementById('supplierModalTitle').textContent = 'New · Suppliers';
    document.getElementById('supplierStatus').value = 'active';
    showSupplierModal();
}

function openEditSupplierModal(button) {
    document.getElementById('supplierForm').reset();
    document.getElementById('supplierAction').value = 'update';
    document.getElementById('supplierId').value = button.dataset.id || '';
    document.getElementById('supplierName').value = button.dataset.name || '';
    document.getElementById('contactPerson').value = button.dataset.contact || '';
    document.getElementById('supplierPhone').value = button.dataset.phone || '';
    document.getElementById('supplierEmail').value = button.dataset.email || '';
    document.getElementById('supplierAddress').value = button.dataset.address || '';
    document.getElementById('supplierStatus').value = button.dataset.status || 'active';
    document.getElementById('supplierModalTitle').textContent = 'Edit · Suppliers';
    showSupplierModal();
}

function showSupplierModal() {
    document.getElementById('supplierModal').style.display = 'flex';
    document.body.classList.add('modal-open');
    document.getElementById('supplierName').focus();
}

function closeSupplierModal() {
    const modal = document.getElementById('supplierModal');
    if (modal) modal.style.display = 'none';
    document.body.classList.remove('modal-open');
}

function openDeleteModal(button) {
    const id = button.dataset.id;
    const equipmentCount = parseInt(button.dataset.equipment, 10) || 0;

    document.getElementById('deleteSupplierId').value = id;
    document.getElementById('deleteMessage').textContent =
        'SUP-' + String(id).padStart(3, '0') +
        ' will be permanently removed. This cannot be undone.';

    document.getElementById('equipmentWarning').textContent =
        equipmentCount > 0
            ? 'This supplier has ' + equipmentCount +
              ' linked equipment item(s). Reassign the equipment before deleting this supplier.'
            : 'No equipment items are linked to this supplier.';

    document.getElementById('deleteModal').style.display = 'flex';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}

document.getElementById('deleteModal')?.addEventListener('click', function(event) {
    if (event.target === this) closeDeleteModal();
});

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeSupplierModal();
        closeDeleteModal();
    }
});

/* Reopen modal if server-side validation fails */
<?php if ($openSupplierModal && $isAdmin): ?>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('supplierAction').value =
        <?= json_encode(($_POST['supplier_action'] ?? 'add')) ?>;
    document.getElementById('supplierId').value =
        <?= json_encode((string)$formData['supplier_id']) ?>;
    document.getElementById('supplierName').value =
        <?= json_encode($formData['supplier_name']) ?>;
    document.getElementById('contactPerson').value =
        <?= json_encode($formData['contact_person']) ?>;
    document.getElementById('supplierPhone').value =
        <?= json_encode($formData['phone']) ?>;
    document.getElementById('supplierEmail').value =
        <?= json_encode($formData['email']) ?>;
    document.getElementById('supplierAddress').value =
        <?= json_encode($formData['address']) ?>;
    document.getElementById('supplierStatus').value =
        <?= json_encode($formData['supplier_status']) ?>;
    document.getElementById('supplierModalTitle').textContent =
        <?= json_encode(($_POST['supplier_action'] ?? 'add') === 'update' ? 'Edit · Suppliers' : 'New · Suppliers') ?>;
    showSupplierModal();
});
<?php endif; ?>
</script>

</div>
</main>
</body>
</html>
