<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group; // 👈 เพิ่มคลาสจัดการกลุ่ม
use App\Application\Middleware\JwtAuthMiddleware; // 👈 เรียกมิดเดิลแวร์เข้ามา

return function (App $app) {

    // 🔒 จัดกลุ่ม Master API ทั้งหมดให้อยู่ภายใต้ด่านตรวจ JWT ร่วมกัน
    $app->group('/api', function (Group $group) {

        // 1. ดึงข้อมูลโบสถ์ทั้งหมด
        $group->get('/master/churches', function (Request $request, Response $response) {
            $db = $this->get(PDO::class);
            $sql = "SELECT ch.id as value, ch.name as label, r.id as region_id, r.name as region_name
                    FROM churches ch
                    left join regions r on ch.region_id = r.id 
                    order by r.id , ch.name";
            $data = $db->query($sql)->fetchAll();

            $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(200);
        });

        // 2. ดึงข้อมูลหมวดหมู่ (พร้อมใส่ Logic แยกสิทธิ์ที่เราคุยกันไว้)
        $group->get('/master/categories', function (Request $request, Response $response) {
            $userRole = $request->getAttribute('userRole'); // 😎 ดึง Role จาก JWT มาเช็กได้ทันที
            $db = $this->get(PDO::class);

            if ($userRole == 3) {
                // ถ้าเป็น Staff (Role 3) ล็อกให้เห็นเฉพาะ 42 และ 319 ตามแผน
                $sql = "SELECT id as value, name as label FROM account_categories WHERE type ='expense' ORDER BY name ASC";
            } else {
                // Role อื่นๆ เห็นหมวดหมู่ทั้งหมด
                $sql = "SELECT id as value, name as label FROM account_categories ORDER BY name ASC";
            }

            $data = $db->query($sql)->fetchAll();
            $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(200);
        });

        // 3. ดึงสิทธิ์/บทบาททั้งหมด
        $group->get('/master/roles', function (Request $request, Response $response) {
            $db = $this->get(PDO::class);
            $data = $db->query("SELECT id AS role_id, role_name FROM roles")->fetchAll();
            $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(200);
        });

        // get all Missions
        $group->get('/master/missions', function (Request $request, Response $response) {
            $db = $this->get(PDO::class);
            $data = $db->query("SELECT id AS value, name as label FROM missions ORDER BY name ")->fetchAll();
            $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(200);
        });

        // get all Departments
        $group->get('/master/departments', function (Request $request, Response $response) {
            $db = $this->get(PDO::class);
            $data = $db->query("SELECT id AS value, name as label FROM departments ORDER BY name ")->fetchAll();
            $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(200);
        });

        // 4. ค้นหาหมวดหมู่แยกตามประเภท
        $group->get('/master/categories/type', function (Request $request, Response $response) {
            $queryParams = $request->getQueryParams();
            $db = $this->get(PDO::class);

            $conditions = [];
            $params = [];

            if (!empty($queryParams['type'])) {
                $conditions[] = "ac.type = :type";
                $params[':type'] = $queryParams['type'];
            }

            $sql = "SELECT ac.id category_id, ac.name category_name , ag.name category_group
                    FROM account_categories ac 
                    left join account_group ag on ac.group = ag.id";

            if (!empty($conditions)) {
                $sql .= " WHERE " . implode(" AND ", $conditions);
            }

            $sql .= " ORDER BY ag.id , ac.name ";

            try {
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $results = $stmt->fetchAll();
                $status = 200;
                $payload = json_encode($results, JSON_UNESCAPED_UNICODE);
            } catch (\PDOException $e) {
                $status = 500;
                $payload = json_encode(['error' => $e->getMessage()]);
            }

            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
        });

        // 5. ดึงข้อมูล Common Master ลิสต์ส่วนกลางทั่วไป
        $group->get('/master/common', function (Request $request, Response $response) {
            $queryParams = $request->getQueryParams();
            $db = $this->get(PDO::class);

            $conditions = [];
            $params = [];

            if (!empty($queryParams['masterFor'])) {
                $conditions[] = "master_for = :masterFor";
                $params[':masterFor'] = $queryParams['masterFor'];
            }

            $sql = "SELECT id as value, name as label FROM master_common";

            if (!empty($conditions)) {
                $sql .= " WHERE " . implode(" AND ", $conditions);
            }

            $sql .= " ORDER BY id ";

            try {
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $results = $stmt->fetchAll();
                $status = 200;
                $payload = json_encode($results, JSON_UNESCAPED_UNICODE);
            } catch (\PDOException $e) {
                $status = 500;
                $payload = json_encode(['error' => $e->getMessage()]);
            }

            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
        });

        // 6. ดึงบัญชีธนาคารของโบสถ์
        $group->get('/master-bank-account/{churchId}', function (Request $request, Response $response, array $args) {
            $churchId = $args['churchId'];

            if (empty($churchId)) {
                $response->getBody()->write(json_encode([]));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $db = $this->get(PDO::class);
            $sql = "SELECT bank_account 
                        , CONCAT('(', bank_account , ') ', bank_account_name ) as bank_account_name  
                        , bank_name 
                    from master_bank_account 
                    where church_id = :churchId
                    and bank_name != 'PERSON'
                    order by bank_name desc, bank_account_name ";

            $stmt = $db->prepare($sql);
            $stmt->execute([':churchId' => $churchId]);
            $results = $stmt->fetchAll();

            $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        });
    })->add(new JwtAuthMiddleware()); // 👈 ตบด้วยมิดเดิลแวร์จุดนี้จุดเดียว ป้องกันหมดทั้งไฟล์แบบเรียบร้อยครับ
};
