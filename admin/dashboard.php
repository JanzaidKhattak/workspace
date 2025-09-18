<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/currency_helper.php';

$database = new Database();
$auth = new Auth($database);

$auth->requireRole(['admin']);

$user_info = $auth->getUserInfo();
$db = $database->getConnection();

// Get statistics
$stats = [];

// Total branches
$stmt = $db->query("SELECT COUNT(*) as count FROM branches WHERE status = 'active'");
$stats['branches'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Total employees
$stmt = $db->query("SELECT COUNT(*) as count FROM employees WHERE status = 'active'");
$stats['employees'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Total receipts this month
$stmt = $db->query("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total FROM receipts WHERE strftime('%Y-%m', created_at) = strftime('%Y-%m', 'now')");
$monthly_data = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['monthly_receipts'] = $monthly_data['count'];
$stats['monthly_revenue'] = $monthly_data['total'];

// Get current currency
$current_currency = getCurrentCurrency($db);

// Recent activities
$stmt = $db->prepare("SELECT al.*, 
    CASE 
        WHEN al.user_type = 'admin' THEN a.full_name
        WHEN al.user_type = 'branch' THEN b.manager_full_name
        WHEN al.user_type = 'employee' THEN e.full_name
    END as user_name
    FROM activity_logs al
    LEFT JOIN admin a ON al.user_type = 'admin' AND al.user_id = a.id
    LEFT JOIN branches b ON al.user_type = 'branch' AND al.user_id = b.id
    LEFT JOIN employees e ON al.user_type = 'employee' AND al.user_id = e.id
    ORDER BY al.created_at DESC LIMIT 10");
$stmt->execute();
$recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Typing Center Management</title>
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .bg-success-gradient {
            background: linear-gradient(135deg, #56ab2f 0%, #a8e6cf 100%);
        }
        .bg-warning-gradient {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        .bg-info-gradient {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
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
                    <div>
                        <h6 class="mb-0"><?= htmlspecialchars($user_info['full_name']) ?></h6>
                        <small class="opacity-75">Administrator</small>
                    </div>
                </div>
                
                <nav class="nav flex-column">
                    <a class="nav-link active" href="/admin/dashboard.php">
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
                    <a class="nav-link" href="/admin/reports.php">
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
                    <h1 class="h3">Dashboard Overview</h1>
                    <div class="text-muted">
                        <i class="fas fa-calendar me-2"></i><?= date('F j, Y') ?>
                    </div>
                </div>
                
                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card shadow-sm">
                            <div class="card-body d-flex align-items-center">
                                <div class="stat-icon bg-primary-gradient me-3">
                                    <i class="fas fa-building"></i>
                                </div>
                                <div>
                                    <h3 class="mb-0"><?= $stats['branches'] ?></h3>
                                    <p class="text-muted mb-0">Active Branches</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card shadow-sm">
                            <div class="card-body d-flex align-items-center">
                                <div class="stat-icon bg-success-gradient me-3">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div>
                                    <h3 class="mb-0"><?= $stats['employees'] ?></h3>
                                    <p class="text-muted mb-0">Total Employees</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card shadow-sm">
                            <div class="card-body d-flex align-items-center">
                                <div class="stat-icon bg-warning-gradient me-3">
                                    <i class="fas fa-receipt"></i>
                                </div>
                                <div>
                                    <h3 class="mb-0"><?= $stats['monthly_receipts'] ?></h3>
                                    <p class="text-muted mb-0">This Month's Receipts</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card shadow-sm">
                            <div class="card-body d-flex align-items-center">
                                <div class="stat-icon bg-info-gradient me-3">
                                    <i class="fas fa-dollar-sign"></i>
                                </div>
                                <div>
                                    <h3 class="mb-0"><?= formatCurrency($stats['monthly_revenue'], $current_currency) ?></h3>
                                    <p class="text-muted mb-0">Monthly Revenue</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Activities -->
                <div class="row">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Activities</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recent_activities)): ?>
                                    <div class="text-center text-muted py-4">
                                        <i class="fas fa-inbox fa-3x mb-3"></i>
                                        <p>No recent activities found</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>User</th>
                                                    <th>Action</th>
                                                    <th>Description</th>
                                                    <th>Time</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recent_activities as $activity): ?>
                                                    <tr>
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <span class="badge bg-<?= 
                                                                    $activity['user_type'] === 'admin' ? 'primary' : 
                                                                    ($activity['user_type'] === 'branch' ? 'success' : 'info') 
                                                                ?> me-2">
                                                                    <?= ucfirst($activity['user_type']) ?>
                                                                </span>
                                                                <?= htmlspecialchars($activity['user_name'] ?? 'Unknown') ?>
                                                            </div>
                                                        </td>
                                                        <td><?= htmlspecialchars($activity['action']) ?></td>
                                                        <td><?= htmlspecialchars($activity['description']) ?></td>
                                                        <td>
                                                            <small class="text-muted">
                                                                <?= date('M j, Y g:i A', strtotime($activity['created_at'])) ?>
                                                            </small>
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
    <script>
        // Currency change listener - reload page if currency was changed
        if (localStorage) {
            let lastCurrencyChange = localStorage.getItem('currency_changed');
            let pageLoadTime = Date.now();
            
            setInterval(function() {
                let currentCurrencyChange = localStorage.getItem('currency_changed');
                if (currentCurrencyChange && currentCurrencyChange != lastCurrencyChange && currentCurrencyChange > pageLoadTime) {
                    location.reload();
                }
            }, 2000); // Check every 2 seconds
        }
    </script>
</body>
</html>