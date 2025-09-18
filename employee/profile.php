<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

$database = new Database();
$auth = new Auth($database);

$auth->requireRole(['employee']);

$user_info = $auth->getUserInfo();
$db = $database->getConnection();

// Get employee information
$stmt = $db->prepare("SELECT e.*, b.branch_name, b.branch_code FROM employees e JOIN branches b ON e.branch_id = b.id WHERE e.id = ?");
$stmt->execute([$user_info['id']]);
$employee_info = $stmt->fetch(PDO::FETCH_ASSOC);

// Get currency setting
$stmt = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'currency' LIMIT 1");
$currency_result = $stmt->fetch(PDO::FETCH_ASSOC);
$currency = $currency_result ? $currency_result['setting_value'] : 'USD';

$success_message = '';
$error_message = '';

// Handle profile update (only certain fields can be updated by employee)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    
    if (empty($email) && empty($phone)) {
        $error_message = 'Please provide at least email or phone number.';
    } else {
        try {
            $stmt = $db->prepare("UPDATE employees SET email = ?, phone = ? WHERE id = ?");
            $stmt->execute([$email, $phone, $user_info['id']]);
            
            $auth->logActivity('employee', $user_info['id'], 'Profile Updated', 'Updated contact information');
            $success_message = 'Profile updated successfully!';
            
            // Refresh employee info
            $stmt = $db->prepare("SELECT e.*, b.branch_name, b.branch_code FROM employees e JOIN branches b ON e.branch_id = b.id WHERE e.id = ?");
            $stmt->execute([$user_info['id']]);
            $employee_info = $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $error_message = 'Error updating profile. Please try again.';
        }
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error_message = 'Please fill in all password fields.';
    } elseif ($new_password !== $confirm_password) {
        $error_message = 'New passwords do not match.';
    } elseif (strlen($new_password) < 6) {
        $error_message = 'New password must be at least 6 characters long.';
    } else {
        // Verify current password
        if (password_verify($current_password, $employee_info['password'])) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            try {
                $stmt = $db->prepare("UPDATE employees SET password = ? WHERE id = ?");
                $stmt->execute([$hashed_password, $user_info['id']]);
                
                $auth->logActivity('employee', $user_info['id'], 'Password Changed', 'Employee changed password');
                $success_message = 'Password changed successfully!';
                
            } catch (Exception $e) {
                $error_message = 'Error changing password. Please try again.';
            }
        } else {
            $error_message = 'Current password is incorrect.';
        }
    }
}

// Get employee statistics
$stmt = $db->prepare("SELECT 
    COUNT(*) as total_receipts,
    COALESCE(SUM(total_amount), 0) as total_revenue,
    COALESCE(SUM(total_commission), 0) as total_commission
    FROM receipts WHERE employee_id = ?");
$stmt->execute([$user_info['id']]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Employee</title>
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
        .profile-avatar {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, #007bff 0%, #6f42c1 100%);
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
                    <a class="nav-link" href="/employee/dashboard.php">
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
                    <a class="nav-link active" href="/employee/profile.php">
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
                    <h1 class="h3"><i class="fas fa-user-cog me-2"></i>My Profile</h1>
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
                    <!-- Profile Information -->
                    <div class="col-lg-4 mb-4">
                        <div class="card info-card shadow-sm">
                            <div class="card-body text-center">
                                <div class="profile-avatar">
                                    <i class="fas fa-user"></i>
                                </div>
                                <h4 class="mb-1"><?= htmlspecialchars($employee_info['full_name']) ?></h4>
                                <p class="text-muted mb-2"><?= htmlspecialchars($employee_info['branch_name']) ?></p>
                                <p class="text-muted mb-3">Employee Code: <code><?= htmlspecialchars($employee_info['employee_code']) ?></code></p>
                                
                                <div class="row text-center">
                                    <div class="col-4">
                                        <div class="border-end">
                                            <h5 class="mb-0"><?= $stats['total_receipts'] ?></h5>
                                            <small class="text-muted">Receipts</small>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="border-end">
                                            <h5 class="mb-0 text-success"><?= $currency ?><?= number_format($stats['total_commission'], 0) ?></h5>
                                            <small class="text-muted">Commission</small>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <h5 class="mb-0"><?= $currency ?><?= number_format($stats['total_revenue'], 0) ?></h5>
                                        <small class="text-muted">Revenue</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Employee Details -->
                    <div class="col-lg-8 mb-4">
                        <div class="card info-card shadow-sm">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Employee Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label text-muted">Full Name</label>
                                        <div class="form-control-plaintext"><?= htmlspecialchars($employee_info['full_name']) ?></div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label text-muted">Employee Code</label>
                                        <div class="form-control-plaintext"><code><?= htmlspecialchars($employee_info['employee_code']) ?></code></div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label text-muted">Branch</label>
                                        <div class="form-control-plaintext"><?= htmlspecialchars($employee_info['branch_name']) ?> (<?= htmlspecialchars($employee_info['branch_code']) ?>)</div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label text-muted">Username</label>
                                        <div class="form-control-plaintext"><?= htmlspecialchars($employee_info['username']) ?></div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label text-muted">Hire Date</label>
                                        <div class="form-control-plaintext"><?= date('F j, Y', strtotime($employee_info['hire_date'])) ?></div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label text-muted">Status</label>
                                        <div class="form-control-plaintext">
                                            <span class="badge bg-success"><?= ucfirst($employee_info['status']) ?></span>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label text-muted">Basic Salary</label>
                                        <div class="form-control-plaintext"><?= $currency ?><?= number_format($employee_info['basic_salary'], 2) ?></div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label text-muted">Commission Rate</label>
                                        <div class="form-control-plaintext"><?= number_format($employee_info['commission_rate'], 1) ?>%</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <!-- Update Contact Info -->
                    <div class="col-lg-6 mb-4">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-edit me-2"></i>Update Contact Information</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="update_profile" value="1">
                                    <div class="mb-3">
                                        <label for="email" class="form-label">Email Address</label>
                                        <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($employee_info['email'] ?? '') ?>">
                                    </div>
                                    <div class="mb-3">
                                        <label for="phone" class="form-label">Phone Number</label>
                                        <input type="tel" class="form-control" id="phone" name="phone" value="<?= htmlspecialchars($employee_info['phone'] ?? '') ?>">
                                    </div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Update Contact Info
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Change Password -->
                    <div class="col-lg-6 mb-4">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-key me-2"></i>Change Password</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="change_password" value="1">
                                    <div class="mb-3">
                                        <label for="current_password" class="form-label">Current Password</label>
                                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="new_password" class="form-label">New Password</label>
                                        <input type="password" class="form-control" id="new_password" name="new_password" required minlength="6">
                                    </div>
                                    <div class="mb-3">
                                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="6">
                                    </div>
                                    <button type="submit" class="btn btn-warning">
                                        <i class="fas fa-key me-2"></i>Change Password
                                    </button>
                                </form>
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