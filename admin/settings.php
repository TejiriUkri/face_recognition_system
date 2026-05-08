<?php
session_start();
// Basic security check: Redirect to login if admin is not logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

// Database Connection (Ensure these match your app.py settings)
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "attendance_system"; 

$conn = new mysqli($host, $user, $pass, $dbname);

$message = "";
if (isset($_POST['update_settings'])) {
    $minutes = intval($_POST['logout_gap']);
    $sql = "UPDATE system_settings SET setting_value = ? WHERE setting_key = 'min_checkout_gap'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $minutes);
    
    if ($stmt->execute()) {
        $message = "Success! The system now requires a $minutes minute gap.";
    }
}

// Get current value
$res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'min_checkout_gap'");
$current_gap = ($row = $res->fetch_assoc()) ? $row['setting_value'] : 30;
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin - System Interval</title>
    <style>
        /* Modern Admin Styling */
        body { font-family: 'Segoe UI', sans-serif; background: #eef2f3; margin: 0; display: flex; }
        .sidebar { width: 250px; height: 100vh; background: #2c3e50; color: white; padding: 20px; }
        .main-content { flex: 1; padding: 40px; }
        .card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); max-width: 500px; }
        .btn { background: #3498db; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; }
        .input-field { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 5px; }
    </style>
</head>
<body>

<div class="sidebar">
    <h2>Admin Hub</h2>
    <p>Logged in as Admin</p>
    <hr>
    <a href="dashboard.php" style="color:white; text-decoration:none;">Dashboard</a><br><br>
    <a href="settings.php" style="color:#3498db; text-decoration:none;"><b>System Settings</b></a><br><br>
    <a href="logout.php" style="color:white; text-decoration:none;">Logout</a>
</div>

<div class="main-content">
    <div class="card">
        <h3>Attendance Timing</h3>
        <?php if($message) echo "<p style='color:green;'>$message</p>"; ?>
        
        <form method="POST">
            <label>Sign-out Interval (Minutes)</label>
            <input type="number" name="logout_gap" class="input-field" value="<?php echo $current_gap; ?>">
            <p style="font-size: 0.8rem; color: #7f8c8d;">
                Example: 480 minutes = 8 hours. Students must wait this long before they can log out.
            </p>
            <button type="submit" name="update_settings" class="btn">Update Interval</button>
        </form>
    </div>
</div>

</body>
</html>