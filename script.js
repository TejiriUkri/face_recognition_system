const video = document.getElementById('video');

// 1. Load the models (Ensure these files are in your /models folder)
Promise.all([
  faceapi.nets.tinyFaceDetector.loadFromUri('models'),
  faceapi.nets.faceLandmark68Net.loadFromUri('models')
]).then(startVideo);

function startVideo() {
  navigator.mediaDevices.getUserMedia({ video: {} })
    .then(stream => video.srcObject = stream)
    .catch(err => console.error("Camera access denied:", err));
}

video.addEventListener('play', () => {
  // Create an overlay canvas for visual feedback (the green box)
  const canvas = faceapi.createCanvasFromMedia(video);
  document.body.append(canvas);
  const displaySize = { width: video.width, height: video.height };
  faceapi.matchDimensions(canvas, displaySize);

  // Recognition Loop
  setInterval(async () => {
    // A. Detect face presence in the browser
    const detections = await faceapi.detectAllFaces(video, new faceapi.TinyFaceDetectorOptions());
    
    // Clear previous drawings
    canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
    const resizedDetections = faceapi.resizeResults(detections, displaySize);
    faceapi.draw.drawDetections(canvas, resizedDetections);

    if (detections.length > 0) {
      console.log("Face centered. Capturing snapshot for Python API...");

      // B. CAPTURE THE SNAPSHOT (Hidden Canvas)
      const captureCanvas = document.createElement('canvas');
      captureCanvas.width = video.videoWidth;
      captureCanvas.height = video.videoHeight;
      captureCanvas.getContext('2d').drawImage(video, 0, 0);
      
      // Convert image to Base64 string
      const imageData = captureCanvas.toDataURL('image/jpeg');

      // C. THE AJAX HANDSHAKE (Send to Flask)
      try {
        const response = await fetch('http://127.0.0.1:5000/verify', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ image: imageData })
        });

        const result = await response.json();

        if (result.status === 'success') {
          console.log(`Verified: ${result.name}`);
          
          // D. INFORM PHP (Mark Attendance)
          // We use AJAX again so the page doesn't reload
          markAttendanceInPHP(result.user_id);
        } else {
          console.warn("Match not found.");
        }
      } catch (error) {
        console.error("Connection to Flask API failed. Is app.py running?", error);
      }
    }
  }, 3000); // 3-second interval prevents overloading the Python server
});

// Function to talk to your PHP Backend
function markAttendanceInPHP(userId) {
    const formData = new FormData();
    formData.append('id', userId);

    fetch('log_attendance.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.text())
    .then(data => alert("Attendance logged for ID: " + userId))
    .catch(err => console.error("PHP Error:", err));
}