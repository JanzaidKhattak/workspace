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

// Get branch statistics
$stats = [];

// Total employees in this branch
$stmt = $db->prepare("SELECT COUNT(*) as count FROM employees WHERE branch_id = ? AND status = 'active'");
$stmt->execute([$user_info['id']]);
$stats['employees'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Total receipts this month for this branch
$stmt = $db->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total FROM receipts WHERE branch_id = ? AND strftime('%Y-%m', created_at) = strftime('%Y-%m', 'now')");
$stmt->execute([$user_info['id']]);
$monthly_data = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['monthly_receipts'] = $monthly_data['count'];
$stats['monthly_revenue'] = $monthly_data['total'];

// Today's receipts
$stmt = $db->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total FROM receipts WHERE branch_id = ? AND DATE(created_at) = DATE('now')");
$stmt->execute([$user_info['id']]);
$today_data = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['today_receipts'] = $today_data['count'];
$stats['today_revenue'] = $today_data['total'];

// Recent activities for this branch
$stmt = $db->prepare("SELECT al.*, e.full_name as user_name
    FROM activity_logs al
    LEFT JOIN employees e ON al.user_type = 'employee' AND al.user_id = e.id
    WHERE (al.user_type = 'branch' AND al.user_id = ?) OR 
          (al.user_type = 'employee' AND al.user_id IN (SELECT id FROM employees WHERE branch_id = ?))
    ORDER BY al.created_at DESC LIMIT 10");
$stmt->execute([$user_info['id'], $user_info['id']]);
$recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get employees list
$stmt = $db->prepare("SELECT * FROM employees WHERE branch_id = ? AND status = 'active' ORDER BY full_name");
$stmt->execute([$user_info['id']]);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branch Dashboard - <?= htmlspecialchars($branch_info['branch_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
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
        .bg-primary-gradient {
            background: linear-gradient(135deg, #007bff 0%, #6f42c1 100%);
        }
        .bg-warning-gradient {
            background: linear-gradient(135deg, #fd7e14 0%, #ffc107 100%);
        }
        .bg-info-gradient {
            background: linear-gradient(135deg, #17a2b8 0%, #6f42c1 100%);
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
                    <a class="nav-link active" href="/branch/dashboard.php">
                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                    </a>
                    <a class="nav-link" href="/branch/employees.php">
                        <i class="fas fa-users me-2"></i>Employees
                    </a>
                    <a class="nav-link" href="/branch/receipts.php">
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
                    <h1 class="h3"><?= htmlspecialchars($branch_info['branch_name']) ?> Dashboard</h1>
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
                                    <i class="fas fa-users"></i>
                                </div>
                                <div>
                                    <h3 class="mb-0"><?= $stats['employees'] ?></h3>
                                    <p class="text-muted mb-0">Active Employees</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
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
                                <div class="stat-icon bg-warning-gradient me-3">
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
                                <div class="stat-icon bg-info-gradient me-3">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <div>
                                    <h3 class="mb-0">$<?= number_format($stats['monthly_revenue'], 2) ?></h3>
                                    <p class="text-muted mb-0">Monthly Revenue</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <!-- Employees Table -->
                    <div class="col-md-6 mb-4">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="fas fa-users me-2"></i>Employees</h5>
                                <a href="/branch/employees.php" class="btn btn-sm btn-success">
                                    <i class="fas fa-plus me-1"></i>Manage
                                </a>
                            </div>
                            <div class="card-body">
                                <?php if (empty($employees)): ?>
                                    <div class="text-center text-muted py-4">
                                        <i class="fas fa-user-plus fa-3x mb-3"></i>
                                        <p>No employees found</p>
                                        <a href="/branch/employees.php" class="btn btn-success">Add First Employee</a>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Name</th>
                                                    <th>Code</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach (array_slice($employees, 0, 5) as $employee): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($employee['full_name']) ?></td>
                                                        <td><code><?= htmlspecialchars($employee['employee_code']) ?></code></td>
                                                        <td>
                                                            <span class="badge bg-success">Active</span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                        <?php if (count($employees) > 5): ?>
                                            <div class="text-center">
                                                <small class="text-muted">Showing 5 of <?= count($employees) ?> employees</small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Activities -->
                    <div class="col-md-6 mb-4">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Activities</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recent_activities)): ?>
                                    <div class="text-center text-muted py-4">
                                        <i class="fas fa-inbox fa-3x mb-3"></i>
                                        <p>No recent activities</p>
                                    </div>
                                <?php else: ?>
                                    <div class="activity-feed">
                                        <?php foreach (array_slice($recent_activities, 0, 5) as $activity): ?>
                                            <div class="d-flex mb-3">
                                                <div class="flex-shrink-0">
                                                    <div class="avatar bg-<?= 
                                                        $activity['user_type'] === 'branch' ? 'success' : 'info' 
                                                    ?> text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 12px;">
                                                        <i class="fas fa-<?= 
                                                            $activity['user_type'] === 'branch' ? 'building' : 'user' 
                                                        ?>"></i>
                                                    </div>
                                                </div>
                                                <div class="flex-grow-1 ms-3">
                                                    <h6 class="mb-1">
                                                        <?= htmlspecialchars($activity['action']) ?>
                                                        <span class="badge bg-<?= 
                                                            $activity['user_type'] === 'branch' ? 'success' : 'info' 
                                                        ?> ms-2">
                                                            <?= ucfirst($activity['user_type']) ?>
                                                        </span>
                                                    </h6>
                                                    <p class="mb-1 small text-muted"><?= htmlspecialchars($activity['description']) ?></p>
                                                    <small class="text-muted">
                                                        <?= date('M j, g:i A', strtotime($activity['created_at'])) ?>
                                                    </small>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
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