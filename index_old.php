<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Face Attendance System</title>
<script src="js/face-api.min.js"></script>
<script defer src="script.js"></script>
    <style>
        body {
            margin: 0;
            padding: 0;
            width: 100vw;
            height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            background-color: #f0f2f5;
            font-family: Arial, sans-serif;
        }
        canvas {
            position: absolute;
        }
        .container {
            position: relative;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            border-radius: 10px;
            overflow: hidden;
            background: #000;
        }
        #status-box {
            margin-top: 20px;
            padding: 15px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            width: 400px;
            text-align: center;
        }

        
    </style>
</head>
<body>

    <h2>Scan Face for Attendance</h2>

    <div class="container">
        <video id="video" width="640" height="480" autoplay muted></video>
    </div>

    <div id="status-box">
        <p id="message">Initializing AI Models...</p>
    </div>

    <script>
        const video = document.getElementById('video');
        const message = document.getElementById('message');

        // 1. START THE SYSTEM
        async function init() {
            try {
                // Change 'models' to the actual path where your .json and .bin files are
                await faceapi.nets.tinyFaceDetector.loadFromUri('models');
                await faceapi.nets.faceLandmark68Net.loadFromUri('models');
                
                message.innerText = "Models Loaded. Starting Camera...";
                startVideo();
            } catch (e) {
                message.innerText = "Error loading models. Check 'models' folder.";
                console.error(e);
            }
        }

        function startVideo() {
            navigator.mediaDevices.getUserMedia({ video: {} })
                .then(stream => {
                    video.srcObject = stream;
                    message.innerText = "System Ready. Please center your face.";
                })
                .catch(err => {
                    message.innerText = "Camera access denied.";
                    console.error(err);
                });
        }

        video.addEventListener('play', () => {
            const canvas = faceapi.createCanvasFromMedia(video);
            document.querySelector('.container').append(canvas);
            const displaySize = { width: video.width, height: video.height };
            faceapi.matchDimensions(canvas, displaySize);

            // RUN DETECTION EVERY 2 SECONDS
            setInterval(async () => {
                const detections = await faceapi.detectAllFaces(video, new faceapi.TinyFaceDetectorOptions());
                
                // Draw green boxes visually
                canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
                const resizedDetections = faceapi.resizeResults(detections, displaySize);
                faceapi.draw.drawDetections(canvas, resizedDetections);

                if (detections.length > 0) {
                    message.innerText = "Face Detected! Verifying with Server...";
                    captureAndVerify();
                }
            }, 2000);
        });

        // 2. CAPTURE & SEND TO FLASK (PHASE 1)
        async function captureAndVerify() {
            const captureCanvas = document.createElement('canvas');
            captureCanvas.width = video.videoWidth;
            captureCanvas.height = video.videoHeight;
            captureCanvas.getContext('2d').drawImage(video, 0, 0);
            const imageData = captureCanvas.toDataURL('image/jpeg');

            try {
                const response = await fetch('http://127.0.0.1:5000/verify', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ image: imageData })
                });

                const result = await response.json();

                if (result.status === 'success') {
                    message.innerHTML = `<b style="color:green">Welcome, ${result.name}!</b>`;
                    // 3. SEND TO PHP BACKEND (PHASE 3)
                    markAttendance(result.user_id);
                } else {
                    message.innerText = "Access Denied: Face not recognized.";
                }
            } catch (err) {
                message.innerText = "Error: Flask API is not running.";
            }
        }

        function markAttendance(userId) {
            const data = new FormData();
            data.append('id', userId);

            fetch('log_attendance.php', {
                method: 'POST',
                body: data
            })
            .then(res => res.text())
            .then(text => console.log("PHP Response: " + text));
        }

        init();
    </script>
</body>
</html>