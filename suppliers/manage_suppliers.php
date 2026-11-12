
<?php
$pageTitle = 'Manage Suppliers';
$activePage = 'sup';

// Include navigation and database connection
require_once '../includes/navigation.php';

// Check user role
$isAdmin = (($_SESSION['role'] ?? '') === 'admin');

// Fetch suppliers and count linked equipment
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
?>

<!-- Page Header -->
<div class="hd">
    <div>
        <h1>Manage Suppliers</h1>
        <div class="sub">
            Manage supplier information, contact details and supplier status.
        </div>
    </div>

    <?php if ($isAdmin): ?>
        <button class="btn" type="button"
            onclick="window.location.href='add_supplier.php'">
            + Add Supplier
        </button>
    <?php endif; ?>
</div>

<!-- Success Messages -->
<?php if (isset($_GET['success'])): ?>
    <div style="color:green; margin-bottom:15px;">
        <?php if ($_GET['success'] === 'added'): ?>
            Supplier successfully added.
        <?php elseif ($_GET['success'] === 'updated'): ?>
            Supplier successfully updated.
        <?php elseif ($_GET['success'] === 'deleted'): ?>
            Supplier successfully deleted.
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Error Messages -->
<?php if (isset($_GET['error'])): ?>
    <div style="color:red; margin-bottom:15px;">
        <?php
        $errors = [
            'invalid' => 'Invalid supplier information.',
            'notfound' => 'Supplier record not found.',
            'linked' => 'Unable to delete supplier. Please check the database relationship.',
            'failed' => 'Something went wrong. Please try again.'
        ];

        echo htmlspecialchars(
            $errors[$_GET['error']] ?? 'An error occurred.'
        );
        ?>
    </div>
<?php endif; ?>

<!-- Search and Filter Toolbar -->
<div class="tb">
    <!-- Search -->
    <div class="sw">
        <svg width="16" height="16"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2">
            <circle cx="11" cy="11" r="8"></circle>
            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
        </svg>

        <input
            type="text"
            class="in"
            id="supplierSearch"
            placeholder="Search suppliers..."
        >
    </div>

    <div class="sp"></div>

    <!-- Status Filter -->
    <select class="in" id="statusFilter">
        <option value="">All Status</option>
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
    </select>
</div>

<!-- Supplier Data Table -->
<div class="tw">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Supplier Name</th>
                <th>Contact Person</th>
                <th>Phone</th>
                <th>Address</th>
                <th>Status</th>

                <?php if ($isAdmin): ?>
                    <th>Actions</th>
                <?php endif; ?>
            </tr>
        </thead>

        <tbody>
        <?php if (count($suppliers) > 0): ?>

            <?php foreach ($suppliers as $s): ?>
                <tr class="m supplier-row"
                    data-status="<?= htmlspecialchars(strtolower($s['supplier_status'])) ?>">

                    <!-- Supplier ID -->
                    <td>
                        <span class="id">
                            SUP-<?= str_pad(
                                $s['supplier_id'],
                                3,
                                '0',
                                STR_PAD_LEFT
                            ) ?>
                        </span>
                    </td>

                    <!-- Supplier Name -->
                    <td>
                        <?= htmlspecialchars($s['supplier_name']) ?>
                    </td>

                    <!-- Contact Person -->
                    <td>
                        <?= htmlspecialchars($s['contact_person']) ?>
                    </td>

                    <!-- Phone -->
                    <td>
                        <?= htmlspecialchars($s['phone']) ?>
                    </td>

                    <!-- Address -->
                    <td>
                        <?= htmlspecialchars($s['address'] ?? 'N/A') ?>
                    </td>

                    <!-- Supplier Status -->
                    <td>
                        <?php
                        $status = strtolower($s['supplier_status']);
                        $badge = 'S';

                        if ($status === 'active') {
                            $badge = 'G';
                        } elseif ($status === 'inactive') {
                            $badge = 'R';
                        }
                        ?>

                        <span class="bd <?= $badge ?>">
                            <?= ucfirst(htmlspecialchars($status)) ?>
                        </span>
                    </td>

                    <!-- Admin Actions Only -->
                    <?php if ($isAdmin): ?>
                        <td style="text-align:right">

                            <!-- Edit Button -->
                            <button
                                class="ic"
                                title="Edit"
                                type="button"
                                onclick="window.location.href='edit_supplier.php?id=<?= (int)$s['supplier_id'] ?>'">

                                <svg width="16" height="16"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8">
                                    <path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>
                                </svg>
                            </button>

                            <!-- Delete Button -->
                            <button
                                class="ic x"
                                title="Delete"
                                type="button"
                                onclick="openDeleteModal(this)"
                                data-id="<?= (int)$s['supplier_id'] ?>"
                                data-name="<?= htmlspecialchars($s['supplier_name'], ENT_QUOTES, 'UTF-8') ?>"
                                data-equipment="<?= (int)$s['equipment_count'] ?>">

                                <svg width="16" height="16"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8">
                                    <path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                </svg>
                            </button>

                        </td>
                    <?php endif; ?>

                </tr>
            <?php endforeach; ?>

            <!-- No Search Results -->
            <tr id="noResults" style="display:none;">
                <td colspan="<?= $isAdmin ? 7 : 6 ?>" class="emp">
                    No matching suppliers found.
                </td>
            </tr>

        <?php else: ?>

            <tr>
                <td colspan="<?= $isAdmin ? 7 : 6 ?>" class="emp">
                    No supplier records found in the database.
                </td>
            </tr>

        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- DELETE CONFIRMATION MODAL: ADMIN ONLY -->
