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
            case 'add_branch':
                $branch_name = trim($_POST['branch_name'] ?? '');
                $branch_code = trim($_POST['branch_code'] ?? '');
                $address = trim($_POST['address'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $manager_username = trim($_POST['manager_username'] ?? '');
                $manager_password = $_POST['manager_password'] ?? '';
                $manager_full_name = trim($_POST['manager_full_name'] ?? '');
                
                if (empty($branch_name) || empty($branch_code) || empty($manager_username) || empty($manager_password) || empty($manager_full_name)) {
                    $error = 'Please fill in all required fields.';
                } else {
                    try {
                        $hashed_password = password_hash($manager_password, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("INSERT INTO branches (branch_name, branch_code, address, phone, email, manager_username, manager_password, manager_full_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$branch_name, $branch_code, $address, $phone, $email, $manager_username, $hashed_password, $manager_full_name]);
                        
                        $auth->logActivity('admin', $user_info['id'], 'Create Branch', "Created new branch: $branch_name");
                        $message = 'Branch created successfully!';
                    } catch (PDOException $e) {
                        if (strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
                            $error = 'Branch code or manager username already exists.';
                        } else {
                            $error = 'Error creating branch. Please try again.';
                        }
                    }
                }
                break;

            case 'edit_branch':
                $branch_id = $_POST['branch_id'] ?? '';
                $branch_name = trim($_POST['branch_name'] ?? '');
                $branch_code = trim($_POST['branch_code'] ?? '');
                $address = trim($_POST['address'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $manager_username = trim($_POST['manager_username'] ?? '');
                $manager_password = $_POST['manager_password'] ?? '';
                $manager_full_name = trim($_POST['manager_full_name'] ?? '');
                
                if (empty($branch_id) || empty($branch_name) || empty($branch_code) || empty($manager_username) || empty($manager_full_name)) {
                    $error = 'Please fill in all required fields.';
                } else {
                    try {
                        // Check if password should be updated
                        if (!empty($manager_password)) {
                            $hashed_password = password_hash($manager_password, PASSWORD_DEFAULT);
                            $stmt = $db->prepare("UPDATE branches SET branch_name = ?, branch_code = ?, address = ?, phone = ?, email = ?, manager_username = ?, manager_password = ?, manager_full_name = ? WHERE id = ?");
                            $stmt->execute([$branch_name, $branch_code, $address, $phone, $email, $manager_username, $hashed_password, $manager_full_name, $branch_id]);
                        } else {
                            // Update without changing password
                            $stmt = $db->prepare("UPDATE branches SET branch_name = ?, branch_code = ?, address = ?, phone = ?, email = ?, manager_username = ?, manager_full_name = ? WHERE id = ?");
                            $stmt->execute([$branch_name, $branch_code, $address, $phone, $email, $manager_username, $manager_full_name, $branch_id]);
                        }
                        
                        $auth->logActivity('admin', $user_info['id'], 'Update Branch', "Updated branch: $branch_name");
                        $message = 'Branch updated successfully!';
                    } catch (PDOException $e) {
                        if (strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
                            $error = 'Branch code or manager username already exists.';
                        } else {
                            $error = 'Error updating branch. Please try again.';
                        }
                    }
                }
                break;
                
            case 'update_status':
                $branch_id = $_POST['branch_id'] ?? '';
                $status = $_POST['status'] ?? '';
                
                if (!empty($branch_id) && in_array($status, ['active', 'inactive'])) {
                    try {
                        $stmt = $db->prepare("UPDATE branches SET status = ? WHERE id = ?");
                        $stmt->execute([$status, $branch_id]);
                        
                        $auth->logActivity('admin', $user_info['id'], 'Update Branch Status', "Changed branch status to: $status");
                        $message = 'Branch status updated successfully!';
                    } catch (PDOException $e) {
                        $error = 'Error updating branch status.';
                    }
                }
                break;
        }
    }
}

// Get all branches with employee count
$stmt = $db->query("SELECT b.*, 
    (SELECT COUNT(*) FROM employees WHERE branch_id = b.id AND status = 'active') as employee_count,
    (SELECT COUNT(*) FROM receipts WHERE branch_id = b.id AND strftime('%Y-%m', created_at) = strftime('%Y-%m', 'now')) as monthly_receipts,
    (SELECT COALESCE(SUM(total_amount), 0) FROM receipts WHERE branch_id = b.id AND strftime('%Y-%m', created_at) = strftime('%Y-%m', 'now')) as monthly_revenue
    FROM branches b 
    ORDER BY b.created_at DESC");
$branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branch Management - Admin Panel</title>
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
        .branch-card {
            border-radius: 15px;
            border: none;
            transition: transform 0.2s;
            margin-bottom: 20px;
        }
        .branch-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        .status-badge {
            font-size: 0.8em;
        }
        .stats-mini {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 10px;
            margin-top: 10px;
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
                    <a class="nav-link active" href="/admin/branches.php">
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
                    <h1 class="h3"><i class="fas fa-building me-2"></i>Branch Management</h1>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBranchModal">
                        <i class="fas fa-plus me-2"></i>Add New Branch
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
                
                <!-- Branches List -->
                <?php if (empty($branches)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-building fa-5x text-muted mb-4"></i>
                        <h3 class="text-muted">No Branches Found</h3>
                        <p class="text-muted">Start by adding your first branch to the system.</p>
                        <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#addBranchModal">
                            <i class="fas fa-plus me-2"></i>Add First Branch
                        </button>
                    </div>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($branches as $branch): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="card branch-card shadow-sm">
                                    <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                                        <h5 class="mb-0"><?= htmlspecialchars($branch['branch_name']) ?></h5>
                                        <span class="badge status-badge bg-<?= $branch['status'] === 'active' ? 'success' : 'secondary' ?>">
                                            <?= ucfirst($branch['status']) ?>
                                        </span>
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <strong>Branch Code:</strong> 
                                            <code class="bg-light px-2 py-1 rounded"><?= htmlspecialchars($branch['branch_code']) ?></code>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <strong>Manager:</strong><br>
                                            <i class="fas fa-user me-1"></i><?= htmlspecialchars($branch['manager_full_name']) ?><br>
                                            <small class="text-muted">
                                                <i class="fas fa-at me-1"></i><?= htmlspecialchars($branch['manager_username']) ?>
                                            </small>
                                        </div>
                                        
                                        <?php if (!empty($branch['address'])): ?>
                                            <div class="mb-3">
                                                <strong>Address:</strong><br>
                                                <small><?= htmlspecialchars($branch['address']) ?></small>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($branch['phone']) || !empty($branch['email'])): ?>
                                            <div class="mb-3">
                                                <?php if (!empty($branch['phone'])): ?>
                                                    <small><i class="fas fa-phone me-1"></i><?= htmlspecialchars($branch['phone']) ?></small><br>
                                                <?php endif; ?>
                                                <?php if (!empty($branch['email'])): ?>
                                                    <small><i class="fas fa-envelope me-1"></i><?= htmlspecialchars($branch['email']) ?></small>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <!-- Branch Statistics -->
                                        <div class="stats-mini">
                                            <div class="row text-center">
                                                <div class="col-4">
                                                    <div class="h6 mb-0"><?= $branch['employee_count'] ?></div>
                                                    <small class="text-muted">Employees</small>
                                                </div>
                                                <div class="col-4">
                                                    <div class="h6 mb-0"><?= $branch['monthly_receipts'] ?></div>
                                                    <small class="text-muted">Receipts</small>
                                                </div>
                                                <div class="col-4">
                                                    <div class="h6 mb-0">$<?= number_format($branch['monthly_revenue'], 0) ?></div>
                                                    <small class="text-muted">Revenue</small>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="mt-3">
                                            <!-- Buttons Row -->
                                            <div class="d-flex gap-2 mb-2">
                                                <!-- Edit Button -->
                                                <button type="button" class="btn btn-sm btn-info flex-fill" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#editBranchModal"
                                                        onclick="populateEditModal(<?= htmlspecialchars(json_encode($branch)) ?>)">
                                                    <i class="fas fa-edit me-1"></i>Edit
                                                </button>
                                                
                                                <!-- Status Toggle Button -->
                                                <form method="POST" class="flex-fill">
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="branch_id" value="<?= $branch['id'] ?>">
                                                    <input type="hidden" name="status" value="<?= $branch['status'] === 'active' ? 'inactive' : 'active' ?>">
                                                    <button type="submit" class="btn btn-sm btn-<?= $branch['status'] === 'active' ? 'warning' : 'success' ?> w-100" 
                                                            onclick="return confirm('Are you sure you want to <?= $branch['status'] === 'active' ? 'deactivate' : 'activate' ?> this branch?')">
                                                        <i class="fas fa-<?= $branch['status'] === 'active' ? 'pause' : 'play' ?> me-1"></i>
                                                        <?= $branch['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                                                    </button>
                                                </form>
                                            </div>
                                            
                                            <!-- Created Date -->
                                            <div class="text-end">
                                                <small class="text-muted">
                                                    Created: <?= date('M j, Y', strtotime($branch['created_at'])) ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Add Branch Modal -->
    <div class="modal fade" id="addBranchModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-building me-2"></i>Add New Branch</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_branch">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="branch_name" class="form-label">Branch Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="branch_name" name="branch_name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="branch_code" class="form-label">Branch Code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="branch_code" name="branch_code" required>
                                <div class="form-text">Unique identifier for the branch (e.g., BR001)</div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="address" class="form-label">Address</label>
                            <textarea class="form-control" id="address" name="address" rows="2"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="phone" class="form-label">Phone</label>
                                <input type="text" class="form-control" id="phone" name="phone">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email">
                            </div>
                        </div>
                        
                        <hr>
                        <h6 class="mb-3"><i class="fas fa-user-tie me-2"></i>Branch Manager Details</h6>
                        
                        <div class="mb-3">
                            <label for="manager_full_name" class="form-label">Manager Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="manager_full_name" name="manager_full_name" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="manager_username" class="form-label">Manager Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="manager_username" name="manager_username" required>
                                <div class="form-text">This will be used for branch manager login</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="manager_password" class="form-label">Manager Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" id="manager_password" name="manager_password" required>
                                <div class="form-text">Minimum 6 characters</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Create Branch
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Branch Modal -->
    <div class="modal fade" id="editBranchModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-building-edit me-2"></i>Edit Branch</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_branch">
                        <input type="hidden" name="branch_id" id="edit_branch_id">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_branch_name" class="form-label">Branch Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_branch_name" name="branch_name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_branch_code" class="form-label">Branch Code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_branch_code" name="branch_code" required>
                                <div class="form-text">Unique identifier for the branch (e.g., BR001)</div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_address" class="form-label">Address</label>
                            <textarea class="form-control" id="edit_address" name="address" rows="2"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_phone" class="form-label">Phone</label>
                                <input type="text" class="form-control" id="edit_phone" name="phone">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="edit_email" name="email">
                            </div>
                        </div>
                        
                        <hr>
                        <h6 class="mb-3"><i class="fas fa-user-tie me-2"></i>Branch Manager Details</h6>
                        
                        <div class="mb-3">
                            <label for="edit_manager_full_name" class="form-label">Manager Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_manager_full_name" name="manager_full_name" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_manager_username" class="form-label">Manager Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_manager_username" name="manager_username" required>
                                <div class="form-text">This will be used for branch manager login</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_manager_password" class="form-label">Manager Password <small class="text-muted">(leave blank to keep current)</small></label>
                                <input type="password" class="form-control" id="edit_manager_password" name="manager_password" placeholder="Leave blank to keep current password">
                                <div class="form-text">Leave blank to keep current password</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save me-2"></i>Update Branch
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-generate branch code based on branch name
        document.getElementById('branch_name').addEventListener('input', function() {
            const name = this.value.trim();
            if (name) {
                const code = 'BR' + name.substring(0, 3).toUpperCase().padEnd(3, 'X') + Math.floor(Math.random() * 100).toString().padStart(2, '0');
                document.getElementById('branch_code').value = code;
            }
        });
        
        // Password validation
        document.getElementById('manager_password').addEventListener('input', function() {
            if (this.value.length < 6 && this.value.length > 0) {
                this.setCustomValidity('Password must be at least 6 characters long');
            } else {
                this.setCustomValidity('');
            }
        });

        // Function to populate edit modal with branch data
        function populateEditModal(branch) {
            document.getElementById('edit_branch_id').value = branch.id;
            document.getElementById('edit_branch_name').value = branch.branch_name;
            document.getElementById('edit_branch_code').value = branch.branch_code;
            document.getElementById('edit_address').value = branch.address || '';
            document.getElementById('edit_phone').value = branch.phone || '';
            document.getElementById('edit_email').value = branch.email || '';
            document.getElementById('edit_manager_full_name').value = branch.manager_full_name;
            document.getElementById('edit_manager_username').value = branch.manager_username;
            // Clear password field for security
            document.getElementById('edit_manager_password').value = '';
        }
    </script>
</body>
</html>