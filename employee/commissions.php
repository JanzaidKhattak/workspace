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

// Get currency setting
$stmt = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'currency' LIMIT 1");
$currency_result = $stmt->fetch(PDO::FETCH_ASSOC);
$currency = $currency_result ? $currency_result['setting_value'] : 'USD';

// Get filter parameters
$month = $_GET['month'] ?? date('Y-m');
$year = $_GET['year'] ?? date('Y');

// Get commission statistics
$stats = [];

// Today's commission
$stmt = $db->prepare("SELECT COALESCE(SUM(total_commission), 0) as commission FROM receipts WHERE employee_id = ? AND DATE(created_at) = DATE('now')");
$stmt->execute([$user_info['id']]);
$stats['today'] = $stmt->fetchColumn();

// This month's commission
$stmt = $db->prepare("SELECT COALESCE(SUM(total_commission), 0) as commission FROM receipts WHERE employee_id = ? AND strftime('%Y-%m', created_at) = strftime('%Y-%m', 'now')");
$stmt->execute([$user_info['id']]);
$stats['this_month'] = $stmt->fetchColumn();

// Last month's commission
$stmt = $db->prepare("SELECT COALESCE(SUM(total_commission), 0) as commission FROM receipts WHERE employee_id = ? AND strftime('%Y-%m', created_at) = strftime('%Y-%m', 'now', '-1 month')");
$stmt->execute([$user_info['id']]);
$stats['last_month'] = $stmt->fetchColumn();

// Total commission
$stmt = $db->prepare("SELECT COALESCE(SUM(total_commission), 0) as commission FROM receipts WHERE employee_id = ?");
$stmt->execute([$user_info['id']]);
$stats['total'] = $stmt->fetchColumn();

