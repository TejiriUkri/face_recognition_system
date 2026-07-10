<?php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "attendan_office_attendance";

$conn = new mysqli($host, $user, $pass, $db);

if (isset($_POST['register_webcam'])){
    $input_identifier = trim($_POST['identifier']);
    
    // 1. Decode the JSON array sent from JavaScript
    $images_array = json_decode($_POST['images_array'], true);

    // 2. Check if the user exists
    $check_stmt = $conn->prepare("SELECT ID, firstname, lastname, companyName FROM users WHERE email = ? OR ID = ? 
                                  LIMIT 1");
    $check_stmt->bind_param("ss", $input_identifier, $input_identifier);
    $check_stmt->execute();
    $result = $check_stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $user_id = $user['ID'];
        $full_name = $user['firstname'] . " " . $user['lastname'];
        $company_name = $user['companyName'];


        // Wipe out their old photos so we don't bloat the AI's math calculations
        $delete_stmt = $conn->prepare("DELETE FROM face_registeration WHERE user_id = ?");
        $delete_stmt->bind_param("i", $user_id);
        $delete_stmt->execute();
        $delete_stmt->close();

        // Prepare the insert statement ONCE outside the loop for better performance
        $insert_stmt = $conn->prepare("INSERT INTO face_registeration (user_id, full_name, CompanyName, photo_blob) VALUES (?, ?, ?, ?)");

        // 3. Loop through every image in the array and insert it
        foreach ($images_array as $image_data) {
            $filteredData = explode(',', $image_data);
            $decodedData = base64_decode($filteredData[1]);
            
            // Bind parameters and execute inside the loop
            $insert_stmt->bind_param("isss", $user_id, $full_name, $company_name, $decodedData);
            $insert_stmt->execute();
        }

        echo "Success";
        $insert_stmt->close();

    } else {
        echo "Redirect";
    }

    $check_stmt->close();
    exit(); 
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Webcam Registration</title>
    <style>
        body { font-family: sans-serif; text-align: center; background: #020e16; padding: 20px; }
        .container { display: inline-block; background: #ddd; padding: 20px; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        video, canvas { border-radius: 10px; background: #000; }
        input { padding: 10px; width: 80%; margin: 10px 0; border: 1px solid #ccc; border-radius: 5px; }
        button { padding: 10px 20px; cursor: pointer; border: none; border-radius: 5px; font-weight: bold; }
        .btn-capture { background: #007bff; color: white; margin-bottom: 10px; }
        .btn-save { background: #28a745; color: white; display: none; }
    </style>
</head>
<body>
<button onclick="history.back()" style="position: fixed; top: 15px; left: 15px; z-index: 1000;">Return</button>
<div class="container">
    <h2>Staff Registration</h2>
    <video id="webcam" width="400" height="300" autoplay></video>
    <br>
    <!-- <input type="text" id="student_name" placeholder="Enter Staff Full Name" required> -->
    <input type="text" id="user_identifier" placeholder="Enter Email or Staff ID" required>
    <br>
    <button class="btn-capture" onclick="takeBurstSnapshots()">Take Photo</button>
    
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
    
    let capturedImages = []; // Array to hold our multiple photos

    // 1. Start the Webcam
    navigator.mediaDevices.getUserMedia({ video: true })
        .then(stream => { video.srcObject = stream; })
        .catch(err => { alert("Check camera permissions!"); });

    // 2. Take a "Burst" of 5 photos
    function takeBurstSnapshots() {
        capturedImages = []; // Clear previous captures
        statusMsg.innerText = "Get ready! Taking 5 photos in 3... 2... 1...";
        
        let count = 0;
        const maxPhotos = 5;
        const context = canvas.getContext('2d');

        // Take a photo every 500 milliseconds (half a second)
        const burstInterval = setInterval(() => {
            context.drawImage(video, 0, 0, 400, 300);
            capturedImages.push(canvas.toDataURL('image/jpeg', 0.8)); // 0.8 compresses slightly to save DB space
            
            count++;
            statusMsg.innerText = `Captured ${count} of ${maxPhotos}... (Turn head slightly)`;

            if (count >= maxPhotos) {
                clearInterval(burstInterval);
                statusMsg.innerText = "Burst complete! Ready to save.";
                canvas.style.display = 'inline-block';
                saveBtn.style.display = 'inline-block';
            }
        }, 500);
    }

    // 3. Send Array to PHP
    function saveToDatabase() {
        const identifier = document.getElementById('user_identifier').value;
        if (!identifier) { alert("Please enter a name first!"); return; }
        if (capturedImages.length === 0) { alert("Please take photos first!"); return; }

        const formData = new FormData();
        formData.append('register_webcam', '1');
        formData.append('identifier', identifier);
        
        // Convert our array of Base64 strings into a single JSON string so PHP can read it
        formData.append('images_array', JSON.stringify(capturedImages));

        statusMsg.innerText = "Saving all photos to database...";

        fetch('register.php', { method: 'POST', body: formData })
        .then(res => res.text())
        .then(data => {
            const response = data.trim();
            if (response === "Success") {
                alert("All faces registered successfully!");
                location.reload();
            } else if (response === "Redirect") {
                alert("User not found. Redirecting to registration page...");
                window.location.href = "../add_staff"; 
            } else {
                alert("Error saving: " + response);
            }
        });
    }
</script>

</body>
</html>