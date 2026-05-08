import os
import base64
import cv2
import numpy as np
import face_recognition
import mysql.connector
from flask import Flask, request, jsonify
from flask_cors import CORS
from datetime import datetime, timedelta

app = Flask(__name__)
# This is the "2026 Standard" for allowing local development requests
CORS(app, resources={r"/*": {"origins": "*"}}, supports_credentials=True)

# --- GLOBAL CACHE ---
KNOWN_ENCODINGS = []
KNOWN_NAMES = []
KNOWN_IDS = []

def get_db_connection():
    return mysql.connector.connect(
        host="localhost",
        user="root",
        password="",
        database="attendance_system"
    )

def load_attendance_cache():
    """Pre-loads all student face math into RAM for instant matching."""
    global KNOWN_ENCODINGS, KNOWN_NAMES, KNOWN_IDS
    KNOWN_ENCODINGS, KNOWN_NAMES, KNOWN_IDS = [], [], []
    
    print("--- CACHING DATABASE: PLEASE WAIT ---")
    conn = get_db_connection()
    cursor = conn.cursor()
    cursor.execute("SELECT id, name, photo_blob FROM students")
    
    for (id, name, blob) in cursor.fetchall():
        nparr = np.frombuffer(blob, np.uint8)
        img = cv2.imdecode(nparr, cv2.IMREAD_COLOR)
        if img is not None:
            rgb_img = cv2.cvtColor(img, cv2.COLOR_BGR2RGB)
            encodings = face_recognition.face_encodings(rgb_img)
            if encodings:
                KNOWN_ENCODINGS.append(encodings[0])
                KNOWN_NAMES.append(name)
                KNOWN_IDS.append(id)
    
    conn.close()
    print(f"--- SUCCESS: {len(KNOWN_NAMES)} STUDENTS LOADED ---")

# LOAD CACHE ON STARTUP
load_attendance_cache()

@app.route('/verify', methods=['POST'])
def verify():
    try:
        data = request.get_json()
        header, encoded = data['image'].split(",", 1)
        image_data = base64.b64decode(encoded)
        
        # Process Live Image
        nparr = np.frombuffer(image_data, np.uint8)
        live_img = cv2.imdecode(nparr, cv2.IMREAD_COLOR)
        live_rgb = cv2.cvtColor(live_img, cv2.COLOR_BGR2RGB)

        # Detect Face
        small_frame = cv2.resize(live_rgb, (0, 0), fx=0.25, fy=0.25)
        live_encodings = face_recognition.face_encodings(small_frame, model="hog")
        
        if not live_encodings:
            return jsonify({"status": "failed", "message": "No face detected"})

        # FAST COMPARISON (Uses vector math instead of a loop)
        if KNOWN_ENCODINGS:
            # face_distance returns a list of distances for ALL students at once
            distances = face_recognition.face_distance(KNOWN_ENCODINGS, live_encodings[0])
            best_match_index = np.argmin(distances)
            

            # STRICTOR THRESHOLD: 0.4 ensures high accuracy for schools
            if distances[best_match_index] < 0.42:
                name = KNOWN_NAMES[best_match_index]
                user_id = KNOWN_IDS[best_match_index]
                print(f"MATCH: {name} (Score: {distances[best_match_index]:.4f})")
                
            
                try:
                    conn = get_db_connection()
                    cursor = conn.cursor(dictionary=True)
                    
                    # 1. FETCH ADMIN SETTING
                    cursor.execute("SELECT setting_value FROM system_settings WHERE setting_key = 'min_checkout_gap'")
                    settings = cursor.fetchone()
                    gap_minutes = settings['setting_value'] if settings else 30 # Fallback to 30

                    today = datetime.now().date()
                    now = datetime.now()

                    cursor.execute("SELECT * FROM attendance WHERE student_id = %s AND date = %s", (user_id, today))
                    record = cursor.fetchone()
                    print(f"DEBUG: Attempting to log ID: {user_id}, Name: {name}, Date: {today}")
                    if not record:
                        # ACTION: SIGN IN
                        print(f"DEBUG: Attempting to log ID: {user_id}, Name: {name}, Date: {today}")
                        cursor.execute(
                            "INSERT INTO attendance (student_id, student_name, date, time_in, status) VALUES (%s, %s, %s, %s, 'IN')",
                            (user_id, name, today, now.strftime('%H:%M:%S'))
                        )
                        message = f"Welcome, {name}! Signed In."
                        status_type = "success"
                    
                    elif record['status'] == 'IN':
                        # 2. CHECK AGAINST ADMIN TIME LIMIT
                        time_in = datetime.combine(today, (datetime.min + record['time_in']).time())
                        allowed_out_time = time_in + timedelta(minutes=gap_minutes)

                        if now >= allowed_out_time:
                            cursor.execute(
                                "UPDATE attendance SET time_out = %s, status = 'OUT' WHERE id = %s",
                                (now.strftime('%H:%M:%S'), record['id'])
                            )
                            message = f"Goodbye, {name}! Signed Out."
                            status_type = "success"
                        else:
                            # Calculate remaining minutes for better UX
                            remaining = int((allowed_out_time - now).total_seconds() / 60)
                            message = f"Already In. Checkout available in {remaining} mins."
                            status_type = "info"
                    
                    else:
                        message = "Attendance complete for today."
                        status_type = "info"

                    conn.commit()
                    
                
                except mysql.connector.Error as db_err:
                    # This prints the SPECIFIC MySQL error to your terminal
                    print(f"CRITICAL DATABASE ERROR: {db_err}")
                        
                except Exception as e:
                    # This prints any other Python error (like logic or math errors)
                    print(f"GENERAL PYTHON ERROR: {e}")

                finally:
                    if 'conn' in locals() and conn.is_connected():
                        cursor.close()
                        conn.close()            
                        # return jsonify({"status": status_type, "name": name, "message": message})
                        return jsonify({"status": "success", "name": name, "id": user_id})

        return jsonify({"status": "failed", "message": "Unknown face"})

    except Exception as e:
        print(f"Server Error: {e}")
        return jsonify({"status": "error", "message": "Internal server error"}), 500

# Add a route to refresh the cache if you add new students
@app.route('/refresh', methods=['GET'])
def refresh():
    load_attendance_cache()
    return jsonify({"status": "success", "message": "Cache updated"})

if __name__ == '__main__':
    app.run(debug=False, port=5000) # Debug=False is faster and more stable