<?php

/**
 * ฟังก์ชันกลางสำหรับดึงเฉพาะรายการ church_id ที่ User มีสิทธิ์เข้าถึง
 */
function getAllowedChurchIds(PDO $db, int $userId): array
{
    $sql = "SELECT c.id 
            FROM churches c
            WHERE EXISTS (
                SELECT 1 
                FROM user_data_permissions udp 
                WHERE udp.user_id = :user_id 
                  AND (
                    udp.scope_type = 'system'
                    OR (udp.scope_type = 'region' AND udp.scope_id = c.region_id)
                    OR (udp.scope_type = 'church' AND udp.scope_id = c.id)
                  )
            )";

    $stmt = $db->prepare($sql);
    $stmt->execute([':user_id' => $userId]);

    // ดึงออกมาเป็น Array มิติเดียวด้วย PDO::FETCH_COLUMN
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/**
 * ดึงชื่อโบสถ์ตาม id ที่ระบุ
 * 
 * @param PDO $pdo ตัวแปรเชื่อมต่อฐานข้อมูล PDO
 * @param int|string $churchId ID ของโบสถ์ที่ต้องการค้นหา
 * @return string|null คืนค่าชื่อโบสถ์ หรือ null หากไม่พบข้อมูล
 */
function getChurchNameById(PDO $pdo, $churchId): ?string
{
    $sql = "SELECT name FROM churches WHERE id = :church_id";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':church_id' => $churchId]);
    
    // ดึงค่าคอลัมน์แรก (name) ออกมาตรงๆ
    $churchName = $stmt->fetchColumn();
    
    return $churchName !== false ? $churchName : null;
}

/**
 * ปรับปรุงให้รับ $churchId เพิ่มเติม
 */
function handleFileUploads($files, $transactionId, $churchId, $transactionDate, $dataFor, $db)
{
    // 1. เตรียมชื่อโฟลเดอร์ตามเงื่อนไข (YYMM และ churchId)
    $dateObj = new DateTime($transactionDate);
    $yymm = date('ym'); // เช่น 2603

    // กำหนด Path ย่อย: uploads/2603/1/
    $subDir = $yymm . DIRECTORY_SEPARATOR . $churchId;

    // Path เต็มสำหรับย้ายไฟล์ใน Server
    $baseUploadDir = __DIR__ . '/../public/uploads';
    $targetDir = $baseUploadDir . DIRECTORY_SEPARATOR . $subDir;

    // 2. ตรวจสอบและสร้างโฟลเดอร์แบบ Recursive (สร้างโฟลเดอร์ที่ซ้อนกันให้อัตโนมัติ)
    if (!is_dir($targetDir)) {
        // พารามิเตอร์ที่สาม true คือระบุให้สร้างแบบ recursive
        mkdir($targetDir, 0777, true);
    }

    if (!is_writable($targetDir)) {
        chmod($targetDir, 0777);
    }

    $uploadedFileNames = [];

    foreach ($files as $file) {
        if ($file->getError() === UPLOAD_ERR_OK) {
            $clientFileName = $file->getClientFilename();

            $extension = pathinfo($clientFileName, PATHINFO_EXTENSION);
            // สร้างชื่อใหม่: churchid + timestamp + สุ่มตัวเลข 4 หลัก
            $storedFileName = "ch" . $churchId . "_" . time() . "_" . rand(1000, 9999) . "." . $extension;

            // ล้างชื่อไฟล์ให้ปลอดภัย
            // $safeFileName = preg_replace("/[^a-zA-Z0-9._-]/", "_", $clientFileName);
            // $storedFileName = $transactionId . '_' . time() . '_' . $safeFileName;

            // 3. ย้ายไฟล์ไปที่โฟลเดอร์ย่อยที่เตรียมไว้
            $file->moveTo($targetDir . DIRECTORY_SEPARATOR . $storedFileName);

            // 4. บันทึกลงฐานข้อมูล
            // สำคัญ: เราต้องเก็บ Path ย่อยไว้ในฟิลด์ file_path ด้วยเพื่อให้ดึงไปแสดงผลถูก
            $dbPath = $subDir . '/' . $storedFileName; // ใช้ / เพื่อให้เป็นมาตรฐาน URL

            $sqlFile = "INSERT INTO transaction_attachments (transaction_id, file_path, file_name, data_for) VALUES (?, ?, ?, ?)";
            $db->prepare($sqlFile)->execute([$transactionId, $dbPath, $clientFileName, $dataFor]);

            $uploadedFileNames[] = $dbPath;
        }
    }

    return $uploadedFileNames;
}

