<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

return function (App $app) {
    $app->get('/api/users', function ($request, $response) {
        // ดึง PDO จาก Container
        $db = $this->get(PDO::class);

        $query = $db->query("SELECT id, firstname, lastname, email, phone FROM users");
        $users = $query->fetchAll();

        // สำหรับให้ return เป็นภาษาไทยได้
        $payload = json_encode($users, JSON_UNESCAPED_UNICODE);

        $response->getBody()->write($payload);

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus(200);

        return $response;
    });

    $app->get('/api/user/staffs', function (Request $request, Response $response) {
        // ดึง PDO จาก Container
        $data = $request->getParsedBody();

        $db = $this->get(PDO::class);

        $query = $db->query("SELECT id, firstname, lastname FROM users ORDER BY firstname ");
        $users = $query->fetchAll();

        // สำหรับให้ return เป็นภาษาไทยได้
        $payload = json_encode($users, JSON_UNESCAPED_UNICODE);

        $response->getBody()->write($payload);

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus(200);

        return $response;
    });

    $app->get('/api/user/person-wallet-list', function (Request $request, Response $response) {
        // 🎯 เปลี่ยนเป็นดึงพารามิเตอร์จาก Query string สำหรับ GET Request
        $queryParams = $request->getQueryParams();
        $churchId = isset($queryParams['church_id']) ? (int)$queryParams['church_id'] : null;

        // 🎯 เช็กเงื่อนไข: ถ้าไม่มีการส่ง church_id มา หรือค่าเป็น 0 ให้ส่งผลลัพธ์ว่างกลับทันที
        if (empty($churchId)) {
            $response->getBody()->write(json_encode([], JSON_UNESCAPED_UNICODE));
            return $response
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withStatus(200);
        }

        $db = $this->get(PDO::class);

        // 🎯 ใช้ SQL Query ตัวใหม่พร้อมผูก Parameter เพื่อความปลอดภัย
        $sql = "SELECT upw.user_id as value, CONCAT(u.firstname, ' ', u.lastname) AS label
                FROM user_person_wallet upw 
                LEFT JOIN users u on upw.user_id = u.id 
                WHERE upw.church_id = :church_id
                ORDER BY u.firstname";

        $stmt = $db->prepare($sql);
        $stmt->execute([':church_id' => $churchId]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // สำหรับให้ return เป็นภาษาไทยได้
        $payload = json_encode($users, JSON_UNESCAPED_UNICODE);

        $response->getBody()->write($payload);

        // เอา return $response; ตัวที่เกินด้านล่างสุดออกให้เรียบร้อยครับ
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus(200);
    });

    $app->post('/api/users', function (Request $request, Response $response) {
        $data = $request->getParsedBody();
        $db = $this->get(PDO::class);

        $firstname = $data['firstname'] ?? '';
        $lastname  = $data['lastname'] ?? '';
        $email     = $data['email'] ?? '';

        if (empty($firstname) || empty($lastname) || empty($email)) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน'], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $db->beginTransaction();

            // สร้าง token สำหรับยืนยัน
            $token = bin2hex(random_bytes(32));
            $expiredAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

            // insert user
            $stmt = $db->prepare("INSERT INTO users (firstname, lastname, email, is_verified) VALUES (:fname, :lname, :email, 0)");
            $stmt->execute([':fname' => $firstname, ':lname' => $lastname, ':email' => $email]);
            $userId = $db->lastInsertId();

            // insert token
            $stmt = $db->prepare("INSERT INTO user_verifications (user_id, token, expired_at) VALUES (:user_id, :token, :expired_at)");
            $stmt->execute([':user_id' => $userId, ':token' => $token, ':expired_at' => $expiredAt]);

            $db->commit();

            // ส่งเมล
            //TODO
            // sendVerificationEmail($email, $firstname, $lastname, $token);

            $response->getBody()->write(json_encode(['status' => 'success', 'message' => 'สร้างผู้ใช้งานเรียบร้อย กรุณาตรวจสอบอีเมลเพื่อยืนยันบัญชี'], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
        } catch (Exception $e) {
            $db->rollBack();
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'ไม่สามารถส่งอีเมลได้: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });

    $app->put('/api/users/{id}', function (Request $request, Response $response, array $args) {
        $id = $args['id'];
        $data = $request->getParsedBody();
        $db = $this->get(PDO::class);

        if (empty($data)) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'No data provided']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $fields = [];
        $params = [':id' => $id];

        // ข้อมูลพื้นฐาน
        if (isset($data['firstname'])) {
            $fields[] = "firstname = :fname";
            $params[':fname'] = $data['firstname'];
        }
        if (isset($data['lastname'])) {
            $fields[] = "lastname = :lname";
            $params[':lname'] = $data['lastname'];
        }
        if (isset($data['email'])) {
            $fields[] = "email = :email";
            $params[':email'] = $data['email'];
        }
        if (isset($data['phone'])) {
            $fields[] = "phone = :phone";
            $params[':phone'] = $data['phone'];
        }

        // --- ส่วนของรหัสผ่าน ---
        // ปรับให้เช็คค่าว่างด้วย เพราะใน React ถ้าไม่แก้เราจะส่งค่าว่างมา
        if (!empty($data['password'])) {
            $hashedPwd = password_hash($data['password'], PASSWORD_DEFAULT);
            $fields[] = "password = :pwd";
            $params[':pwd'] = $hashedPwd;
        }

        if (empty($fields)) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'No valid fields to update']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            // ส่ง status: success กลับไปด้วยเพื่อให้ notifications ใน React ทำงานง่าย
            $response->getBody()->write(json_encode([
                'status' => 'success',
                'message' => 'User updated successfully'
            ], JSON_UNESCAPED_UNICODE));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (PDOException $e) {
            // กรณี Email ซ้ำ หรือ Error อื่นๆ จาก DB
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });

    $app->get('/api/users/search', function ($request, $response) {
        $params = $request->getQueryParams();
        $search = $params['search'] ?? '';
        $page = (int)($params['page'] ?? 1);
        $limit = (int)($params['limit'] ?? 20);
        $offset = ($page - 1) * $limit;

        $db = $this->get(PDO::class);

        // 1. หาจำนวนทั้งหมดเพื่อเอาไปคำนวณหน้า (Pagination)
        $countSql = "SELECT COUNT(*) FROM users WHERE firstname LIKE :q OR email LIKE :q";
        $stmtCount = $db->prepare($countSql);
        $stmtCount->execute([':q' => "%$search%"]);
        $totalCount = $stmtCount->fetchColumn();

        // 2. ดึงข้อมูลแบบมี Limit และ Offset
        $sql = "SELECT id, firstname, lastname, email FROM users 
            WHERE firstname LIKE :q OR email LIKE :q 
            ORDER BY firstname ASC 
            LIMIT :limit OFFSET :offset";

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':q', "%$search%", PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $users = $stmt->fetchAll();

        $result = [
            'data' => $users,
            'total_count' => (int)$totalCount,
            'total_pages' => ceil($totalCount / $limit),
            'current_page' => $page
        ];

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $app->get('/api/users/{id}', function (Request $request, Response $response, array $args) {
        $id = $args['id'];
        $db = $this->get(PDO::class);

        try {
            if (!is_numeric($id)) {
                throw new Exception("Invalid ID format");
            }

            // 1. Query ข้อมูลหลักจากตาราง transactions
            $stmt = $db->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($result) {
                $status = 200;
                $payload = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } else {
                $status = 404;
                $payload = json_encode(['error' => 'ไม่พบข้อมูลรายการนี้'], JSON_UNESCAPED_UNICODE);
            }
        } catch (Exception $e) {
            $status = 500;
            $payload = json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }

        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    });

    // $app->get('/api/users/search', function (Request $request, Response $response) {
    //     // 1. ดึงค่าจาก Query Parameter (เช่น ?q=keyword)
    //     $queryParams = $request->getQueryParams();
    //     $searchTerm = $queryParams['searchTerm'] ?? '';

    //     // ถ้าไม่ได้ส่งคำค้นมา ให้คืนค่าว่างหรือ Error ก็ได้ครับ
    //     if (empty($searchTerm)) {
    //         $response->getBody()->write(json_encode([]));
    //         return $response->withHeader('Content-Type', 'application/json');
    //     }

    //     $db = $this->get(PDO::class);

    //     // 2. เขียน SQL โดยใช้ OR และ LIKE
    //     // เราใช้ % ล้อมหน้าหลัง keyword เพื่อให้หาคำที่ "มีส่วนประกอบของ..."
    //     $sql = "SELECT id, firstname, lastname, email, phone
    //         FROM users 
    //         WHERE firstname LIKE :q1 
    //         OR email LIKE :q2";

    //     $stmt = $db->prepare($sql);

    //     // ใส่ % เข้าไปในตัวแปร parameter
    //     $likeTerm = "%$searchTerm%";
    //     $stmt->execute([
    //         ':q1' => $likeTerm,
    //         ':q2' => $likeTerm
    //     ]);

    //     $results = $stmt->fetchAll();

    //     // 3. ส่งผลลัพธ์กลับ
    //     $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
    //     return $response->withHeader('Content-Type', 'application/json');
    // });
};
