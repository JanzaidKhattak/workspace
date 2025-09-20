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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            position: relative;
            overflow-x: hidden;
        }
        
        .login-wrapper {
            min-height: 100vh;
            display: flex;
            position: relative;
        }
        
        .left-section {
            flex: 1;
            background-image: url('assets/Images/background.webp');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .left-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.8) 0%, rgba(118, 75, 162, 0.8) 100%);
            z-index: 1;
        }
        
        .left-content {
            position: relative;
            z-index: 2;
            text-align: center;
            color: white;
            padding: 40px;
        }
        
        .left-content h1 {
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 20px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }
        
        .left-content p {
            font-size: 1.3rem;
            font-weight: 300;
            opacity: 0.9;
            line-height: 1.6;
            max-width: 600px;
        }
        
        .right-section {
            flex: 1;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            /* padding: 40px; */
            position: relative;
        }
        
        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            max-width: 450px;
            width: 100%;
            position: relative;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .login-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px 30px;
            text-align: center;
            position: relative;
        }
        
        .login-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="white" opacity="0.1"/><circle cx="75" cy="75" r="1" fill="white" opacity="0.1"/><circle cx="50" cy="10" r="0.5" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.1;
        }
        
        .login-header h3 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
        }
        
        .login-header .subtitle {
            font-size: 0.9rem;
            opacity: 0.9;
            font-weight: 300;
            position: relative;
            z-index: 1;
        }
        
        .login-body {
            padding: 10px 18px;
            background: white;
        }
        
        .alert {
            border-radius: 12px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 25px;
            font-weight: 500;
        }
        
        .alert-danger {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
            color: white;
        }
        
        .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
            font-size: 0.95rem;
        }
        
        .user-type-selection {
            margin-bottom: 20px;
        }
        
        .user-type-grid {
            display: grid;
            gap: 12px;
        }
        
        .user-type-btn {
            border-radius: 12px;
            border: 2px solid #e9ecef;
            padding: 12px 18px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: #f8f9fa;
            font-weight: 500;
            position: relative;
            overflow: hidden;
        }
        
        .user-type-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
            transition: left 0.5s;
        }
        
        .user-type-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.2);
        }
        
        .user-type-btn:hover::before {
            left: 100%;
        }
        
        .user-type-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-color: #667eea;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }
        
        .input-group {
            margin-bottom: 18px;
        }
        
        .input-group-text {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 12px 0 0 12px;
            padding: 12px 15px;
        }
        
        .form-control {
            border-radius: 0 12px 12px 0;
            border: 2px solid #e9ecef;
            border-left: none;
            padding: 12px 15px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
            border-left: none;
        }
        
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 12px;
            padding: 12px;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }
        
        .btn-login:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 35px rgba(102, 126, 234, 0.4);
            background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
        }
        
        .btn-login:hover::before {
            left: 100%;
        }
        
        .btn-login:active {
            transform: translateY(-1px);
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .login-wrapper {
                flex-direction: column;
            }
            
            .left-section {
                min-height: 40vh;
            }
            
            .left-content h1 {
                font-size: 2.5rem;
            }
            
            .left-content p {
                font-size: 1.1rem;
            }
            
            .right-section {
                padding: 20px;
            }
            
            .login-container {
                margin: 0;
            }
            
            .login-header, .login-body {
                padding: 30px 25px;
            }
        }
        
        @media (max-width: 480px) {
            .left-content h1 {
                font-size: 2rem;
            }
            
            .login-header, .login-body {
                padding: 25px 20px;
            }
        }
        
        /* Animation */
        .login-container {
            animation: slideIn 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .left-content {
            animation: fadeInLeft 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        @keyframes fadeInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="left-section">
            <div class="left-content">
                <h1><i class="fas fa-keyboard"></i></h1>
                <h1>Welcome Back</h1>
                <p>Access your typing center management system with secure login. Manage your business efficiently with our comprehensive solution.</p>
            </div>
        </div>
        
        <div class="right-section">
            <div class="login-container">
                <div class="login-header">
                    <h3>Typing Center Management</h3>
                    <p class="subtitle mb-0">Sign in to continue</p>
                </div>
                <div class="login-body">
                    <?php if ($error_message): ?>
                        <div class="alert alert-danger" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error_message) ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <div class="user-type-selection">
                            <label class="form-label">Select User Type:</label>
                            <div class="user-type-grid">
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
                        
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                            <input type="text" class="form-control" id="username" name="username" placeholder="Enter your username" required>
                        </div>
                        
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                            <input type="password" class="form-control" id="password" name="password" placeholder="Enter your password" required>
                        </div>
                        
                        <button type="submit" class="btn btn-primary btn-login w-100">
                            <i class="fas fa-sign-in-alt me-2"></i>Sign In
                        </button>
                    </form>
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
        
        // Add loading animation on form submit
        document.querySelector('form').addEventListener('submit', function(e) {
            const submitBtn = document.querySelector('.btn-login');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Signing In...';
            submitBtn.disabled = true;
        });
    </script>
</body>
</html>