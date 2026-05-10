<?php
// ============================================================
// Medicare HMS — PHP Backend API
// File: api.php  (place in same folder as HMS.HTML)
// Requirements: PHP 8.0+, MySQL 8.0+, PDO extension enabled
// ============================================================

// -------- CORS & JSON headers --------
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// -------- DB CONFIG — change these --------
define('DB_HOST', 'localhost');
define('DB_NAME', 'medicare_hms');
define('DB_USER', 'root');         // your MySQL username
define('DB_PASS', '');             // your MySQL password
define('JWT_SECRET', 'MedicareHMS_Secret_Key_2024!');  // change in production

// -------- Bootstrap --------
session_start();
$pdo = null;

function getDB(): PDO {
    global $pdo;
    if ($pdo) return $pdo;
    $dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err(string $msg, int $code = 400): void {
    json_out(['success' => false, 'error' => $msg], $code);
}

function body(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

// -------- Simple JWT helpers --------
function jwt_encode(array $payload): string {
    $header  = base64_url_encode(json_encode(['alg'=>'HS256','typ'=>'JWT']));
    $payload = base64_url_encode(json_encode($payload));
    $sig     = base64_url_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    return "$header.$payload.$sig";
}
function base64_url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function jwt_decode(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$h, $p, $s] = $parts;
    $expected = base64_url_encode(hash_hmac('sha256', "$h.$p", JWT_SECRET, true));
    if (!hash_equals($expected, $s)) return null;
    $data = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
    if (($data['exp'] ?? 0) < time()) return null;
    return $data;
}

function require_auth(): array {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $auth);
    $user  = jwt_decode($token);
    if (!$user) json_err('Unauthorized', 401);
    return $user;
}

// -------- Router --------
$method = $_SERVER['REQUEST_METHOD'];

// Support both PATH_INFO (/api.php/patients/1) and query string (?r=patients&id=1)
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
if ($pathInfo) {
    $seg      = explode('/', trim($pathInfo, '/'));
    $resource = $seg[0] ?? '';
    $id       = isset($seg[1]) && is_numeric($seg[1]) ? (int)$seg[1] : null;
} else {
    $resource = $_GET['r'] ?? '';
    $id       = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : null;
}

try {
    route($method, $resource, $id);
} catch (PDOException $e) {
    json_err('Database error: ' . $e->getMessage(), 500);
}

function route(string $method, string $resource, ?int $id): void {
    match ($resource) {
        'login'         => handle_login(),
        'patients'      => handle_patients($method, $id),
        'doctors'       => handle_doctors($method, $id),
        'nurses'        => handle_nurses($method, $id),
        'receptionists' => handle_receptionists($method, $id),
        'admins'        => handle_admins($method, $id),
        'appointments'  => handle_appointments($method, $id),
        'prescriptions' => handle_prescriptions($method, $id),
        'lab_reports'   => handle_lab_reports($method, $id),
        'bills'         => handle_bills($method, $id),
        'vitals'        => handle_vitals($method, $id),
        'medicines'     => handle_medicines($method, $id),
        'medications'   => handle_medications($method, $id),
        'instructions'  => handle_instructions($method, $id),
        'messages'      => handle_messages($method, $id),
        'health'        => handle_health($method, $id),
        'beds'          => handle_beds($method, $id),
        'departments'   => handle_departments($method, $id),
        'dashboard'     => handle_dashboard(),
        default         => json_err('Route not found', 404),
    };
}

