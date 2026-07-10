<?php
session_start();

$host = "localhost";
$user = "root";
$pass = "";
$db   = "attendan_office_attendance";

$conn = new mysqli($host, $user, $pass, $db);
// require_once("../../resources/Config.php");

// $db = new Database();
// $conn = $db->getConnection();

if (isset($_POST['register_webcam'])){
    $input_identifier = trim($_POST['identifier']);
    
    // 1. Decode the JSON array sent from JavaScript
    $images_array = json_decode($_POST['images_array'], true);

    // 2. Check if the user exists
    $check_stmt = $conn->prepare("SELECT ID, firstname, lastname, companyName FROM users WHERE email = ? OR ID = ? LIMIT 1");
    $check_stmt->bind_param("ss", $input_identifier, $input_identifier);
    $check_stmt->execute();
    $result = $check_stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $user_id = $user['ID'];
        $full_name = $user['firstname'] . " " . $user['lastname'];
        $company_name = $user['companyName'];
        $company_id = $_SESSION['company_id'];    

        // Wipe out old photos
        $delete_stmt = $conn->prepare("DELETE FROM face_registeration WHERE user_id = ?");
        $delete_stmt->bind_param("i", $user_id);
        $delete_stmt->execute();
        $delete_stmt->close();

        // --- FIXED BINDING: Prepare AND Bind ONCE outside the loop ---
        $insert_stmt = $conn->prepare("INSERT INTO face_registeration (user_id, company_id, full_name, CompanyName, face_encoding) VALUES (?, ?, ?, ?, ?)");
        
        // Define the placeholder variable for the encoding string
        $encoding_string = ""; 
        $insert_stmt->bind_param("issss", $user_id, $company_id, $full_name, $company_name, $encoding_string);

        // Keep track of counts for debugging
        $total_sent = count($images_array);
        $python_success = 0;
        $db_inserted = 0;
        $errors = [];

        // 3. Loop through every image
        foreach ($images_array as $index => $image_data) {
            $filteredData = explode(',', $image_data);
            if (!isset($filteredData[1])) {
                $errors[] = "Frame " . ($index + 1) . ": Invalid image data format.";
                continue;
            }
            $base64_clean = $filteredData[1];
            
            // Setup cURL to send the image to Python
            $ch = curl_init('http://127.0.0.1:5000/calculate_encoding');
            $payload = json_encode(array('image' => $base64_clean));
            
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            
            $python_response = curl_exec($ch);
            curl_close($ch);
            
            $result_data = json_decode($python_response, true);

            if (isset($result_data['success']) && $result_data['success'] === true) {
                $python_success++;
                
                // Update the bound variable value directly
                $encoding_string = json_encode($result_data['encoding']);
                
                // Execute insertion and check for DB constraints/errors
                if ($insert_stmt->execute()) {
                    $db_inserted++;
                } else {
                    $errors[] = "Frame " . ($index + 1) . ": Database insertion failed -> " . $insert_stmt->error;
                }
            } else {
                $msg = isset($result_data['message']) ? $result_data['message'] : 'No face found';
                $errors[] = "Frame " . ($index + 1) . " (Pose: " . ($index + 1) . ") failed AI math: " . $msg;
            }
        }

        $insert_stmt->close();

        // --- NEW INTELLIGENT RESPONSE SYSTEM ---
        if ($db_inserted === $total_sent) {
            echo "Success";
        } else {
            // Send back a descriptive breakdown instead of a silent failure
            echo "Saved " . $db_inserted . " out of " . $total_sent . " frames.\n";
            echo "Python detected faces in: " . $python_success . "/" . $total_sent . " frames.\n";
            echo "Details:\n" . implode("\n", $errors);
        }

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
        
        .container { 
            display: inline-block; 
            background: #ffffff; 
            padding: 25px; 
            border-radius: 15px; 
            box-shadow: 0 8px 24px rgba(0,0,0,0.3); 
            max-width: 450px;
            width: 100%;
        }
        
        /* Camera Layout Frame */
        .camera-wrapper {
            position: relative;
            width: 400px;
            height: 300px;
            background: #000;
            border-radius: 10px;
            overflow: hidden;
            margin: 0 auto;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        video { 
            width: 100%; 
            height: 100%; 
            object-fit: cover;
            transform: scaleX(-1); /* Mirrors webcam for natural movement */
        }
        
        /* Dashed Guide Oval over Video */
        .guide-oval {
            position: absolute;
            width: 180px;
            height: 220px;
            border: 4px dashed #00c6ff;
            border-radius: 50%;
            box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.4); /* Dims video outside oval */
            transition: border-color 0.3s;
            pointer-events: none; /* Allows clicks to pass through if needed */
        }

        /* Instruction Text Box Overlay */
        #instruction-overlay {
            position: absolute;
            bottom: 15px;
            width: 85%;
            background: rgba(0, 0, 0, 0.75);
            color: white;
            text-align: center;
            padding: 8px;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: bold;
        }

        #countdown-timer {
            font-size: 1.4rem;
            color: #ffc107;
            margin-top: 3px;
        }

        /* Progress Dots CSS */
        .progress-container {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin: 15px 0;
        }

        .dot {
            width: 12px;
            height: 12px;
            background: #ccc;
            border-radius: 50%;
            transition: background 0.3s, transform 0.3s;
        }

        .dot.active {
            background: #00c6ff;
            transform: scale(1.3);
        }

        .dot.done {
            background: #28a745;
        }

        input { 
            padding: 12px; 
            width: 85%; 
            margin: 15px 0 5px 0; 
            border: 1px solid #ccc; 
            border-radius: 5px; 
            font-size: 1rem;
            text-align: center;
        }
        
        button { padding: 12px 24px; cursor: pointer; border: none; border-radius: 5px; font-weight: bold; font-size: 1rem; }
        .btn-capture { background: #007bff; color: white; width: 90%; margin-top: 5px; transition: background 0.2s; }
        .btn-capture:hover { background: #0056b3; }
        .btn-capture:disabled { background: #6c757d; cursor: not-allowed; }
    </style>
</head>
<body>

<button onclick="history.back()" style="position: fixed; top: 15px; left: 15px; z-index: 1000; background:#333; color:white;">Return</button>

<div class="container">
    <h2 style="color: #333; margin-top: 5px;">Staff Registration</h2>
    
    <input type="text" id="user_identifier" placeholder="Enter Email or Staff ID" required >
    
    <div class="camera-wrapper">
        <video id="webcam" autoplay playsinline></video>
        <div id="registration-guide" class="guide-oval"></div>
        <div id="instruction-overlay">
            <span id="instruction-text">Enter your ID/Email above to start</span>
            <div id="countdown-timer"></div>
        </div>
    </div>
    
    <div class="progress-container">
        <div class="dot" id="dot-0"></div>
        <div class="dot" id="dot-1"></div>
        <div class="dot" id="dot-2"></div>
        <div class="dot" id="dot-3"></div>
        <div class="dot" id="dot-4"></div>
    </div>
    
    <button id="start-btn" class="btn-capture" onclick="startGuidedRegistration()">Start Guided Capture</button>
    
    <canvas id="photo-canvas" width="400" height="300" style="display:none;"></canvas>
</div>

<script>
    const video = document.getElementById('webcam');
    const canvas = document.getElementById('photo-canvas');
    const startBtn = document.getElementById('start-btn');
    const instructionText = document.getElementById('instruction-text');
    const countdownText = document.getElementById('countdown-timer');
    const guideOval = document.getElementById('registration-guide');
    
    let capturedImages = []; 
    let currentPoseIndex = 0;
    let isRegistering = false;

    // Ordered sequence of poses requested from the employee
 // Change the wording to prevent users from over-turning
const poses = [
    "Look straight at the camera",
    "Turn your head a TINY BIT left",
    "Turn your head a TINY BIT right",
    "Tilt your head just a FRACTION up",
    "Tilt your head just a FRACTION down"
];
    // 1. Initialize System Webcam Feed
    navigator.mediaDevices.getUserMedia({ video: true })
        .then(stream => { video.srcObject = stream; })
        .catch(err => { 
            alert("Check camera permissions!"); 
            instructionText.innerText = "Camera Access Error";
        });

    // 2. Form Verification & Execution Step
    function startGuidedRegistration() {
        const identifier = document.getElementById('user_identifier').value.trim();
        if (!identifier) { 
            alert("Please enter your Email or Staff ID first!"); 
            return; 
        }

        if (isRegistering) return; 
        
        isRegistering = true;
        capturedImages = [];
        currentPoseIndex = 0;
        startBtn.disabled = true;
        startBtn.innerText = "Capturing Poses...";
        
        // Wipe old session dot statuses
        for(let i = 0; i < 5; i++) {
            document.getElementById(`dot-${i}`).className = "dot";
        }

        // Run the looping sequence controller
        captureNextAngle();
    }

    // 3. The Guided State Matrix Loop
    function captureNextAngle() {
        if (currentPoseIndex >= poses.length) {
            instructionText.innerText = "Processing & Uploading profiles...";
            countdownText.innerText = "";
            guideOval.style.borderColor = "#28a745"; // Success Flash Color Green
            
            saveToDatabase(); 
            return;
        }

        // Update target visual cues
        instructionText.innerText = poses[currentPoseIndex];
        document.getElementById(`dot-${currentPoseIndex}`).classList.add('active');
        guideOval.style.borderColor = "#00c6ff"; 
        
        let timeLeft = 3; 
        countdownText.innerText = timeLeft;

        let timer = setInterval(() => {
            timeLeft--;
            if (timeLeft > 0) {
                countdownText.innerText = timeLeft;
            } else {
                clearInterval(timer);
                countdownText.innerText = "SNAP!";
                guideOval.style.borderColor = "#ffffff"; // Frame Flash effect Simulation
                
                takeSnapshot();
                
                // Finalize active step visualization
                document.getElementById(`dot-${currentPoseIndex}`).classList.replace('active', 'done');
                currentPoseIndex++;
                
                // 1 Second buffer for user recovery before the next direction is declared
                setTimeout(captureNextAngle, 1000); 
            }
        }, 1000);
    }

    // 4. Render context frame to hidden canvas element
    function takeSnapshot() {
        const context = canvas.getContext('2d');
        context.drawImage(video, 0, 0, 400, 300);
        capturedImages.push(canvas.toDataURL('image/jpeg', 0.8));
    }

    // 5. Package and POST dataset directly to self (register.php backend handler)
    function saveToDatabase() {
        const identifier = document.getElementById('user_identifier').value.trim();
        
        const formData = new FormData();
        formData.append('register_webcam', '1');
        formData.append('identifier', identifier);
        formData.append('images_array', JSON.stringify(capturedImages));

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
                alert("Error saving profiles: " + response);
                resetWizardUI();
            }
        })
        .catch(err => {
            alert("Network system fault occurred.");
            resetWizardUI();
        });
    }

    // Recovery option if queries error out
    function resetWizardUI() {
        isRegistering = false;
        startBtn.disabled = false;
        startBtn.innerText = "Start Guided Capture";
        instructionText.innerText = "Enter your ID/Email above to start";
        countdownText.innerText = "";
        guideOval.style.borderColor = "#00c6ff";
        for(let i = 0; i < 5; i++) {
            document.getElementById(`dot-${i}`).className = "dot";
        }
    }
</script>

</body>
</html>