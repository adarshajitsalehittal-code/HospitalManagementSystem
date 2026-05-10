-- ============================================================
-- Medicare HMS — Complete Database Schema + Seed Data
-- Compatible with: MySQL 8.0+ / MariaDB 10.5+
--
-- HOW TO IMPORT:
--   Option 1 (command line):
--     mysql -u root -p < hms_database.sql
--
--   Option 2 (phpMyAdmin):
--     Open phpMyAdmin → Import tab → Choose this file → Go
--
-- DEFAULT LOGIN PASSWORDS (used by api.php):
--   Admin       → Admin@2024
--   Doctor      → Doctor@2024
--   Nurse       → Nurse@2024
--   Receptionist→ Recept@2024
--   Patient     → Patient@123  (or their mobile number if registered via website)
-- ============================================================

DROP DATABASE IF EXISTS medicare_hms;
CREATE DATABASE medicare_hms
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE medicare_hms;

-- ============================================================
-- 1. ADMINS TABLE
-- ============================================================
CREATE TABLE admins (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(100)  NOT NULL,
  email      VARCHAR(150)  NOT NULL UNIQUE,
  password   VARCHAR(255)  NOT NULL,
  mobile     VARCHAR(15),
  status     ENUM('active','inactive') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- 2. DEPARTMENTS TABLE
-- ============================================================
CREATE TABLE departments (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(100) NOT NULL UNIQUE,
  description    TEXT,
  floor          TINYINT      DEFAULT 0,
  total_beds     SMALLINT     DEFAULT 0,
  available_beds SMALLINT     DEFAULT 0,
  head_doctor_id INT          DEFAULT NULL,   -- FK added after doctors table
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- 3. DOCTORS TABLE
-- ============================================================
CREATE TABLE doctors (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(100) NOT NULL,
  email            VARCHAR(150) NOT NULL UNIQUE,
  password         VARCHAR(255) NOT NULL,
  specialization   VARCHAR(100) NOT NULL,
  department_id    INT          DEFAULT NULL,
  qualification    VARCHAR(200),
  experience_years TINYINT      DEFAULT 0,
  mobile           VARCHAR(15),
  consultation_fee DECIMAL(8,2) DEFAULT 0,
  available_days   VARCHAR(100),
  status           ENUM('active','inactive','on-leave') DEFAULT 'active',
  joined_date      DATE,
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
);

-- Add department head FK now that doctors table exists
ALTER TABLE departments
  ADD CONSTRAINT fk_dept_head
  FOREIGN KEY (head_doctor_id) REFERENCES doctors(id) ON DELETE SET NULL;

-- ============================================================
-- 4. NURSES TABLE
-- ============================================================
CREATE TABLE nurses (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100) NOT NULL,
  email       VARCHAR(150) NOT NULL UNIQUE,
  password    VARCHAR(255) NOT NULL,
  ward        VARCHAR(100),
  shift       ENUM('Morning','Evening','Night') DEFAULT 'Morning',
  mobile      VARCHAR(15),
  status      ENUM('active','inactive') DEFAULT 'active',
  joined_date DATE,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- 5. RECEPTIONISTS TABLE
-- ============================================================
CREATE TABLE receptionists (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100) NOT NULL,
  email       VARCHAR(150) NOT NULL UNIQUE,
  password    VARCHAR(255) NOT NULL,
  mobile      VARCHAR(15),
  shift       ENUM('Morning','Evening','Night') DEFAULT 'Morning',
  status      ENUM('active','inactive') DEFAULT 'active',
  joined_date DATE,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- 6. PATIENTS TABLE
-- ============================================================
CREATE TABLE patients (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  name              VARCHAR(100) NOT NULL,
  email             VARCHAR(150) NOT NULL UNIQUE,
  password          VARCHAR(255) NOT NULL,
  age               TINYINT UNSIGNED DEFAULT NULL,
  gender            ENUM('Male','Female','Other','') DEFAULT '',
  mobile            VARCHAR(15),
  blood_group       VARCHAR(5),
  address           TEXT,
  emergency_contact VARCHAR(15),
  joined_date       DATE,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- 7. BEDS TABLE
-- ============================================================
CREATE TABLE beds (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  bed_number  VARCHAR(20) NOT NULL UNIQUE,
  ward        VARCHAR(100),
  type        ENUM('General','ICU','Private','Semi-Private') DEFAULT 'General',
  status      ENUM('available','occupied','maintenance') DEFAULT 'available',
  patient_id  INT         DEFAULT NULL,
  admitted_at TIMESTAMP   DEFAULT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE SET NULL
);

-- ============================================================
-- 8. APPOINTMENTS TABLE
-- ============================================================
CREATE TABLE appointments (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  patient_id       INT  NOT NULL,
  doctor_id        INT  NOT NULL,
  appointment_date DATE NOT NULL,
  appointment_time TIME NOT NULL,
  token_number     SMALLINT DEFAULT NULL,
  status           ENUM('scheduled','completed','cancelled','no-show') DEFAULT 'scheduled',
  reason           VARCHAR(255),
  type             ENUM('in-person','telemedicine') DEFAULT 'in-person',
  created_by       ENUM('patient','receptionist','admin') DEFAULT 'patient',
  checked_in       BOOLEAN DEFAULT FALSE,
  checked_out      BOOLEAN DEFAULT FALSE,
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  FOREIGN KEY (doctor_id)  REFERENCES doctors(id)  ON DELETE CASCADE
);

-- Auto-assign token number per doctor per day
DELIMITER $$
CREATE TRIGGER trg_appointment_token
BEFORE INSERT ON appointments
FOR EACH ROW
BEGIN
  IF NEW.token_number IS NULL THEN
    SELECT COALESCE(MAX(token_number),0)+1
      INTO NEW.token_number
      FROM appointments
     WHERE doctor_id = NEW.doctor_id
       AND appointment_date = NEW.appointment_date;
  END IF;
END$$
DELIMITER ;

-- ============================================================
-- 9. PRESCRIPTIONS TABLE
-- ============================================================
CREATE TABLE prescriptions (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  patient_id        INT  NOT NULL,
  doctor_id         INT  NOT NULL,
  appointment_id    INT  DEFAULT NULL,
  diagnosis         TEXT,
  notes             TEXT,
  follow_up_date    DATE DEFAULT NULL,
  prescription_date DATE NOT NULL,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (patient_id)    REFERENCES patients(id)     ON DELETE CASCADE,
  FOREIGN KEY (doctor_id)     REFERENCES doctors(id)      ON DELETE CASCADE,
  FOREIGN KEY (appointment_id)REFERENCES appointments(id) ON DELETE SET NULL
);

-- Prescription medicines (one prescription → many medicines)
CREATE TABLE prescription_medicines (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  prescription_id INT          NOT NULL,
  medicine_name   VARCHAR(150) NOT NULL,
  dosage          VARCHAR(100),
  frequency       VARCHAR(100),
  duration        VARCHAR(100),
  instructions    VARCHAR(255),
  FOREIGN KEY (prescription_id) REFERENCES prescriptions(id) ON DELETE CASCADE
);

-- ============================================================
-- 10. LAB REPORTS TABLE
-- ============================================================
CREATE TABLE lab_reports (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  patient_id   INT          NOT NULL,
  doctor_id    INT          NOT NULL,
  test_name    VARCHAR(200) NOT NULL,
  test_date    DATE         NOT NULL,
  result       TEXT,
  normal_range VARCHAR(255),
  status       ENUM('pending','completed','cancelled') DEFAULT 'pending',
  remarks      TEXT,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  FOREIGN KEY (doctor_id)  REFERENCES doctors(id)  ON DELETE CASCADE
);

-- ============================================================
-- 11. BILLING TABLE
-- ============================================================
CREATE TABLE bills (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  patient_id      INT            NOT NULL,
  appointment_id  INT            DEFAULT NULL,
  invoice_number  VARCHAR(50)    NOT NULL UNIQUE,
  total_amount    DECIMAL(10,2)  DEFAULT 0,
  paid_amount     DECIMAL(10,2)  DEFAULT 0,
  discount        DECIMAL(10,2)  DEFAULT 0,
  status          ENUM('pending','paid','partial','cancelled') DEFAULT 'pending',
  payment_method  ENUM('Cash','Card','UPI','Insurance','Online') DEFAULT 'Cash',
  bill_date       DATE           NOT NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (patient_id)     REFERENCES patients(id)     ON DELETE CASCADE,
  FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL
);

-- Bill line items (one bill → many items)
CREATE TABLE bill_items (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  bill_id     INT           NOT NULL,
  description VARCHAR(255)  NOT NULL,
  quantity    SMALLINT      DEFAULT 1,
  unit_price  DECIMAL(10,2) DEFAULT 0,
  total_price DECIMAL(10,2) DEFAULT 0,
  FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE
);

-- ============================================================
-- 12. VITALS TABLE
-- ============================================================
CREATE TABLE vitals (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  patient_id       INT           NOT NULL,
  nurse_id         INT           DEFAULT NULL,
  blood_pressure   VARCHAR(20),
  temperature      DECIMAL(4,1),
  pulse_rate       SMALLINT,
  respiratory_rate SMALLINT,
  spo2             TINYINT,
  weight           DECIMAL(5,1),
  height           DECIMAL(5,1),
  notes            TEXT,
  recorded_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  FOREIGN KEY (nurse_id)   REFERENCES nurses(id)   ON DELETE SET NULL
);

-- ============================================================
-- 13. MEDICINES / PHARMACY TABLE
-- ============================================================
CREATE TABLE medicines (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(200) NOT NULL,
  generic_name  VARCHAR(200),
  category      VARCHAR(100),
  unit          VARCHAR(50),
  stock         INT          DEFAULT 0,
  reorder_level INT          DEFAULT 10,
  unit_price    DECIMAL(8,2) DEFAULT 0,
  expiry_date   DATE,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- 14. MEDICATION SCHEDULE TABLE
-- ============================================================
CREATE TABLE medication_schedule (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  patient_id     INT          NOT NULL,
  nurse_id       INT          DEFAULT NULL,
  medicine       VARCHAR(200) NOT NULL,
  dosage         VARCHAR(100),
  scheduled_time TIME         NOT NULL,
  scheduled_date DATE         NOT NULL,
  is_given       BOOLEAN      DEFAULT FALSE,
  notes          TEXT,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  FOREIGN KEY (nurse_id)   REFERENCES nurses(id)   ON DELETE SET NULL
);

-- ============================================================
-- 15. DOCTOR INSTRUCTIONS TABLE
-- ============================================================
CREATE TABLE doctor_instructions (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  patient_id       INT  NOT NULL,
  doctor_id        INT  NOT NULL,
  instruction      TEXT NOT NULL,
  priority         ENUM('low','medium','high') DEFAULT 'medium',
  status           ENUM('pending','done') DEFAULT 'pending',
  instruction_date DATE NOT NULL,
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  FOREIGN KEY (doctor_id)  REFERENCES doctors(id)  ON DELETE CASCADE
);

-- ============================================================
-- 16. MESSAGES TABLE (Patient ↔ Doctor chat)
-- ============================================================
CREATE TABLE messages (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  from_type ENUM('patient','doctor','nurse','receptionist','admin') NOT NULL,
  from_id   INT     NOT NULL,
  to_type   ENUM('patient','doctor','nurse','receptionist','admin') NOT NULL,
  to_id     INT     NOT NULL,
  message   TEXT    NOT NULL,
  is_read   BOOLEAN DEFAULT FALSE,
  sent_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- 17. HEALTH TRACKING TABLE
-- ============================================================
CREATE TABLE health_tracking (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  patient_id       INT          NOT NULL,
  log_date         DATE         NOT NULL,
  steps            INT          DEFAULT 0,
  water_litres     DECIMAL(4,2) DEFAULT 0,
  sleep_hours      DECIMAL(4,2) DEFAULT 0,
  exercise_minutes SMALLINT     DEFAULT 0,
  calories         SMALLINT     DEFAULT 0,
  mood             VARCHAR(50),
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_patient_date (patient_id, log_date),
  FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
);

-- ============================================================
-- SEED DATA — Default accounts for testing
-- ============================================================

-- Admin (login: admin@medicare.com / Admin@2024)
INSERT INTO admins (name, email, password, mobile, status) VALUES
('Super Admin', 'admin@medicare.com', 'Admin@2024', '9999999999', 'active');

-- Departments
INSERT INTO departments (name, description, floor, total_beds, available_beds) VALUES
('Cardiology',  'Heart and cardiovascular care',       2, 20, 14),
('Neurology',   'Brain and nervous system disorders',  3, 15,  9),
('Orthopedics', 'Bone, joint and muscle care',         2, 18, 12),
('Emergency',   '24/7 emergency and trauma care',      0, 10,  3),
('Pediatrics',  'Healthcare for infants and children', 1, 25, 18);

-- Doctors (login password: Doctor@2024 for all)
INSERT INTO doctors (name, email, password, specialization, department_id, qualification, experience_years, mobile, consultation_fee, available_days, status, joined_date) VALUES
('Dr. Aditya Sharma', 'dr.sharma@medicare.com', 'Doctor@2024', 'Cardiology',  1, 'MBBS, MD Cardiology',  12, '9876543210', 500.00, 'Mon,Tue,Wed,Thu,Fri', 'active', '2020-01-15'),
('Dr. Priya Nair',    'dr.nair@medicare.com',   'Doctor@2024', 'Neurology',   2, 'MBBS, DM Neurology',    8, '9876543211', 600.00, 'Mon,Wed,Fri',         'active', '2021-03-10'),
('Dr. Ramesh Gupta',  'dr.gupta@medicare.com',  'Doctor@2024', 'Orthopedics', 3, 'MBBS, MS Ortho',       15, '9876543212', 450.00, 'Tue,Thu,Sat',         'active', '2019-06-01');

-- Set department heads
UPDATE departments SET head_doctor_id = 1 WHERE id = 1;
UPDATE departments SET head_doctor_id = 2 WHERE id = 2;
UPDATE departments SET head_doctor_id = 3 WHERE id = 3;

-- Nurses (login password: Nurse@2024 for all)
INSERT INTO nurses (name, email, password, ward, shift, mobile, status, joined_date) VALUES
('Nurse Sunita Devi', 'nurse.sunita@medicare.com',  'Nurse@2024', 'General Ward', 'Morning', '9123456789', 'active', '2022-02-01'),
('Nurse Kavitha Rao', 'nurse.kavitha@medicare.com', 'Nurse@2024', 'ICU',          'Evening', '9123456790', 'active', '2022-05-15');

-- Receptionists (login password: Recept@2024 for all)
INSERT INTO receptionists (name, email, password, mobile, shift, status, joined_date) VALUES
('Meena Patel', 'reception@medicare.com', 'Recept@2024', '9988776655', 'Morning', 'active', '2023-01-10');

-- Patients (login password: Patient@123 for all)
-- NOTE: New patients registered via website use their MOBILE NUMBER as password
INSERT INTO patients (name, email, password, age, gender, mobile, blood_group, address, emergency_contact, joined_date) VALUES
('Ravi Kumar',   'ravi@email.com',   'Patient@123', 35, 'Male',   '9001234567', 'O+', 'Bengaluru', '9001234568', '2024-01-10'),
('Anjali Singh', 'anjali@email.com', 'Patient@123', 28, 'Female', '9001234568', 'A+', 'Mumbai',    '9001234569', '2024-02-15');

-- Beds
INSERT INTO beds (bed_number, ward, type, status, patient_id) VALUES
('A-101', 'General Ward', 'General', 'available', NULL),
('A-102', 'General Ward', 'General', 'occupied',  1),
('B-201', 'ICU',          'ICU',     'available', NULL),
('C-301', 'Private',      'Private', 'available', NULL);

-- Appointments
INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, status, reason, type, created_by) VALUES
(1, 1, CURDATE(), '10:00:00', 'scheduled', 'Chest pain', 'in-person', 'patient'),
(2, 2, CURDATE(), '11:00:00', 'completed', 'Headache',   'in-person', 'receptionist');

-- Prescriptions
INSERT INTO prescriptions (patient_id, doctor_id, appointment_id, diagnosis, notes, follow_up_date, prescription_date) VALUES
(2, 2, 2, 'Tension Headache', 'Rest and avoid screen time', DATE_ADD(CURDATE(), INTERVAL 7 DAY), CURDATE());

INSERT INTO prescription_medicines (prescription_id, medicine_name, dosage, frequency, duration, instructions) VALUES
(1, 'Paracetamol 500mg', '1 tablet', 'Twice daily', '5 days', 'After meals'),
(1, 'Ibuprofen 400mg',   '1 tablet', 'Once daily',  '3 days', 'With food');

-- Lab Reports
INSERT INTO lab_reports (patient_id, doctor_id, test_name, test_date, result, normal_range, status, remarks) VALUES
(2, 2, 'CBC (Complete Blood Count)', CURDATE(), 'WBC: 7200, RBC: 4.5M, Hb: 13.5g/dL', 'WBC: 4000-11000', 'completed', 'Normal range');

-- Bills
INSERT INTO bills (patient_id, appointment_id, invoice_number, total_amount, paid_amount, discount, status, payment_method, bill_date) VALUES
(2, 2, 'INV-20240215-001', 1200.00, 1200.00, 0, 'paid', 'Cash', CURDATE());

INSERT INTO bill_items (bill_id, description, quantity, unit_price, total_price) VALUES
(1, 'Consultation Fee', 1, 600.00, 600.00),
(1, 'Lab Tests',        1, 600.00, 600.00);

-- Vitals
INSERT INTO vitals (patient_id, nurse_id, blood_pressure, temperature, pulse_rate, respiratory_rate, spo2, weight, height, notes) VALUES
(2, 1, '120/80', 98.6, 72, 16, 98, 58, 162, 'Normal');

-- Medicines
INSERT INTO medicines (name, generic_name, category, unit, stock, reorder_level, unit_price, expiry_date) VALUES
('Paracetamol 500mg', 'Acetaminophen', 'Analgesic',    'Tablet',  500, 50, 2.50, '2026-12-31'),
('Amoxicillin 250mg', 'Amoxicillin',   'Antibiotic',   'Capsule', 200, 30, 8.00, '2026-06-30'),
('Metformin 500mg',   'Metformin HCl', 'Antidiabetic', 'Tablet',  350, 40, 5.00, '2026-09-30'),
('Ibuprofen 400mg',   'Ibuprofen',     'NSAID',        'Tablet',   15, 50, 4.00, '2025-12-31');

-- Medication schedule
INSERT INTO medication_schedule (patient_id, nurse_id, medicine, dosage, scheduled_time, scheduled_date, is_given, notes) VALUES
(2, 1, 'Paracetamol 500mg', '1 tab', '08:00:00', CURDATE(), FALSE, ''),
(2, 1, 'Ibuprofen 400mg',   '1 tab', '14:00:00', CURDATE(), TRUE,  'Given on time');

-- Doctor instructions
INSERT INTO doctor_instructions (patient_id, doctor_id, instruction, priority, status, instruction_date) VALUES
(2, 2, 'Monitor blood pressure every 2 hours', 'high', 'pending', CURDATE());

-- Messages
INSERT INTO messages (from_type, from_id, to_type, to_id, message) VALUES
('patient', 2, 'doctor', 2, 'Doctor, I have been feeling dizzy since morning.'),
('doctor',  2, 'patient', 2, 'Please rest and take the prescribed medication. If dizziness persists, come in immediately.');

-- Health tracking
INSERT INTO health_tracking (patient_id, log_date, steps, water_litres, sleep_hours, exercise_minutes, calories, mood) VALUES
(1, CURDATE(), 7200, 2.10, 7.5, 30, 1800, 'Good');


-- ============================================================
-- VERIFY SETUP — Run these after import to confirm data loaded
-- ============================================================
-- SELECT 'admins'       AS tbl, COUNT(*) AS rows FROM admins
-- UNION SELECT 'doctors',      COUNT(*) FROM doctors
-- UNION SELECT 'nurses',       COUNT(*) FROM nurses
-- UNION SELECT 'receptionists',COUNT(*) FROM receptionists
-- UNION SELECT 'patients',     COUNT(*) FROM patients
-- UNION SELECT 'appointments', COUNT(*) FROM appointments
-- UNION SELECT 'medicines',    COUNT(*) FROM medicines;


-- ============================================================
-- USEFUL QUERIES
-- ============================================================

-- All patients with today's appointments
-- SELECT p.name, p.mobile, a.appointment_time, a.token_number, d.name AS doctor, a.status
-- FROM appointments a
-- JOIN patients p ON a.patient_id = p.id
-- JOIN doctors  d ON a.doctor_id  = d.id
-- WHERE a.appointment_date = CURDATE()
-- ORDER BY a.token_number;

-- Unpaid bills
-- SELECT b.invoice_number, p.name AS patient, b.total_amount, b.paid_amount,
--        (b.total_amount - b.paid_amount) AS balance
-- FROM bills b JOIN patients p ON b.patient_id = p.id
-- WHERE b.status != 'paid';

-- Low stock medicines
-- SELECT name, stock, reorder_level FROM medicines WHERE stock <= reorder_level;

-- Bed occupancy
-- SELECT ward, SUM(status='available') AS available, SUM(status='occupied') AS occupied
-- FROM beds GROUP BY ward;
