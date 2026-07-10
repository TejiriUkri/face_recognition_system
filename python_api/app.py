import os
import base64
import cv2
import json
import traceback
import urllib.request
import numpy as np
import face_recognition
import mysql.connector
from flask import Flask, request, jsonify
from flask_cors import CORS
from datetime import datetime, timedelta

app = Flask(__name__)
# Allows cross-origin requests for local development environments
CORS(app, resources={r"/*": {"origins": "*"}}, supports_credentials=True)

def get_db_connection():
    return mysql.connector.connect(
        host="localhost",
        user="root",
        password="",
        database="attendan_office_attendance"
    )

def get_network_or_local_time():
    """
    Attempts to fetch the authentic date/time from an online API.
    Falls back gracefully to the local system time if offline.
    """
    try:
        # Using a reliable, public time API
        url = "http://worldtimeapi.org/api/ip"
        with urllib.request.urlopen(url, timeout=3) as response:
            data = json.loads(response.read().decode())
            # Extract standard ISO timestamp up to seconds characters: YYYY-MM-DDTHH:MM:SS
            dt_str = data['datetime'][:19]
            now = datetime.strptime(dt_str, "%Y-%m-%dT%H:%M:%S")
            print(f"DEBUG: Successfully synchronized time via Network API: {now}")
            return now, now.date()
    except Exception as e:
        print(f"DEBUG: Network time sync failed ({e}). Defaulting to local system time.")
        now = datetime.now()
        return now, now.date()


