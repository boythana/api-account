<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group; // 👈 เพิ่มตัวจัดการกลุ่มเข้ามา
use App\Application\Middleware\JwtAuthMiddleware; // 👈 เรียกใช้งานมิดเดิลแวร์ของเรา
use Firebase\JWT\JWT;

return function (App $app) {

    // 🚪 [ประตูบานแรก] ปล่อยไว้นอกด่านตรวจ JWT เพื่อให้ทุกคนยิงเข้ามาล็อคอินได้ปกติ
    $app->post('/api/login', function ($request, $response) {
        $data = $request->getParsedBody();
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        if (empty($email) || empty($password)) {
            $result = ['status' => 'error', 'message' => 'โปรดระบุอีเมลและรหัสผ่าน'];
            $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $db = $this->get(PDO::class);

        $stmt = $db->prepare("SELECT id, firstname, lastname, email, password FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {

            $stmt = $db->prepare("SELECT role_id from user_role where user_id = :userId LIMIT 1");
            $stmt->execute([':userId' => $user['id']]);
            $userRole = $stmt->fetch();

            $secretKey = $_ENV['JWT_SECRET'];
            $issuedAt = time();
            $expire = $issuedAt + (60 * 60 * 24);

            $payload = [
                'iat'  => $issuedAt,
                'exp'  => $expire,
                'uid'  => $user['id'],
                'name' => $user['firstname'],
                'role' => $userRole['role_id']
            ];

            $token = JWT::encode($payload, $secretKey, 'HS256');

            unset($user['password']);

            $result = [
                'status' => 'success',
                'message' => 'เข้าสู่ระบบสำเร็จ',
                'user' => $user,
                'token' => $token
            ];
            $status = 200;
        } else {
            $result = [
                'status' => 'error',
                'message' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง'
            ];
            $status = 401;
        }

        $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    });

    // 🆕 API สำหรับจัดการกรณีลืมรหัสผ่าน (บันทึก Token ลงตาราง user_verifications และส่ง URL ไปทางอีเมล)
    $app->post('/api/forgot-password', function ($request, $response) {
        $data = $request->getParsedBody();
        $email = $data['email'] ?? '';

        if (empty($email)) {
            $result = ['status' => 'error', 'message' => 'โปรดระบุอีเมลผู้ใช้งาน'];
            $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $db = $this->get(PDO::class);

        // 1. ตรวจสอบว่ามีอีเมลนี้อยู่ในระบบจริงหรือไม่
        $stmt = $db->prepare("SELECT id, firstname FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if (!$user) {
            // เพื่อความปลอดภัย แจ้งเตือนกลางๆ หรือแจ้งว่าส่งสำเร็จแล้ว (ป้องกันการสุ่มเช็คอีเมล)
            $result = ['status' => 'error', 'message' => 'ไม่พบอีเมลนี้ในระบบ'];
            $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // 2. สร้าง Token สำหรับ Verify และกำหนดเวลาหมดอายุ (เช่น หมดอายุใน 1 ชั่วโมง)
        $userId = $user['id'];
        $token = bin2hex(random_bytes(32));
        $expiredAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

        // 3. บันทึกลงตาราง user_verifications ตามโครงสร้างที่ต้องการ
        $stmt = $db->prepare("INSERT INTO user_verifications (user_id, token, expired_at) VALUES (:user_id, :token, :expired_at)");
        $stmt->execute([
            ':user_id' => $userId,
            ':token' => $token,
            ':expired_at' => $expiredAt
        ]);

        // 4. สร้าง URL สำหรับให้ผู้ใช้คลิกจากอีเมล (ตัวอย่างชี้ไปที่ Frontend หน้า Reset Password)
        // สามารถปรับเปลี่ยน Domain หรือ Path ตามหน้าบ้านของคุณได้
        // $_ENV['WEB_URL'];
        $resetUrl = $_ENV['WEB_URL'] . "/reset-new-password?token=" . $token;

        // 5. (ส่วนส่งอีเมล) ส่ง URL ไปยังอีเมลของ User
        date_default_timezone_set('Asia/Bangkok');
        $subject = "แจ้งขอเปลี่ยนรหัสผ่านใหม่ระบบบัญชี Nexus เมื่อ ".date('d-M-Y H:i');
        
        $mailBody = mailForNewPassword($user['firstname'], $resetUrl);

        try {
            sendEmail($email, $user['firstname'], $subject, $mailBody);
        } catch (\Exception $mailEx) {
            // บันทึก Log ความผิดพลาดเรื่องเมลไว้ แต่ปล่อยให้โปรแกรมทำงานต่อได้
        }

        $result = [
            'status' => 'success',
            'message' => 'ระบบได้ส่งลิงก์สำหรับตั้งรหัสผ่านใหม่ไปยังอีเมลของคุณแล้ว',
            'debug_url' => $resetUrl // แนบมาให้ดูเป็นตัวอย่างตอนทดสอบ (สามารถลบออกได้ตอนขึ้น Production)
        ];

        $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    });

    // 🚪 1. API สำหรับตรวจสอบความถูกต้องของ Token เมื่อ User คลิกมาจากอีเมล
    $app->get('/api/verify-reset-token', function ($request, $response) {
        $queryParams = $request->getQueryParams();
        $token = $queryParams['token'] ?? '';

        if (empty($token)) {
            $result = ['status' => 'error', 'message' => 'ไม่พบ Token สำหรับตรวจสอบ'];
            $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $db = $this->get(PDO::class);

        // ค้นหา Token ในตาราง user_verifications และเช็คว่ายังไม่หมดอายุ (expired_at > เวลาปัจจุบัน)
        $stmt = $db->prepare("SELECT user_id, expired_at FROM user_verifications WHERE token = :token LIMIT 1");
        $stmt->execute([':token' => $token]);
        $verification = $stmt->fetch();

        if (!$verification) {
            $result = ['status' => 'error', 'message' => 'Token ไม่ถูกต้องหรือถูกใช้งานไปแล้ว'];
            $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // ตรวจสอบวันหมดอายุ
        if (strtotime($verification['expired_at']) < time()) {
            $result = ['status' => 'error', 'message' => 'ลิงก์นี้หมดอายุแล้ว กรุณาขอรหัสผ่านใหม่อีกครั้ง'];
            $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $result = [
            'status' => 'success',
            'message' => 'Token ถูกต้อง',
            'user_id' => $verification['user_id']
        ];

        $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    });

    // 🚪 2. API สำหรับบันทึกรหัสผ่านใหม่
    $app->post('/api/reset-password', function ($request, $response) {
        $data = $request->getParsedBody();
        $token = $data['token'] ?? '';
        $newPassword = $data['new_password'] ?? '';

        if (empty($token) || empty($newPassword)) {
            $result = ['status' => 'error', 'message' => 'ข้อมูลไม่ครบถ้วน'];
            $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $db = $this->get(PDO::class);

        // 1. ตรวจสอบ Token อีกครั้งเพื่อความปลอดภัย
        $stmt = $db->prepare("SELECT user_id, expired_at FROM user_verifications WHERE token = :token LIMIT 1");
        $stmt->execute([':token' => $token]);
        $verification = $stmt->fetch();

        if (!$verification || strtotime($verification['expired_at']) < time()) {
            $result = ['status' => 'error', 'message' => 'Token ไม่ถูกต้องหรือหมดอายุแล้ว'];
            $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $userId = $verification['user_id'];
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

        try {
            $db->beginTransaction();

            // 2. อัปเดตรหัสผ่านใหม่ให้กับผู้ใช้งาน
            $updateStmt = $db->prepare("UPDATE users SET password = :password WHERE id = :id");
            $updateStmt->execute([
                ':password' => $hashedPassword,
                ':id' => $userId
            ]);

            // 3. ลบ Token ออกจากตาราง user_verifications เพื่อไม่ให้สามารถนำกลับมาใช้ซ้ำได้
            $deleteStmt = $db->prepare("DELETE FROM user_verifications WHERE token = :token");
            $deleteStmt->execute([':token' => $token]);

            $db->commit();

            $result = ['status' => 'success', 'message' => 'เปลี่ยนรหัสผ่านสำเร็จ คุณสามารถเข้าสู่ระบบด้วยรหัสผ่านใหม่ได้เลย'];
            $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (\PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $result = ['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูล: ' . $e->getMessage()];
            $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });

    // 🔒 [เขตหวงห้าม] รวบเส้นที่เกี่ยวข้องกับสิทธิ์ทั้งหมดเข้าด่านตรวจ JWT บรรทัดเดียวจบ
    $app->group('/api', function (Group $group) {

        // 🆕 ดึงข้อมูลสิทธิ์การอนุมัติ (Approve Permission) รับค่าผ่าน Query Parameters
        $group->get('/approve-permission', function (Request $request, Response $response) {
            $queryParams = $request->getQueryParams();
            $churchId = $queryParams['church_id'] ?? '';
            $requestFormId = $queryParams['request_form_id'] ?? '';

            // ตรวจสอบความถูกต้องของข้อมูลนำเข้า
            if (empty($churchId) || empty($requestFormId)) {
                $result = ['status' => 'error', 'message' => 'โปรดระบุ church_id และ request_form_id ให้ครบถ้วน'];
                $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $db = $this->get(PDO::class);
            $sql = "SELECT user_id, approve_level 
                    FROM approve_permissions
                    WHERE org_id = :church_id
                    AND request_form_id = :request_form_id
                    ORDER BY approve_level";

            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':church_id' => $churchId,
                ':request_form_id' => $requestFormId
            ]);
            $results = $stmt->fetchAll();

            $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        });

        // ดึง Role ของผู้ใช้งาน
        $group->get('/user-roles/{userId}', function (Request $request, Response $response, array $args) {
            $userId = $args['userId'];
            if (empty($userId)) {
                $response->getBody()->write(json_encode([]));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $db = $this->get(PDO::class);
            $sql = "SELECT ur.role_id, r.role_name 
                    FROM user_role ur
                    LEFT JOIN roles r on ur.role_id = r.id  
                    WHERE ur.user_id = :userId
                    AND ur.role_id != 1";

            $stmt = $db->prepare($sql);
            $stmt->execute([':userId' => $userId]);
            $results = $stmt->fetchAll();

            $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        });

        // ดึงข้อมูลสิทธิ์ข้อมูลหลัก (Master Permission)
        $group->get('/permission-data-master/{userId}', function (Request $request, Response $response, array $args) {
            $userId = $args['userId'];
            if (empty($userId)) {
                $response->getBody()->write(json_encode([]));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $db = $this->get(PDO::class);
            $sql = "SELECT udp.scope_type , udp.scope_id, udp.permission  
                    from user_data_permissions udp 
                    where udp.user_id = :userId
                    and udp.scope_type != 'system'";

            $stmt = $db->prepare($sql);
            $stmt->execute([':userId' => $userId]);
            $results = $stmt->fetchAll();

            $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        });

        // ดึงข้อมูลสิทธิ์ผูกพันโบสถ์
        $group->get('/permission-data/{userId}', function (Request $request, Response $response, array $args) {
            $userId = $args['userId'];
            if (empty($userId)) {
                $response->getBody()->write(json_encode([]));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $db = $this->get(PDO::class);
            $sql = "SELECT 
                        c.id AS church_id, 
                        c.name AS church_name,
                        MAX(udp.permission) AS permission
                    FROM churches c
                    JOIN user_data_permissions udp ON (
                        udp.user_id = :userId AND (
                            udp.scope_type = 'system'
                            OR (udp.scope_type = 'region' AND udp.scope_id = c.region_id)
                            OR (udp.scope_type = 'church' AND udp.scope_id = c.id)
                        )
                    )
                    GROUP BY c.id, c.name";

            $stmt = $db->prepare($sql);
            $stmt->execute([':userId' => $userId]);
            $results = $stmt->fetchAll();

            $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        });

        // ดึงสิทธิ์เมนูสำหรับขึ้น Sidebar
        $group->get('/permission-menu/{userId}', function (Request $request, Response $response, array $args) {
            $userId = $args['userId'];
            if (empty($userId)) {
                $response->getBody()->write(json_encode([]));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $db = $this->get(PDO::class);
            $sql = "SELECT 
                        m.id AS menu_id,
                        m.label AS menu_label,
                        m.menu_key AS menu_key,
                        m.sort_order,
                        MAX(rp.permission) AS permission
                    FROM menus m
                    JOIN role_permissions rp ON m.id = rp.menu_id
                    JOIN user_role ur ON rp.role_id = ur.role_id AND ur.user_id = :userId
                    GROUP BY m.id, m.label, m.menu_key, m.sort_order
                    ORDER BY m.sort_order ASC";

            $stmt = $db->prepare($sql);
            $stmt->execute([':userId' => $userId]);
            $results = $stmt->fetchAll();

            $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        });

        // อัปเดตสิทธิ์ของ User
        $group->put('/user-permissions/{userId}', function (Request $request, Response $response, array $args) {
            $userId = $args['userId'];
            $body = $request->getParsedBody();
            $permissions = $body['permissions'] ?? [];

            if (empty($userId)) {
                $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'ไม่พบ userId'], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $db = $this->get(PDO::class);

            try {
                $db->beginTransaction();
                $stmt = $db->prepare("DELETE FROM user_data_permissions WHERE user_id = :userId AND scope_type != 'system'");
                $stmt->execute([':userId' => $userId]);

                if (!empty($permissions)) {
                    $stmt = $db->prepare("INSERT INTO user_data_permissions (user_id, scope_type, scope_id, permission) VALUES (:user_id, :scope_type, :scope_id, :permission)");
                    foreach ($permissions as $perm) {
                        $stmt->execute([
                            ':user_id'    => $userId,
                            ':scope_type' => $perm['scope_type'],
                            ':scope_id'   => $perm['scope_id'],
                            ':permission' => $perm['permission'],
                        ]);
                    }
                }

                $db->commit();
                $response->getBody()->write(json_encode(['status' => 'success', 'message' => 'บันทึกสิทธิ์เรียบร้อยแล้ว'], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json');
            } catch (\PDOException $e) {
                if ($db->inTransaction()) $db->rollBack();
                $response->getBody()->write(json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }
        });

        // อัปเดตบทบาทหน้าที่ (Role) ของ User
        $group->put('/user-roles/{userId}', function (Request $request, Response $response, array $args) {
            $userId = $args['userId'];
            $body = $request->getParsedBody();
            $roleIds = $body['role_ids'] ?? [];

            if (empty($userId)) {
                $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'ไม่พบ userId'], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $db = $this->get(PDO::class);

            try {
                $db->beginTransaction();
                $stmt = $db->prepare("DELETE FROM user_role WHERE user_id = :userId AND role_id != 1");
                $stmt->execute([':userId' => $userId]);

                if (!empty($roleIds)) {
                    $stmt = $db->prepare("INSERT INTO user_role (user_id, role_id) VALUES (:user_id, :role_id)");
                    foreach ($roleIds as $roleId) {
                        $stmt->execute([
                            ':user_id' => $userId,
                            ':role_id' => $roleId,
                        ]);
                    }
                }

                $db->commit();
                $response->getBody()->write(json_encode(['status' => 'success', 'message' => 'บันทึก Role เรียบร้อยแล้ว'], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json');
            } catch (\PDOException $e) {
                if ($db->inTransaction()) $db->rollBack();
                $response->getBody()->write(json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }
        });
    })->add(new JwtAuthMiddleware()); // 👈 ตบคุมความปลอดภัยท้ายกลุ่ม ยกเว้นหน้า Login ไว้ข้างนอกเรียบร้อยครับ
};
