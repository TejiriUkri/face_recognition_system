<?php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "attendance_system";

$conn = new mysqli($host, $user, $pass, $db);

if (isset($_POST['register_webcam'])) {
    $name = $_POST['student_name'];
    $image_data = $_POST['image_base64'];

    // 1. Clean the Base64 string
    $filteredData = explode(',', $image_data);
    $decodedData = base64_decode($filteredData[1]);

    // 2. Use a simpler Prepared Statement
    // We use "b" for blob, but we will pass the data directly
    $stmt = $conn->prepare("INSERT INTO students (name, photo_blob) VALUES (?, ?)");
    
    // Bind parameters: 's' for string (name), 'b' for blob (photo)
    $stmt->bind_param("sb", $name, $decodedData);

    // This specifically tells MySQL that the second parameter is a large binary object
    $stmt->send_long_data(1, $decodedData);

    if ($stmt->execute()) {
        echo "Success";
    } else {
        echo "Error: " . $stmt->error;
    }
    $stmt->close();
    exit(); 
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Webcam Registration</title>
    <style>
        body { font-family: sans-serif; text-align: center; background: #f4f4f9; padding: 20px; }
        .container { display: inline-block; background: white; padding: 20px; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        video, canvas { border-radius: 10px; background: #000; }
        input { padding: 10px; width: 80%; margin: 10px 0; border: 1px solid #ccc; border-radius: 5px; }
        button { padding: 10px 20px; cursor: pointer; border: none; border-radius: 5px; font-weight: bold; }
        .btn-capture { background: #007bff; color: white; margin-bottom: 10px; }
        .btn-save { background: #28a745; color: white; display: none; }
    </style>
</head>
<body>

<div class="container">
    <h2>Student Registration</h2>
    <video id="webcam" width="400" height="300" autoplay></video>
    <br>
    <input type="text" id="student_name" placeholder="Enter Student Name" required>
    <br>
    <button class="btn-capture" onclick="takeSnapshot()">Take Photo</button>
    
    <div id="preview-area" style="margin-top:10px;">
        <canvas id="photo-canvas" width="400" height="300" style="display:none;"></canvas>
        <p id="status-msg"></p>
        <button id="save-btn" class="btn-save" onclick="saveToDatabase()">Confirm & Save Student</button>
    </div>
</div>

<script>
    const video = document.getElementById('webcam');
    const canvas = document.getElementById('photo-canvas');
    const saveBtn = document.getElementById('save-btn');
    const statusMsg = document.getElementById('status-msg');

    // 1. Start the Webcam
    navigator.mediaDevices.getUserMedia({ video: true })
        .then(stream => { video.srcObject = stream; })
        .catch(err => { alert("Check camera permissions!"); });

    // 2. Freeze the frame
    function takeSnapshot() {
        const context = canvas.getContext('2d');
        context.drawImage(video, 0, 0, 400, 300);
        
        canvas.style.display = 'inline-block';
        saveBtn.style.display = 'inline-block';
        statusMsg.innerText = "Photo Captured!";
    }

    // 3. Send to PHP via AJAX
    function saveToDatabase() {
        const name = document.getElementById('student_name').value;
        if (!name) { alert("Please enter a name first!"); return; }

        const imageData = canvas.toDataURL('image/jpeg');

        const formData = new FormData();
        formData.append('register_webcam', '1');
        formData.append('student_name', name);
        formData.append('image_base64', imageData);

        statusMsg.innerText = "Saving to database...";

        fetch('register.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.text())
        .then(data => {
            if (data.trim() === "Success") {
                alert("Student registered successfully!");
                location.reload();
            } else {
                alert("Error saving: " + data);
            }
        });
    }
</script>

</body>
</html>