<?php if ($isAdmin): ?>

<div id="deleteModal" class="delete-overlay">
    <div class="delete-modal">
        <h3>Delete record?</h3>

        <p id="deleteMessage"></p>
        <p id="equipmentWarning" class="delete-warning"></p>

        <div class="delete-actions">
            <!-- Cancel Button -->
            <button
                type="button"
                class="cancel-btn"
                onclick="closeDeleteModal()">
                Cancel
            </button>

            <!-- Confirm Delete Form -->
            <form method="POST" action="delete_supplier.php">
                <input
                    type="hidden"
                    name="supplier_id"
                    id="deleteSupplierId"
                >

                <button type="submit" class="confirm-delete-btn">
                    Delete
                </button>
            </form>
        </div>
    </div>
</div>

<style>
.delete-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(20, 25, 65, 0.55);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.delete-modal {
    background: #ffffff;
    width: 100%;
    max-width: 450px;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 10px 35px rgba(0,0,0,0.2);
}

.delete-modal h3 {
    color: #141b4d;
    font-size: 20px;
    margin: 0 0 15px;
}

.delete-modal p {
    color: #6b7194;
    font-size: 14px;
    line-height: 1.6;
}

.delete-warning {
    margin-top: 12px;
}

.delete-actions {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 10px;
    margin-top: 25px;
}

.delete-actions form {
    margin: 0;
}

.cancel-btn,
.confirm-delete-btn {
    padding: 10px 18px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
}

.cancel-btn {
    background: #ffffff;
    border: 1px solid #a0a4b5;
    color: #444444;
}

.confirm-delete-btn {
    background: #dc2626;
    border: none;
    color: #ffffff;
}

.confirm-delete-btn:hover {
    background: #b91c1c;
}
</style>

<script>
// DELETE MODAL
function openDeleteModal(button) {
    const id = button.dataset.id;
    const equipmentCount = parseInt(button.dataset.equipment) || 0;

    document.getElementById('deleteSupplierId').value = id;

    document.getElementById('deleteMessage').textContent =
        'SUP-' + String(id).padStart(3, '0') +
        ' will be permanently removed. This cannot be undone.';

    const warning = document.getElementById('equipmentWarning');

    if (equipmentCount > 0) {
        warning.textContent =
            equipmentCount +
            ' equipment item(s) from this supplier will be left without a supplier and will need to be reassigned.';
    } else {
        warning.textContent =
            'No equipment items are linked to this supplier.';
    }

    document.getElementById('deleteModal').style.display = 'flex';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}

// Close modal when clicking outside
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeDeleteModal();
    }
});

// Close modal using Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDeleteModal();
    }
});
</script>

<?php endif; ?>

<!-- SEARCH AND STATUS FILTER: BOTH ADMIN AND STAFF -->
<script>
const supplierSearch = document.getElementById('supplierSearch');
const statusFilter = document.getElementById('statusFilter');

function filterSuppliers() {
    const searchValue = supplierSearch.value.toLowerCase().trim();
    const selectedStatus = statusFilter.value.toLowerCase();
    const rows = document.querySelectorAll('.supplier-row');

    let visibleCount = 0;

    rows.forEach(row => {
        const rowText = row.textContent.toLowerCase();
        const rowStatus = row.dataset.status;

        const matchesSearch = rowText.includes(searchValue);

        const matchesStatus =
            selectedStatus === '' ||
            rowStatus === selectedStatus;

        if (matchesSearch && matchesStatus) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    const noResults = document.getElementById('noResults');

    if (noResults) {
        noResults.style.display =
            visibleCount === 0 ? '' : 'none';
    }
}

// Search while typing
if (supplierSearch) {
    supplierSearch.addEventListener('input', filterSuppliers);
}

// Filter when status changes
if (statusFilter) {
    statusFilter.addEventListener('change', filterSuppliers);
}
</script>

<!-- Close Navigation Layout -->
</div>
</main>
</body>
</html>
