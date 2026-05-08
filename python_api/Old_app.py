import cv2
import mysql.connector
import numpy as np
import base64
import face_recognition
from flask import Flask, request, jsonify
from flask_cors import CORS

app = Flask(__name__)
CORS(app, resources={r"/*": {"origins": "*"}})# This allows your PHP site to talk to this Python script

# 1. CONNECT TO YOUR DATABASE
def get_db_connection():
    return mysql.connector.connect(
        host="localhost",
        user="root",
        password="",
        database="attendance_system"
    )

# 2. THE RECOGNITION ENGINE
@app.route('/verify', methods=['POST'])
def verify():
    conn = None # Initialize to avoid errors in finally block
    try:
        print("\n--- STEP 1: Receiving Data ---")
        data = request.get_json()
        if not data or 'image' not in data:
            print("FAILED: No image in JSON")
            return jsonify({"status": "error", "message": "No image received"}), 400
            
        # 1. Clean the incoming string
        print("--- STEP 2: Stripping Header & Decoding Base64 ---")

        try:
            image_data_string = data['image']
            if "," in image_data_string:
                 # Strip "data:image/jpeg;base64," if it exists
                image_data_string = image_data_string.split(",")[1]
            
                # 2. Decode and convert to Numpy array
                image_bytes = base64.b64decode(image_data_string)
                conn = get_db_connection()
                cursor = conn.cursor()
        except Exception as e:
               print(f"FAILED at Step 2: {e}")
               return jsonify({"status": "error", "message": "Base64 decode failed"}), 400 
        
        print("--- STEP 3: Converting to OpenCV Format (imdecode) ---")
        nparr = np.frombuffer(image_bytes, np.uint8)
        live_img = cv2.imdecode(nparr, cv2.IMREAD_COLOR)
        
        if live_img is None:
            print("FAILED at Step 3: OpenCV returned None. Image data is likely corrupt.")
            return jsonify({"status": "error", "message": "Failed to decode live image"}), 400

        print(f"--- STEP 4: Color Conversion (BGR to RGB) ---")    
        live_img_rgb = cv2.cvtColor(live_img, cv2.COLOR_BGR2RGB)

        print(f"--- STEP 5: Forcing 8-bit Integer Format ---")
        # 1. Ensure 8-bit
        live_img_rgb = np.uint8(live_img_rgb)
        
        # 2. Force RGB (removes any hidden channels)
        if live_img_rgb.shape[2] == 4:
            live_img_rgb = live_img_rgb[:, :, :3]
            
        # 3. Force C-Contiguous memory (This is the most common fix for Step 6 crashes)
        live_img_rgb = np.ascontiguousarray(live_img_rgb)

        print(f"Image Type: {live_img_rgb.dtype}, Shape: {live_img_rgb.shape}")

        print("--- STEP 6: AI Face Encoding (The strict part) ---")
        # This is where 'Unsupported image type' usually triggers
        # live_img_rgb = np.ascontiguousarray(live_img_rgb)
        # live_encodings = face_recognition.face_encodings(live_img_rgb)
        # print(f"Found {len(live_encodings)} faces in live feed.")
        try:
            # We use a lower model 'hog' for testing to see if it bypasses the error
            live_encodings = face_recognition.face_encodings(live_img_rgb, model="hog")
            print(f"Found {len(live_encodings)} faces.")
        except Exception as e:
            print(f"STEP 6 INTERNAL FAILURE: {e}")
            raise e
        
            
        if len(live_encodings) > 0:
            print("--- STEP 7: Database Comparison Loop ---")
            conn = get_db_connection()
            cursor = conn.cursor()
            cursor.execute("SELECT id, name, photo_blob FROM students")
            students = cursor.fetchall()

            for (id, name, blob) in students:
                print(f"Checking Student: {name} (ID: {id})...")

                # 1. Convert BLOB to numpy array
                student_img_arr = np.frombuffer(blob, np.uint8)
                # 2. Decode the image
                student_img = cv2.imdecode(student_img_arr, cv2.IMREAD_COLOR)
                
                # --- CRITICAL CHECK ---
                if student_img is None:
                    print(f"SKIPPING: Student '{name}' (ID: {id}) has a corrupt photo in DB.")
                    continue 
                else:
                      print(f"DATABASE OK: Successfully loaded photo for {name}. Shape: {student_img.shape}")
                      
                student_rgb = cv2.cvtColor(student_img, cv2.COLOR_BGR2RGB)
                student_rgb = np.array(student_rgb, dtype='uint8')
                student_rgb = np.ascontiguousarray(student_rgb) # Fixes memory layout issues

                try:

                    # 3. Get encoding for database image
                    db_encs = face_recognition.face_encodings(student_rgb)
                    if not db_encs:
                        continue
                        
                    # 4. Compare
                    match = face_recognition.compare_faces([db_encs[0]], live_encodings[0])
                    

                    # Calculate the numerical distance (lower is better)
                    face_distances = face_recognition.face_distance([db_encs[0]], live_encodings[0])
                    score = face_distances[0]

                    print(f"Checking {name} - Match Score: {score:.4f}")

                    # 0.4 is a very strong match. 0.6 is a weak/risky match.
                    if score < 0.45:
                        print(f"  [+] STRONG MATCH FOUND: {name}")
                        conn.close()
                        return jsonify({"status": "success", "user_id": id, "name": name})
                    else:
                        print(f"  [-] Distance too high ({score:.4f}) for {name}")

                    # if match[0]:
                    #     print(f"  [+] MATCH FOUND: {name}")
                    #     conn.close()
                    #     return jsonify({"status": "success", "user_id": id, "name": name})
                    
                except Exception as e:
                    # This will tell us if a SPECIFIC student causes the 8-bit error
                    print(f"  [!!!] CRASHED ON STUDENT {name}: {e}")
                    continue

            conn.close()
            return jsonify({"status": "failed", "message": "No match found"})
            
        return jsonify({"status": "failed", "message": "No face detected in camera"})

    except Exception as e:
        print(f"CRASH ERROR: {str(e)}")
        return jsonify({"status": "error", "message": str(e)}), 500
    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()
            print("Database connection closed safely.")
    
if __name__ == '__main__':
    app.run(port=5000, debug=True)