// Get monthly commission breakdown
$stmt = $db->prepare("SELECT 
    strftime('%Y-%m', created_at) as month,
    COUNT(*) as receipt_count,
    COALESCE(SUM(total_amount), 0) as total_revenue,
    COALESCE(SUM(total_commission), 0) as total_commission
    FROM receipts 
    WHERE employee_id = ? 
    GROUP BY strftime('%Y-%m', created_at)
    ORDER BY month DESC
    LIMIT 12");
$stmt->execute([$user_info['id']]);
$monthly_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get commission by service
$stmt = $db->prepare("SELECT 
    s.service_name,
    COUNT(ri.id) as service_count,
    COALESCE(SUM(ri.total_price), 0) as service_revenue,
    COALESCE(SUM(ri.commission_amount), 0) as service_commission,
    AVG(s.commission_rate) as commission_rate
    FROM receipt_items ri
    JOIN receipts r ON ri.receipt_id = r.id
    JOIN services s ON ri.service_id = s.id
    WHERE r.employee_id = ?
    GROUP BY s.id, s.service_name
    ORDER BY service_commission DESC");
$stmt->execute([$user_info['id']]);
$service_commissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent high commission receipts
$stmt = $db->prepare("SELECT 
    receipt_number,
    customer_name,
    total_amount,
    total_commission,
    created_at
    FROM receipts 
    WHERE employee_id = ? AND total_commission > 0
    ORDER BY total_commission DESC
    LIMIT 10");
$stmt->execute([$user_info['id']]);
$high_commission_receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Commissions - Employee</title>
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
        .bg-success-gradient {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        }
        .bg-info-gradient {
            background: linear-gradient(135deg, #17a2b8 0%, #6f42c1 100%);
        }
        .bg-warning-gradient {
            background: linear-gradient(135deg, #fd7e14 0%, #ffc107 100%);
        }
        .bg-primary-gradient {
            background: linear-gradient(135deg, #007bff 0%, #6f42c1 100%);
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
                    <a class="nav-link" href="/employee/receipts.php">
                        <i class="fas fa-receipt me-2"></i>My Receipts
                    </a>
                    <a class="nav-link active" href="/employee/commissions.php">
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
                    <h1 class="h3"><i class="fas fa-chart-line me-2"></i>My Commissions</h1>
                    <div class="text-muted">
                        <i class="fas fa-calendar me-2"></i><?= date('F j, Y') ?>
                    </div>
                </div>
                
                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card shadow-sm">
                            <div class="card-body d-flex align-items-center">
                                <div class="stat-icon bg-success-gradient me-3">
                                    <i class="fas fa-coins"></i>
                                </div>
                                <div>
                                    <h3 class="mb-0"><?= $currency ?><?= number_format($stats['today'], 2) ?></h3>
                                    <p class="text-muted mb-0">Today's Commission</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card shadow-sm">
                            <div class="card-body d-flex align-items-center">
                                <div class="stat-icon bg-info-gradient me-3">
                                    <i class="fas fa-calendar-month"></i>
                                </div>
                                <div>
                                    <h3 class="mb-0"><?= $currency ?><?= number_format($stats['this_month'], 2) ?></h3>
                                    <p class="text-muted mb-0">This Month</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card shadow-sm">
                            <div class="card-body d-flex align-items-center">
                                <div class="stat-icon bg-warning-gradient me-3">
                                    <i class="fas fa-calendar-alt"></i>
                                </div>
                                <div>
                                    <h3 class="mb-0"><?= $currency ?><?= number_format($stats['last_month'], 2) ?></h3>
                                    <p class="text-muted mb-0">Last Month</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card shadow-sm">
                            <div class="card-body d-flex align-items-center">
                                <div class="stat-icon bg-primary-gradient me-3">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <div>
                                    <h3 class="mb-0"><?= $currency ?><?= number_format($stats['total'], 2) ?></h3>
                                    <p class="text-muted mb-0">Total Earned</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <!-- Monthly Breakdown -->
                    <div class="col-lg-6 mb-4">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Monthly Commission Breakdown</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($monthly_data)): ?>
                                    <div class="text-center text-muted py-4">
                                        <i class="fas fa-chart-bar fa-3x mb-3"></i>
                                        <p>No commission data available</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Month</th>
                                                    <th>Receipts</th>
                                                    <th>Revenue</th>
                                                    <th>Commission</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($monthly_data as $data): ?>
                                                    <tr>
                                                        <td><?= date('M Y', strtotime($data['month'] . '-01')) ?></td>
                                                        <td><?= $data['receipt_count'] ?></td>
                                                        <td><?= $currency ?><?= number_format($data['total_revenue'], 2) ?></td>
                                                        <td class="text-success">
                                                            <strong><?= $currency ?><?= number_format($data['total_commission'], 2) ?></strong>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Commission by Service -->
                    <div class="col-lg-6 mb-4">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-cogs me-2"></i>Commission by Service</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($service_commissions)): ?>
                                    <div class="text-center text-muted py-4">
                                        <i class="fas fa-cogs fa-3x mb-3"></i>
                                        <p>No service data available</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Service</th>
                                                    <th>Count</th>
                                                    <th>Commission</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($service_commissions as $service): ?>
                                                    <tr>
                                                        <td>
                                                            <?= htmlspecialchars($service['service_name']) ?>
                                                            <small class="text-muted d-block"><?= number_format($service['commission_rate'], 1) ?>% rate</small>
                                                        </td>
                                                        <td><?= $service['service_count'] ?></td>
                                                        <td class="text-success">
                                                            <strong><?= $currency ?><?= number_format($service['service_commission'], 2) ?></strong>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Top Commission Receipts -->
                <div class="row">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-trophy me-2"></i>Top Commission Receipts</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($high_commission_receipts)): ?>
                                    <div class="text-center text-muted py-4">
                                        <i class="fas fa-trophy fa-3x mb-3"></i>
                                        <p>No high commission receipts yet</p>
                                        <a href="/employee/create-receipt.php" class="btn btn-primary">Create Your First Receipt</a>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Receipt #</th>
                                                    <th>Customer</th>
                                                    <th>Total Amount</th>
                                                    <th>Commission</th>
                                                    <th>Date</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($high_commission_receipts as $receipt): ?>
                                                    <tr>
                                                        <td><code><?= htmlspecialchars($receipt['receipt_number']) ?></code></td>
                                                        <td><?= htmlspecialchars($receipt['customer_name']) ?></td>
                                                        <td><?= $currency ?><?= number_format($receipt['total_amount'], 2) ?></td>
                                                        <td class="text-success">
                                                            <strong><?= $currency ?><?= number_format($receipt['total_commission'], 2) ?></strong>
                                                        </td>
                                                        <td>
                                                            <small><?= date('M j, Y', strtotime($receipt['created_at'])) ?></small>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
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