@app.route('/verify', methods=['POST'])
def verify():
    conn = None
    cursor = None
    try:
        data = request.get_json()
        incoming_admin_id = data.get('admin_id')
        base64_string = data.get('image', '')

        if not base64_string:
            return jsonify({"status": "failed", "message": "No image data received"}), 400

        # 1. Strip the HTML Data URL header if present
        if "," in base64_string:
            base64_string = base64_string.split(",")[1]

        # 2. Decode base64 to image matrix
        image_data = base64.b64decode(base64_string)
        nparr = np.frombuffer(image_data, np.uint8)
        live_img = cv2.imdecode(nparr, cv2.IMREAD_COLOR)
        
        if live_img is None:
            return jsonify({"status": "failed", "message": "Invalid image format"}), 400
            
        live_rgb = cv2.cvtColor(live_img, cv2.COLOR_BGR2RGB)

        # 3. Downscale and Extract Facial Vectors
        small_frame = cv2.resize(live_rgb, (0, 0), fx=0.25, fy=0.25)
        live_encodings = face_recognition.face_encodings(small_frame, model="hog")
        
        if not live_encodings:
            return jsonify({"status": "failed", "message": "No face detected in frame"})
        
        live_encoding = live_encodings[0]

        # 4. Tenant-Isolated Database Query
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)
        
        print(f"DEBUG: Looking for faces belonging to Admin ID: {incoming_admin_id}")

        query = """
            SELECT f.user_id, f.full_name, f.face_encoding 
            FROM face_registeration f
            JOIN companies c ON f.company_id = c.id
            WHERE c.user_id = %s
        """
        cursor.execute(query, (incoming_admin_id,))
        records = cursor.fetchall()
        
        if not records:
            return jsonify({"status": "error", "message": "No registered records found for this company view."})

        # Parse DB data into explicit parallel arrays
        company_encodings = []
        company_names = []
        company_ids = []

        for record in records:
            db_string = record['face_encoding']
            if not db_string:
                continue
                
            try:
                # FORMAT 1: Saved as JSON array string
                if db_string.startswith('['):
                    encoding_math = np.array(json.loads(db_string))
                # FORMAT 2: Saved as flat raw text/binary
                else:
                    encoding_bytes = db_string.encode('latin-1')
                    encoding_math = np.frombuffer(encoding_bytes, dtype=np.float64)
                
                company_encodings.append(encoding_math)
                company_names.append(record['full_name'])
                company_ids.append(record['user_id'])
            except Exception as parse_err:
                print(f"DEBUG: Skipping broken encoding for row ID {record.get('user_id')}: {parse_err}")

        print(f"DEBUG: Evaluated {len(company_encodings)} face profiles.")
        if len(company_encodings) == 0:
            return jsonify({"status": "error", "message": "No valid face signatures ready for analysis."})

        # 5. Vector Distance Matching Engine
        distances = face_recognition.face_distance(company_encodings, live_encoding)
        best_match_index = np.argmin(distances)
        lowest_distance = distances[best_match_index]
        
        print(f"DEBUG: System closest distance score matching: {lowest_distance:.4f}")

        # Strict precision check (0.42 threshold filters out false metrics effectively)
        if lowest_distance < 0.42:
            name = company_names[best_match_index]
            user_id = company_ids[best_match_index]
            print(f"MATCH IDENTIFIED: {name} (Score: {lowest_distance:.4f})")
            
            # Fetch network time or local clock fallback
            now, today = get_network_or_local_time()

            # Fetch Configured Checkout Buffer settings
            cursor.execute("SELECT setting_value FROM system_settings WHERE setting_key = 'min_checkout_gap'")
            settings = cursor.fetchone()
            gap_minutes = int(settings['setting_value']) if settings else 30

            # Audit Check for matching existing daily row entries
            cursor.execute("SELECT * FROM attendance WHERE userid = %s AND log_date = %s", (user_id, today))
            attendance_record = cursor.fetchone()

            if not attendance_record:
                # Action: Perform Check-In
                cursor.execute(
                    "INSERT INTO attendance (userid, log_date, time_clock_in, status) VALUES (%s, %s, %s, 'IN')",
                    (user_id, today, now.strftime('%H:%M:%S'))
                )
                message = f"Welcome, {name}! Signed In."
                status_type = "success"
            
            elif attendance_record['status'] == 'IN':
                # Convert MySQL standard TIME object representation to standard Python datetime
                time_in = datetime.combine(today, (datetime.min + attendance_record['time_clock_in']).time())
                allowed_out_time = time_in + timedelta(minutes=gap_minutes)

                if now >= allowed_out_time:
                    # Action: Perform Check-Out
                    cursor.execute(
                        "UPDATE attendance SET time_clock_out = %s, status = 'OUT' WHERE id = %s",
                        (now.strftime('%H:%M:%S'), attendance_record['id'])
                    )
                    message = f"Goodbye, {name}! Signed Out."
                    status_type = "success"
                else:
                    remaining_minutes = int((allowed_out_time - now).total_seconds() / 60)
                    message = f"Already Clocked In. Checkout available in {remaining_minutes} mins."
                    status_type = "info"
            
            else:
                message = "Attendance logs complete for today."
                status_type = "info"

            conn.commit()
            return jsonify({
                "status": status_type, 
                "name": name, 
                "id": user_id, 
                "message": message
            })
        
        else:
            print("DEBUG: Distance exceeds recognition limit.")
            return jsonify({"status": "failed", "message": "Unknown face signature"})

    except mysql.connector.Error as db_err:
        print(f"CRITICAL DATABASE ERROR: {db_err}")
        return jsonify({"status": "error", "message": "Database interaction error occurring"}), 500
    except Exception as e:
        print("=== CRASH REPORT ===")
        traceback.print_exc()
        print("====================")
        return jsonify({"status": "error", "message": "Internal processing engine crash"}), 500
    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()
            print("DEBUG: Database connection safely returned to pool.")


@app.route('/calculate_encoding', methods=['POST'])
def calculate_encoding():
    data = request.json or {}
    base64_string = data.get('image')

    if not base64_string:
        return jsonify({"error": "No image provided"}), 400

    try:
        if "," in base64_string:
            base64_string = base64_string.split(",")[1]
            
        img_data = base64.b64decode(base64_string)
        nparr = np.frombuffer(img_data, np.uint8)
        img = cv2.imdecode(nparr, cv2.IMREAD_COLOR)
        
        if img is None:
            return jsonify({"error": "Failed to read image content"}), 400
            
        rgb_img = cv2.cvtColor(img, cv2.COLOR_BGR2RGB)
        face_locations = face_recognition.face_locations(rgb_img)
        
        if len(face_locations) == 0:
            return jsonify({"error": "No face found in image"}), 400
            
        face_encoding = face_recognition.face_encodings(rgb_img, face_locations)[0]
        return jsonify({"success": True, "encoding": face_encoding.tolist()})

    except Exception as e:
        return jsonify({"error": str(e)}), 500


@app.route('/refresh', methods=['GET'])
def refresh():
    return jsonify({"status": "success", "message": "Cache logic bypassed for real-time isolation queries"})


if __name__ == '__main__':
    # Running directly with debug=False avoids launching secondary monitoring threads
    app.run(debug=False, host='0.0.0.0', port=5000)