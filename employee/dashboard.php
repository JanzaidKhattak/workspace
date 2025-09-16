<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

$database = new Database();
$auth = new Auth($database);

$auth->requireRole(['employee']);

$user_info = $auth->getUserInfo();
$db = $database->getConnection();

// Get employee information
$stmt = $db->prepare("SELECT e.*, b.branch_name FROM employees e JOIN branches b ON e.branch_id = b.id WHERE e.id = ?");
$stmt->execute([$user_info['id']]);
$employee_info = $stmt->fetch(PDO::FETCH_ASSOC);

// Get employee statistics
$stats = [];

// Total receipts created this month
$stmt = $db->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total, COALESCE(SUM(total_commission), 0) as commission FROM receipts WHERE employee_id = ? AND strftime('%Y-%m', created_at) = strftime('%Y-%m', 'now')");
$stmt->execute([$user_info['id']]);
$monthly_data = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['monthly_receipts'] = $monthly_data['count'];
$stats['monthly_revenue'] = $monthly_data['total'];
$stats['monthly_commission'] = $monthly_data['commission'];

// Today's receipts
$stmt = $db->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total, COALESCE(SUM(total_commission), 0) as commission FROM receipts WHERE employee_id = ? AND DATE(created_at) = DATE('now')");
$stmt->execute([$user_info['id']]);
$today_data = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['today_receipts'] = $today_data['count'];
$stats['today_revenue'] = $today_data['total'];
$stats['today_commission'] = $today_data['commission'];

