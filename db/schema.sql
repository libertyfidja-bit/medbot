-- MedBot Database Schema
CREATE DATABASE IF NOT EXISTS medbot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE medbot;

CREATE TABLE IF NOT EXISTS patients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  age INT,
  room_number VARCHAR(20),
  condition_notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS medications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rxcui VARCHAR(50),
  name VARCHAR(200) NOT NULL,
  generic_name VARCHAR(200),
  form VARCHAR(100),
  strength VARCHAR(100),
  color VARCHAR(50) DEFAULT '#6c63ff'
);

CREATE TABLE IF NOT EXISTS prescriptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  medication_id INT NOT NULL,
  dosage_mg DECIMAL(10,2) NOT NULL,
  frequency_per_day INT NOT NULL DEFAULT 1,
  start_date DATE NOT NULL,
  end_date DATE,
  instructions TEXT,
  status ENUM('active','completed','paused') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  FOREIGN KEY (medication_id) REFERENCES medications(id)
);

CREATE TABLE IF NOT EXISTS dose_schedule (
  id INT AUTO_INCREMENT PRIMARY KEY,
  prescription_id INT NOT NULL,
  scheduled_date DATE NOT NULL,
  scheduled_time TIME NOT NULL,
  status ENUM('pending','dispensed','taken','missed','skipped') DEFAULT 'pending',
  taken_at TIMESTAMP NULL,
  dispensed_by_robot TINYINT(1) DEFAULT 0,
  notes TEXT,
  FOREIGN KEY (prescription_id) REFERENCES prescriptions(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS interaction_alerts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  prescription_id INT,
  severity ENUM('info','warning','danger') DEFAULT 'warning',
  drug_a VARCHAR(200),
  drug_b VARCHAR(200),
  description TEXT,
  flagged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  resolved TINYINT(1) DEFAULT 0,
  FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS robot_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  action_type ENUM('pick','place','navigate','dispense','home','alert') NOT NULL,
  medication_name VARCHAR(200),
  patient_name VARCHAR(150),
  from_location VARCHAR(100),
  to_location VARCHAR(100),
  status ENUM('in_progress','completed','failed') DEFAULT 'completed',
  metadata JSON,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS robot_state (
  id INT PRIMARY KEY DEFAULT 1,
  current_action VARCHAR(200) DEFAULT 'idle',
  current_location VARCHAR(100) DEFAULT 'pharmacy',
  arm_position JSON,
  last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO robot_state (id, current_action, current_location, arm_position)
VALUES (1, 'idle', 'pharmacy', '{"x":0,"y":0,"z":0,"rotation":0}');
