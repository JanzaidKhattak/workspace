<?php
require_once 'config/database.php';
require_once 'includes/auth.php';

$database = new Database();
$auth = new Auth($database);

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $user_type = $_POST['user_type'] ?? '';
    
    if (empty($username) || empty($password) || empty($user_type)) {
        $error_message = 'Please fill in all fields.';
    } else {
        if ($auth->login($username, $password, $user_type)) {
            header('Location: /index.php');
            exit;
        } else {
            $error_message = 'Invalid credentials. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Typing Center Management System - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: 'Arial', sans-serif;
        }
        .login-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            max-width: 400px;
            width: 100%;
        }
        .login-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .login-body {
            padding: 30px;
        }
        .form-control {
            border-radius: 10px;
            border: 2px solid #e9ecef;
            padding: 12px 15px;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 10px;
            padding: 12px;
            font-weight: 600;
            transition: transform 0.2s;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
        }
        .user-type-btn {
            border-radius: 10px;
            border: 2px solid #e9ecef;
            margin-bottom: 10px;
            transition: all 0.3s;
        }
        .user-type-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-color: #667eea;
            color: white;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="login-container">
                    <div class="login-header">
                        <h3><i class="fas fa-keyboard me-2"></i>Typing Center</h3>
                        <p class="mb-0">Management System</p>
                    </div>
                    <div class="login-body">
                        <?php if ($error_message): ?>
                            <div class="alert alert-danger" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error_message) ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Login As:</label>
                                <div class="btn-group-vertical d-grid gap-2">
                                    <input type="radio" class="btn-check" name="user_type" id="admin" value="admin" required>
                                    <label class="btn btn-outline-primary user-type-btn" for="admin">
                                        <i class="fas fa-user-shield me-2"></i>System Administrator
                                    </label>
                                    
                                    <input type="radio" class="btn-check" name="user_type" id="branch" value="branch" required>
                                    <label class="btn btn-outline-primary user-type-btn" for="branch">
                                        <i class="fas fa-building me-2"></i>Branch Manager
                                    </label>
                                    
                                    <input type="radio" class="btn-check" name="user_type" id="employee" value="employee" required>
                                    <label class="btn btn-outline-primary user-type-btn" for="employee">
                                        <i class="fas fa-user me-2"></i>Employee
                                    </label>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                                    <input type="text" class="form-control" id="username" name="username" required>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label for="password" class="form-label">Password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    <input type="password" class="form-control" id="password" name="password" required>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary btn-login w-100">
                                <i class="fas fa-sign-in-alt me-2"></i>Login
                            </button>
                        </form>
                        
                        <div class="mt-4 text-center">
                            <small class="text-muted">
                                <strong>Default Admin:</strong> admin / admin123
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Add active class to selected user type
        document.querySelectorAll('input[name="user_type"]').forEach(radio => {
            radio.addEventListener('change', function() {
                document.querySelectorAll('.user-type-btn').forEach(btn => btn.classList.remove('active'));
                if (this.checked) {
                    document.querySelector(`label[for="${this.id}"]`).classList.add('active');
                }
            });
        });
    </script>
</body>
</html>