// ============================================================
// AUTH — POST /login
// Body: { "role": "admin|doctor|nurse|receptionist|patient", "email": "", "password": "" }
// ============================================================
function handle_login(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Method not allowed', 405);
    $b = body();
    $role  = $b['role']     ?? '';
    $email = trim($b['email']    ?? '');
    $pass  = $b['password'] ?? '';
    if (!$role || !$email || !$pass) json_err('role, email and password required');

    $db = getDB();

    if ($role === 'admin') {
        $st = $db->prepare('SELECT * FROM admins WHERE email = ? AND status = "active" LIMIT 1');
        $st->execute([$email]);
        $user = $st->fetch();
        // In prod: password_verify($pass, $user['password'])
        if (!$user || $pass !== 'Admin@2024') json_err('Invalid credentials', 401);
        $user['role'] = 'admin';

    } elseif ($role === 'doctor') {
        $st = $db->prepare('SELECT * FROM doctors WHERE email = ? AND status = "active" LIMIT 1');
        $st->execute([$email]);
        $user = $st->fetch();
        if (!$user || $pass !== 'Doctor@2024') json_err('Invalid credentials', 401);
        $user['role'] = 'doctor';

    } elseif ($role === 'nurse') {
        $st = $db->prepare('SELECT * FROM nurses WHERE email = ? AND status = "active" LIMIT 1');
        $st->execute([$email]);
        $user = $st->fetch();
        if (!$user || $pass !== 'Nurse@2024') json_err('Invalid credentials', 401);
        $user['role'] = 'nurse';

    } elseif ($role === 'receptionist') {
        $st = $db->prepare('SELECT * FROM receptionists WHERE email = ? AND status = "active" LIMIT 1');
        $st->execute([$email]);
        $user = $st->fetch();
        if (!$user || $pass !== 'Recept@2024') json_err('Invalid credentials', 401);
        $user['role'] = 'receptionist';

    } elseif ($role === 'patient') {
        $st = $db->prepare('SELECT * FROM patients WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $user = $st->fetch();
        $valid = $user && ($user['password'] === $pass || password_verify($pass, $user['password']));
        if (!$valid) json_err('Invalid credentials', 401);
        $user['role'] = 'patient';

    } else {
        json_err('Unknown role');
    }

    unset($user['password']);
    $token = jwt_encode([
        'id'   => $user['id'],
        'role' => $user['role'],
        'exp'  => time() + 86400 * 7, // 7 days
    ]);
    json_out(['success' => true, 'token' => $token, 'user' => $user]);
}

// ============================================================
// PATIENTS  GET/POST/PUT/DELETE /patients[/id]
// ============================================================
function handle_patients(string $method, ?int $id): void {
    $db = getDB();

    // ---- PUBLIC: Patient self-registration (no token needed) ----
    if ($method === 'POST') {
        $b = body();
        $required = ['name','email','password'];
        foreach ($required as $f) if (empty($b[$f])) json_err("$f is required");
        // Check for duplicate email
        $chk = $db->prepare('SELECT id FROM patients WHERE email = ? LIMIT 1');
        $chk->execute([$b['email']]);
        if ($chk->fetch()) json_err('Email already registered', 409);
        $st = $db->prepare('INSERT INTO patients
            (name,email,password,age,gender,mobile,blood_group,address,emergency_contact,joined_date)
            VALUES (?,?,?,?,?,?,?,?,?,?)');
        $st->execute([
            $b['name'], $b['email'], password_hash($b['password'], PASSWORD_DEFAULT),
            $b['age'] ?? null, $b['gender'] ?? '',
            $b['mobile'] ?? null, $b['blood_group'] ?? null,
            $b['address'] ?? null, $b['emergency_contact'] ?? null,
            $b['joined_date'] ?? date('Y-m-d'),
        ]);
        json_out(['success' => true, 'id' => (int)$db->lastInsertId()], 201);
    }

    // All other operations require authentication
    $user = require_auth();

    if ($method === 'GET') {
        if ($id) {
            $st = $db->prepare('SELECT * FROM patients WHERE id = ?');
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row) json_err('Patient not found', 404);
            unset($row['password']);
            json_out(['success' => true, 'data' => $row]);
        } else {
            // Patients can only see themselves
            if ($user['role'] === 'patient') {
                $st = $db->prepare('SELECT * FROM patients WHERE id = ?');
                $st->execute([$user['id']]);
            } else {
                $st = $db->query('SELECT * FROM patients ORDER BY created_at DESC');
            }
            $rows = $st->fetchAll();
            foreach ($rows as &$r) unset($r['password']);
            json_out(['success' => true, 'data' => $rows]);
        }
    }

    if ($method === 'PUT' && $id) {
        $b = body();
        $db->prepare('UPDATE patients SET name=?,age=?,gender=?,mobile=?,blood_group=?,address=?,emergency_contact=? WHERE id=?')
           ->execute([$b['name'],$b['age'],$b['gender'],$b['mobile'],$b['blood_group'],$b['address'],$b['emergency_contact'],$id]);
        json_out(['success' => true]);
    }

    if ($method === 'DELETE' && $id) {
        if ($user['role'] !== 'admin') json_err('Forbidden', 403);
        $db->prepare('DELETE FROM patients WHERE id=?')->execute([$id]);
        json_out(['success' => true]);
    }

    json_err('Method not allowed', 405);
}

// ============================================================
// DOCTORS  GET/POST/PUT/DELETE /doctors[/id]
// ============================================================
function handle_doctors(string $method, ?int $id): void {
    $db   = getDB();
    $user = require_auth();

    if ($method === 'GET') {
        if ($id) {
            $st = $db->prepare('SELECT d.*, dept.name AS department_name FROM doctors d LEFT JOIN departments dept ON d.department_id=dept.id WHERE d.id=?');
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row) json_err('Doctor not found', 404);
            unset($row['password']);
            json_out(['success' => true, 'data' => $row]);
        } else {
            $rows = $db->query('SELECT d.*, dept.name AS department_name FROM doctors d LEFT JOIN departments dept ON d.department_id=dept.id ORDER BY d.name')->fetchAll();
            foreach ($rows as &$r) unset($r['password']);
            json_out(['success' => true, 'data' => $rows]);
        }
    }

    if ($method === 'POST') {
        if ($user['role'] !== 'admin') json_err('Forbidden', 403);
        $b = body();
        $db->prepare('INSERT INTO doctors (name,email,password,specialization,department_id,qualification,experience_years,mobile,consultation_fee,available_days,status,joined_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$b['name'],$b['email'],$b['password']??'Doctor@2024',$b['specialization'],$b['department_id']??null,$b['qualification']??null,$b['experience_years']??0,$b['mobile']??null,$b['consultation_fee']??0,$b['available_days']??null,$b['status']??'active',$b['joined_date']??date('Y-m-d')]);
        json_out(['success' => true, 'id' => (int)$db->lastInsertId()], 201);
    }

    if ($method === 'PUT' && $id) {
        if (!in_array($user['role'], ['admin','doctor'])) json_err('Forbidden', 403);
        $b = body();
        $db->prepare('UPDATE doctors SET name=?,specialization=?,department_id=?,qualification=?,experience_years=?,mobile=?,consultation_fee=?,available_days=?,status=? WHERE id=?')
           ->execute([$b['name'],$b['specialization'],$b['department_id'],$b['qualification'],$b['experience_years'],$b['mobile'],$b['consultation_fee'],$b['available_days'],$b['status'],$id]);
        json_out(['success' => true]);
    }

    if ($method === 'DELETE' && $id) {
        if ($user['role'] !== 'admin') json_err('Forbidden', 403);
        $db->prepare('DELETE FROM doctors WHERE id=?')->execute([$id]);
        json_out(['success' => true]);
    }

    json_err('Method not allowed', 405);
}

// ============================================================
// NURSES  GET/POST/PUT/DELETE /nurses[/id]
// ============================================================
function handle_nurses(string $method, ?int $id): void {
    $db   = getDB();
    $user = require_auth();
    if (!in_array($user['role'], ['admin','nurse','doctor'])) json_err('Forbidden', 403);

    if ($method === 'GET') {
        if ($id) {
            $st = $db->prepare('SELECT * FROM nurses WHERE id=?'); $st->execute([$id]);
            $row = $st->fetch(); if (!$row) json_err('Not found', 404);
            unset($row['password']); json_out(['success'=>true,'data'=>$row]);
        } else {
            $rows = $db->query('SELECT * FROM nurses ORDER BY name')->fetchAll();
            foreach ($rows as &$r) unset($r['password']);
            json_out(['success'=>true,'data'=>$rows]);
        }
    }
    if ($method === 'POST') {
        if ($user['role'] !== 'admin') json_err('Forbidden', 403);
        $b = body();
        $db->prepare('INSERT INTO nurses (name,email,password,ward,shift,mobile,status,joined_date) VALUES (?,?,?,?,?,?,?,?)')
           ->execute([$b['name'],$b['email'],$b['password']??'Nurse@2024',$b['ward']??null,$b['shift']??'Morning',$b['mobile']??null,$b['status']??'active',$b['joined_date']??date('Y-m-d')]);
        json_out(['success'=>true,'id'=>(int)$db->lastInsertId()],201);
    }
    if ($method === 'PUT' && $id) {
        $b = body();
        $db->prepare('UPDATE nurses SET name=?,ward=?,shift=?,mobile=?,status=? WHERE id=?')
           ->execute([$b['name'],$b['ward'],$b['shift'],$b['mobile'],$b['status'],$id]);
        json_out(['success'=>true]);
    }
    if ($method === 'DELETE' && $id) {
        if ($user['role']!=='admin') json_err('Forbidden',403);
        $db->prepare('DELETE FROM nurses WHERE id=?')->execute([$id]);
        json_out(['success'=>true]);
    }
    json_err('Method not allowed', 405);
}

// ============================================================
// RECEPTIONISTS
// ============================================================
function handle_receptionists(string $method, ?int $id): void {
    $db   = getDB();
    $user = require_auth();
    if (!in_array($user['role'], ['admin','receptionist'])) json_err('Forbidden', 403);

    if ($method === 'GET') {
        $rows = $db->query('SELECT * FROM receptionists ORDER BY name')->fetchAll();
        foreach ($rows as &$r) unset($r['password']);
        json_out(['success'=>true,'data'=>$rows]);
    }
    if ($method === 'POST') {
        if ($user['role']!=='admin') json_err('Forbidden',403);
        $b = body();
        $db->prepare('INSERT INTO receptionists (name,email,password,mobile,shift,status,joined_date) VALUES (?,?,?,?,?,?,?)')
           ->execute([$b['name'],$b['email'],$b['password']??'Recept@2024',$b['mobile']??null,$b['shift']??'Morning',$b['status']??'active',$b['joined_date']??date('Y-m-d')]);
        json_out(['success'=>true,'id'=>(int)$db->lastInsertId()],201);
    }
    if ($method === 'PUT' && $id) {
        $b = body();
        $db->prepare('UPDATE receptionists SET name=?,mobile=?,shift=?,status=? WHERE id=?')
           ->execute([$b['name'],$b['mobile'],$b['shift'],$b['status'],$id]);
        json_out(['success'=>true]);
    }
    json_err('Method not allowed', 405);
}

// ============================================================
// ADMINS
// ============================================================
function handle_admins(string $method, ?int $id): void {
    $user = require_auth();
    if ($user['role']!=='admin') json_err('Forbidden',403);
    $db = getDB();
    if ($method==='GET') {
        $rows = getDB()->query('SELECT id,name,email,mobile,status,created_at FROM admins')->fetchAll();
        json_out(['success'=>true,'data'=>$rows]);
    }
    json_err('Method not allowed',405);
}

// ============================================================
// APPOINTMENTS  GET/POST/PUT/DELETE
// ============================================================
function handle_appointments(string $method, ?int $id): void {
    $db   = getDB();
    $user = require_auth();

    if ($method === 'GET') {
        $sql = 'SELECT a.*, p.name AS patient_name, p.mobile AS patient_mobile,
                       d.name AS doctor_name, d.specialization
                FROM appointments a
                JOIN patients p ON a.patient_id = p.id
                JOIN doctors  d ON a.doctor_id  = d.id';
        $params = [];

        if ($user['role'] === 'patient') {
            $sql .= ' WHERE a.patient_id = ?'; $params[] = $user['id'];
        } elseif ($user['role'] === 'doctor') {
            $sql .= ' WHERE a.doctor_id = ?';  $params[] = $user['id'];
        }
        // filter by date if ?date=YYYY-MM-DD
        if (!empty($_GET['date'])) {
            $sql .= (strpos($sql,'WHERE') ? ' AND' : ' WHERE') . ' a.appointment_date = ?';
            $params[] = $_GET['date'];
        }
        $sql .= ' ORDER BY a.appointment_date DESC, a.token_number';
        $st = $db->prepare($sql); $st->execute($params);
        json_out(['success'=>true,'data'=>$st->fetchAll()]);
    }

    if ($method === 'POST') {
        $b = body();
        if (empty($b['patient_id']) || empty($b['doctor_id']) || empty($b['appointment_date'])) json_err('patient_id, doctor_id, appointment_date required');
        // Auto-assign token number
        $st = $db->prepare('SELECT COUNT(*)+1 AS next FROM appointments WHERE doctor_id=? AND appointment_date=?');
        $st->execute([$b['doctor_id'],$b['appointment_date']]);
        $token = $st->fetchColumn();
        $db->prepare('INSERT INTO appointments (patient_id,doctor_id,appointment_date,appointment_time,token_number,status,reason,type,created_by) VALUES (?,?,?,?,?,?,?,?,?)')
           ->execute([$b['patient_id'],$b['doctor_id'],$b['appointment_date'],$b['appointment_time']??'09:00',$token,$b['status']??'scheduled',$b['reason']??null,$b['type']??'in-person',$b['created_by']??$user['role']]);
        json_out(['success'=>true,'id'=>(int)$db->lastInsertId(),'token'=>$token],201);
    }

    if ($method === 'PUT' && $id) {
        $b = body();
        $db->prepare('UPDATE appointments SET status=?,checked_in=?,checked_out=? WHERE id=?')
           ->execute([$b['status']??'scheduled',(int)($b['checked_in']??0),(int)($b['checked_out']??0),$id]);
        json_out(['success'=>true]);
    }

    if ($method === 'DELETE' && $id) {
        if (!in_array($user['role'],['admin','receptionist'])) json_err('Forbidden',403);
        $db->prepare('DELETE FROM appointments WHERE id=?')->execute([$id]);
        json_out(['success'=>true]);
    }

    json_err('Method not allowed', 405);
}

// ============================================================
// PRESCRIPTIONS
// ============================================================
function handle_prescriptions(string $method, ?int $id): void {
    $db   = getDB();
    $user = require_auth();

    if ($method === 'GET') {
        $sql = 'SELECT pr.*, d.name AS doctor_name, p.name AS patient_name FROM prescriptions pr
                JOIN doctors d  ON pr.doctor_id  = d.id
                JOIN patients p ON pr.patient_id = p.id';
        $params = [];
        if ($user['role']==='patient') { $sql.=' WHERE pr.patient_id=?'; $params[]=$user['id']; }
        if ($user['role']==='doctor')  { $sql.=' WHERE pr.doctor_id=?';  $params[]=$user['id']; }
        $sql.=' ORDER BY pr.prescription_date DESC';
        $st=$db->prepare($sql); $st->execute($params); $rows=$st->fetchAll();
        // Attach medicines to each prescription
        $pmSt = $db->prepare('SELECT * FROM prescription_medicines WHERE prescription_id=?');
        foreach ($rows as &$row) {
            $pmSt->execute([$row['id']]);
            $row['medicines'] = $pmSt->fetchAll();
        }
        json_out(['success'=>true,'data'=>$rows]);
    }

    if ($method === 'POST') {
        if ($user['role']!=='doctor') json_err('Forbidden',403);
        $b = body();
        $db->prepare('INSERT INTO prescriptions (patient_id,doctor_id,appointment_id,diagnosis,notes,follow_up_date,prescription_date) VALUES (?,?,?,?,?,?,?)')
           ->execute([$b['patient_id'],$user['id'],$b['appointment_id']??null,$b['diagnosis']??null,$b['notes']??null,$b['follow_up_date']??null,$b['prescription_date']??date('Y-m-d')]);
        $presId = (int)$db->lastInsertId();
        $pmSt = $db->prepare('INSERT INTO prescription_medicines (prescription_id,medicine_name,dosage,frequency,duration,instructions) VALUES (?,?,?,?,?,?)');
        foreach (($b['medicines']??[]) as $m) {
            $pmSt->execute([$presId,$m['name']??'',$m['dosage']??null,$m['frequency']??null,$m['duration']??null,$m['instructions']??null]);
        }
        json_out(['success'=>true,'id'=>$presId],201);
    }
    json_err('Method not allowed',405);
}

// ============================================================
// LAB REPORTS
// ============================================================
function handle_lab_reports(string $method, ?int $id): void {
    $db=$db=getDB(); $user=require_auth();
    if ($method==='GET') {
        $sql='SELECT lr.*,p.name AS patient_name,d.name AS doctor_name FROM lab_reports lr JOIN patients p ON lr.patient_id=p.id JOIN doctors d ON lr.doctor_id=d.id';
        $params=[];
        if($user['role']==='patient'){$sql.=' WHERE lr.patient_id=?';$params[]=$user['id'];}
        elseif($user['role']==='doctor'){$sql.=' WHERE lr.doctor_id=?';$params[]=$user['id'];}
        $sql.=' ORDER BY lr.test_date DESC';
        $st=$db->prepare($sql);$st->execute($params);
        json_out(['success'=>true,'data'=>$st->fetchAll()]);
    }
    if($method==='POST'){
        if(!in_array($user['role'],['doctor','admin'])) json_err('Forbidden',403);
        $b=body();
        $db->prepare('INSERT INTO lab_reports (patient_id,doctor_id,test_name,test_date,result,normal_range,status,remarks) VALUES (?,?,?,?,?,?,?,?)')
           ->execute([$b['patient_id'],$user['role']==='doctor'?$user['id']:$b['doctor_id'],$b['test_name'],$b['test_date']??date('Y-m-d'),$b['result']??null,$b['normal_range']??null,$b['status']??'pending',$b['remarks']??null]);
        json_out(['success'=>true,'id'=>(int)$db->lastInsertId()],201);
    }
    if($method==='PUT'&&$id){
        $b=body();
        $db->prepare('UPDATE lab_reports SET result=?,status=?,remarks=? WHERE id=?')->execute([$b['result'],$b['status'],$b['remarks'],$id]);
        json_out(['success'=>true]);
    }
    json_err('Method not allowed',405);
}

// ============================================================
// BILLS
// ============================================================
function handle_bills(string $method, ?int $id): void {
    $db=getDB(); $user=require_auth();
    if($method==='GET'){
        $sql='SELECT b.*,p.name AS patient_name FROM bills b JOIN patients p ON b.patient_id=p.id';
        $params=[];
        if($user['role']==='patient'){$sql.=' WHERE b.patient_id=?';$params[]=$user['id'];}
        $sql.=' ORDER BY b.created_at DESC';
        $st=$db->prepare($sql);$st->execute($params);$rows=$st->fetchAll();
        $iSt=$db->prepare('SELECT * FROM bill_items WHERE bill_id=?');
        foreach($rows as &$row){$iSt->execute([$row['id']]);$row['items']=$iSt->fetchAll();}
        json_out(['success'=>true,'data'=>$rows]);
    }
    if($method==='POST'){
        if(!in_array($user['role'],['receptionist','admin'])) json_err('Forbidden',403);
        $b=body();
        $bills=$db->query('SELECT COUNT(*) FROM bills')->fetchColumn();
        $invoice='INV-'.date('Ymd').'-'.str_pad((int)$bills+1,3,'0',STR_PAD_LEFT);
        $db->prepare('INSERT INTO bills (patient_id,appointment_id,invoice_number,total_amount,paid_amount,discount,status,payment_method,bill_date) VALUES (?,?,?,?,?,?,?,?,?)')
           ->execute([$b['patient_id'],$b['appointment_id']??null,$invoice,$b['total']??0,$b['status']==='paid'?$b['total']:0,$b['discount']??0,$b['status']??'pending',$b['method']??'Cash',$b['date']??date('Y-m-d')]);
        $billId=(int)$db->lastInsertId();
        $iSt=$db->prepare('INSERT INTO bill_items (bill_id,description,quantity,unit_price,total_price) VALUES (?,?,?,?,?)');
        foreach(($b['items']??[]) as $item){
            $iSt->execute([$billId,$item['desc'],$item['qty']??1,$item['price']??0,($item['qty']??1)*($item['price']??0)]);
        }
        json_out(['success'=>true,'id'=>$billId,'invoice'=>$invoice],201);
    }
    if($method==='PUT'&&$id){
        $b=body();
        $db->prepare('UPDATE bills SET paid_amount=?,status=? WHERE id=?')->execute([$b['paid_amount'],$b['status'],$id]);
        json_out(['success'=>true]);
    }
    json_err('Method not allowed',405);
}

// ============================================================
// VITALS
// ============================================================
function handle_vitals(string $method, ?int $id): void {
    $db=getDB(); $user=require_auth();
    if($method==='GET'){
        $pid=$_GET['patient_id']??($user['role']==='patient'?$user['id']:null);
        if($pid){$st=$db->prepare('SELECT * FROM vitals WHERE patient_id=? ORDER BY recorded_at DESC');$st->execute([$pid]);}
        else{$st=$db->query('SELECT * FROM vitals ORDER BY recorded_at DESC');}
        json_out(['success'=>true,'data'=>$st->fetchAll()]);
    }
    if($method==='POST'){
        if(!in_array($user['role'],['nurse','doctor','admin'])) json_err('Forbidden',403);
        $b=body();
        $db->prepare('INSERT INTO vitals (patient_id,nurse_id,blood_pressure,temperature,pulse_rate,respiratory_rate,spo2,weight,height,notes) VALUES (?,?,?,?,?,?,?,?,?,?)')
           ->execute([$b['patient_id'],$user['role']==='nurse'?$user['id']:null,$b['bp']??null,$b['temp']??null,$b['pulse']??null,$b['rr']??null,$b['spo2']??null,$b['weight']??null,$b['height']??null,$b['notes']??null]);
        json_out(['success'=>true,'id'=>(int)$db->lastInsertId()],201);
    }
    json_err('Method not allowed',405);
}

// ============================================================
// MEDICINES / PHARMACY
// ============================================================
function handle_medicines(string $method, ?int $id): void {
    $db=getDB(); require_auth();
    if($method==='GET'){
        $rows=$db->query('SELECT * FROM medicines ORDER BY name')->fetchAll();
        json_out(['success'=>true,'data'=>$rows]);
    }
    if($method==='POST'){
        $b=body();
        $db->prepare('INSERT INTO medicines (name,generic_name,category,unit,stock,reorder_level,unit_price,expiry_date) VALUES (?,?,?,?,?,?,?,?)')
           ->execute([$b['name'],$b['generic_name']??null,$b['category']??null,$b['unit']??null,$b['stock']??0,$b['reorder_level']??10,$b['unit_price']??0,$b['expiry_date']??null]);
        json_out(['success'=>true,'id'=>(int)$db->lastInsertId()],201);
    }
    if($method==='PUT'&&$id){
        $b=body();
        $db->prepare('UPDATE medicines SET stock=?,reorder_level=?,unit_price=?,expiry_date=? WHERE id=?')->execute([$b['stock'],$b['reorder_level'],$b['unit_price'],$b['expiry_date'],$id]);
        json_out(['success'=>true]);
    }
    json_err('Method not allowed',405);
}

// ============================================================
// MEDICATION SCHEDULE
// ============================================================
function handle_medications(string $method, ?int $id): void {
    $db=getDB(); $user=require_auth();
    if($method==='GET'){
        $date=$_GET['date']??date('Y-m-d');
        if($user['role']==='nurse'){$st=$db->prepare('SELECT ms.*,p.name AS patient_name FROM medication_schedule ms JOIN patients p ON ms.patient_id=p.id WHERE ms.nurse_id=? AND ms.scheduled_date=? ORDER BY ms.scheduled_time');$st->execute([$user['id'],$date]);}
        else{$st=$db->prepare('SELECT ms.*,p.name AS patient_name FROM medication_schedule ms JOIN patients p ON ms.patient_id=p.id WHERE ms.scheduled_date=? ORDER BY ms.scheduled_time');$st->execute([$date]);}
        json_out(['success'=>true,'data'=>$st->fetchAll()]);
    }
    if($method==='PUT'&&$id){
        $b=body();
        $db->prepare('UPDATE medication_schedule SET is_given=?,notes=? WHERE id=?')->execute([(int)$b['is_given'],$b['notes']??null,$id]);
        json_out(['success'=>true]);
    }
    if($method==='POST'){
        $b=body();
        $db->prepare('INSERT INTO medication_schedule (patient_id,nurse_id,medicine,dosage,scheduled_time,scheduled_date) VALUES (?,?,?,?,?,?)')
           ->execute([$b['patient_id'],$b['nurse_id']??null,$b['medicine'],$b['dosage']??null,$b['time'],$b['date']??date('Y-m-d')]);
        json_out(['success'=>true,'id'=>(int)$db->lastInsertId()],201);
    }
    json_err('Method not allowed',405);
}

// ============================================================
// DOCTOR INSTRUCTIONS
// ============================================================
function handle_instructions(string $method, ?int $id): void {
    $db=getDB(); $user=require_auth();
    if($method==='GET'){
        $pid=$_GET['patient_id']??null;
        if($pid){$st=$db->prepare('SELECT di.*,d.name AS doctor_name FROM doctor_instructions di JOIN doctors d ON di.doctor_id=d.id WHERE di.patient_id=? ORDER BY di.created_at DESC');$st->execute([$pid]);}
        elseif($user['role']==='doctor'){$st=$db->prepare('SELECT di.*,p.name AS patient_name FROM doctor_instructions di JOIN patients p ON di.patient_id=p.id WHERE di.doctor_id=? ORDER BY di.created_at DESC');$st->execute([$user['id']]);}
        else{$st=$db->query('SELECT di.*,d.name AS doctor_name,p.name AS patient_name FROM doctor_instructions di JOIN doctors d ON di.doctor_id=d.id JOIN patients p ON di.patient_id=p.id ORDER BY di.created_at DESC');}
        json_out(['success'=>true,'data'=>$st->fetchAll()]);
    }
    if($method==='POST'){
        if($user['role']!=='doctor') json_err('Forbidden',403);
        $b=body();
        $db->prepare('INSERT INTO doctor_instructions (patient_id,doctor_id,instruction,priority,status,instruction_date) VALUES (?,?,?,?,?,?)')
           ->execute([$b['patient_id'],$user['id'],$b['instruction'],$b['priority']??'medium','pending',$b['date']??date('Y-m-d')]);
        json_out(['success'=>true,'id'=>(int)$db->lastInsertId()],201);
    }
    if($method==='PUT'&&$id){
        $b=body();
        $db->prepare('UPDATE doctor_instructions SET status=? WHERE id=?')->execute([$b['status'],$id]);
        json_out(['success'=>true]);
    }
    json_err('Method not allowed',405);
}

// ============================================================
// MESSAGES
// ============================================================
function handle_messages(string $method, ?int $id): void {
    $db=getDB(); $user=require_auth();
    if($method==='GET'){
        $toType=$_GET['to_type']??null; $toId=$_GET['to_id']??null;
        if($toType&&$toId){
            $st=$db->prepare('SELECT * FROM messages WHERE (from_type=? AND from_id=? AND to_type=? AND to_id=?) OR (from_type=? AND from_id=? AND to_type=? AND to_id=?) ORDER BY sent_at');
            $st->execute([$user['role'],$user['id'],$toType,$toId,$toType,$toId,$user['role'],$user['id']]);
        } else {
            $st=$db->prepare('SELECT * FROM messages WHERE (from_type=? AND from_id=?) OR (to_type=? AND to_id=?) ORDER BY sent_at');
            $st->execute([$user['role'],$user['id'],$user['role'],$user['id']]);
        }
        json_out(['success'=>true,'data'=>$st->fetchAll()]);
    }
    if($method==='POST'){
        $b=body();
        $db->prepare('INSERT INTO messages (from_type,from_id,to_type,to_id,message) VALUES (?,?,?,?,?)')
           ->execute([$user['role'],$user['id'],$b['to_type'],$b['to_id'],$b['message']]);
        json_out(['success'=>true,'id'=>(int)$db->lastInsertId()],201);
    }
    json_err('Method not allowed',405);
}

// ============================================================
// HEALTH TRACKING
// ============================================================
function handle_health(string $method, ?int $id): void {
    $db=getDB(); $user=require_auth();
    $pid=$user['role']==='patient'?$user['id']:($_GET['patient_id']??null);
    if($method==='GET'){
        if($pid){$st=$db->prepare('SELECT * FROM health_tracking WHERE patient_id=? ORDER BY log_date DESC LIMIT 30');$st->execute([$pid]);}
        else{$st=$db->query('SELECT * FROM health_tracking ORDER BY log_date DESC');}
        json_out(['success'=>true,'data'=>$st->fetchAll()]);
    }
    if($method==='POST'){
        $b=body();
        $db->prepare('INSERT INTO health_tracking (patient_id,log_date,steps,water_litres,sleep_hours,exercise_minutes,calories,mood) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE steps=VALUES(steps),water_litres=VALUES(water_litres),sleep_hours=VALUES(sleep_hours),exercise_minutes=VALUES(exercise_minutes),calories=VALUES(calories),mood=VALUES(mood)')
           ->execute([$pid??$b['patient_id'],$b['date']??date('Y-m-d'),$b['steps']??0,$b['water']??0,$b['sleep']??0,$b['exercise']??0,$b['calories']??0,$b['mood']??null]);
        json_out(['success'=>true],201);
    }
    json_err('Method not allowed',405);
}

// ============================================================
// BEDS
// ============================================================
function handle_beds(string $method, ?int $id): void {
    $db=getDB(); require_auth();
    if($method==='GET'){
        $rows=$db->query('SELECT b.*,p.name AS patient_name FROM beds b LEFT JOIN patients p ON b.patient_id=p.id ORDER BY b.ward,b.bed_number')->fetchAll();
        json_out(['success'=>true,'data'=>$rows]);
    }
    if($method==='PUT'&&$id){
        $b=body();
        $db->prepare('UPDATE beds SET status=?,patient_id=?,admitted_at=? WHERE id=?')
           ->execute([$b['status'],$b['patient_id']??null,$b['status']==='occupied'?date('Y-m-d H:i:s'):null,$id]);
        json_out(['success'=>true]);
    }
    if($method==='POST'){
        $b=body();
        $db->prepare('INSERT INTO beds (bed_number,ward,type,status) VALUES (?,?,?,?)')->execute([$b['bed_number'],$b['ward'],$b['type']??'General','available']);
        json_out(['success'=>true,'id'=>(int)$db->lastInsertId()],201);
    }
    json_err('Method not allowed',405);
}

// ============================================================
// DEPARTMENTS
// ============================================================
function handle_departments(string $method, ?int $id): void {
    $db=getDB(); require_auth();
    if($method==='GET'){
        $rows=$db->query('SELECT d.*,doc.name AS head_doctor_name FROM departments d LEFT JOIN doctors doc ON d.head_doctor_id=doc.id')->fetchAll();
        json_out(['success'=>true,'data'=>$rows]);
    }
    if($method==='PUT'&&$id){
        $b=body();
        $db->prepare('UPDATE departments SET available_beds=?,total_beds=? WHERE id=?')->execute([$b['available_beds'],$b['total_beds'],$id]);
        json_out(['success'=>true]);
    }
    json_err('Method not allowed',405);
}

// ============================================================
// DASHBOARD STATS  GET /dashboard
// ============================================================
function handle_dashboard(): void {
    $db=getDB(); $user=require_auth();
    $stats=[
        'total_patients'     => $db->query('SELECT COUNT(*) FROM patients')->fetchColumn(),
        'total_doctors'      => $db->query('SELECT COUNT(*) FROM doctors WHERE status="active"')->fetchColumn(),
        'total_nurses'       => $db->query('SELECT COUNT(*) FROM nurses WHERE status="active"')->fetchColumn(),
        'today_appointments' => $db->query('SELECT COUNT(*) FROM appointments WHERE appointment_date=CURDATE()')->fetchColumn(),
        'available_beds'     => $db->query('SELECT COUNT(*) FROM beds WHERE status="available"')->fetchColumn(),
        'occupied_beds'      => $db->query('SELECT COUNT(*) FROM beds WHERE status="occupied"')->fetchColumn(),
        'pending_bills'      => $db->query('SELECT COUNT(*) FROM bills WHERE status="pending"')->fetchColumn(),
        'today_revenue'      => $db->query('SELECT COALESCE(SUM(paid_amount),0) FROM bills WHERE bill_date=CURDATE()')->fetchColumn(),
        'low_stock_meds'     => $db->query('SELECT COUNT(*) FROM medicines WHERE stock <= reorder_level')->fetchColumn(),
    ];
    json_out(['success'=>true,'data'=>$stats]);
}
?>
