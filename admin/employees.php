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
            case 'add_employee':
                $branch_id = $_POST['branch_id'] ?? '';
                $employee_code = trim($_POST['employee_code'] ?? '');
                $username = trim($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';
                $full_name = trim($_POST['full_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $basic_salary = floatval($_POST['basic_salary'] ?? 0);
                $commission_rate = floatval($_POST['commission_rate'] ?? 0);
                $hire_date = $_POST['hire_date'] ?? '';
                
                if (empty($branch_id) || empty($employee_code) || empty($username) || empty($password) || empty($full_name) || empty($hire_date)) {
                    $error = 'Please fill in all required fields.';
                } else {
                    try {
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("INSERT INTO employees (branch_id, employee_code, username, password, full_name, email, phone, basic_salary, commission_rate, hire_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$branch_id, $employee_code, $username, $hashed_password, $full_name, $email, $phone, $basic_salary, $commission_rate, $hire_date]);
                        
                        $auth->logActivity('admin', $user_info['id'], 'Create Employee', "Created new employee: $full_name");
                        $message = 'Employee created successfully!';
                    } catch (PDOException $e) {
                        if (strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
                            $error = 'Employee code or username already exists.';
                        } else {
                            $error = 'Error creating employee. Please try again.';
                        }
                    }
                }
                break;

            case 'edit_employee':
                $employee_id = $_POST['employee_id'] ?? '';
                $branch_id = $_POST['branch_id'] ?? '';
                $employee_code = trim($_POST['employee_code'] ?? '');
                $username = trim($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';
                $full_name = trim($_POST['full_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $basic_salary = floatval($_POST['basic_salary'] ?? 0);
                $commission_rate = floatval($_POST['commission_rate'] ?? 0);
                $hire_date = $_POST['hire_date'] ?? '';
                
                if (empty($employee_id) || empty($branch_id) || empty($employee_code) || empty($username) || empty($full_name) || empty($hire_date)) {
                    $error = 'Please fill in all required fields.';
                } else {
                    try {
                        // Check if password should be updated
                        if (!empty($password)) {
                            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                            $stmt = $db->prepare("UPDATE employees SET branch_id = ?, employee_code = ?, username = ?, password = ?, full_name = ?, email = ?, phone = ?, basic_salary = ?, commission_rate = ?, hire_date = ? WHERE id = ?");
                            $stmt->execute([$branch_id, $employee_code, $username, $hashed_password, $full_name, $email, $phone, $basic_salary, $commission_rate, $hire_date, $employee_id]);
                        } else {
                            // Update without changing password
                            $stmt = $db->prepare("UPDATE employees SET branch_id = ?, employee_code = ?, username = ?, full_name = ?, email = ?, phone = ?, basic_salary = ?, commission_rate = ?, hire_date = ? WHERE id = ?");
                            $stmt->execute([$branch_id, $employee_code, $username, $full_name, $email, $phone, $basic_salary, $commission_rate, $hire_date, $employee_id]);
                        }
                        
                        $auth->logActivity('admin', $user_info['id'], 'Update Employee', "Updated employee: $full_name");
                        $message = 'Employee updated successfully!';
                    } catch (PDOException $e) {
                        if (strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
                            $error = 'Employee code or username already exists.';
                        } else {
                            $error = 'Error updating employee. Please try again.';
                        }
                    }
                }
                break;
                
            case 'update_status':
                $employee_id = $_POST['employee_id'] ?? '';
                $status = $_POST['status'] ?? '';
                
                if (!empty($employee_id) && in_array($status, ['active', 'inactive'])) {
                    try {
                        $stmt = $db->prepare("UPDATE employees SET status = ? WHERE id = ?");
                        $stmt->execute([$status, $employee_id]);
                        
                        $auth->logActivity('admin', $user_info['id'], 'Update Employee Status', "Changed employee status to: $status");
                        $message = 'Employee status updated successfully!';
                    } catch (PDOException $e) {
                        $error = 'Error updating employee status.';
                    }
                }
                break;
        }
    }
}

// Get all employees with branch information
$stmt = $db->query("SELECT e.*, b.branch_name,
    (SELECT COUNT(*) FROM receipts WHERE employee_id = e.id AND strftime('%Y-%m', created_at) = strftime('%Y-%m', 'now')) as monthly_receipts,
    (SELECT COALESCE(SUM(total_commission), 0) FROM receipts WHERE employee_id = e.id AND strftime('%Y-%m', created_at) = strftime('%Y-%m', 'now')) as monthly_commission
    FROM employees e 
    LEFT JOIN branches b ON e.branch_id = b.id 
    ORDER BY e.created_at DESC");
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get active branches for the form
$stmt = $db->query("SELECT * FROM branches WHERE status = 'active' ORDER BY branch_name");
$branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Management - Admin Panel</title>
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
        .employee-card {
            border-radius: 15px;
            border: none;
            transition: transform 0.2s;
            margin-bottom: 20px;
        }
        .employee-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
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
                    <a class="nav-link active" href="/admin/employees.php">
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
                    <h1 class="h3"><i class="fas fa-users me-2"></i>Employee Management</h1>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addEmployeeModal">
                        <i class="fas fa-plus me-2"></i>Add New Employee
                    </button>
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
                
                <!-- Employees List -->
                <?php if (empty($employees)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-users fa-5x text-muted mb-4"></i>
                        <h3 class="text-muted">No Employees Found</h3>
                        <p class="text-muted">Start by adding your first employee to the system.</p>
                        <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#addEmployeeModal">
                            <i class="fas fa-plus me-2"></i>Add First Employee
                        </button>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Employee</th>
                                    <th>Branch</th>
                                    <th>Contact</th>
                                    <th>Salary & Commission</th>
                                    <th>This Month</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($employees as $employee): ?>
                                    <tr>
                                        <td>
                                            <div>
                                                <strong><?= htmlspecialchars($employee['full_name']) ?></strong><br>
                                                <small class="text-muted">
                                                    Code: <?= htmlspecialchars($employee['employee_code']) ?><br>
                                                    Username: <?= htmlspecialchars($employee['username']) ?>
                                                </small>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary"><?= htmlspecialchars($employee['branch_name'] ?? 'No Branch') ?></span>
                                        </td>
                                        <td>
                                            <?php if (!empty($employee['email'])): ?>
                                                <small><i class="fas fa-envelope me-1"></i><?= htmlspecialchars($employee['email']) ?></small><br>
                                            <?php endif; ?>
                                            <?php if (!empty($employee['phone'])): ?>
                                                <small><i class="fas fa-phone me-1"></i><?= htmlspecialchars($employee['phone']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div>
                                                <strong>$<?= number_format($employee['basic_salary'], 2) ?></strong> <small class="text-muted">salary</small><br>
                                                <strong><?= number_format($employee['commission_rate'], 2) ?>%</strong> <small class="text-muted">commission</small>
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <strong><?= $employee['monthly_receipts'] ?></strong> <small class="text-muted">receipts</small><br>
                                                <strong>$<?= number_format($employee['monthly_commission'], 2) ?></strong> <small class="text-muted">commission</small>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $employee['status'] === 'active' ? 'success' : 'secondary' ?>">
                                                <?= ucfirst($employee['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <!-- Edit Button -->
                                            <button type="button" class="btn btn-sm btn-info me-1" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editEmployeeModal"
                                                    onclick="populateEditModal(<?= htmlspecialchars(json_encode($employee)) ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            
                                            <!-- Status Toggle Button -->
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                                <input type="hidden" name="status" value="<?= $employee['status'] === 'active' ? 'inactive' : 'active' ?>">
                                                <button type="submit" class="btn btn-sm btn-<?= $employee['status'] === 'active' ? 'warning' : 'success' ?>" 
                                                        onclick="return confirm('Are you sure you want to <?= $employee['status'] === 'active' ? 'deactivate' : 'activate' ?> this employee?')">
                                                    <i class="fas fa-<?= $employee['status'] === 'active' ? 'pause' : 'play' ?>"></i>
                                                </button>
                                            </form>
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
    
    <!-- Add Employee Modal -->
    <div class="modal fade" id="addEmployeeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add New Employee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_employee">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="branch_id" class="form-label">Branch <span class="text-danger">*</span></label>
                                <select class="form-control" id="branch_id" name="branch_id" required>
                                    <option value="">Select Branch</option>
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?= $branch['id'] ?>"><?= htmlspecialchars($branch['branch_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="employee_code" class="form-label">Employee Code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="employee_code" name="employee_code" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="full_name" name="full_name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="hire_date" class="form-label">Hire Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="hire_date" name="hire_date" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="username" name="username" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="phone" class="form-label">Phone</label>
                                <input type="text" class="form-control" id="phone" name="phone">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="basic_salary" class="form-label">Basic Salary</label>
                                <input type="number" class="form-control" id="basic_salary" name="basic_salary" step="0.01" min="0" value="0">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="commission_rate" class="form-label">Commission Rate (%)</label>
                                <input type="number" class="form-control" id="commission_rate" name="commission_rate" step="0.01" min="0" max="100" value="0">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Create Employee
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Employee Modal -->
    <div class="modal fade" id="editEmployeeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-edit me-2"></i>Edit Employee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_employee">
                        <input type="hidden" name="employee_id" id="edit_employee_id">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_branch_id" class="form-label">Branch <span class="text-danger">*</span></label>
                                <select class="form-control" id="edit_branch_id" name="branch_id" required>
                                    <option value="">Select Branch</option>
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?= $branch['id'] ?>"><?= htmlspecialchars($branch['branch_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_employee_code" class="form-label">Employee Code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_employee_code" name="employee_code" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_full_name" name="full_name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_hire_date" class="form-label">Hire Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="edit_hire_date" name="hire_date" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_username" class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_username" name="username" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_password" class="form-label">Password <small class="text-muted">(leave blank to keep current)</small></label>
                                <input type="password" class="form-control" id="edit_password" name="password" placeholder="Leave blank to keep current password">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="edit_email" name="email">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_phone" class="form-label">Phone</label>
                                <input type="text" class="form-control" id="edit_phone" name="phone">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_basic_salary" class="form-label">Basic Salary</label>
                                <input type="number" class="form-control" id="edit_basic_salary" name="basic_salary" step="0.01" min="0" value="0">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_commission_rate" class="form-label">Commission Rate (%)</label>
                                <input type="number" class="form-control" id="edit_commission_rate" name="commission_rate" step="0.01" min="0" max="100" value="0">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save me-2"></i>Update Employee
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-generate employee code
        document.getElementById('full_name').addEventListener('input', function() {
            const name = this.value.trim();
            if (name) {
                const code = 'EMP' + name.substring(0, 3).toUpperCase().padEnd(3, 'X') + Math.floor(Math.random() * 1000).toString().padStart(3, '0');
                document.getElementById('employee_code').value = code;
            }
        });
        
        // Set default hire date to today
        document.getElementById('hire_date').value = new Date().toISOString().split('T')[0];

        // Function to populate edit modal with employee data
        function populateEditModal(employee) {
            document.getElementById('edit_employee_id').value = employee.id;
            document.getElementById('edit_branch_id').value = employee.branch_id;
            document.getElementById('edit_employee_code').value = employee.employee_code;
            document.getElementById('edit_full_name').value = employee.full_name;
            document.getElementById('edit_username').value = employee.username;
            document.getElementById('edit_email').value = employee.email || '';
            document.getElementById('edit_phone').value = employee.phone || '';
            document.getElementById('edit_basic_salary').value = employee.basic_salary;
            document.getElementById('edit_commission_rate').value = employee.commission_rate;
            document.getElementById('edit_hire_date').value = employee.hire_date;
            // Clear password field
            document.getElementById('edit_password').value = '';
        }
    </script>
</body>
</html>