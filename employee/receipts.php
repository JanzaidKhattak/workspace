<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/currency_helper.php';

$database = new Database();
$auth = new Auth($database);

$auth->requireRole(['employee']);

$user_info = $auth->getUserInfo();
$db = $database->getConnection();

// Get employee information
$stmt = $db->prepare("SELECT e.*, b.branch_name FROM employees e JOIN branches b ON e.branch_id = b.id WHERE e.id = ?");
$stmt->execute([$user_info['id']]);
$employee_info = $stmt->fetch(PDO::FETCH_ASSOC);

// Get current currency
$currency = getCurrentCurrency($db);

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Get total receipts count
$stmt = $db->prepare("SELECT COUNT(*) FROM receipts WHERE employee_id = ?");
$stmt->execute([$user_info['id']]);
$total_receipts = $stmt->fetchColumn();
$total_pages = ceil($total_receipts / $per_page);

// Get receipts
$stmt = $db->prepare("SELECT r.*, 
    (SELECT COUNT(*) FROM receipt_items WHERE receipt_id = r.id) as item_count
    FROM receipts r 
    WHERE r.employee_id = ? 
    ORDER BY r.created_at DESC 
    LIMIT ? OFFSET ?");
$stmt->execute([$user_info['id'], $per_page, $offset]);
$receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get receipt details for modal
if (isset($_GET['receipt_id'])) {
    $receipt_id = intval($_GET['receipt_id']);
    
    // Get receipt
    $stmt = $db->prepare("SELECT r.*, b.branch_name FROM receipts r 
        JOIN branches b ON r.branch_id = b.id 
        WHERE r.id = ? AND r.employee_id = ?");
    $stmt->execute([$receipt_id, $user_info['id']]);
    $receipt_details = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($receipt_details) {
        // Get receipt items
        $stmt = $db->prepare("SELECT ri.*, s.service_name FROM receipt_items ri
            JOIN services s ON ri.service_id = s.id
            WHERE ri.receipt_id = ?");
        $stmt->execute([$receipt_id]);
        $receipt_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Receipts - Employee</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .sidebar {
            background: linear-gradient(135deg, #007bff 0%, #6f42c1 100%);
            min-height: 100vh;
            color: white;
        }
        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
            border-radius: 10px;
            margin: 5px 0;
            transition: all 0.3s;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            color: white;
            background-color: rgba(255, 255, 255, 0.1);
        }
        .receipt-card {
            border-left: 4px solid #007bff;
            transition: all 0.3s;
            cursor: pointer;
        }
        .receipt-card:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar p-3">
                <h4 class="mb-4"><i class="fas fa-user me-2"></i>Employee</h4>
                
                <div class="user-info bg-white bg-opacity-10 rounded p-3 mb-4">
                    <div>
                        <h6 class="mb-0"><?= htmlspecialchars($employee_info['full_name']) ?></h6>
                        <small class="opacity-75">Employee</small>
                        <div class="small opacity-75"><?= htmlspecialchars($employee_info['branch_name']) ?></div>
                        <div class="small opacity-75">Code: <?= htmlspecialchars($employee_info['employee_code']) ?></div>
                    </div>
                </div>
                
                <nav class="nav flex-column">
                    <a class="nav-link" href="/employee/dashboard.php">
                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                    </a>
                    <a class="nav-link" href="/employee/create-receipt.php">
                        <i class="fas fa-plus-circle me-2"></i>Create Receipt
                    </a>
                    <a class="nav-link active" href="/employee/receipts.php">
                        <i class="fas fa-receipt me-2"></i>My Receipts
                    </a>
                    <a class="nav-link" href="/employee/commissions.php">
                        <i class="fas fa-chart-line me-2"></i>Commissions
                    </a>
                    <a class="nav-link" href="/employee/profile.php">
                        <i class="fas fa-user-cog me-2"></i>Profile
                    </a>
                    <hr class="my-3">
                    <a class="nav-link" href="/logout.php">
                        <i class="fas fa-sign-out-alt me-2"></i>Logout
                    </a>
                </nav>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h3"><i class="fas fa-receipt me-2"></i>My Receipts</h1>
                    <a href="/employee/create-receipt.php" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i>Create New Receipt
                    </a>
                </div>
                
                <?php if (empty($receipts)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-receipt fa-5x text-muted mb-4"></i>
                        <h3 class="text-muted">No receipts found</h3>
                        <p class="text-muted">You haven't created any receipts yet. Start by creating your first receipt!</p>
                        <a href="/employee/create-receipt.php" class="btn btn-primary btn-lg">
                            <i class="fas fa-plus me-2"></i>Create First Receipt
                        </a>
                    </div>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($receipts as $receipt): ?>
                            <div class="col-md-6 col-lg-4 mb-4">
                                <div class="card receipt-card h-100" onclick="showReceiptDetails(<?= $receipt['id'] ?>)">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <h5 class="card-title mb-0">
                                                #<?= htmlspecialchars($receipt['receipt_number']) ?>
                                            </h5>
                                            <span class="badge bg-<?= 
                                                $receipt['payment_status'] === 'paid' ? 'success' : 
                                                ($receipt['payment_status'] === 'pending' ? 'warning' : 'danger') 
                                            ?>">
                                                <?= ucfirst($receipt['payment_status']) ?>
                                            </span>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <div class="d-flex align-items-center mb-1">
                                                <i class="fas fa-user me-2 text-muted"></i>
                                                <strong><?= htmlspecialchars($receipt['customer_name']) ?></strong>
                                            </div>
                                            <?php if ($receipt['customer_phone']): ?>
                                                <div class="d-flex align-items-center mb-1 small">
                                                    <i class="fas fa-phone me-2 text-muted"></i>
                                                    <?= htmlspecialchars($receipt['customer_phone']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="row text-center mb-3">
                                            <div class="col-4">
                                                <div class="small text-muted">Amount</div>
                                                <strong><?= formatCurrency($receipt['total_amount'], $currency) ?></strong>
                                            </div>
                                            <div class="col-4">
                                                <div class="small text-muted">Commission</div>
                                                <strong class="text-success"><?= formatCurrency($receipt['total_commission'], $currency) ?></strong>
                                            </div>
                                            <div class="col-4">
                                                <div class="small text-muted">Items</div>
                                                <strong><?= $receipt['item_count'] ?></strong>
                                            </div>
                                        </div>
                                        
                                        <div class="text-muted small mb-2">
                                            <i class="fas fa-clock me-1"></i>
                                            <?= date('M j, Y g:i A', strtotime($receipt['created_at'])) ?>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <a href="/receipt_print.php?id=<?= $receipt['id'] ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                                <i class="fas fa-eye me-1"></i>View
                                            </a>
                                            <a href="/receipt_print.php?id=<?= $receipt['id'] ?>&print=1" class="btn btn-sm btn-outline-success" target="_blank">
                                                <i class="fas fa-print me-1"></i>Print
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <nav aria-label="Page navigation" class="d-flex justify-content-center mt-4">
                            <ul class="pagination">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?= $page - 1 ?>">Previous</a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?= $page + 1 ?>">Next</a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showReceiptDetails(receiptId) {
            window.location.href = '/employee/receipts.php?receipt_id=' + receiptId;
        }
    </script>
</body>
</html>