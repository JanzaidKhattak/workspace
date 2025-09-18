<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

$database = new Database();
$auth = new Auth($database);

$auth->requireRole(['admin']);

$user_info = $auth->getUserInfo();
$db = $database->getConnection();

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_company_settings':
                $company_name = trim($_POST['company_name'] ?? '');
                $company_address = trim($_POST['company_address'] ?? '');
                $company_phone = trim($_POST['company_phone'] ?? '');
                $receipt_header = trim($_POST['receipt_header'] ?? '');
                $receipt_footer = trim($_POST['receipt_footer'] ?? '');
                $currency = trim($_POST['currency'] ?? 'USD');
                $logo_url = trim($_POST['logo_url'] ?? '');
                
                // Validate currency - only allow specific currencies
                $allowed_currencies = ['USD', 'AED', 'PKR', 'INR', 'EUR'];
                if (!in_array($currency, $allowed_currencies)) {
                    $error = 'Invalid currency selection. Please choose from USD, AED, PKR, INR, or EUR.';
                    break;
                }
                
                // Validate logo URL for security (prevent XSS)
                if (!empty($logo_url) && !filter_var($logo_url, FILTER_VALIDATE_URL)) {
                    $error = 'Invalid logo URL format.';
                    break;
                }
                
                try {
                    $settings = [
                        'company_name' => $company_name,
                        'company_address' => $company_address,
                        'company_phone' => $company_phone,
                        'receipt_header' => $receipt_header,
                        'receipt_footer' => $receipt_footer,
                        'currency' => $currency,
                        'logo_url' => $logo_url
                    ];
                    
                    foreach ($settings as $key => $value) {
                        $stmt = $db->prepare("INSERT OR REPLACE INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)");
                        $stmt->execute([$key, $value]);
                    }
                    
                    $auth->logActivity('admin', $user_info['id'], 'Update Settings', 'Updated company settings');
                    $message = 'Company settings updated successfully!';
                } catch (PDOException $e) {
                    $error = 'Error updating settings. Please try again.';
                }
                break;
                
            case 'change_password':
                $current_password = $_POST['current_password'] ?? '';
                $new_password = $_POST['new_password'] ?? '';
                $confirm_password = $_POST['confirm_password'] ?? '';
                
                if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                    $error = 'Please fill in all password fields.';
                } elseif ($new_password !== $confirm_password) {
                    $error = 'New passwords do not match.';
                } elseif (strlen($new_password) < 6) {
                    $error = 'New password must be at least 6 characters long.';
                } else {
                    // Verify current password
                    $stmt = $db->prepare("SELECT password FROM admin WHERE id = ?");
                    $stmt->execute([$user_info['id']]);
                    $admin_data = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (password_verify($current_password, $admin_data['password'])) {
                        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("UPDATE admin SET password = ? WHERE id = ?");
                        $stmt->execute([$hashed_password, $user_info['id']]);
                        
                        $auth->logActivity('admin', $user_info['id'], 'Change Password', 'Admin password changed');
                        $message = 'Password changed successfully!';
                    } else {
                        $error = 'Current password is incorrect.';
                    }
                }
                break;
        }
    }
}

// Get current settings
$stmt = $db->query("SELECT setting_key, setting_value FROM settings");
$settings_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Get system statistics
$stats = [];

