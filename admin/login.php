<?php
session_start();
if (isset($_SESSION['admin_logged_in'])) {
    header("Location: dashboard.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Replace 'admin' and 'password123' with your preferred credentials
    if ($username === 'admin' && $password === 'password123') {
        $_SESSION['admin_logged_in'] = true;
        header("Location: dashboard.php");
    } else {
        $error = "Invalid credentials!";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Login</title>
    <style>
        body { font-family: 'Inter', sans-serif; background: #0f172a; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; color: white; }
        .login-box { background: rgba(255,255,255,0.05); padding: 40px; border-radius: 20px; border: 1px solid rgba(255,255,255,0.1); width: 350px; text-align: center; }
        input { width: 100%; padding: 12px; margin: 10px 0; border-radius: 8px; border: none; background: #1e293b; color: white; box-sizing: border-box; }
        button { width: 100%; padding: 12px; background: #38bdf8; border: none; border-radius: 8px; color: #0f172a; font-weight: bold; cursor: pointer; margin-top: 10px; }
        .error { color: #f87171; font-size: 0.9rem; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>Admin Access</h2>
        <?php if(isset($error)) echo "<p class='error'>$error</p>"; ?>
        <form method="POST">
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Enter Dashboard</button>
        </form>
    </div>
</body>
</html>