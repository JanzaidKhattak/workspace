<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

$database = new Database();
$auth = new Auth($database);

$auth->requireRole(['branch']);

$user_info = $auth->getUserInfo();
$db = $database->getConnection();

// Get branch information
$stmt = $db->prepare("SELECT * FROM branches WHERE id = ?");
$stmt->execute([$user_info['id']]);
$branch_info = $stmt->fetch(PDO::FETCH_ASSOC);

// Get currency setting
$stmt = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'currency' LIMIT 1");
$currency_result = $stmt->fetch(PDO::FETCH_ASSOC);
$currency = $currency_result ? $currency_result['setting_value'] : 'USD';

// Get date range for reports (default to current month)
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$report_type = $_GET['report_type'] ?? 'overview';

// Overview Statistics
$stmt = $db->prepare("SELECT 
    COUNT(*) as total_receipts,
    COALESCE(SUM(total_amount), 0) as total_revenue,
    COALESCE(SUM(total_commission), 0) as total_commission,
    AVG(total_amount) as avg_receipt_value
    FROM receipts 
    WHERE branch_id = ? AND DATE(created_at) BETWEEN ? AND ?");
$stmt->execute([$user_info['id'], $start_date, $end_date]);
$overview = $stmt->fetch(PDO::FETCH_ASSOC);

// Daily Revenue Report
$stmt = $db->prepare("SELECT 
    DATE(created_at) as date,
    COUNT(*) as daily_receipts,
    COALESCE(SUM(total_amount), 0) as daily_revenue,
    COALESCE(SUM(total_commission), 0) as daily_commission
    FROM receipts 
    WHERE branch_id = ? AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at)
    ORDER BY date DESC");
$stmt->execute([$user_info['id'], $start_date, $end_date]);
$daily_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Employee Performance Report
$stmt = $db->prepare("SELECT 
    e.full_name,
    e.employee_code,
    COUNT(r.id) as total_receipts,
    COALESCE(SUM(r.total_amount), 0) as total_revenue,
    COALESCE(SUM(r.total_commission), 0) as total_commission,
    AVG(r.total_amount) as avg_receipt_value
    FROM employees e
    LEFT JOIN receipts r ON e.id = r.employee_id AND DATE(r.created_at) BETWEEN ? AND ?
    WHERE e.branch_id = ? AND e.status = 'active'
    GROUP BY e.id
    ORDER BY total_revenue DESC");
$stmt->execute([$start_date, $end_date, $user_info['id']]);
$employee_performance = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Service Performance Report
$stmt = $db->prepare("SELECT 
    s.service_name,
    s.service_price,
    COUNT(ri.id) as total_sold,
    SUM(ri.quantity) as total_quantity,
    COALESCE(SUM(ri.total_price), 0) as total_revenue,
    COALESCE(SUM(ri.commission_amount), 0) as total_commission
    FROM services s
    LEFT JOIN receipt_items ri ON s.id = ri.service_id
    LEFT JOIN receipts r ON ri.receipt_id = r.id AND r.branch_id = ? AND DATE(r.created_at) BETWEEN ? AND ?
    WHERE s.status = 'active'
    GROUP BY s.id
    ORDER BY total_revenue DESC");
$stmt->execute([$user_info['id'], $start_date, $end_date]);
$service_performance = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Payment Status Report
$stmt = $db->prepare("SELECT 
    payment_status,
    COUNT(*) as count,
    COALESCE(SUM(total_amount), 0) as total_amount
    FROM receipts 
    WHERE branch_id = ? AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY payment_status");
$stmt->execute([$user_info['id'], $start_date, $end_date]);
$payment_status_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Top Customers Report
$stmt = $db->prepare("SELECT 
    customer_name,
    customer_phone,
    COUNT(*) as visit_count,
    COALESCE(SUM(total_amount), 0) as total_spent,
    AVG(total_amount) as avg_spent
    FROM receipts 
    WHERE branch_id = ? AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY customer_name, customer_phone
    ORDER BY total_spent DESC
    LIMIT 10");
$stmt->execute([$user_info['id'], $start_date, $end_date]);
$top_customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branch Reports - Branch Manager</title>
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
        .report-card {
            border-radius: 15px;
            border: none;
            transition: transform 0.2s;
        }
        .report-card:hover {
            transform: translateY(-3px);
        }
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
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
                    <div class="d-flex align-items-center">
                        <div class="avatar bg-white bg-opacity-20 rounded-circle p-2 me-3">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <div>
                            <h6 class="mb-0"><?= htmlspecialchars($user_info['full_name']) ?></h6>
                            <small class="opacity-75">Branch Manager</small>
                            <div class="small opacity-75"><?= htmlspecialchars($branch_info['branch_name']) ?></div>
                        </div>
                    </div>
                </div>
                
                <nav class="nav flex-column">
                    <a class="nav-link" href="/branch/dashboard.php">
                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                    </a>
                    <a class="nav-link" href="/branch/employees.php">
                        <i class="fas fa-users me-2"></i>Employees
                    </a>
                    <a class="nav-link" href="/branch/receipts.php">
                        <i class="fas fa-receipt me-2"></i>Receipts
                    </a>
                    <a class="nav-link active" href="/branch/reports.php">
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
                    <h1 class="h3"><i class="fas fa-chart-bar me-2"></i>Branch Reports</h1>
                    <div class="text-muted">
                        <i class="fas fa-calendar me-2"></i><?= date('M j', strtotime($start_date)) ?> - <?= date('M j, Y', strtotime($end_date)) ?>
                    </div>
                </div>
                
                <!-- Date Range Filter -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label">Start Date</label>
                                <input type="date" class="form-control" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">End Date</label>
                                <input type="date" class="form-control" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search me-2"></i>Generate Report
                                </button>
                            </div>
                            <div class="col-md-3 text-end">
                                <button type="button" class="btn btn-outline-success" onclick="window.print()">
                                    <i class="fas fa-print me-2"></i>Print Report
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Overview Statistics -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="card report-card shadow-sm">
                            <div class="card-body d-flex align-items-center">
                                <div class="stat-icon bg-primary me-3">
                                    <i class="fas fa-receipt"></i>
                                </div>
                                <div>
                                    <h3 class="mb-0"><?= $overview['total_receipts'] ?></h3>
                                    <p class="text-muted mb-0">Total Receipts</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card report-card shadow-sm">
                            <div class="card-body d-flex align-items-center">
                                <div class="stat-icon bg-success me-3">
                                    <i class="fas fa-dollar-sign"></i>
                                </div>
                                <div>
                                    <h3 class="mb-0"><?= $currency ?><?= number_format($overview['total_revenue'], 0) ?></h3>
                                    <p class="text-muted mb-0">Total Revenue</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card report-card shadow-sm">
                            <div class="card-body d-flex align-items-center">
                                <div class="stat-icon bg-info me-3">
                                    <i class="fas fa-percentage"></i>
                                </div>
                                <div>
                                    <h3 class="mb-0"><?= $currency ?><?= number_format($overview['total_commission'], 0) ?></h3>
                                    <p class="text-muted mb-0">Total Commissions</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card report-card shadow-sm">
                            <div class="card-body d-flex align-items-center">
                                <div class="stat-icon bg-warning me-3">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <div>
                                    <h3 class="mb-0"><?= $currency ?><?= number_format($overview['avg_receipt_value'], 0) ?></h3>
                                    <p class="text-muted mb-0">Avg Receipt Value</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <!-- Daily Revenue Chart -->
                    <div class="col-lg-8 mb-4">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Daily Revenue Trend</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($daily_data)): ?>
                                    <div class="text-center text-muted py-4">
                                        <i class="fas fa-chart-line fa-3x mb-3"></i>
                                        <p>No data available for the selected period</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Receipts</th>
                                                    <th>Revenue</th>
                                                    <th>Commission</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($daily_data as $day): ?>
                                                    <tr>
                                                        <td><?= date('M j, Y', strtotime($day['date'])) ?></td>
                                                        <td><?= $day['daily_receipts'] ?></td>
                                                        <td><?= $currency ?><?= number_format($day['daily_revenue'], 2) ?></td>
                                                        <td class="text-success"><?= $currency ?><?= number_format($day['daily_commission'], 2) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Payment Status -->
                    <div class="col-lg-4 mb-4">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-credit-card me-2"></i>Payment Status</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($payment_status_data)): ?>
                                    <div class="text-center text-muted py-4">
                                        <i class="fas fa-credit-card fa-3x mb-3"></i>
                                        <p>No payment data available</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($payment_status_data as $status): ?>
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <div>
                                                <span class="badge bg-<?= 
                                                    $status['payment_status'] === 'paid' ? 'success' : 
                                                    ($status['payment_status'] === 'pending' ? 'warning' : 'danger') 
                                                ?>">
                                                    <?= ucfirst($status['payment_status']) ?>
                                                </span>
                                                <div class="small text-muted"><?= $status['count'] ?> receipts</div>
                                            </div>
                                            <div class="text-end">
                                                <strong><?= $currency ?><?= number_format($status['total_amount'], 0) ?></strong>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <!-- Employee Performance -->
                    <div class="col-lg-6 mb-4">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-users me-2"></i>Employee Performance</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($employee_performance)): ?>
                                    <div class="text-center text-muted py-4">
                                        <i class="fas fa-users fa-3x mb-3"></i>
                                        <p>No employee data available</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Employee</th>
                                                    <th>Receipts</th>
                                                    <th>Revenue</th>
                                                    <th>Commission</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($employee_performance as $emp): ?>
                                                    <tr>
                                                        <td>
                                                            <?= htmlspecialchars($emp['full_name']) ?>
                                                            <div class="small text-muted"><?= htmlspecialchars($emp['employee_code']) ?></div>
                                                        </td>
                                                        <td><?= $emp['total_receipts'] ?></td>
                                                        <td><?= $currency ?><?= number_format($emp['total_revenue'], 0) ?></td>
                                                        <td class="text-success"><?= $currency ?><?= number_format($emp['total_commission'], 0) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Top Services -->
                    <div class="col-lg-6 mb-4">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-cogs me-2"></i>Service Performance</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($service_performance)): ?>
                                    <div class="text-center text-muted py-4">
                                        <i class="fas fa-cogs fa-3x mb-3"></i>
                                        <p>No service data available</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Service</th>
                                                    <th>Sold</th>
                                                    <th>Revenue</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach (array_slice($service_performance, 0, 10) as $service): ?>
                                                    <tr>
                                                        <td>
                                                            <?= htmlspecialchars($service['service_name']) ?>
                                                            <div class="small text-muted">Unit: <?= $currency ?><?= number_format($service['service_price'], 2) ?></div>
                                                        </td>
                                                        <td>
                                                            <?= $service['total_sold'] ?>
                                                            <div class="small text-muted">Qty: <?= $service['total_quantity'] ?></div>
                                                        </td>
                                                        <td><?= $currency ?><?= number_format($service['total_revenue'], 0) ?></td>
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
                
                <!-- Top Customers -->
                <?php if (!empty($top_customers)): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="card shadow-sm">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0"><i class="fas fa-crown me-2"></i>Top Customers</h5>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Customer</th>
                                                    <th>Phone</th>
                                                    <th>Visits</th>
                                                    <th>Total Spent</th>
                                                    <th>Avg per Visit</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($top_customers as $customer): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($customer['customer_name']) ?></td>
                                                        <td><?= htmlspecialchars($customer['customer_phone']) ?></td>
                                                        <td><?= $customer['visit_count'] ?></td>
                                                        <td><?= $currency ?><?= number_format($customer['total_spent'], 2) ?></td>
                                                        <td><?= $currency ?><?= number_format($customer['avg_spent'], 2) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>