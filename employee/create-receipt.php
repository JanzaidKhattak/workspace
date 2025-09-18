<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

$database = new Database();
$auth = new Auth($database);

$auth->requireRole(['employee']);

$user_info = $auth->getUserInfo();
$db = $database->getConnection();

// Get employee information
$stmt = $db->prepare("SELECT e.*, b.branch_name FROM employees e JOIN branches b ON e.branch_id = b.id WHERE e.id = ?");
$stmt->execute([$user_info['id']]);
$employee_info = $stmt->fetch(PDO::FETCH_ASSOC);

// Get services
$stmt = $db->query("SELECT * FROM services WHERE status = 'active' ORDER BY service_name");
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get currency setting
$stmt = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'currency' LIMIT 1");
$currency_result = $stmt->fetch(PDO::FETCH_ASSOC);
$currency = $currency_result ? $currency_result['setting_value'] : 'USD';

$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_name = trim($_POST['customer_name'] ?? '');
    $customer_phone = trim($_POST['customer_phone'] ?? '');
    $customer_email = trim($_POST['customer_email'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $services_data = $_POST['services'] ?? [];
    
    if (empty($customer_name) || empty($services_data)) {
        $error_message = 'Customer name and at least one service are required.';
    } else {
        try {
            $db->beginTransaction();
            
            // Generate receipt number
            $receipt_number = 'RCP' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            // Check if receipt number exists
            $stmt = $db->prepare("SELECT id FROM receipts WHERE receipt_number = ?");
            $stmt->execute([$receipt_number]);
            while ($stmt->fetch()) {
                $receipt_number = 'RCP' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $stmt->execute([$receipt_number]);
            }
            
            $total_amount = 0;
            $total_commission = 0;
            
            // Calculate totals
            foreach ($services_data as $service_data) {
                if (!empty($service_data['service_id']) && !empty($service_data['quantity'])) {
                    $stmt = $db->prepare("SELECT service_price, commission_rate FROM services WHERE id = ? AND status = 'active'");
                    $stmt->execute([$service_data['service_id']]);
                    $service = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($service) {
                        $quantity = max(1, intval($service_data['quantity']));
                        $unit_price = $service['service_price'];
                        $line_total = $unit_price * $quantity;
                        $commission_amount = ($line_total * $service['commission_rate']) / 100;
                        
                        $total_amount += $line_total;
                        $total_commission += $commission_amount;
                    }
                }
            }
            
            // Insert receipt
            $stmt = $db->prepare("INSERT INTO receipts (receipt_number, branch_id, employee_id, customer_name, customer_phone, customer_email, total_amount, total_commission, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $receipt_number,
                $employee_info['branch_id'],
                $user_info['id'],
                $customer_name,
                $customer_phone,
                $customer_email,
                $total_amount,
                $total_commission,
                $notes
            ]);
            
            $receipt_id = $db->lastInsertId();
            
            // Insert receipt items
            foreach ($services_data as $service_data) {
                if (!empty($service_data['service_id']) && !empty($service_data['quantity'])) {
                    $stmt = $db->prepare("SELECT service_price, commission_rate FROM services WHERE id = ? AND status = 'active'");
                    $stmt->execute([$service_data['service_id']]);
                    $service = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($service) {
                        $quantity = max(1, intval($service_data['quantity']));
                        $unit_price = $service['service_price'];
                        $line_total = $unit_price * $quantity;
                        $commission_amount = ($line_total * $service['commission_rate']) / 100;
                        
                        $stmt = $db->prepare("INSERT INTO receipt_items (receipt_id, service_id, quantity, unit_price, total_price, commission_amount) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $receipt_id,
                            $service_data['service_id'],
                            $quantity,
                            $unit_price,
                            $line_total,
                            $commission_amount
                        ]);
                    }
                }
            }
            
            // Log activity
            $auth->logActivity('employee', $user_info['id'], 'Receipt Created', "Created receipt #$receipt_number for $customer_name");
            
            $db->commit();
            $success_message = "Receipt #$receipt_number created successfully!";
            
        } catch (Exception $e) {
            $db->rollback();
            $error_message = "Error creating receipt. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Receipt - Employee</title>
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
        .service-row {
            background: #f8f9fa;
            padding: 15px;
            margin: 10px 0;
            border-radius: 10px;
            border: 1px solid #dee2e6;
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
                    <div>
                        <h6 class="mb-0"><?= htmlspecialchars($employee_info['full_name']) ?></h6>
                        <small class="opacity-75">Employee</small>
                        <div class="small opacity-75"><?= htmlspecialchars($employee_info['branch_name']) ?></div>
                        <div class="small opacity-75">Code: <?= htmlspecialchars($employee_info['employee_code']) ?></div>
                    </div>
                </div>
                
                <nav class="nav flex-column">
                    <a class="nav-link" href="/employee/dashboard.php">
                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                    </a>
                    <a class="nav-link active" href="/employee/create-receipt.php">
                        <i class="fas fa-plus-circle me-2"></i>Create Receipt
                    </a>
                    <a class="nav-link" href="/employee/receipts.php">
                        <i class="fas fa-receipt me-2"></i>My Receipts
                    </a>
                    <a class="nav-link" href="/employee/commissions.php">
                        <i class="fas fa-chart-line me-2"></i>Commissions
                    </a>
                    <a class="nav-link" href="/employee/profile.php">
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
                    <h1 class="h3"><i class="fas fa-plus-circle me-2"></i>Create New Receipt</h1>
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
                
                <form method="POST" id="receiptForm">
                    <div class="row">
                        <div class="col-lg-8">
                            <div class="card shadow-sm">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0"><i class="fas fa-user me-2"></i>Customer Information</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="customer_name" class="form-label">Customer Name *</label>
                                            <input type="text" class="form-control" id="customer_name" name="customer_name" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="customer_phone" class="form-label">Phone Number</label>
                                            <input type="tel" class="form-control" id="customer_phone" name="customer_phone">
                                        </div>
                                        <div class="col-12 mb-3">
                                            <label for="customer_email" class="form-label">Email Address</label>
                                            <input type="email" class="form-control" id="customer_email" name="customer_email">
                                        </div>
                                        <div class="col-12 mb-3">
                                            <label for="notes" class="form-label">Notes</label>
                                            <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="card shadow-sm mt-4">
                                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0"><i class="fas fa-cogs me-2"></i>Services</h5>
                                    <button type="button" class="btn btn-primary btn-sm" onclick="addService()">
                                        <i class="fas fa-plus me-1"></i>Add Service
                                    </button>
                                </div>
                                <div class="card-body">
                                    <div id="services-container">
                                        <div class="service-row" data-index="0">
                                            <div class="row align-items-end">
                                                <div class="col-md-5 mb-2">
                                                    <label class="form-label">Service</label>
                                                    <select class="form-select service-select" name="services[0][service_id]" onchange="updatePrice(this)" required>
                                                        <option value="">Select Service</option>
                                                        <?php foreach ($services as $service): ?>
                                                            <option value="<?= $service['id'] ?>" data-price="<?= $service['service_price'] ?>" data-commission="<?= $service['commission_rate'] ?>">
                                                                <?= htmlspecialchars($service['service_name']) ?> - <?= $currency ?><?= number_format($service['service_price'], 2) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-2 mb-2">
                                                    <label class="form-label">Quantity</label>
                                                    <input type="number" class="form-control quantity-input" name="services[0][quantity]" value="1" min="1" onchange="calculateTotal()" required>
                                                </div>
                                                <div class="col-md-2 mb-2">
                                                    <label class="form-label">Unit Price</label>
                                                    <input type="text" class="form-control unit-price" readonly>
                                                </div>
                                                <div class="col-md-2 mb-2">
                                                    <label class="form-label">Total</label>
                                                    <input type="text" class="form-control line-total" readonly>
                                                </div>
                                                <div class="col-md-1 mb-2">
                                                    <button type="button" class="btn btn-danger btn-sm" onclick="removeService(this)">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-4">
                            <div class="card shadow-sm">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0"><i class="fas fa-calculator me-2"></i>Receipt Summary</h5>
                                </div>
                                <div class="card-body">
                                    <div class="d-flex justify-content-between mb-2">
                                        <span>Subtotal:</span>
                                        <span id="subtotal"><?= $currency ?>0.00</span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-2">
                                        <span>Your Commission:</span>
                                        <span id="commission"><?= $currency ?>0.00</span>
                                    </div>
                                    <hr>
                                    <div class="d-flex justify-content-between h5">
                                        <span>Total:</span>
                                        <span id="total"><?= $currency ?>0.00</span>
                                    </div>
                                    
                                    <div class="d-grid gap-2 mt-4">
                                        <button type="submit" class="btn btn-primary btn-lg">
                                            <i class="fas fa-save me-2"></i>Create Receipt
                                        </button>
                                        <a href="/employee/dashboard.php" class="btn btn-secondary">
                                            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let serviceIndex = 1;
        const currency = '<?= $currency ?>';
        
        function addService() {
            const container = document.getElementById('services-container');
            const serviceRow = document.createElement('div');
            serviceRow.className = 'service-row';
            serviceRow.setAttribute('data-index', serviceIndex);
            
            serviceRow.innerHTML = `
                <div class="row align-items-end">
                    <div class="col-md-5 mb-2">
                        <label class="form-label">Service</label>
                        <select class="form-select service-select" name="services[${serviceIndex}][service_id]" onchange="updatePrice(this)" required>
                            <option value="">Select Service</option>
                            <?php foreach ($services as $service): ?>
                                <option value="<?= $service['id'] ?>" data-price="<?= $service['service_price'] ?>" data-commission="<?= $service['commission_rate'] ?>">
                                    <?= htmlspecialchars($service['service_name']) ?> - ${currency}<?= number_format($service['service_price'], 2) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 mb-2">
                        <label class="form-label">Quantity</label>
                        <input type="number" class="form-control quantity-input" name="services[${serviceIndex}][quantity]" value="1" min="1" onchange="calculateTotal()" required>
                    </div>
                    <div class="col-md-2 mb-2">
                        <label class="form-label">Unit Price</label>
                        <input type="text" class="form-control unit-price" readonly>
                    </div>
                    <div class="col-md-2 mb-2">
                        <label class="form-label">Total</label>
                        <input type="text" class="form-control line-total" readonly>
                    </div>
                    <div class="col-md-1 mb-2">
                        <button type="button" class="btn btn-danger btn-sm" onclick="removeService(this)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            `;
            
            container.appendChild(serviceRow);
            serviceIndex++;
        }
        
        function removeService(button) {
            const serviceRow = button.closest('.service-row');
            if (document.querySelectorAll('.service-row').length > 1) {
                serviceRow.remove();
                calculateTotal();
            }
        }
        
        function updatePrice(selectElement) {
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            const row = selectElement.closest('.service-row');
            const unitPriceInput = row.querySelector('.unit-price');
            const quantityInput = row.querySelector('.quantity-input');
            
            if (selectedOption.value) {
                const price = parseFloat(selectedOption.getAttribute('data-price'));
                unitPriceInput.value = currency + price.toFixed(2);
                calculateLineTotal(row);
            } else {
                unitPriceInput.value = '';
                row.querySelector('.line-total').value = '';
            }
            calculateTotal();
        }
        
        function calculateLineTotal(row) {
            const selectElement = row.querySelector('.service-select');
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            const quantityInput = row.querySelector('.quantity-input');
            const lineTotalInput = row.querySelector('.line-total');
            
            if (selectedOption.value && quantityInput.value) {
                const price = parseFloat(selectedOption.getAttribute('data-price'));
                const quantity = parseInt(quantityInput.value) || 1;
                const lineTotal = price * quantity;
                lineTotalInput.value = currency + lineTotal.toFixed(2);
            }
        }
        
        function calculateTotal() {
            let subtotal = 0;
            let totalCommission = 0;
            
            document.querySelectorAll('.service-row').forEach(row => {
                const selectElement = row.querySelector('.service-select');
                const selectedOption = selectElement.options[selectElement.selectedIndex];
                const quantityInput = row.querySelector('.quantity-input');
                
                if (selectedOption.value && quantityInput.value) {
                    const price = parseFloat(selectedOption.getAttribute('data-price'));
                    const commission = parseFloat(selectedOption.getAttribute('data-commission'));
                    const quantity = parseInt(quantityInput.value) || 1;
                    const lineTotal = price * quantity;
                    const lineCommission = (lineTotal * commission) / 100;
                    
                    subtotal += lineTotal;
                    totalCommission += lineCommission;
                    
                    calculateLineTotal(row);
                }
            });
            
            document.getElementById('subtotal').textContent = currency + subtotal.toFixed(2);
            document.getElementById('commission').textContent = currency + totalCommission.toFixed(2);
            document.getElementById('total').textContent = currency + subtotal.toFixed(2);
        }
        
        // Add change event listeners to quantity inputs
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('quantity-input')) {
                calculateTotal();
            }
        });
    </script>
</body>
</html>