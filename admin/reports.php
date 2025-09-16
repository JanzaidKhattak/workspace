<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

$database = new Database();
$auth = new Auth($database);

$auth->requireRole(['admin']);

$user_info = $auth->getUserInfo();
$db = $database->getConnection();

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$branch_id = $_GET['branch_id'] ?? '';
$report_type = $_GET['report_type'] ?? 'overview';

// Get branches for filter
$stmt = $db->query("SELECT * FROM branches WHERE status = 'active' ORDER BY branch_name");
$branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build WHERE clause for filtering
$where_conditions = ["DATE(r.created_at) BETWEEN ? AND ?"];
$params = [$start_date, $end_date];

if (!empty($branch_id)) {
    $where_conditions[] = "r.branch_id = ?";
    $params[] = $branch_id;
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Get report data based on type
switch ($report_type) {
    case 'overview':
        // Overall statistics
        $stmt = $db->prepare("SELECT 
            COUNT(*) as total_receipts,
            COALESCE(SUM(total_amount), 0) as total_revenue,
            COALESCE(SUM(total_commission), 0) as total_commission,
            COALESCE(AVG(total_amount), 0) as avg_receipt_value
            FROM receipts r $where_clause");
        $stmt->execute($params);
        $overview = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Daily breakdown
        $stmt = $db->prepare("SELECT 
            DATE(r.created_at) as date,
            COUNT(*) as receipts,
            COALESCE(SUM(total_amount), 0) as revenue
            FROM receipts r $where_clause
            GROUP BY DATE(r.created_at)
            ORDER BY DATE(r.created_at) DESC");
        $stmt->execute($params);
        $daily_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
        
    case 'branch':
        // Branch-wise report
        $stmt = $db->prepare("SELECT 
            b.branch_name,
            b.branch_code,
            COUNT(r.id) as total_receipts,
            COALESCE(SUM(r.total_amount), 0) as total_revenue,
            COALESCE(SUM(r.total_commission), 0) as total_commission,
            (SELECT COUNT(*) FROM employees WHERE branch_id = b.id AND status = 'active') as active_employees
            FROM branches b
            LEFT JOIN receipts r ON b.id = r.branch_id AND DATE(r.created_at) BETWEEN ? AND ?
            WHERE b.status = 'active'
            GROUP BY b.id
            ORDER BY total_revenue DESC");
        $stmt->execute([$start_date, $end_date]);
        $branch_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
        
    case 'employee':
        // Employee-wise report
        $employee_where = $where_conditions;
        $employee_params = $params;
        if (!empty($branch_id)) {
            $employee_where[] = "e.branch_id = ?";
            $employee_params[] = $branch_id;
        }
        $employee_where_clause = "WHERE " . implode(" AND ", $employee_where);
        
        $stmt = $db->prepare("SELECT 
            e.full_name,
            e.employee_code,
            b.branch_name,
            COUNT(r.id) as total_receipts,
            COALESCE(SUM(r.total_amount), 0) as total_revenue,
            COALESCE(SUM(r.total_commission), 0) as total_commission
            FROM employees e
            LEFT JOIN receipts r ON e.id = r.employee_id AND DATE(r.created_at) BETWEEN ? AND ?
            LEFT JOIN branches b ON e.branch_id = b.id
            WHERE e.status = 'active'
            " . (!empty($branch_id) ? "AND e.branch_id = ?" : "") . "
            GROUP BY e.id
            ORDER BY total_revenue DESC");
        
        $employee_params = [$start_date, $end_date];
        if (!empty($branch_id)) {
            $employee_params[] = $branch_id;
        }
        $stmt->execute($employee_params);
        $employee_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
        
    case 'service':
        // Service-wise report
        $stmt = $db->prepare("SELECT 
            s.service_name,
            s.service_price,
            COUNT(ri.id) as total_usage,
            COALESCE(SUM(ri.quantity), 0) as total_quantity,
            COALESCE(SUM(ri.total_price), 0) as total_revenue,
            COALESCE(SUM(ri.commission_amount), 0) as total_commission
            FROM services s
            LEFT JOIN receipt_items ri ON s.id = ri.service_id
            LEFT JOIN receipts r ON ri.receipt_id = r.id AND DATE(r.created_at) BETWEEN ? AND ?
            " . (!empty($branch_id) ? "AND r.branch_id = ?" : "") . "
            WHERE s.status = 'active'
            GROUP BY s.id
            ORDER BY total_revenue DESC");
        
        $service_params = [$start_date, $end_date];
        if (!empty($branch_id)) {
            $service_params[] = $branch_id;
        }
        $stmt->execute($service_params);
        $service_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .sidebar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            margin-bottom: 20px;
        }
        .filter-card {
            background: #fff;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar p-3">
                <h4 class="mb-4"><i class="fas fa-keyboard me-2"></i>Admin Panel</h4>
                
                <div class="user-info bg-white bg-opacity-10 rounded p-3 mb-4">
                    <div class="d-flex align-items-center">
                        <div class="avatar bg-white bg-opacity-20 rounded-circle p-2 me-3">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <div>
                            <h6 class="mb-0"><?= htmlspecialchars($user_info['full_name']) ?></h6>
                            <small class="opacity-75">Administrator</small>
                        </div>
                    </div>
                </div>
                
                <nav class="nav flex-column">
                    <a class="nav-link" href="/admin/dashboard.php">
                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                    </a>
                    <a class="nav-link" href="/admin/branches.php">
                        <i class="fas fa-building me-2"></i>Branches
                    </a>
                    <a class="nav-link" href="/admin/employees.php">
                        <i class="fas fa-users me-2"></i>Employees
                    </a>
                    <a class="nav-link" href="/admin/services.php">
                        <i class="fas fa-cogs me-2"></i>Services
                    </a>
                    <a class="nav-link active" href="/admin/reports.php">
                        <i class="fas fa-chart-bar me-2"></i>Reports
                    </a>
                    <a class="nav-link" href="/admin/settings.php">
                        <i class="fas fa-cog me-2"></i>Settings
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
                    <h1 class="h3"><i class="fas fa-chart-bar me-2"></i>Reports & Analytics</h1>
                </div>
                
                <!-- Filters -->
                <div class="card filter-card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-2">
                                <label class="form-label">Report Type</label>
                                <select name="report_type" class="form-control">
                                    <option value="overview" <?= $report_type === 'overview' ? 'selected' : '' ?>>Overview</option>
                                    <option value="branch" <?= $report_type === 'branch' ? 'selected' : '' ?>>By Branch</option>
                                    <option value="employee" <?= $report_type === 'employee' ? 'selected' : '' ?>>By Employee</option>
                                    <option value="service" <?= $report_type === 'service' ? 'selected' : '' ?>>By Service</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" class="form-control" value="<?= $start_date ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">End Date</label>
                                <input type="date" name="end_date" class="form-control" value="<?= $end_date ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Branch</label>
                                <select name="branch_id" class="form-control">
                                    <option value="">All Branches</option>
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?= $branch['id'] ?>" <?= $branch_id == $branch['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($branch['branch_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary me-2">
                                    <i class="fas fa-search me-1"></i>Generate Report
                                </button>
                                <button type="button" class="btn btn-outline-success" onclick="window.print()">
                                    <i class="fas fa-print me-1"></i>Print
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Report Content -->
                <?php if ($report_type === 'overview'): ?>
                    <!-- Overview Report -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="card report-card text-center">
                                <div class="card-body">
                                    <h3 class="text-primary"><?= $overview['total_receipts'] ?></h3>
                                    <p class="mb-0">Total Receipts</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card report-card text-center">
                                <div class="card-body">
                                    <h3 class="text-success">$<?= number_format($overview['total_revenue'], 2) ?></h3>
                                    <p class="mb-0">Total Revenue</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card report-card text-center">
                                <div class="card-body">
                                    <h3 class="text-warning">$<?= number_format($overview['total_commission'], 2) ?></h3>
                                    <p class="mb-0">Total Commission</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card report-card text-center">
                                <div class="card-body">
                                    <h3 class="text-info">$<?= number_format($overview['avg_receipt_value'], 2) ?></h3>
                                    <p class="mb-0">Avg Receipt Value</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Daily Breakdown -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Daily Breakdown</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Receipts</th>
                                            <th>Revenue</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($daily_data as $day): ?>
                                            <tr>
                                                <td><?= date('M j, Y', strtotime($day['date'])) ?></td>
                                                <td><?= $day['receipts'] ?></td>
                                                <td>$<?= number_format($day['revenue'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                <?php elseif ($report_type === 'branch'): ?>
                    <!-- Branch Report -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Branch Performance Report</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Branch</th>
                                            <th>Code</th>
                                            <th>Employees</th>
                                            <th>Receipts</th>
                                            <th>Revenue</th>
                                            <th>Commission</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($branch_data as $branch): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($branch['branch_name']) ?></td>
                                                <td><code><?= htmlspecialchars($branch['branch_code']) ?></code></td>
                                                <td><?= $branch['active_employees'] ?></td>
                                                <td><?= $branch['total_receipts'] ?></td>
                                                <td>$<?= number_format($branch['total_revenue'], 2) ?></td>
                                                <td>$<?= number_format($branch['total_commission'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                <?php elseif ($report_type === 'employee'): ?>
                    <!-- Employee Report -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Employee Performance Report</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Employee</th>
                                            <th>Code</th>
                                            <th>Branch</th>
                                            <th>Receipts</th>
                                            <th>Revenue</th>
                                            <th>Commission</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($employee_data as $employee): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($employee['full_name']) ?></td>
                                                <td><code><?= htmlspecialchars($employee['employee_code']) ?></code></td>
                                                <td><?= htmlspecialchars($employee['branch_name']) ?></td>
                                                <td><?= $employee['total_receipts'] ?></td>
                                                <td>$<?= number_format($employee['total_revenue'], 2) ?></td>
                                                <td>$<?= number_format($employee['total_commission'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                <?php elseif ($report_type === 'service'): ?>
                    <!-- Service Report -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Service Usage Report</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Service</th>
                                            <th>Price</th>
                                            <th>Usage Count</th>
                                            <th>Total Quantity</th>
                                            <th>Revenue</th>
                                            <th>Commission</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($service_data as $service): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($service['service_name']) ?></td>
                                                <td>$<?= number_format($service['service_price'], 2) ?></td>
                                                <td><?= $service['total_usage'] ?></td>
                                                <td><?= $service['total_quantity'] ?></td>
                                                <td>$<?= number_format($service['total_revenue'], 2) ?></td>
                                                <td>$<?= number_format($service['total_commission'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
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