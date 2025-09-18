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

$success_message = '';
$error_message = '';

// Handle branch info update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_branch'])) {
    $address = trim($_POST['address'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    
    try {
        $stmt = $db->prepare("UPDATE branches SET address = ?, phone = ?, email = ? WHERE id = ?");
        $stmt->execute([$address, $phone, $email, $user_info['id']]);
        
        $auth->logActivity('branch', $user_info['id'], 'Branch Info Updated', 'Updated branch contact information');
        $success_message = 'Branch information updated successfully!';
        
        // Refresh branch info
        $stmt = $db->prepare("SELECT * FROM branches WHERE id = ?");
        $stmt->execute([$user_info['id']]);
        $branch_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $error_message = 'Error updating branch information. Please try again.';
    }
}

// Password change functionality removed - Only admins can reset branch manager passwords

// Get branch statistics
$stmt = $db->prepare("SELECT 
    COUNT(DISTINCT e.id) as total_employees,
    COUNT(DISTINCT r.id) as total_receipts,
    COALESCE(SUM(r.total_amount), 0) as total_revenue,
    COALESCE(SUM(r.total_commission), 0) as total_commission
    FROM branches b
    LEFT JOIN employees e ON b.id = e.branch_id AND e.status = 'active'
    LEFT JOIN receipts r ON b.id = r.branch_id
    WHERE b.id = ?");
$stmt->execute([$user_info['id']]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get recent activities
$stmt = $db->prepare("SELECT al.*, e.full_name as user_name
    FROM activity_logs al
    LEFT JOIN employees e ON al.user_type = 'employee' AND al.user_id = e.id
    WHERE (al.user_type = 'branch' AND al.user_id = ?) OR 
          (al.user_type = 'employee' AND al.user_id IN (SELECT id FROM employees WHERE branch_id = ?))
    ORDER BY al.created_at DESC LIMIT 5");
$stmt->execute([$user_info['id'], $user_info['id']]);
$recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branch Profile - Branch Manager</title>
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
        .profile-avatar {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: white;
            margin: 0 auto 20px;
        }
        .info-card {
            border-radius: 15px;
            border: none;
            transition: all 0.3s;
        }
        .info-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
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
                    <a class="nav-link" href="/branch/receipts.php">
                        <i class="fas fa-receipt me-2"></i>Receipts
                    </a>
                    <a class="nav-link" href="/branch/reports.php">
                        <i class="fas fa-chart-bar me-2"></i>Reports
                    </a>
                    <a class="nav-link active" href="/branch/profile.php">
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
                    <h1 class="h3"><i class="fas fa-building me-2"></i>Branch Profile</h1>
                    <div class="text-muted">
                        <i class="fas fa-calendar me-2"></i><?= date('F j, Y') ?>
                    </div>
                </div>
                
                <?php if ($success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error_message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <div class="row">
                    <!-- Branch Overview -->
                    <div class="col-lg-4 mb-4">
                        <div class="card info-card shadow-sm">
                            <div class="card-body text-center">
                                <div class="profile-avatar">
                                    <i class="fas fa-building"></i>
                                </div>
                                <h4 class="mb-1"><?= htmlspecialchars($branch_info['branch_name']) ?></h4>
                                <p class="text-muted mb-2">Branch Code: <code><?= htmlspecialchars($branch_info['branch_code']) ?></code></p>
                                <p class="text-muted mb-3">
                                    <span class="badge bg-<?= $branch_info['status'] === 'active' ? 'success' : 'danger' ?>">
                                        <?= ucfirst($branch_info['status']) ?>
                                    </span>
                                </p>
                                
                                <div class="row text-center">
                                    <div class="col-6">
                                        <div class="border-end">
                                            <h5 class="mb-0 text-success"><?= $stats['total_employees'] ?></h5>
                                            <small class="text-muted">Employees</small>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <h5 class="mb-0 text-primary"><?= $stats['total_receipts'] ?></h5>
                                        <small class="text-muted">Receipts</small>
                                    </div>
                                </div>
                                
                                <hr>
                                
                                <div class="row text-center">
                                    <div class="col-6">
                                        <div class="border-end">
                                            <h5 class="mb-0"><?= $currency ?><?= number_format($stats['total_revenue'], 0) ?></h5>
                                            <small class="text-muted">Revenue</small>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <h5 class="mb-0 text-info"><?= $currency ?><?= number_format($stats['total_commission'], 0) ?></h5>
                                        <small class="text-muted">Commissions</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Branch Details -->
                    <div class="col-lg-8 mb-4">
                        <div class="card info-card shadow-sm">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Branch Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label text-muted">Branch Name</label>
                                        <div class="form-control-plaintext"><?= htmlspecialchars($branch_info['branch_name']) ?></div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label text-muted">Branch Code</label>
                                        <div class="form-control-plaintext"><code><?= htmlspecialchars($branch_info['branch_code']) ?></code></div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label text-muted">Manager Name</label>
                                        <div class="form-control-plaintext"><?= htmlspecialchars($branch_info['manager_full_name']) ?></div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label text-muted">Manager Username</label>
                                        <div class="form-control-plaintext"><?= htmlspecialchars($branch_info['manager_username']) ?></div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label text-muted">Status</label>
                                        <div class="form-control-plaintext">
                                            <span class="badge bg-<?= $branch_info['status'] === 'active' ? 'success' : 'danger' ?>">
                                                <?= ucfirst($branch_info['status']) ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label text-muted">Created</label>
                                        <div class="form-control-plaintext"><?= date('F j, Y', strtotime($branch_info['created_at'])) ?></div>
                                    </div>
                                    <div class="col-12 mb-3">
                                        <label class="form-label text-muted">Current Address</label>
                                        <div class="form-control-plaintext"><?= htmlspecialchars($branch_info['address'] ?: 'Not specified') ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <!-- Update Branch Info -->
                    <div class="col-lg-6 mb-4">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-edit me-2"></i>Update Branch Information</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="update_branch" value="1">
                                    <div class="mb-3">
                                        <label for="address" class="form-label">Branch Address</label>
                                        <textarea class="form-control" id="address" name="address" rows="3"><?= htmlspecialchars($branch_info['address'] ?? '') ?></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label for="phone" class="form-label">Branch Phone</label>
                                        <input type="tel" class="form-control" id="phone" name="phone" value="<?= htmlspecialchars($branch_info['phone'] ?? '') ?>">
                                    </div>
                                    <div class="mb-3">
                                        <label for="email" class="form-label">Branch Email</label>
                                        <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($branch_info['email'] ?? '') ?>">
                                    </div>
                                    <button type="submit" class="btn btn-success">
                                        <i class="fas fa-save me-2"></i>Update Branch Info
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Password updates removed - Only admins can reset branch manager passwords -->
                    <div class="col-lg-6 mb-4">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Password Management</h5>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-info">
                                    <i class="fas fa-lock me-2"></i>
                                    <strong>Password Security:</strong> For security reasons, only administrators can reset branch manager passwords. 
                                    Contact your administrator if you need to change your password.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Activities -->
                <?php if (!empty($recent_activities)): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="card shadow-sm">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Branch Activities</h5>
                                </div>
                                <div class="card-body">
                                    <div class="activity-feed">
                                        <?php foreach ($recent_activities as $activity): ?>
                                            <div class="d-flex mb-3">
                                                <div class="flex-shrink-0">
                                                    <div class="avatar bg-<?= 
                                                        $activity['user_type'] === 'branch' ? 'success' : 'info' 
                                                    ?> text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 36px; height: 36px; font-size: 14px;">
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