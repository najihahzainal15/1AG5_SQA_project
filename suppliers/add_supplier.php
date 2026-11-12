
<?php
require_once __DIR__ . '/../config/db_connection.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Admin access only
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Access denied. Admin only.');
}

$error = '';

$supplier_name = '';
$contact_person = '';
$phone = '';
$address = '';
$supplier_status = 'active';



// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $supplier_name = trim($_POST['supplier_name'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $supplier_status = $_POST['supplier_status'] ?? 'active';

    // Validate required fields
    if (
        $supplier_name === '' ||
        $contact_person === '' ||
        $phone === ''
    ) {
        $error = 'Please fill in all required fields.';

    } elseif (!in_array($supplier_status, ['active', 'inactive'], true)) {
        $error = 'Invalid supplier status.';

    } else {

        try {
            // Insert supplier into database
            $stmt = $pdo->prepare("
                INSERT INTO suppliers
                (supplier_name, contact_person, phone, address, supplier_status)
                VALUES (:name, :contact, :phone, :address, :status)
            ");

            $stmt->execute([
                ':name' => $supplier_name,
                ':contact' => $contact_person,
                ':phone' => $phone,
                ':address' => $address !== '' ? $address : null,
                ':status' => $supplier_status
            ]);

            // Redirect after successful insertion
            header("Location: manage_suppliers.php?success=added");
            exit();

        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = 'Unable to add supplier. Please try again.';
        }
    }
}

$pageTitle = 'Add Supplier';
$activePage = 'sup';

require_once __DIR__ . '/../includes/navigation.php';
?>

<!-- Page Header -->
<div class="hd">
    <div>
        <h1>Add Supplier</h1>
        <div class="sub">
            Register a new supplier for LensTrack.
        </div>
    </div>
</div>

<!-- Error Message -->
<?php if ($error): ?>
    <div style="color: var(--r); margin-bottom: 15px;">
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<!-- Add Supplier Form -->
<div style="max-width:650px;">

    <form method="POST" action="">

        <!-- Supplier Name -->
        <div style="margin-bottom:18px;">
            <label>Supplier Name *</label>
            <input
                type="text"
                name="supplier_name"
                class="in"
                style="width:100%; margin-top:8px;"
                maxlength="150"
                value="<?= htmlspecialchars($supplier_name) ?>"
                placeholder="Enter supplier name"
                required>
        </div>

        <!-- Contact Person -->
        <div style="margin-bottom:18px;">
            <label>Contact Person *</label>
            <input
                type="text"
                name="contact_person"
                class="in"
                style="width:100%; margin-top:8px;"
                maxlength="100"
                value="<?= htmlspecialchars($contact_person) ?>"
                placeholder="Enter contact person"
                required>
        </div>

        <!-- Phone Number -->
        <div style="margin-bottom:18px;">
            <label>Phone Number *</label>
            <input
                type="tel"
                name="phone"
                class="in"
                style="width:100%; margin-top:8px;"
                maxlength="20"
                value="<?= htmlspecialchars($phone) ?>"
                placeholder="e.g. 0123456789"
                required>
        </div>

        <!-- Address -->
        <div style="margin-bottom:18px;">
            <label>Address</label>
            <textarea
                name="address"
                class="in"
                style="width:100%; margin-top:8px; min-height:100px;"
                placeholder="Enter supplier address"><?= htmlspecialchars($address) ?></textarea>
        </div>

        <!-- Supplier Status -->
        <div style="margin-bottom:25px;">
            <label>Supplier Status *</label>

            <select
                name="supplier_status"
                class="in"
                style="width:100%; margin-top:8px;"
                required>

                <option value="active"
                    <?= $supplier_status === 'active' ? 'selected' : '' ?>>
                    Active
                </option>

                <option value="inactive"
                    <?= $supplier_status === 'inactive' ? 'selected' : '' ?>>
                    Inactive
                </option>

            </select>
        </div>

        <!-- Form Buttons -->
        <button type="submit" class="btn">
            Save Supplier
        </button>

        <a href="manage_suppliers.php"
           style="margin-left:12px; text-decoration:none;">
            Cancel
        </a>

    </form>

</div>

<!-- Close Navigation Layout -->
</div>
</main>
</body>
</html>