// Total entities
$stmt = $db->query("SELECT 
    (SELECT COUNT(*) FROM branches) as total_branches,
    (SELECT COUNT(*) FROM employees) as total_employees,
    (SELECT COUNT(*) FROM services) as total_services,
    (SELECT COUNT(*) FROM receipts) as total_receipts");
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Database size (approximate)
$db_size = filesize('../data/typing_center.db');
$db_size_mb = round($db_size / (1024 * 1024), 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Admin Panel</title>
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
        .settings-card {
            border-radius: 15px;
            border: none;
            margin-bottom: 20px;
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
                    <a class="nav-link" href="/admin/reports.php">
                        <i class="fas fa-chart-bar me-2"></i>Reports
                    </a>
                    <a class="nav-link active" href="/admin/settings.php">
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
                    <h1 class="h3"><i class="fas fa-cog me-2"></i>System Settings</h1>
                </div>
                
                <!-- Messages -->
                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <div class="row">
                    <!-- Company Settings -->
                    <div class="col-md-8">
                        <div class="card settings-card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-building me-2"></i>Company Information</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="action" value="update_company_settings">
                                    
                                    <div class="mb-3">
                                        <label for="company_name" class="form-label">Company Name</label>
                                        <input type="text" class="form-control" id="company_name" name="company_name" 
                                               value="<?= htmlspecialchars($settings_data['company_name'] ?? '') ?>">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="company_address" class="form-label">Company Address</label>
                                        <textarea class="form-control" id="company_address" name="company_address" rows="3"><?= htmlspecialchars($settings_data['company_address'] ?? '') ?></textarea>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="company_phone" class="form-label">Company Phone</label>
                                        <input type="text" class="form-control" id="company_phone" name="company_phone" 
                                               value="<?= htmlspecialchars($settings_data['company_phone'] ?? '') ?>">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="receipt_header" class="form-label">Receipt Header Text</label>
                                        <input type="text" class="form-control" id="receipt_header" name="receipt_header" 
                                               value="<?= htmlspecialchars($settings_data['receipt_header'] ?? '') ?>">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="receipt_footer" class="form-label">Receipt Footer Text</label>
                                        <input type="text" class="form-control" id="receipt_footer" name="receipt_footer" 
                                               value="<?= htmlspecialchars($settings_data['receipt_footer'] ?? '') ?>">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="currency" class="form-label">System Currency</label>
                                        <select class="form-select" id="currency" name="currency">
                                            <option value="USD" <?= ($settings_data['currency'] ?? 'USD') === 'USD' ? 'selected' : '' ?>>USD ($) - US Dollar</option>
                                            <option value="AED" <?= ($settings_data['currency'] ?? '') === 'AED' ? 'selected' : '' ?>>AED (د.إ) - UAE Dirham</option>
                                            <option value="PKR" <?= ($settings_data['currency'] ?? '') === 'PKR' ? 'selected' : '' ?>>PKR (₨) - Pakistani Rupee</option>
                                            <option value="INR" <?= ($settings_data['currency'] ?? '') === 'INR' ? 'selected' : '' ?>>INR (₹) - Indian Rupee</option>
                                            <option value="EUR" <?= ($settings_data['currency'] ?? '') === 'EUR' ? 'selected' : '' ?>>EUR (€) - Euro</option>
                                        </select>
                                        <div class="form-text">Currency will be displayed throughout the system</div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="logo_url" class="form-label">Company Logo URL</label>
                                        <input type="url" class="form-control" id="logo_url" name="logo_url" 
                                               value="<?= htmlspecialchars($settings_data['logo_url'] ?? '') ?>"
                                               placeholder="https://example.com/logo.png">
                                        <div class="form-text">Logo will appear on receipts and system header</div>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Save Settings
                                    </button>
                                </form>
                            </div>
                        </div>
                        
                        <!-- Security Settings -->
                        <div class="card settings-card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-lock me-2"></i>Security Settings</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="action" value="change_password">
                                    
                                    <div class="mb-3">
                                        <label for="current_password" class="form-label">Current Password</label>
                                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="new_password" class="form-label">New Password</label>
                                        <input type="password" class="form-control" id="new_password" name="new_password" required>
                                        <div class="form-text">Password must be at least 6 characters long</div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-warning">
                                        <i class="fas fa-key me-2"></i>Change Password
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- System Information -->
                    <div class="col-md-4">
                        <div class="card settings-card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>System Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="row text-center mb-3">
                                    <div class="col-6">
                                        <h4 class="text-primary"><?= $stats['total_branches'] ?></h4>
                                        <small class="text-muted">Branches</small>
                                    </div>
                                    <div class="col-6">
                                        <h4 class="text-success"><?= $stats['total_employees'] ?></h4>
                                        <small class="text-muted">Employees</small>
                                    </div>
                                </div>
                                
                                <div class="row text-center mb-3">
                                    <div class="col-6">
                                        <h4 class="text-warning"><?= $stats['total_services'] ?></h4>
                                        <small class="text-muted">Services</small>
                                    </div>
                                    <div class="col-6">
                                        <h4 class="text-info"><?= $stats['total_receipts'] ?></h4>
                                        <small class="text-muted">Receipts</small>
                                    </div>
                                </div>
                                
                                <hr>
                                
                                <div class="mb-2">
                                    <strong>Database Size:</strong> <?= $db_size_mb ?> MB
                                </div>
                                
                                <div class="mb-2">
                                    <strong>PHP Version:</strong> <?= phpversion() ?>
                                </div>
                                
                                <div class="mb-2">
                                    <strong>Server Time:</strong> <?= date('Y-m-d H:i:s') ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Quick Actions -->
                        <div class="card settings-card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-tools me-2"></i>Quick Actions</h5>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <a href="/admin/reports.php" class="btn btn-outline-primary">
                                        <i class="fas fa-chart-bar me-2"></i>View Reports
                                    </a>
                                    <a href="/admin/branches.php" class="btn btn-outline-success">
                                        <i class="fas fa-building me-2"></i>Manage Branches
                                    </a>
                                    <a href="/admin/employees.php" class="btn btn-outline-info">
                                        <i class="fas fa-users me-2"></i>Manage Employees
                                    </a>
                                    <a href="/admin/services.php" class="btn btn-outline-warning">
                                        <i class="fas fa-cogs me-2"></i>Manage Services
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            
            if (newPassword !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>
</html>