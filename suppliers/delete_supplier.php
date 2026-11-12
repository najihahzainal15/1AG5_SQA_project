
<?php
require_once '../config/db_connection.php';

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

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: manage_suppliers.php");
    exit();
}

$supplier_id = filter_input(
    INPUT_POST,
    'supplier_id',
    FILTER_VALIDATE_INT
);

if (!$supplier_id) {
    header("Location: manage_suppliers.php?error=invalid");
    exit();
}

try {

    $pdo->beginTransaction();

    // Check supplier exists
    $check = $pdo->prepare("
        SELECT supplier_id
        FROM suppliers
        WHERE supplier_id = ?
    ");

    $check->execute([$supplier_id]);

    if (!$check->fetch()) {
        $pdo->rollBack();
        header("Location: manage_suppliers.php?error=notfound");
        exit();
    }

    // Keep equipment records but remove supplier association
    $update = $pdo->prepare("
        UPDATE equipment
        SET supplier_id = NULL
        WHERE supplier_id = ?
    ");

    $update->execute([$supplier_id]);

    // Delete supplier
    $delete = $pdo->prepare("
        DELETE FROM suppliers
        WHERE supplier_id = ?
    ");

    $delete->execute([$supplier_id]);

    $pdo->commit();

    header("Location: manage_suppliers.php?success=deleted");
    exit();

} catch (PDOException $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    header("Location: manage_suppliers.php?error=linked");
    exit();
}