function deletePhysicalFile($filePath)
{
    if (empty($filePath)) return false;

    // ตรวจสอบว่ามี / คั่นระหว่าง uploads กับชื่อไฟล์หรือไม่
    $basePath = __DIR__ . '/../public/uploads/';
    $fullPath = $basePath . ltrim($filePath, '/');

    if (file_exists($fullPath) && is_file($fullPath)) {
        return @unlink($fullPath); // ใช้ @ เพื่อไม่ให้พ่น Warning ออกมา
    }
    return false;
}

function isMonthClosed($db, $churchId, $transactionDate)
{
    // แกะปีและเดือนออกมาจากวันที่ของธุรกรรมที่เขากำลังจะกระทำ
    $date = new DateTime($transactionDate);
    $year = $date->format('Y');
    $month = $date->format('n');

    // วิ่งไปเช็กในตารางแม่เลยว่าสเตตัสเป็น approved หรือยัง
    $stmt = $db->prepare("
        SELECT status FROM monthly_closings 
        WHERE church_id = :church_id AND year = :year AND month = :month
    ");
    $stmt->execute([':church_id' => $churchId, ':year' => $year, ':month' => $month]);
    $status = $stmt->fetchColumn();

    return ($status === 'approved');
}

/**
 * ดึงรายชื่อผู้มีสิทธิ์อนุมัติทั้งหมดของฟอร์ม (แยกตาม Level)
 * 
 * @param PDO $db ตัวเชื่อมต่อฐานข้อมูล PDO
 * @param int $org_id รหัสองค์กร
 * @param int $request_form_id รหัสรูปแบบฟอร์ม
 * @return array คืนค่าอาเรย์ของข้อมูลผู้มีสิทธิ์ทั้งหมด (approve_level, user_id, firstname, email)
 */
function getAllApproversByForm(PDO $db, int $org_id, int $request_form_id): array 
{
    $sql = "SELECT ap.approve_level, ap.user_id, u.firstname, u.email 
            FROM approve_permissions ap
            LEFT JOIN users u ON ap.user_id = u.id 
            WHERE ap.org_id = :org_id 
              AND ap.request_form_id = :request_form_id
            ORDER BY ap.approve_level";

    $stmt = $db->prepare($sql);
    
    // Bind ค่าพารามิเตอร์เพื่อความปลอดภัย
    $stmt->bindValue(':org_id', $org_id, PDO::PARAM_INT);
    $stmt->bindValue(':request_form_id', $request_form_id, PDO::PARAM_INT);
    
    $stmt->execute();
    
    // คืนค่าเป็น Array ข้อมูลทั้งหมด
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * ตรวจสอบสถานะการอนุมัติ โดยค้นหา level จาก user_id ที่ทำรายการล่าสุด
 * 
 * @param array $allApproversByForm รายชื่อผู้อนุมัติทั้งหมด (จาก getAllApproversByForm)
 * @param int|null $approvedBy user_id ของผู้ที่ทำรายการล่าสุด
 * @param string|null $approvedStatus สถานะล่าสุด เช่น 'approved', 'rejected', 'pending'
 * @return string คืนค่าสถานะ 'rejected', 'approved', หรือ 'pending'
 */
function evaluateApprovalStatus(array $allApproversByForm, ?int $approvedBy, ?string $approvedStatus): string 
{
    // 1. ถ้าสถานะล่าสุดเป็น rejected ให้ return 'rejected' ทันที
    if ($approvedStatus === 'rejected') {
        return 'rejected';
    }

    // 2. ถ้าสถานะล่าสุดเป็น approved
    if ($approvedStatus === 'approved') {
        $maxLevel = 0;
        $actorLevel = 0;

        // วนลูปหา maxLevel ทั้งหมด และหา level ของผู้ที่ทำรายการ ($approvedBy)
        foreach ($allApproversByForm as $row) {
            $level = (int)$row['approve_level'];
            
            // หา Level สูงสุด
            if ($level > $maxLevel) {
                $maxLevel = $level;
            }

            // หาว่า user_id ที่ส่งมา อยู่ที่ Level ไหน
            if ($approvedBy !== null && (int)$row['user_id'] === $approvedBy) {
                $actorLevel = $level;
            }
        }

        // 3. เช็คว่า Level ของคนที่กด approve เป็น level สูงสุดแล้วหรือยัง
        if ($actorLevel > 0 && $actorLevel >= $maxLevel) {
            return 'approved'; // ถึงระดับสูงสุดแล้ว
        } else {
            return 'pending';  // ยังไม่ถึงระดับสูงสุด
        }
    }

    // 4. กรณีอื่นๆ
    return 'pending';
}

/**
 * ดึงรายชื่อและอีเมลของผู้อนุมัติใน Level ถัดไป (หรือ Level 1 ถ้าถูก Reject)
 * 
 * @param array $allApproversByForm รายชื่อผู้อนุมัติทั้งหมด (จาก getAllApproversByForm)
 * @param int|null $approvedBy user_id ของผู้ที่ทำรายการล่าสุด
 * @param string|null $approvedStatus สถานะล่าสุด เช่น 'approved', 'rejected', 'pending'
 * @return array คืนค่าอาเรย์ของชื่อและอีเมล (เช่น [['firstname' => '...', 'email' => '...'], ...])
 */
function getNextApproverDetails(array $allApproversByForm, ?int $approvedBy, ?string $approvedStatus): array 
{
    $targetLevel = 1; // ค่าเริ่มต้นกรณีถูก Rejected จะวิ่งกลับไปที่ Level 1

    // 1. ถ้าสถานะเป็น approved ให้หาว่าคนล่าสุดอยู่ Level ไหน แล้วบวกเพิ่มไป 1 Level
    if (($approvedStatus === 'approved' || $approvedStatus === 'pending') && $approvedBy !== null) {
        $actorLevel = 0;

        // วนลูปหา level ของผู้ที่ทำรายการ ($approvedBy)
        foreach ($allApproversByForm as $row) {
            if ((int)$row['user_id'] === $approvedBy) {
                $actorLevel = (int)$row['approve_level'];
                break; // เจอแล้วหยุดหาได้เลย
            }
        }

        // กำหนดให้ Target Level เป็นขั้นถัดไปจากคนล่าสุด
        $targetLevel = $actorLevel + 1;
    } 
    // 2. ถ้าสถานะเป็น rejected หรือกรณีอื่นๆ $targetLevel จะถูกเซ็ตไว้ที่ 1 ตั้งแต่ต้นแล้ว

    $nextApprovers = [];

    // 3. กรองรายชื่อเฉพาะคนที่อยู่ใน $targetLevel ที่ต้องการ
    foreach ($allApproversByForm as $row) { 
        if ((int)$row['approve_level'] === $targetLevel) {
            $nextApprovers[] = [
                'approve_level' => $row['approve_level'],
                'user_id' => $row['user_id'],
                'firstname' => $row['firstname'],
                'email'     => $row['email']
            ];
        }
    }

    return $nextApprovers;
}

/**
 * 🎯 ฟังก์ชันสำหรับดึงรายการใบงานปิดงบประจำเดือนที่รอดำเนินการอนุมัติของ User เฉพาะคน (ใช้กับ Dashboard)
 * 
 * @param PDO $db ตัวเชื่อมต่อฐานข้อมูล PDO
 * @param int $userId รหัสผู้ใช้งานปัจจุบัน (เช่น 7)
 * @return array รายการใบงานทั้งหมดที่อยู่ในคิวที่ต้องกดอนุมัติของ User นี้
 */
function getMyPendingApprovals(PDO $db, int $userId): array
{
    $sql = "SELECT 
                mc.id AS closing_id,
                mc.status AS closing_status,
                mc.year,
                mc.month,
                mc.church_id,
                p.approve_level AS my_approve_level,
                u.firstname, 
                u.email
            FROM monthly_closings mc
            INNER JOIN approve_permissions p ON mc.church_id = p.org_id
            LEFT JOIN users u ON p.user_id = u.id 
            WHERE mc.status != 'approved'  
              AND p.request_form_id = 1    
              AND p.user_id = :user_id
              AND p.approve_level = CASE 
                  WHEN (
                      SELECT action_status 
                      FROM monthly_closing_logs 
                      WHERE closing_id = mc.id  
                      ORDER BY created_at DESC, id DESC 
                      LIMIT 1
                  ) = 'rejected' THEN 1 
                  ELSE (
                      COALESCE(
                          (
                              SELECT approve_level 
                              FROM monthly_closing_logs 
                              WHERE closing_id = mc.id 
                                AND action_status IN ('approved', 'pending')
                              ORDER BY created_at DESC, id DESC 
                              LIMIT 1
                          ), 0) + 1
                  )
              END
            ORDER BY mc.church_id ASC, p.approve_level ASC, mc.id DESC";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * ดึงรายชื่อผู้ที่มีสิทธิ์ต้องดำเนินการในระดับถัดไป (หรือถูกตีกลับมาที่ Level 1)
 * 
 * @param PDO $db ตัวเชื่อมต่อฐานข้อมูล PDO
 * @param int $closing_id รหัสการปิดงบ
 * @param int $org_id รหัสองค์กร
 * @param int $request_form_id รหัสรูปแบบฟอร์ม
 * @return array คืนค่าอาเรย์ของ User ที่ต้องดำเนินการ (มี user_id, firstname, email)
 */
// function getNextApprovers(PDO $db, int $closing_id, int $org_id, int $request_form_id): array
// {
//     $sql = "SELECT p.user_id, u.firstname, u.email 
//             FROM approve_permissions p
//             LEFT JOIN users u ON p.user_id = u.id 
//             WHERE p.org_id = :org_id_1 
//               AND p.request_form_id = :request_form_id_1
//               AND p.approve_level = CASE 
//                   WHEN (
//                       SELECT action_status 
//                       FROM monthly_closing_logs 
//                       WHERE closing_id = :closing_id_1 
//                       ORDER BY created_at DESC, id DESC 
//                       LIMIT 1
//                   ) = 'rejected' THEN 1 
//                   ELSE (
//                       COALESCE(
//                           (
//                               SELECT approve_level 
//                               FROM monthly_closing_logs 
//                               WHERE closing_id = :closing_id_2 
//                                 AND action_status IN ('approved', 'pending')
//                               ORDER BY created_at DESC, id DESC 
//                               LIMIT 1
//                           ), 0) + 1
//                   )
//               END";

//     $stmt = $db->prepare($sql);

//     // Bind ค่าพารามิเตอร์ทั้งหมดเพื่อความปลอดภัย
//     $stmt->bindValue(':org_id_1', $org_id, PDO::PARAM_INT);
//     $stmt->bindValue(':request_form_id_1', $request_form_id, PDO::PARAM_INT);
//     $stmt->bindValue(':closing_id_1', $closing_id, PDO::PARAM_INT);
//     $stmt->bindValue(':closing_id_2', $closing_id, PDO::PARAM_INT);

//     $stmt->execute();

//     // คืนค่าเป็น Array ข้อมูลผู้ใช้งานทั้งหมดที่ตรงกับเงื่อนไข
//     return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
// }

/**
 * ตรวจสอบสถานะการอนุมัติของฟอร์ม (Form Status)
 * รับค่า parameters: closing_id, org_id, และ request_form_id
 * Return ค่าเป็น string ('approved' หรือ 'pending')
 */
// function getMonthlyClosingFormStatus(PDO $db, int $closing_id, int $org_id, int $request_form_id): string
// {
//     $sql = "SELECT 
//                 CASE 
//                     WHEN (
//                         SELECT action_status 
//                         FROM monthly_closing_logs 
//                         WHERE closing_id = :closing_id_1 
//                         ORDER BY created_at DESC, id DESC 
//                         LIMIT 1
//                     ) = 'rejected'
//                     THEN 'rejected'
//                     WHEN (
//                         SELECT action_status 
//                         FROM monthly_closing_logs 
//                         WHERE closing_id = :closing_id_2 
//                         ORDER BY created_at DESC, id DESC 
//                         LIMIT 1
//                     ) = 'approved' 
//                     AND (
//                         SELECT approve_level 
//                         FROM monthly_closing_logs 
//                         WHERE closing_id = :closing_id_3 
//                         ORDER BY created_at DESC, id DESC 
//                         LIMIT 1
//                     ) = (
//                         SELECT MAX(approve_level) 
//                         FROM approve_permissions 
//                         WHERE org_id = :org_id AND request_form_id = :request_form_id
//                     ) 
//                     THEN 'approved'
//                     ELSE 'pending' 
//                 END AS form_status;";

//     $stmt = $db->prepare($sql);

//     // Bind ค่าพารามิเตอร์เพื่อความปลอดภัย
//     $stmt->bindValue(':closing_id_1', $closing_id, PDO::PARAM_INT);
//     $stmt->bindValue(':closing_id_2', $closing_id, PDO::PARAM_INT);
//     $stmt->bindValue(':closing_id_3', $closing_id, PDO::PARAM_INT);
//     $stmt->bindValue(':org_id', $org_id, PDO::PARAM_INT);
//     $stmt->bindValue(':request_form_id', $request_form_id, PDO::PARAM_INT);

//     $stmt->execute();
//     $result = $stmt->fetch(PDO::FETCH_ASSOC);

//     return $result['form_status'] ?? 'pending';
// }

function sendVerificationEmail($email, $firstname, $lastname, $token)
{
    $verifyUrl = $_ENV['APP_URL'] . '/verify-email?token=' . $token;

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $_ENV['MAIL_HOST'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $_ENV['MAIL_USERNAME'];
    $mail->Password   = $_ENV['MAIL_PASSWORD'];
    $mail->SMTPSecure = 'tls';
    // $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS; // สลับมาใช้ SSL แทน
    $mail->Port       = $_ENV['MAIL_PORT'];
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom($_ENV['MAIL_FROM'], $_ENV['MAIL_FROM_NAME']);
    $mail->addAddress($email, $firstname . ' ' . $lastname);
    $mail->Subject = 'ยืนยันบัญชีผู้ใช้งาน';
    $mail->isHTML(true);
    $mail->Body = "
        <p>สวัสดีคุณ {$firstname}</p>
        <p>กรุณากดลิงก์ด้านล่างเพื่อยืนยันบัญชีและตั้งรหัสผ่าน</p>
        <p><a href='{$verifyUrl}'>ยืนยันบัญชีผู้ใช้งาน</a></p>
        <p>ลิงก์นี้จะหมดอายุใน 24 ชั่วโมง</p>
    ";
    $mail->send();
}

function sendEmail($email, $name, $subject, $body)
{
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $_ENV['MAIL_HOST'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $_ENV['MAIL_USERNAME'];
    $mail->Password   = $_ENV['MAIL_PASSWORD'];
    $mail->SMTPSecure = 'tls';
    $mail->Port       = $_ENV['MAIL_PORT'];
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom($_ENV['MAIL_FROM'], $_ENV['MAIL_FROM_NAME']);
    $mail->addAddress($email, $name);
    $mail->Subject = $subject;
    $mail->isHTML(true);
    $mail->Body = $body;
    $mail->send();
}

function mailForNextApprover($toName, $orgName, $closingMonthYear): ?string
{
    $appUrl = $_ENV['APP_URL'];

$mailBody = "
<div style='max-width: 600px; margin: 0 auto; font-family: sans-serif; color: #333; background: #fff; border: 1px solid #eee; border-radius: 8px; overflow: hidden;'>
  
  <div style='background-color: #1c7ed6; color: #ffffff; padding: 20px; text-align: center;'>
    <h2 style='margin: 0; font-size: 18px;'>แจ้งเตือนรายการรออนุมัติปิดบัญชี</h2>
  </div>

  <div style='padding: 24px;'>
    <div style='font-size: 16px; font-weight: bold; margin-bottom: 16px;'>
      เรียน คุณ{$toName}
    </div>
    
    <p style='font-size: 14px; color: #495057; line-height: 1.6;'>
      ขอเรียนแจ้งให้ทราบว่า มีรายการรายงานปิดบัญชีประจำเดือนรอการตรวจสอบและอนุมัติจากท่าน โดยมีรายละเอียดดังต่อไปนี้
    </p>

    <div style='background-color: #f8f9fa; border-left: 4px solid #1c7ed6; padding: 16px; border-radius: 4px; margin: 20px 0;'>
      <table style='width: 100%; border-collapse: collapse; font-size: 14px;'>
        <tr>
          <td style='color: #6c757d; width: 40%; padding: 4px 0;'>หน่วยงาน / โบสถ์:</td>
          <td style='color: #212529; font-weight: bold; padding: 4px 0;'>{$orgName}</td>
        </tr>
        <tr>
          <td style='color: #6c757d; padding: 4px 0;'>รอบปิดงบ (เดือน/ปี):</td>
          <td style='color: #212529; font-weight: bold; padding: 4px 0;'>{$closingMonthYear}</td>
        </tr>
      </table>
    </div>

    <p style='font-size: 14px; color: #495057;'>
      กรุณาคลิกปุ่มด้านล่างเพื่อเข้าสู่ระบบและดำเนินการตรวจสอบรายการดังกล่าว
    </p>

    <div style='text-align: center; margin: 28px 0 16px 0;'>
      <a href='{$appUrl}' target='_blank' style='background-color: #1c7ed6; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 14px; display: inline-block;'>
        เข้าสู่ระบบเพื่อตรวจสอบและอนุมัติ
      </a>
    </div>
  </div>

  <div style='background-color: #f8f9fa; padding: 16px; text-align: center; font-size: 12px; color: #868e96; border-top: 1px solid #eee;'>
    <p style='margin: 0 0 4px 0;'>อีเมลฉบับนี้เป็นการแจ้งเตือนจากระบบอัตโนมัติ กรุณาอย่าตอบกลับอีเมลนี้</p>
    <div style='color: #e03131;'>
      * เพื่อความปลอดภัยของข้อมูลการเงิน โปรดอย่าส่งต่ออีเมลนี้ให้แก่ผู้อื่น
    </div>
  </div>

</div>
";

    return $mailBody;
}

function mailApproveForRequester($toName, $orgName, $closingMonthYear): ?string
{
    $appUrl = $_ENV['APP_URL'];

$mailBody = "
<div style='max-width: 600px; margin: 0 auto; font-family: sans-serif; color: #333; background: #fff; border: 1px solid #eee; border-radius: 8px; overflow: hidden;'>
  
  <div style='background-color: #1c7ed6; color: #ffffff; padding: 20px; text-align: center;'>
    <h2 style='margin: 0; font-size: 18px;'>แจ้งเตือนรายการรออนุมัติปิดบัญชี</h2>
  </div>

  <div style='padding: 24px;'>
    <div style='font-size: 16px; font-weight: bold; margin-bottom: 16px;'>
      เรียน คุณ{$toName}
    </div>
    
    <p style='font-size: 14px; color: #495057; line-height: 1.6;'>
      ขอเรียนแจ้งให้ทราบเกี่ยวกับผลการอนุมัติรายงานปิดบัญชีประจำเดือน โดยมีรายละเอียดดังต่อไปนี้
    </p>

    <div style='background-color: #f8f9fa; border-left: 4px solid #1c7ed6; padding: 16px; border-radius: 4px; margin: 20px 0;'>
      <table style='width: 100%; border-collapse: collapse; font-size: 14px;'>
        <tr>
          <td style='color: #6c757d; width: 40%; padding: 4px 0;'>หน่วยงาน / โบสถ์:</td>
          <td style='color: #212529; font-weight: bold; padding: 4px 0;'>{$orgName}</td>
        </tr>
        <tr>
          <td style='color: #6c757d; padding: 4px 0;'>รอบปิดงบ (เดือน/ปี):</td>
          <td style='color: #212529; font-weight: bold; padding: 4px 0;'>{$closingMonthYear}</td>
        </tr>
        <tr>
          <td style='color: #6c757d; padding: 4px 0;'>ผลการอนุมัติ:</td>
          <td style='color: #212529; font-weight: bold; padding: 4px 0;'>ถูก Approved แล้ว</td>
        </tr>
      </table>
    </div>
  </div>

  <div style='background-color: #f8f9fa; padding: 16px; text-align: center; font-size: 12px; color: #868e96; border-top: 1px solid #eee;'>
    <p style='margin: 0 0 4px 0;'>อีเมลฉบับนี้เป็นการแจ้งเตือนจากระบบอัตโนมัติ กรุณาอย่าตอบกลับอีเมลนี้</p>
    <div style='color: #e03131;'>
      * เพื่อความปลอดภัยของข้อมูลการเงิน โปรดอย่าส่งต่ออีเมลนี้ให้แก่ผู้อื่น
    </div>
  </div>

</div>
";

    return $mailBody;
}

function mailRejectForRequester($toName, $orgName, $closingMonthYear): ?string
{
    $appUrl = $_ENV['APP_URL'];

$mailBody = "
<div style='max-width: 600px; margin: 0 auto; font-family: sans-serif; color: #333; background: #fff; border: 1px solid #eee; border-radius: 8px; overflow: hidden;'>
  
  <div style='background-color: #1c7ed6; color: #ffffff; padding: 20px; text-align: center;'>
    <h2 style='margin: 0; font-size: 18px;'>แจ้งเตือนรายการรออนุมัติปิดบัญชี</h2>
  </div>

  <div style='padding: 24px;'>
    <div style='font-size: 16px; font-weight: bold; margin-bottom: 16px;'>
      เรียน คุณ{$toName}
    </div>
    
    <p style='font-size: 14px; color: #495057; line-height: 1.6;'>
      ขอเรียนแจ้งให้ทราบเกี่ยวกับผลการอนุมัติรายงานปิดบัญชีประจำเดือน โดยมีรายละเอียดดังต่อไปนี้
    </p>

    <div style='background-color: #f8f9fa; border-left: 4px solid #1c7ed6; padding: 16px; border-radius: 4px; margin: 20px 0;'>
      <table style='width: 100%; border-collapse: collapse; font-size: 14px;'>
        <tr>
          <td style='color: #6c757d; width: 40%; padding: 4px 0;'>หน่วยงาน / โบสถ์:</td>
          <td style='color: #212529; font-weight: bold; padding: 4px 0;'>{$orgName}</td>
        </tr>
        <tr>
          <td style='color: #6c757d; padding: 4px 0;'>รอบปิดงบ (เดือน/ปี):</td>
          <td style='color: #212529; font-weight: bold; padding: 4px 0;'>{$closingMonthYear}</td>
        </tr>
        <tr>
          <td style='color: #6c757d; padding: 4px 0;'>ผลการอนุมัติ:</td>
          <td style='color: #212529; font-weight: bold; padding: 4px 0;'>ถูก Rejected</td>
        </tr>
      </table>
    </div>

    <p style='font-size: 14px; color: #495057;'>
      กรุณาคลิกปุ่มด้านล่างเพื่อเข้าสู่ระบบและดำเนินการตรวจสอบรายการดังกล่าว
    </p>

    <div style='text-align: center; margin: 28px 0 16px 0;'>
      <a href='{$appUrl}' target='_blank' style='background-color: #1c7ed6; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 14px; display: inline-block;'>
        เข้าสู่ระบบเพื่อดูรายละเอียดการอนุมัติ
      </a>
    </div>
  </div>

  <div style='background-color: #f8f9fa; padding: 16px; text-align: center; font-size: 12px; color: #868e96; border-top: 1px solid #eee;'>
    <p style='margin: 0 0 4px 0;'>อีเมลฉบับนี้เป็นการแจ้งเตือนจากระบบอัตโนมัติ กรุณาอย่าตอบกลับอีเมลนี้</p>
    <div style='color: #e03131;'>
      * เพื่อความปลอดภัยของข้อมูลการเงิน โปรดอย่าส่งต่ออีเมลนี้ให้แก่ผู้อื่น
    </div>
  </div>

</div>
";

    return $mailBody;
}

function mailForNewPassword($toName, $url): ?string
{
    $mailBody = "
<div style='max-width: 600px; margin: 0 auto; font-family: sans-serif; color: #333; background: #fff; border: 1px solid #eee; border-radius: 8px; overflow: hidden;'>
  
  <div style='background-color: #2b8a3e; color: #ffffff; padding: 20px; text-align: center;'>
    <h2 style='margin: 0; font-size: 18px;'>คำขอตั้งรหัสผ่านใหม่</h2>
  </div>

  <div style='padding: 24px;'>
    <div style='font-size: 16px; font-weight: bold; margin-bottom: 16px;'>
      เรียน คุณ{$toName}
    </div>
    
    <p style='font-size: 14px; color: #495057; line-height: 1.6;'>
      เราได้รับคำขอรีเซ็ตรหัสผ่านสำหรับบัญชีผู้ใช้งานของคุณ หากคุณเป็นผู้ส่งคำขอนี้ กรุณาคลิกปุ่มด้านล่างเพื่อดำเนินการตั้งรหัสผ่านใหม่
    </p>

    <div style='text-align: center; margin: 28px 0;'>
      <a href='{$url}' target='_blank' style='background-color: #2b8a3e; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 14px; display: inline-block;'>
        ตั้งรหัสผ่านใหม่
      </a>
    </div>

  </div>

  <div style='background-color: #f8f9fa; padding: 16px; text-align: center; font-size: 12px; color: #868e96; border-top: 1px solid #eee;'>
    <p style='margin: 0 0 4px 0;'>หากคุณไม่ได้เป็นผู้ส่งคำขอนี้ โปรดละเลยอีเมลนี้ รหัสผ่านของคุณจะไม่ถูกเปลี่ยนแปลง</p>
    <div style='color: #e03131;'>
      * เพื่อความปลอดภัย โปรดอย่าส่งต่ออีเมลนี้หรือลิงก์ด้านบนให้แก่ผู้อื่น
    </div>
  </div>

</div>
";

// <p style='font-size: 13px; color: #868e96; line-height: 1.5;'>
//       หากปุ่มด้านบนใช้งานไม่ได้ คุณสามารถคัดลอกลิงก์ด้านล่างนี้ไปวางในเว็บเบราว์เซอร์ของคุณ:<br>
//       <a href='{$url}' style='color: #2b8a3e; word-break: break-all;'>{$url}</a>
//     </p>

    return $mailBody;
}