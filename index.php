<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Attendance System - Login</title>
    <style>
        /* YOUR ORIGINAL CSS LAYOUT (With minor modern tweaks) */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f0f2f5;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }

        .main-container {
            background-color: white;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            text-align: center;
            width: 90%;
            max-width: 680px;
        }

        h1 {
            color: #333;
            margin-bottom: 0.5rem;
        }

        p.subtitle {
            color: #666;
            margin-bottom: 2rem;
        }

        /* --- THE VIDEO CONTAINER & WIDE FACE GUIDE --- */
        .video-container {
            position: relative;
            width: 640px; /* Standard Webcam Width */
            height: 480px; /* Standard Webcam Height */
            margin: 0 auto;
            overflow: hidden;
            border-radius: 12px;
            background-color: #000;
            border: 4px solid #ddd;
        }

        #webcam {
            width: 100%;
            height: 100%;
            display: block;
            transform: scaleX(-1); /* Mirrors the video for natural feel */
        }

        .face-guide-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            /* Creates a darker background, leaving a large clear oval in center */
            background: radial-gradient(ellipse at center, transparent 35%, rgba(0, 0, 0, 0.7) 70%);
            pointer-events: none; /* Allows clicks to pass through */
            z-index: 10;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        /* The circular border - Made WIDER and TALLER */
        .guide-ring {
            width: 380px;  /* Increased width significantly */
            height: 420px; /* Increased height significantly */
            border: 4px dashed #00c6ff; /* Original Cyan accent */
            border-radius: 50%; /* Oval shape due to unequal W/H */
            box-shadow: 0 0 20px rgba(0, 198, 255, 0.5);
            transition: border-color 0.3s ease; /* Smooth color change on match */
        }

        /* --- STATUS AND RESULTS --- */
        #status-box {
            margin-top: 1.5rem;
            font-size: 1.1rem;
            font-weight: 600;
            color: #555;
            padding: 10px;
            border-radius: 8px;
            background: #eee;
            display: inline-block;
        }

        .success-card {
            margin-top: 1.5rem;
            padding: 1.5rem;
            border-radius: 10px;
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            display: none; /* Hidden by default */
        }

        .error-card {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

    </style>
</head>
<body>

<div class="main-container">
    <h1>Student Check-In</h1>
    <p class="subtitle">Please align your face within the guide below</p>

    <div class="video-container">
        <!-- The interactive guide -->
        <div class="face-guide-overlay">
            <div class="guide-ring" id="guide-ring"></div>
        </div>
        <!-- The live video feed -->
        <video id="webcam" autoplay muted></video>
    </div>

    <div id="status-box">Initializing Camera...</div>

    <!-- The Success Card (Displays Name) -->
    <div id="result-card" class="success-card">
        <div style="font-size: 0.9rem;">Verified Student:</div>
        <div id="student-name" style="font-size: 1.8rem; font-weight: bold;">---</div>
        <div id="checkin-time" style="font-size: 0.9rem; margin-top: 5px;"></div>
    </div>
</div>

<script>
    const video = document.getElementById('webcam');
    const statusBox = document.getElementById('status-box');
    const resultCard = document.getElementById('result-card');
    const studentNameBox = document.getElementById('student-name');
    const checkinTimeBox = document.getElementById('checkin-time');
    const guideRing = document.getElementById('guide-ring');

    let isProcessing = false; // Prevents overlapping requests

    // 1. Setup Webcam and Start Loop
    navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480 } })
        .then(stream => {
            video.srcObject = stream;
            statusBox.innerText = "Scanning... Place face in guide.";
            // 0.8 seconds is very fast but stable
            setInterval(captureAndVerify, 800); 
        })
        .catch(err => {
            console.error("Camera error:", err);
            statusBox.innerText = "Error: Camera access denied.";
            statusBox.style.background = "#f8d7da";
        });

    // 2. The Capture and Verify Function (Connects to Flask)
    async function captureAndVerify() {
        if (isProcessing) return; // Don't start a new scan if one is running
        
        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        const ctx = canvas.getContext('2d');
        
        // Match video mirroring for the canvas capture
        ctx.translate(canvas.width, 0);
        ctx.scale(-1, 1);
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

        // Convert to JPEG (fixes the "unsupported type" error)
        const imageData = canvas.toDataURL('image/jpeg', 0.8);

        isProcessing = true;
        
        // Interactive UI: Set guide to "Processing" color (Yellow)
        guideRing.style.borderColor = "#ffc107";
        statusBox.innerText = "Analyzing face...";

        try {
            const response = await fetch('http://127.0.0.1:5000/verify', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ image: imageData })
            });

            const data = await response.json();

            if (data.status === "success") {
                // --- SUCCESS STATE ---
                handleSuccess(data.name);
            } else {
                // --- FAIL/UNKNOWN STATE ---
                handleFailure();
            }
        } catch (error) {
            console.error("Flask connection error:", error);
            statusBox.innerText = "Server Offline (Port 5000)";
            statusBox.style.background = "#f8d7da";
            guideRing.style.borderColor = "#dc3545"; // Red for error
        } finally {
            // Allow next scan attempt
            isProcessing = false; 
        }
    }

    // --- Helper Functions for UI Interactivity ---

    function handleSuccess(name) {
        // 1. Update Status Box
        statusBox.innerText = "Access Granted";
        statusBox.style.background = "#d4edda";
        statusBox.style.color = "#155724";

        // 2. Update Face Guide (Green)
        guideRing.style.borderColor = "#28a745";
        guideRing.style.boxShadow = "0 0 20px rgba(40, 167, 69, 0.7)";

        // 3. Show Success Card
        resultCard.style.display = "block";
        resultCard.classList.remove('error-card');
        studentNameBox.innerText = name;
        checkinTimeBox.innerText = "Checked in at: " + new Date().toLocaleTimeString();

        // 4. Reset UI after 4 seconds to scan next student
        setTimeout(resetUI, 4000);
    }

    function handleFailure() {
        // 1. Update Status Box
        statusBox.innerText = "Scanning... No Match Found";
        statusBox.style.background = "#eee";
        statusBox.style.color = "#555";

        // 2. Reset Guide to default (Cyan)
        guideRing.style.borderColor = "#00c6ff";
        guideRing.style.boxShadow = "0 0 20px rgba(0, 198, 255, 0.5)";

        // 3. Hide Result Card
        resultCard.style.display = "none";
    }

    function resetUI() {
        if (isProcessing) return; // Don't reset if a scan is active
        resultCard.style.display = "none";
        statusBox.innerText = "Scanning... Place face in guide.";
        statusBox.style.background = "#eee";
        statusBox.style.color = "#555";
        guideRing.style.borderColor = "#00c6ff";
        guideRing.style.boxShadow = "0 0 20px rgba(0, 198, 255, 0.5)";
    }

</script>

</body>
</html>