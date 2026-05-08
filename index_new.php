<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Face Attendance System</title>
<script src="js/face-api.min.js"></script>
<script defer src="script.js"></script>
    <style>

:root {
    --primary: #00f2ff;
    --secondary: #7000ff;
    --bg: #0a0b10;
    --glass: rgba(255, 255, 255, 0.05);
}

body {
    background: radial-gradient(circle at top right, #1a1b25, var(--bg));
    color: white;
    font-family: 'Inter', system-ui, sans-serif;
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 100vh;
    margin: 0;
}

.scanner-container {
    background: var(--glass);
    backdrop-filter: blur(20px);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 24px;
    padding: 2rem;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
    text-align: center;
    width: 90%;
    max-width: 700px;
}

.video-wrapper {
    position: relative;
    border-radius: 16px;
    overflow: hidden;
    border: 2px solid var(--glass);
    margin-bottom: 1.5rem;
}

/* The "Scanning" Animation Overlay */
.video-wrapper::after {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 4px;
    background: var(--primary);
    box-shadow: 0 0 15px var(--primary);
    animation: scan 3s infinite ease-in-out;
}

@keyframes scan {
    0%, 100% { top: 0%; }
    50% { top: 100%; }
}

video {
    width: 100%;
    display: block;
    transform: scaleX(-1); /* Mirror effect */
}

.status-badge {
    display: inline-block;
    padding: 8px 20px;
    border-radius: 50px;
    background: rgba(0, 242, 255, 0.1);
    color: var(--primary);
    font-weight: 600;
    letter-spacing: 1px;
    text-transform: uppercase;
    font-size: 0.8rem;
    margin-top: 1rem;
}

.user-card {
    margin-top: 1rem;
    padding: 1rem;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.03);
    border-left: 4px solid var(--primary);
    display: none; /* Shown on success */
}


  </style>
</head>
<body>

    <!-- <h2>Scan Face for Attendance</h2> -->
<div class="scanner-container">
    <div class="header">
        <div class="logo"></div>
        <h2>AI Attendance <span class="cyan">PRO</span></h2>
    </div>

    <div class="video-wrapper">
        <video id="webcam" autoplay muted></video>
        <div class="scan-line"></div> <!-- Interactive scanning element -->
    </div>

    <div id="status-box" class="status-badge">Initializing Camera...</div>

    <div id="result-card" class="user-card">
        <div class="card-label">VERIFIED STUDENT</div>
        <div id="student-name" class="card-name">---</div>
        <div class="card-time" id="current-time"></div>
    </div>
</div>

<script>
       const video = document.getElementById('webcam');
const statusBox = document.getElementById('status-box');
const resultCard = document.getElementById('result-card');
const studentName = document.getElementById('student-name');

// 1. Start the Camera
navigator.mediaDevices.getUserMedia({ video: true })
    .then(stream => { 
        video.srcObject = stream;
        statusBox.innerText = "System Active: Scanning...";
        // Start the verification loop
        setInterval(captureAndVerify, 3000); // Check every 3 seconds
    });

async function captureAndVerify() {
    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0);

    // Convert to JPEG for the '8-bit' fix we did earlier
    const imageData = canvas.toDataURL('image/jpeg');

    try {
        statusBox.innerText = "Analyzing Face...";
        statusBox.style.color = "#00f2ff";

        const response = await fetch('http://127.0.0.1:5000/verify', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ image: imageData })
        });

        const data = await response.json();

        if (data.status === "success") {
            // SUCCESS UI
            statusBox.innerText = "Access Granted";
            statusBox.style.background = "rgba(0, 255, 100, 0.2)";
            statusBox.style.color = "#00ff64";
            
            resultCard.style.display = "block";
            studentName.innerText = data.name;
            document.getElementById('current-time').innerText = new Date().toLocaleTimeString();
            
            // Hide the card after 5 seconds
            setTimeout(() => { resultCard.style.display = "none"; }, 5000);
        } else {
            // FAILED UI
            statusBox.innerText = "Scanning: No Match";
            statusBox.style.background = "rgba(255, 255, 255, 0.05)";
            statusBox.style.color = "#888";
        }
    } catch (error) {
        statusBox.innerText = "Server Offline";
        statusBox.style.color = "#ff4b4b";
    }
}
    </script>
</body>
</html>