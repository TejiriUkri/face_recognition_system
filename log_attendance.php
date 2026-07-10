<?php
// 1. Database Connection
$host = "localhost";
$user = "root";
$pass = "";
$db   = "attendan_office_attendance"

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// 2. Get the ID sent by JavaScript AJAX
if (isset($_POST['id'])) {
    $user_id = $_mysqli->real_escape_string($_POST['id']);
    $date = date('Y-m-d');
    $time = date('H:i:s');

    // 3. Check if they already marked attendance today (Optional but recommended)
    $check = "SELECT * FROM attendance WHERE userid = '$user_id' AND log_date = '$date'";
    $result = $conn->query($check);

    if ($result->num_rows == 0) {
        // 4. Insert the record
        $sql = "INSERT INTO attendance (userid, log_date, time_clock_in, status) 
                VALUES ('$student_id', '$date', '$time', 'Present')";
        
        if ($conn->query($sql) === TRUE) {
            echo "Success: Attendance recorded for ID " . $user_id;
        } else {
            echo "Error: " . $conn->error;
        }
    } else {
        echo "Already Marked: Student has already checked in today.";
    }
} else {
    echo "No ID provided.";
}

$conn->close();
?>