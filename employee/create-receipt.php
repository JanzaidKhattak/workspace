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

// Get currency and VAT settings
$stmt = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('currency', 'vat_percentage')");
$settings_result = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings_result['currency'] ?? 'USD';
$vat_percentage = floatval($settings_result['vat_percentage'] ?? 0);

$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_name = trim($_POST['customer_name'] ?? '');
    $customer_phone = trim($_POST['customer_phone'] ?? '');
    $customer_email = trim($_POST['customer_email'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $services_data = $_POST['services'] ?? [];
    $apply_vat = isset($_POST['apply_vat']) ? 1 : 0;
    
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
            
            $subtotal = 0;
            $total_commission = 0;
            
            // Calculate subtotal and commission
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
                        
                        $subtotal += $line_total;
                        $total_commission += $commission_amount;
                    }
                }
            }
            
            // Calculate VAT and total
            $vat_amount = $apply_vat ? ($subtotal * $vat_percentage / 100) : 0;
            $total_amount = $subtotal + $vat_amount;
            
            // Insert receipt
            $stmt = $db->prepare("INSERT INTO receipts (receipt_number, branch_id, employee_id, customer_name, customer_phone, customer_email, subtotal, vat_percentage, vat_amount, total_amount, total_commission, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $receipt_number,
                $employee_info['branch_id'],
                $user_info['id'],
                $customer_name,
                $customer_phone,
                $customer_email,
                $subtotal,
                $apply_vat ? $vat_percentage : 0,
                $vat_amount,
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
            error_log("Receipt creation error: " . $e->getMessage());
            $error_message = "Error creating receipt. Please try again. [Error: " . $e->getMessage() . "]";
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
                                    
                                    <div class="mb-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="apply_vat" name="apply_vat" checked onchange="calculateTotal()">
                                            <label class="form-check-label" for="apply_vat">
                                                Apply VAT (<?= number_format($vat_percentage, 1) ?>%)
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between mb-2" id="vat-row">
                                        <span>VAT (<?= number_format($vat_percentage, 1) ?>%):</span>
                                        <span id="vat-amount"><?= $currency ?>0.00</span>
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
                                        <button type="button" class="btn btn-warning btn-lg" onclick="showReceiptPreview()" id="previewBtn" disabled>
                                            <i class="fas fa-eye me-2"></i>Preview Receipt
                                        </button>
                                        <button type="submit" class="btn btn-primary btn-lg" id="submitBtn" style="display: none;" disabled>
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

    <!-- Receipt Preview Modal -->
    <div class="modal fade" id="receiptPreviewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-eye me-2"></i>Receipt Preview - Please Review Carefully
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Important:</strong> Please review all details carefully before confirming. 
                        Once created, this receipt cannot be deleted or modified.
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="fw-bold">Customer Information:</h6>
                            <p class="mb-1"><strong>Name:</strong> <span id="preview-customer-name"></span></p>
                            <p class="mb-1"><strong>Phone:</strong> <span id="preview-customer-phone"></span></p>
                            <p class="mb-1"><strong>Email:</strong> <span id="preview-customer-email"></span></p>
                            <p class="mb-1"><strong>Notes:</strong> <span id="preview-notes"></span></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="fw-bold">Employee Information:</h6>
                            <p class="mb-1"><strong>Employee:</strong> <?= htmlspecialchars($employee_info['full_name']) ?></p>
                            <p class="mb-1"><strong>Branch:</strong> <?= htmlspecialchars($employee_info['branch_name']) ?></p>
                            <p class="mb-1"><strong>Date:</strong> <?= date('F j, Y g:i A') ?></p>
                        </div>
                    </div>
                    
                    <h6 class="fw-bold mt-3">Services:</h6>
                    <div class="table-responsive">
                        <table class="table table-bordered" id="preview-services-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Service</th>
                                    <th>Quantity</th>
                                    <th>Unit Price</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-6"></div>
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <span>Subtotal:</span>
                                        <span id="preview-subtotal"></span>
                                    </div>
                                    <div class="d-flex justify-content-between" id="preview-vat-row" style="display: none;">
                                        <span>VAT (<span id="preview-vat-percentage"></span>%):</span>
                                        <span id="preview-vat-amount"></span>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Your Commission:</span>
                                        <span id="preview-commission"></span>
                                    </div>
                                    <hr>
                                    <div class="d-flex justify-content-between h5">
                                        <span>Total Amount:</span>
                                        <span id="preview-total"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-edit me-2"></i>Edit Receipt
                    </button>
                    <button type="button" class="btn btn-success" onclick="confirmCreateReceipt()" id="confirmBtn">
                        <i class="fas fa-check me-2"></i>Confirm & Create Receipt
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let serviceIndex = 1;
        const currency = '<?= $currency ?>';
        const vatPercentage = <?= $vat_percentage ?>;
        
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
            
            // Calculate VAT
            const applyVat = document.getElementById('apply_vat').checked;
            const vatAmount = applyVat ? (subtotal * vatPercentage / 100) : 0;
            const total = subtotal + vatAmount;
            
            // Update display
            document.getElementById('subtotal').textContent = currency + subtotal.toFixed(2);
            document.getElementById('vat-amount').textContent = currency + vatAmount.toFixed(2);
            document.getElementById('commission').textContent = currency + totalCommission.toFixed(2);
            document.getElementById('total').textContent = currency + total.toFixed(2);
            
            // Show/hide VAT row based on checkbox
            const vatRow = document.getElementById('vat-row');
            if (applyVat && vatPercentage > 0) {
                vatRow.style.display = 'flex';
            } else {
                vatRow.style.display = 'none';
            }
            
            // Enable/disable preview button based on form validation
            validateForm();
        }
        
        // Form validation function
        function validateForm() {
            const customerName = document.getElementById('customer_name').value.trim();
            let hasValidService = false;
            
            // Check if at least one service is selected with quantity > 0
            document.querySelectorAll('.service-row').forEach(row => {
                const selectElement = row.querySelector('.service-select');
                const quantityInput = row.querySelector('.quantity-input');
                
                if (selectElement.value && quantityInput.value && parseInt(quantityInput.value) > 0) {
                    hasValidService = true;
                }
            });
            
            const isValid = customerName && hasValidService;
            const previewBtn = document.getElementById('previewBtn');
            
            if (isValid) {
                previewBtn.disabled = false;
                previewBtn.classList.remove('btn-secondary');
                previewBtn.classList.add('btn-warning');
            } else {
                previewBtn.disabled = true;
                previewBtn.classList.remove('btn-warning');
                previewBtn.classList.add('btn-secondary');
            }
        }
        
        // Show receipt preview function
        function showReceiptPreview() {
            // Populate customer information
            document.getElementById('preview-customer-name').textContent = 
                document.getElementById('customer_name').value || 'Not provided';
            document.getElementById('preview-customer-phone').textContent = 
                document.getElementById('customer_phone').value || 'Not provided';
            document.getElementById('preview-customer-email').textContent = 
                document.getElementById('customer_email').value || 'Not provided';
            document.getElementById('preview-notes').textContent = 
                document.getElementById('notes').value || 'No notes';
            
            // Populate services table
            const tbody = document.querySelector('#preview-services-table tbody');
            tbody.innerHTML = '';
            
            let subtotal = 0;
            let totalCommission = 0;
            
            document.querySelectorAll('.service-row').forEach(row => {
                const selectElement = row.querySelector('.service-select');
                const selectedOption = selectElement.options[selectElement.selectedIndex];
                const quantityInput = row.querySelector('.quantity-input');
                
                if (selectedOption.value && quantityInput.value) {
                    const serviceName = selectedOption.textContent.split(' - ')[0];
                    const price = parseFloat(selectedOption.getAttribute('data-price'));
                    const commission = parseFloat(selectedOption.getAttribute('data-commission'));
                    const quantity = parseInt(quantityInput.value) || 1;
                    const lineTotal = price * quantity;
                    const lineCommission = (lineTotal * commission) / 100;
                    
                    subtotal += lineTotal;
                    totalCommission += lineCommission;
                    
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td>${serviceName}</td>
                        <td>${quantity}</td>
                        <td>${currency}${price.toFixed(2)}</td>
                        <td>${currency}${lineTotal.toFixed(2)}</td>
                    `;
                    tbody.appendChild(tr);
                }
            });
            
            // Calculate VAT for preview
            const applyVat = document.getElementById('apply_vat').checked;
            const vatAmount = applyVat ? (subtotal * vatPercentage / 100) : 0;
            const total = subtotal + vatAmount;
            
            // Update preview totals
            document.getElementById('preview-subtotal').textContent = currency + subtotal.toFixed(2);
            document.getElementById('preview-commission').textContent = currency + totalCommission.toFixed(2);
            document.getElementById('preview-total').textContent = currency + total.toFixed(2);
            
            // Show/hide VAT in preview
            const previewVatRow = document.getElementById('preview-vat-row');
            if (applyVat && vatPercentage > 0) {
                document.getElementById('preview-vat-percentage').textContent = vatPercentage.toFixed(1);
                document.getElementById('preview-vat-amount').textContent = currency + vatAmount.toFixed(2);
                previewVatRow.style.display = 'flex';
            } else {
                previewVatRow.style.display = 'none';
            }
            
            // Show the modal
            const modal = new bootstrap.Modal(document.getElementById('receiptPreviewModal'));
            modal.show();
        }
        
        // Confirm and create receipt function
        let isSubmitting = false;
        function confirmCreateReceipt() {
            if (isSubmitting) {
                return; // Prevent double submission
            }
            
            // Final confirmation
            if (confirm('Are you absolutely sure you want to create this receipt? This action cannot be undone.')) {
                isSubmitting = true;
                document.getElementById('confirmBtn').disabled = true;
                document.getElementById('confirmBtn').innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Creating...';
                
                // Submit the form
                document.getElementById('receiptForm').submit();
            }
        }
        
        // Add change event listeners to all form inputs
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('quantity-input') || 
                e.target.classList.contains('service-select') ||
                e.target.id === 'customer_name' ||
                e.target.id === 'apply_vat') {
                calculateTotal();
                validateForm();
            }
        });
        
        // Add input event listener for real-time validation
        document.addEventListener('input', function(e) {
            if (e.target.id === 'customer_name') {
                validateForm();
            }
        });
        
        // Prevent form submission via Enter key - force users to use preview
        document.getElementById('receiptForm').addEventListener('submit', function(e) {
            if (!isSubmitting) {
                e.preventDefault();
                alert('Please use the Preview Receipt button to review your receipt before creating it.');
                return false;
            }
        });
        
        // Initial validation on page load
        document.addEventListener('DOMContentLoaded', function() {
            validateForm();
        });
    </script>
</body>
</html>