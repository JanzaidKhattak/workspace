<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/currency_helper.php';

$database = new Database();
$auth = new Auth($database);

// Allow employees and branch managers to view receipts
$auth->requireRole(['employee', 'branch', 'admin']);

$user_info = $auth->getUserInfo();
$db = $database->getConnection();

$receipt_id = intval($_GET['id'] ?? 0);
$error_message = '';

if (!$receipt_id) {
    $error_message = 'Invalid receipt ID.';
} else {
    // Get receipt details with authorization check
    $authorization_query = '';
    $params = [$receipt_id];
    
    if ($user_info['type'] === 'employee') {
        $authorization_query = ' AND r.employee_id = ?';
        $params[] = $user_info['id'];
    } elseif ($user_info['type'] === 'branch') {
        $authorization_query = ' AND r.branch_id = ?';
        $params[] = $user_info['id'];
    }
    
    $stmt = $db->prepare("SELECT r.*, 
        e.full_name as employee_name, e.employee_code,
        b.branch_name, b.address as branch_address, b.phone as branch_phone
        FROM receipts r 
        JOIN employees e ON r.employee_id = e.id
        JOIN branches b ON r.branch_id = b.id
        WHERE r.id = ? $authorization_query");
    $stmt->execute($params);
    $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$receipt) {
        $error_message = 'Receipt not found or you do not have permission to view it.';
    } else {
        // Get receipt items
        $stmt = $db->prepare("SELECT ri.*, s.service_name 
            FROM receipt_items ri
            JOIN services s ON ri.service_id = s.id
            WHERE ri.receipt_id = ?");
        $stmt->execute([$receipt_id]);
        $receipt_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get company settings
        $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $currency = getCurrentCurrency($db);
        $currency_symbol = getCurrencySymbol($currency);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt #<?= htmlspecialchars($receipt['receipt_number'] ?? 'N/A') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Arial', sans-serif;
        }
        .receipt-container {
            max-width: 800px;
            margin: 20px auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .receipt-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
        }
        .company-logo {
            max-height: 60px;
            margin-bottom: 15px;
        }
        .receipt-body {
            padding: 30px;
        }
        .receipt-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }
        .info-group {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            border-left: 4px solid #667eea;
        }
        .info-group h6 {
            color: #667eea;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .items-table {
            border-collapse: separate;
            border-spacing: 0;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .items-table th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            font-weight: 600;
        }
        .items-table td {
            padding: 15px;
            border-bottom: 1px solid #eee;
        }
        .items-table tr:last-child td {
            border-bottom: none;
        }
        .items-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        .total-section {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-top: 20px;
        }
        .receipt-footer {
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            color: #666;
            font-style: italic;
        }
        .status-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 12px;
        }
        .status-paid { background-color: #28a745; }
        .status-pending { background-color: #ffc107; color: #000; }
        .status-cancelled { background-color: #dc3545; }
        
        @media print {
            body { background: white; }
            .receipt-container { 
                box-shadow: none; 
                border-radius: 0;
                margin: 0;
                max-width: none;
            }
            .print-hide { display: none; }
        }
        
        .print-actions {
            text-align: center;
            padding: 20px;
            background: white;
            margin: 20px auto;
            max-width: 800px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <?php if ($error_message): ?>
        <div class="container mt-5">
            <div class="alert alert-danger text-center">
                <i class="fas fa-exclamation-circle fa-3x mb-3"></i>
                <h4><?= htmlspecialchars($error_message) ?></h4>
                <a href="javascript:history.back()" class="btn btn-primary mt-3">
                    <i class="fas fa-arrow-left me-2"></i>Go Back
                </a>
            </div>
        </div>
    <?php else: ?>
        <!-- Print Actions -->
        <div class="print-actions print-hide">
            <button onclick="window.print()" class="btn btn-primary btn-lg me-3">
                <i class="fas fa-print me-2"></i>Print Receipt
            </button>
            <a href="javascript:history.back()" class="btn btn-secondary btn-lg">
                <i class="fas fa-arrow-left me-2"></i>Back
            </a>
        </div>
        
        <!-- Receipt -->
        <div class="receipt-container">
            <!-- Receipt Header -->
            <div class="receipt-header">
                <span class="status-badge status-<?= $receipt['payment_status'] ?>">
                    <?= ucfirst($receipt['payment_status']) ?>
                </span>
                
                <?php if (!empty($settings['logo_url'])): ?>
                    <img src="<?= htmlspecialchars($settings['logo_url'], ENT_QUOTES, 'UTF-8') ?>" alt="Company Logo" class="company-logo">
                <?php endif; ?>
                
                <h2 class="mb-1"><?= htmlspecialchars($settings['company_name'] ?? 'Typing Center') ?></h2>
                <?php if (!empty($settings['company_address'])): ?>
                    <p class="mb-1"><?= htmlspecialchars($settings['company_address']) ?></p>
                <?php endif; ?>
                <?php if (!empty($settings['company_phone'])): ?>
                    <p class="mb-3"><?= htmlspecialchars($settings['company_phone']) ?></p>
                <?php endif; ?>
                
                <?php if (!empty($settings['receipt_header'])): ?>
                    <div class="alert alert-light d-inline-block mb-0" style="background: rgba(255,255,255,0.2); border: none; color: white;">
                        <i class="fas fa-info-circle me-2"></i><?= htmlspecialchars($settings['receipt_header']) ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Receipt Body -->
            <div class="receipt-body">
                <!-- Receipt Info -->
                <div class="text-center mb-4">
                    <h1 class="display-6 text-primary">Receipt #<?= htmlspecialchars($receipt['receipt_number']) ?></h1>
                    <p class="text-muted"><?= date('l, F j, Y \a\t g:i A', strtotime($receipt['created_at'])) ?></p>
                </div>
                
                <div class="receipt-info">
                    <!-- Customer Info -->
                    <div class="info-group">
                        <h6><i class="fas fa-user me-2"></i>Customer Information</h6>
                        <p class="mb-1"><strong><?= htmlspecialchars($receipt['customer_name']) ?></strong></p>
                        <?php if ($receipt['customer_phone']): ?>
                            <p class="mb-1"><i class="fas fa-phone me-2"></i><?= htmlspecialchars($receipt['customer_phone']) ?></p>
                        <?php endif; ?>
                        <?php if ($receipt['customer_email']): ?>
                            <p class="mb-0"><i class="fas fa-envelope me-2"></i><?= htmlspecialchars($receipt['customer_email']) ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Service Info -->
                    <div class="info-group">
                        <h6><i class="fas fa-building me-2"></i>Service Information</h6>
                        <p class="mb-1"><strong><?= htmlspecialchars($receipt['branch_name']) ?></strong></p>
                        <p class="mb-1">Served by: <?= htmlspecialchars($receipt['employee_name']) ?></p>
                        <p class="mb-0">Employee: <?= htmlspecialchars($receipt['employee_code']) ?></p>
                    </div>
                </div>
                
                <!-- Items Table -->
                <table class="table items-table mb-0">
                    <thead>
                        <tr>
                            <th>Service</th>
                            <th class="text-center">Qty</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($receipt_items as $item): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($item['service_name']) ?></strong>
                                    <?php if ($item['commission_amount'] > 0): ?>
                                        <br><small class="text-success">
                                            <i class="fas fa-percentage me-1"></i>Commission: <?= formatCurrency($item['commission_amount'], $currency) ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?= $item['quantity'] ?></td>
                                <td class="text-end"><?= formatCurrency($item['unit_price'], $currency) ?></td>
                                <td class="text-end"><strong><?= formatCurrency($item['total_price'], $currency) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- Total Section -->
                <div class="total-section">
                    <div class="row">
                        <div class="col-sm-6">
                            <h5 class="mb-1">Total Amount</h5>
                            <p class="mb-0">Payment Status: <?= ucfirst($receipt['payment_status']) ?></p>
                        </div>
                        <div class="col-sm-6 text-end">
                            <h2 class="mb-1"><?= formatCurrency($receipt['total_amount'], $currency) ?></h2>
                            <?php if ($receipt['total_commission'] > 0): ?>
                                <p class="mb-0">Employee Commission: <?= formatCurrency($receipt['total_commission'], $currency) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <?php if ($receipt['notes']): ?>
                    <div class="mt-4 p-3 bg-light rounded">
                        <h6><i class="fas fa-sticky-note me-2"></i>Notes</h6>
                        <p class="mb-0"><?= nl2br(htmlspecialchars($receipt['notes'])) ?></p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Receipt Footer -->
            <?php if (!empty($settings['receipt_footer'])): ?>
                <div class="receipt-footer">
                    <i class="fas fa-heart me-2"></i><?= htmlspecialchars($settings['receipt_footer']) ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Print Actions -->
        <div class="print-actions print-hide">
            <button onclick="window.print()" class="btn btn-primary btn-lg me-3">
                <i class="fas fa-print me-2"></i>Print Receipt
            </button>
            <a href="javascript:history.back()" class="btn btn-secondary btn-lg">
                <i class="fas fa-arrow-left me-2"></i>Back
            </a>
        </div>
    <?php endif; ?>
    
    <script>
        // Auto-focus on print button when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Auto print if 'print' parameter is present
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('print') === '1') {
                setTimeout(() => window.print(), 500);
            }
        });
    </script>
</body>
</html>