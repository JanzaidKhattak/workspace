<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/currency_helper.php';

$database = new Database();
$auth = new Auth($database);

$auth->requireRole(['branch']);

$user_info = $auth->getUserInfo();
$db = $database->getConnection();

// Get branch information
$stmt = $db->prepare("SELECT * FROM branches WHERE id = ?");
$stmt->execute([$user_info['id']]);
$branch_info = $stmt->fetch(PDO::FETCH_ASSOC);

// Get current currency
$currency = getCurrentCurrency($db);

// Pagination and filtering
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 15;
$offset = ($page - 1) * $per_page;
$search = trim($_GET['search'] ?? '');
$employee_filter = intval($_GET['employee_id'] ?? 0);
$status_filter = $_GET['status'] ?? '';
$date_filter = $_GET['date'] ?? '';

// Build query conditions
$conditions = ['r.branch_id = ?'];
$params = [$user_info['id']];

if ($search) {
    $conditions[] = "(r.receipt_number LIKE ? OR r.customer_name LIKE ? OR r.customer_phone LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if ($employee_filter) {
    $conditions[] = "r.employee_id = ?";
    $params[] = $employee_filter;
}

if ($status_filter) {
    $conditions[] = "r.payment_status = ?";
    $params[] = $status_filter;
}

if ($date_filter) {
    $conditions[] = "DATE(r.created_at) = ?";
    $params[] = $date_filter;
}

$where_clause = implode(' AND ', $conditions);

// Get total receipts count
$stmt = $db->prepare("SELECT COUNT(*) FROM receipts r WHERE $where_clause");
$stmt->execute($params);
$total_receipts = $stmt->fetchColumn();
$total_pages = ceil($total_receipts / $per_page);

// Get receipts
$stmt = $db->prepare("SELECT r.*, e.full_name as employee_name, e.employee_code,
    (SELECT COUNT(*) FROM receipt_items WHERE receipt_id = r.id) as item_count
    FROM receipts r 
    JOIN employees e ON r.employee_id = e.id 
    WHERE $where_clause
    ORDER BY r.created_at DESC 
    LIMIT ? OFFSET ?");
$stmt->execute([...$params, $per_page, $offset]);
$receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get employees for filter
$stmt = $db->prepare("SELECT id, full_name, employee_code FROM employees WHERE branch_id = ? AND status = 'active' ORDER BY full_name");
$stmt->execute([$user_info['id']]);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get branch statistics
$stmt = $db->prepare("SELECT 
    COUNT(*) as total_receipts,
    COALESCE(SUM(total_amount), 0) as total_revenue,
    COALESCE(SUM(total_commission), 0) as total_commission
    FROM receipts WHERE branch_id = ?");
$stmt->execute([$user_info['id']]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branch Receipts - Branch Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .sidebar {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
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
            transition: all 0.3s;
            cursor: pointer;
        }
        .receipt-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar p-3">
                <h4 class="mb-4"><i class="fas fa-building me-2"></i>Branch Panel</h4>
                
                <div class="user-info bg-white bg-opacity-10 rounded p-3 mb-4">
                    <div>
                        <h6 class="mb-0"><?= htmlspecialchars($user_info['full_name']) ?></h6>
                        <small class="opacity-75">Branch Manager</small>
                        <div class="small opacity-75"><?= htmlspecialchars($branch_info['branch_name']) ?></div>
                    </div>
                </div>
                
                <nav class="nav flex-column">
                    <a class="nav-link" href="/branch/dashboard.php">
                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                    </a>
                    <a class="nav-link" href="/branch/employees.php">
                        <i class="fas fa-users me-2"></i>Employees
                    </a>
                    <a class="nav-link active" href="/branch/receipts.php">
                        <i class="fas fa-receipt me-2"></i>Receipts
                    </a>
                    <a class="nav-link" href="/branch/reports.php">
                        <i class="fas fa-chart-bar me-2"></i>Reports
                    </a>
                    <a class="nav-link" href="/branch/profile.php">
                        <i class="fas fa-building me-2"></i>Branch Profile
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
                    <h1 class="h3"><i class="fas fa-receipt me-2"></i>Branch Receipts</h1>
                    <div class="text-muted">
                        <i class="fas fa-calendar me-2"></i><?= date('F j, Y') ?>
                    </div>
                </div>
                
                <!-- Statistics -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card border-success shadow-sm">
                            <div class="card-body text-center">
                                <h3 class="text-success"><?= $stats['total_receipts'] ?></h3>
                                <p class="mb-0 text-muted">Total Receipts</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-primary shadow-sm">
                            <div class="card-body text-center">
                                <h3 class="text-primary"><?= $currency ?><?= number_format($stats['total_revenue'], 0) ?></h3>
                                <p class="mb-0 text-muted">Total Revenue</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-info shadow-sm">
                            <div class="card-body text-center">
                                <h3 class="text-info"><?= $currency ?><?= number_format($stats['total_commission'], 0) ?></h3>
                                <p class="mb-0 text-muted">Total Commissions</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Filters -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Receipt #, Customer...">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Employee</label>
                                <select class="form-select" name="employee_id">
                                    <option value="">All Employees</option>
                                    <?php foreach ($employees as $employee): ?>
                                        <option value="<?= $employee['id'] ?>" <?= $employee_filter == $employee['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($employee['full_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="">All Status</option>
                                    <option value="paid" <?= $status_filter === 'paid' ? 'selected' : '' ?>>Paid</option>
                                    <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Date</label>
                                <input type="date" class="form-control" name="date" value="<?= htmlspecialchars($date_filter) ?>">
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary me-2">
                                    <i class="fas fa-search me-1"></i>Filter
                                </button>
                                <a href="/branch/receipts.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-1"></i>Clear
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <?php if (empty($receipts)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-receipt fa-5x text-muted mb-4"></i>
                        <h3 class="text-muted">No receipts found</h3>
                        <p class="text-muted">No receipts match your current filters.</p>
                    </div>
                <?php else: ?>
                    <!-- Receipts List -->
                    <div class="row">
                        <?php foreach ($receipts as $receipt): ?>
                            <div class="col-lg-4 col-md-6 mb-4">
                                <div class="card receipt-card shadow-sm">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <h6 class="card-title mb-0">
                                                #<?= htmlspecialchars($receipt['receipt_number']) ?>
                                            </h6>
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
                                            <div class="d-flex align-items-center mb-1 small">
                                                <i class="fas fa-user-tie me-2 text-muted"></i>
                                                <?= htmlspecialchars($receipt['employee_name']) ?>
                                                <span class="badge bg-light text-dark ms-2"><?= htmlspecialchars($receipt['employee_code']) ?></span>
                                            </div>
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
                                        <a class="page-link" href="?page=<?= $page - 1 ?>&<?= http_build_query(array_filter($_GET, fn($k) => $k !== 'page', ARRAY_FILTER_USE_KEY)) ?>">Previous</a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="?page=<?= $i ?>&<?= http_build_query(array_filter($_GET, fn($k) => $k !== 'page', ARRAY_FILTER_USE_KEY)) ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?= $page + 1 ?>&<?= http_build_query(array_filter($_GET, fn($k) => $k !== 'page', ARRAY_FILTER_USE_KEY)) ?>">Next</a>
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
</body>
</html>