// Recent receipts
$stmt = $db->prepare("SELECT r.*, 
    (SELECT COUNT(*) FROM receipt_items WHERE receipt_id = r.id) as item_count
    FROM receipts r 
    WHERE r.employee_id = ? 
    ORDER BY r.created_at DESC 
    LIMIT 10");
$stmt->execute([$user_info['id']]);
$recent_receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get services for receipt creation
$stmt = $db->query("SELECT * FROM services WHERE status = 'active' ORDER BY service_name");
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Dashboard - <?= htmlspecialchars($employee_info['full_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
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
        .stat-card {
            border-radius: 15px;
            border: none;
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }
        .bg-primary-gradient {
            background: linear-gradient(135deg, #007bff 0%, #6f42c1 100%);
        }
        .bg-success-gradient {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        }
        .bg-warning-gradient {
            background: linear-gradient(135deg, #fd7e14 0%, #ffc107 100%);
        }
        .bg-info-gradient {
            background: linear-gradient(135deg, #17a2b8 0%, #6f42c1 100%);
        }
        .receipt-card {
            border-left: 4px solid #007bff;
            transition: all 0.3s;
        }
        .receipt-card:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .quick-action-btn {
            border-radius: 15px;
            padding: 15px 20px;
            border: none;
            transition: all 0.3s;
            margin-bottom: 10px;
        }
        .quick-action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
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
                    <div class="d-flex align-items-center">
                        <div class="avatar bg-white bg-opacity-20 rounded-circle p-2 me-3">
                            <i class="fas fa-user"></i>
                        </div>
                        <div>
                            <h6 class="mb-0"><?= htmlspecialchars($employee_info['full_name']) ?></h6>
                            <small class="opacity-75">Employee</small>
                            <div class="small opacity-75"><?= htmlspecialchars($employee_info['branch_name']) ?></div>
                            <div class="small opacity-75">Code: <?= htmlspecialchars($employee_info['employee_code']) ?></div>
                        </div>
                    </div>
                </div>
                
                <nav class="nav flex-column">
                    <a class="nav-link active" href="/employee/dashboard.php">
                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                    </a>
                    <a class="nav-link" href="/employee/create-receipt.php">
                        <i class="fas fa-plus-circle me-2"></i>Create Receipt
                    </a>
                    <a class="nav-link" href="/employee/receipts.php">
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
                    <h1 class="h3">Welcome, <?= htmlspecialchars($employee_info['full_name']) ?>!</h1>
                    <div class="text-muted">
                        <i class="fas fa-calendar me-2"></i><?= date('F j, Y') ?>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <h5 class="card-title mb-3"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
                                <div class="row">
                                    <div class="col-md-4 mb-2">
                                        <button class="btn btn-primary quick-action-btn w-100" onclick="window.location.href='/employee/create-receipt.php'">
                                            <i class="fas fa-plus-circle fa-2x d-block mb-2"></i>
                                            <strong>Create New Receipt</strong>
                                        </button>
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <button class="btn btn-success quick-action-btn w-100" onclick="window.location.href='/employee/receipts.php'">
                                            <i class="fas fa-receipt fa-2x d-block mb-2"></i>
                                            <strong>View My Receipts</strong>
                                        </button>
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <button class="btn btn-info quick-action-btn w-100" onclick="window.location.href='/employee/commissions.php'">
                                            <i class="fas fa-chart-line fa-2x d-block mb-2"></i>
                                            <strong>Check Commissions</strong>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card shadow-sm">
                            <div class="card-body d-flex align-items-center">
                                <div class="stat-icon bg-primary-gradient me-3">
                                    <i class="fas fa-receipt"></i>
                                </div>
                                <div>
                                    <h3 class="mb-0"><?= $stats['today_receipts'] ?></h3>
                                    <p class="text-muted mb-0">Today's Receipts</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card shadow-sm">
                            <div class="card-body d-flex align-items-center">
                                <div class="stat-icon bg-success-gradient me-3">
                                    <i class="fas fa-dollar-sign"></i>
                                </div>
                                <div>
                                    <h3 class="mb-0">$<?= number_format($stats['today_revenue'], 2) ?></h3>
                                    <p class="text-muted mb-0">Today's Revenue</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card shadow-sm">
                            <div class="card-body d-flex align-items-center">
                                <div class="stat-icon bg-warning-gradient me-3">
                                    <i class="fas fa-percent"></i>
                                </div>
                                <div>
                                    <h3 class="mb-0">$<?= number_format($stats['today_commission'], 2) ?></h3>
                                    <p class="text-muted mb-0">Today's Commission</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card shadow-sm">
                            <div class="card-body d-flex align-items-center">
                                <div class="stat-icon bg-info-gradient me-3">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <div>
                                    <h3 class="mb-0"><?= $stats['monthly_receipts'] ?></h3>
                                    <p class="text-muted mb-0">Monthly Receipts</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <!-- Recent Receipts -->
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Receipts</h5>
                                <a href="/employee/receipts.php" class="btn btn-sm btn-primary">
                                    <i class="fas fa-eye me-1"></i>View All
                                </a>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recent_receipts)): ?>
                                    <div class="text-center text-muted py-5">
                                        <i class="fas fa-receipt fa-3x mb-3"></i>
                                        <h4>No receipts created yet</h4>
                                        <p>Start by creating your first receipt to help customers!</p>
                                        <a href="/employee/create-receipt.php" class="btn btn-primary">
                                            <i class="fas fa-plus me-2"></i>Create First Receipt
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="row">
                                        <?php foreach (array_slice($recent_receipts, 0, 6) as $receipt): ?>
                                            <div class="col-md-6 col-lg-4 mb-3">
                                                <div class="card receipt-card h-100">
                                                    <div class="card-body">
                                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                                            <h6 class="card-title mb-0">
                                                                Receipt #<?= htmlspecialchars($receipt['receipt_number']) ?>
                                                            </h6>
                                                            <span class="badge bg-<?= 
                                                                $receipt['payment_status'] === 'paid' ? 'success' : 
                                                                ($receipt['payment_status'] === 'pending' ? 'warning' : 'danger') 
                                                            ?>">
                                                                <?= ucfirst($receipt['payment_status']) ?>
                                                            </span>
                                                        </div>
                                                        <p class="card-text">
                                                            <strong>Customer:</strong> <?= htmlspecialchars($receipt['customer_name']) ?><br>
                                                            <strong>Amount:</strong> $<?= number_format($receipt['total_amount'], 2) ?><br>
                                                            <strong>Commission:</strong> $<?= number_format($receipt['total_commission'], 2) ?><br>
                                                            <strong>Items:</strong> <?= $receipt['item_count'] ?> service(s)
                                                        </p>
                                                        <small class="text-muted">
                                                            <i class="fas fa-clock me-1"></i>
                                                            <?= date('M j, Y g:i A', strtotime($receipt['created_at'])) ?>
                                                        </small>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <?php if (count($recent_receipts) > 6): ?>
                                        <div class="text-center mt-3">
                                            <small class="text-muted">
                                                Showing 6 of <?= count($recent_receipts) ?> recent receipts
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>