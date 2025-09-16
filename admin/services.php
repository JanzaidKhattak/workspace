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
            case 'add_service':
                $service_name = trim($_POST['service_name'] ?? '');
                $service_price = floatval($_POST['service_price'] ?? 0);
                $commission_rate = floatval($_POST['commission_rate'] ?? 0);
                
                if (empty($service_name) || $service_price <= 0) {
                    $error = 'Please enter a valid service name and price.';
                } else {
                    try {
                        $stmt = $db->prepare("INSERT INTO services (service_name, service_price, commission_rate) VALUES (?, ?, ?)");
                        $stmt->execute([$service_name, $service_price, $commission_rate]);
                        
                        $auth->logActivity('admin', $user_info['id'], 'Create Service', "Created new service: $service_name");
                        $message = 'Service created successfully!';
                    } catch (PDOException $e) {
                        $error = 'Error creating service. Please try again.';
                    }
                }
                break;
                
            case 'update_service':
                $service_id = $_POST['service_id'] ?? '';
                $service_name = trim($_POST['service_name'] ?? '');
                $service_price = floatval($_POST['service_price'] ?? 0);
                $commission_rate = floatval($_POST['commission_rate'] ?? 0);
                
                if (!empty($service_id) && !empty($service_name) && $service_price > 0) {
                    try {
                        $stmt = $db->prepare("UPDATE services SET service_name = ?, service_price = ?, commission_rate = ? WHERE id = ?");
                        $stmt->execute([$service_name, $service_price, $commission_rate, $service_id]);
                        
                        $auth->logActivity('admin', $user_info['id'], 'Update Service', "Updated service: $service_name");
                        $message = 'Service updated successfully!';
                    } catch (PDOException $e) {
                        $error = 'Error updating service.';
                    }
                }
                break;
                
            case 'update_status':
                $service_id = $_POST['service_id'] ?? '';
                $status = $_POST['status'] ?? '';
                
                if (!empty($service_id) && in_array($status, ['active', 'inactive'])) {
                    try {
                        $stmt = $db->prepare("UPDATE services SET status = ? WHERE id = ?");
                        $stmt->execute([$status, $service_id]);
                        
                        $auth->logActivity('admin', $user_info['id'], 'Update Service Status', "Changed service status to: $status");
                        $message = 'Service status updated successfully!';
                    } catch (PDOException $e) {
                        $error = 'Error updating service status.';
                    }
                }
                break;
        }
    }
}

// Get all services with usage statistics
$stmt = $db->query("SELECT s.*,
    (SELECT COUNT(*) FROM receipt_items ri WHERE ri.service_id = s.id) as total_usage,
    (SELECT COUNT(*) FROM receipt_items ri JOIN receipts r ON ri.receipt_id = r.id WHERE ri.service_id = s.id AND strftime('%Y-%m', r.created_at) = strftime('%Y-%m', 'now')) as monthly_usage,
    (SELECT COALESCE(SUM(ri.total_price), 0) FROM receipt_items ri JOIN receipts r ON ri.receipt_id = r.id WHERE ri.service_id = s.id AND strftime('%Y-%m', r.created_at) = strftime('%Y-%m', 'now')) as monthly_revenue
    FROM services s 
    ORDER BY s.created_at DESC");
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Management - Admin Panel</title>
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
        .service-card {
            border-radius: 15px;
            border: none;
            transition: transform 0.2s;
            margin-bottom: 20px;
        }
        .service-card:hover {
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
                    <a class="nav-link" href="/admin/employees.php">
                        <i class="fas fa-users me-2"></i>Employees
                    </a>
                    <a class="nav-link active" href="/admin/services.php">
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
                    <h1 class="h3"><i class="fas fa-cogs me-2"></i>Service Management</h1>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addServiceModal">
                        <i class="fas fa-plus me-2"></i>Add New Service
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
                
                <!-- Services List -->
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Service Name</th>
                                <th>Price</th>
                                <th>Commission Rate</th>
                                <th>Monthly Usage</th>
                                <th>Monthly Revenue</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($services as $service): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($service['service_name']) ?></strong><br>
                                        <small class="text-muted">Total uses: <?= $service['total_usage'] ?></small>
                                    </td>
                                    <td>
                                        <strong>$<?= number_format($service['service_price'], 2) ?></strong>
                                    </td>
                                    <td>
                                        <strong><?= number_format($service['commission_rate'], 2) ?>%</strong>
                                    </td>
                                    <td>
                                        <strong><?= $service['monthly_usage'] ?></strong> <small class="text-muted">uses</small>
                                    </td>
                                    <td>
                                        <strong>$<?= number_format($service['monthly_revenue'], 2) ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $service['status'] === 'active' ? 'success' : 'secondary' ?>">
                                            <?= ucfirst($service['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-primary" onclick="editService(<?= htmlspecialchars(json_encode($service)) ?>)" data-bs-toggle="modal" data-bs-target="#editServiceModal">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="service_id" value="<?= $service['id'] ?>">
                                                <input type="hidden" name="status" value="<?= $service['status'] === 'active' ? 'inactive' : 'active' ?>">
                                                <button type="submit" class="btn btn-outline-<?= $service['status'] === 'active' ? 'warning' : 'success' ?>" 
                                                        onclick="return confirm('Are you sure you want to <?= $service['status'] === 'active' ? 'deactivate' : 'activate' ?> this service?')">
                                                    <i class="fas fa-<?= $service['status'] === 'active' ? 'pause' : 'play' ?>"></i>
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
    </div>
    
    <!-- Add Service Modal -->
    <div class="modal fade" id="addServiceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add New Service</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_service">
                        
                        <div class="mb-3">
                            <label for="service_name" class="form-label">Service Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="service_name" name="service_name" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="service_price" class="form-label">Price <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="service_price" name="service_price" step="0.01" min="0.01" required>
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
                            <i class="fas fa-save me-2"></i>Create Service
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Service Modal -->
    <div class="modal fade" id="editServiceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Service</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_service">
                        <input type="hidden" name="service_id" id="edit_service_id">
                        
                        <div class="mb-3">
                            <label for="edit_service_name" class="form-label">Service Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_service_name" name="service_name" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_service_price" class="form-label">Price <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="edit_service_price" name="service_price" step="0.01" min="0.01" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_commission_rate" class="form-label">Commission Rate (%)</label>
                                <input type="number" class="form-control" id="edit_commission_rate" name="commission_rate" step="0.01" min="0" max="100">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Update Service
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editService(service) {
            document.getElementById('edit_service_id').value = service.id;
            document.getElementById('edit_service_name').value = service.service_name;
            document.getElementById('edit_service_price').value = service.service_price;
            document.getElementById('edit_commission_rate').value = service.commission_rate;
        }
    </script>
</body>
</html>