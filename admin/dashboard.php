<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "attendance_system");

// Fetch attendance logs joined with the date
$sql = "SELECT * FROM attendance ORDER BY date DESC, time_in DESC";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard</title>
    <style>
        body { font-family: 'Inter', sans-serif; margin: 0; display: flex; background: #f8fafc; }
        .sidebar { width: 250px; background: #1e293b; color: white; height: 100vh; padding: 20px; position: fixed; }
        .content { margin-left: 290px; padding: 40px; width: 100%; }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        th { background: #334155; color: white; padding: 15px; text-align: left; }
        td { padding: 15px; border-bottom: 1px solid #e2e8f0; color: #334155; }
        .status-in { color: #16a34a; font-weight: bold; background: #dcfce7; padding: 4px 8px; border-radius: 4px; }
        .status-out { color: #ef4444; font-weight: bold; background: #fee2e2; padding: 4px 8px; border-radius: 4px; }
        .sidebar a { display: block; color: #cbd5e1; text-decoration: none; padding: 10px 0; }
        .sidebar a:hover { color: white; }
    </style>
</head>
<body>

<div class="sidebar">
    <h2>School AI</h2>
    <hr style="border: 0.5px solid #334155;">
    <a href="dashboard.php" style="color: white;">📋 Attendance Logs</a>
    <a href="settings.php">⚙️ System Settings</a>
    <a href="logout.php">🚪 Logout</a>
</div>

<div class="content">
    <h1>Attendance Logs</h1>
    <table>
        <thead>
            <tr>
                <th>Student ID</th>
                <th>Name</th>
                <th>Date</th>
                <th>Login Time</th>
                <th>Logout Time</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php while($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?php echo $row['student_id']; ?></td>
                <td><?php echo $row['student_name']; ?></td>
                <td><?php echo $row['date']; ?></td>
                <td><?php echo $row['time_in']; ?></td>
                <td><?php echo $row['time_out'] ? $row['time_out'] : '--:--'; ?></td>
                <td>
                    <span class="<?php echo $row['status'] == 'IN' ? 'status-in' : 'status-out'; ?>">
                        <?php echo $row['status']; ?>
                    </span>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

</body>
</html>