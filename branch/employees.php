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

// Handle add employee
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_employee'])) {
    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $basic_salary = floatval($_POST['basic_salary'] ?? 0);
    $commission_rate = floatval($_POST['commission_rate'] ?? 0);
    $hire_date = $_POST['hire_date'] ?? '';
    
    if (empty($full_name) || empty($username) || empty($password) || empty($hire_date)) {
        $error_message = 'Please fill in all required fields.';
    } else {
        try {
            // Check if username exists
            $stmt = $db->prepare("SELECT id FROM employees WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error_message = 'Username already exists. Please choose a different username.';
            } else {
                // Generate employee code
                $employee_code = 'EMP' . $user_info['id'] . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
                
                // Check if employee code exists
                $stmt = $db->prepare("SELECT id FROM employees WHERE employee_code = ?");
                $stmt->execute([$employee_code]);
                while ($stmt->fetch()) {
                    $employee_code = 'EMP' . $user_info['id'] . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
                    $stmt->execute([$employee_code]);
                }
                
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $db->prepare("INSERT INTO employees (branch_id, employee_code, username, password, full_name, email, phone, basic_salary, commission_rate, hire_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $user_info['id'],
                    $employee_code,
                    $username,
                    $hashed_password,
                    $full_name,
                    $email,
                    $phone,
                    $basic_salary,
                    $commission_rate,
                    $hire_date
                ]);
                
                $auth->logActivity('branch', $user_info['id'], 'Employee Added', "Added employee: $full_name ($employee_code)");
                $success_message = "Employee $full_name added successfully with code: $employee_code";
            }
        } catch (Exception $e) {
            $error_message = 'Error adding employee. Please try again.';
        }
    }
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $employee_id = intval($_POST['employee_id']);
    $status = $_POST['status'];
    
    if (in_array($status, ['active', 'inactive'])) {
        try {
            $stmt = $db->prepare("UPDATE employees SET status = ? WHERE id = ? AND branch_id = ?");
            $stmt->execute([$status, $employee_id, $user_info['id']]);
            
            $stmt = $db->prepare("SELECT full_name FROM employees WHERE id = ?");
            $stmt->execute([$employee_id]);
            $emp_name = $stmt->fetchColumn();
            
            $auth->logActivity('branch', $user_info['id'], 'Employee Status Updated', "Changed status of $emp_name to $status");
            $success_message = "Employee status updated successfully.";
        } catch (Exception $e) {
            $error_message = 'Error updating employee status.';
        }
    }
}

// Get employees
$stmt = $db->prepare("SELECT e.*, 
    COUNT(r.id) as total_receipts,
    COALESCE(SUM(r.total_amount), 0) as total_revenue,
    COALESCE(SUM(r.total_commission), 0) as total_commission
    FROM employees e
    LEFT JOIN receipts r ON e.id = r.employee_id
    WHERE e.branch_id = ? 
    GROUP BY e.id
    ORDER BY e.created_at DESC");
$stmt->execute([$user_info['id']]);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Employees - Branch</title>
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
                    <a class="nav-link active" href="/branch/employees.php">
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
                    <h1 class="h3"><i class="fas fa-users me-2"></i>Manage Employees</h1>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addEmployeeModal">
                        <i class="fas fa-plus me-2"></i>Add Employee
                    </button>
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
                
                <?php if (empty($employees)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-users fa-5x text-muted mb-4"></i>
                        <h3 class="text-muted">No employees found</h3>
                        <p class="text-muted">Add your first employee to get started!</p>
                        <button class="btn btn-success btn-lg" data-bs-toggle="modal" data-bs-target="#addEmployeeModal">
                            <i class="fas fa-plus me-2"></i>Add First Employee
                        </button>
                    </div>
                <?php else: ?>
                    <div class="card shadow-sm">
                        <div class="card-header bg-white">
                            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Employee List</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Employee</th>
                                            <th>Code</th>
                                            <th>Contact</th>
                                            <th>Performance</th>
                                            <th>Salary Info</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($employees as $employee): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="avatar bg-success text-white rounded-circle p-2 me-3" style="width: 40px; height: 40px; font-size: 14px;">
                                                            <?= strtoupper(substr($employee['full_name'], 0, 2)) ?>
                                                        </div>
                                                        <div>
                                                            <strong><?= htmlspecialchars($employee['full_name']) ?></strong>
                                                            <div class="small text-muted">@<?= htmlspecialchars($employee['username']) ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><code><?= htmlspecialchars($employee['employee_code']) ?></code></td>
                                                <td>
                                                    <?php if ($employee['email']): ?>
                                                        <div class="small"><i class="fas fa-envelope me-1"></i><?= htmlspecialchars($employee['email']) ?></div>
                                                    <?php endif; ?>
                                                    <?php if ($employee['phone']): ?>
                                                        <div class="small"><i class="fas fa-phone me-1"></i><?= htmlspecialchars($employee['phone']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="small">
                                                        <strong><?= $employee['total_receipts'] ?></strong> receipts<br>
                                                        <span class="text-success"><?= $currency ?><?= number_format($employee['total_commission'], 0) ?></span> earned
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="small">
                                                        Base: <?= $currency ?><?= number_format($employee['basic_salary'], 0) ?><br>
                                                        Rate: <?= number_format($employee['commission_rate'], 1) ?>%
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?= $employee['status'] === 'active' ? 'success' : 'danger' ?>">
                                                        <?= ucfirst($employee['status']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="btn-group" role="group">
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="update_status" value="1">
                                                            <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                                            <input type="hidden" name="status" value="<?= $employee['status'] === 'active' ? 'inactive' : 'active' ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-<?= $employee['status'] === 'active' ? 'danger' : 'success' ?>" 
                                                                onclick="return confirm('Are you sure you want to change this employee\'s status?')">
                                                                <i class="fas fa-<?= $employee['status'] === 'active' ? 'ban' : 'check' ?>"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
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
    
    <!-- Add Employee Modal -->
    <div class="modal fade" id="addEmployeeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add New Employee</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="add_employee" value="1">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="full_name" class="form-label">Full Name *</label>
                                <input type="text" class="form-control" id="full_name" name="full_name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="username" class="form-label">Username *</label>
                                <input type="text" class="form-control" id="username" name="username" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="password" class="form-label">Password *</label>
                                <input type="password" class="form-control" id="password" name="password" required minlength="6">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="hire_date" class="form-label">Hire Date *</label>
                                <input type="date" class="form-control" id="hire_date" name="hire_date" required max="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="phone" class="form-label">Phone</label>
                                <input type="tel" class="form-control" id="phone" name="phone">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="basic_salary" class="form-label">Basic Salary</label>
                                <div class="input-group">
                                    <span class="input-group-text"><?= $currency ?></span>
                                    <input type="number" class="form-control" id="basic_salary" name="basic_salary" min="0" step="0.01" value="0">
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="commission_rate" class="form-label">Commission Rate</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="commission_rate" name="commission_rate" min="0" max="100" step="0.1" value="0">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save me-2"></i>Add Employee
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>