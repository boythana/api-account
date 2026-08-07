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

    // 🔒 [เขตหวงห้าม] รวบเส้นที่เกี่ยวข้องกับสิทธิ์ทั้งหมดเข้าด่านตรวจ JWT บรรทัดเดียวจบ
    $app->group('/api', function (Group $group) {

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