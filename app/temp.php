<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group; // 👈 รวบกลุ่ม API
use App\Application\Middleware\JwtAuthMiddleware; // 👈 เรียกใช้งาน Middleware
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

return function (App $app) {

    // 🔒 จัดกลุ่มให้ทุกเส้นต้องผ่านด่านตรวจ JWT เพื่อความปลอดภัยของข้อมูลองค์กร
    $app->group('/api', function (Group $group) {

        // 1. รายงานแยกตามหมวดหมู่ (กราฟวงกลม)
        $group->get('/report/category', function (Request $request, Response $response) {
            $queryParams = $request->getQueryParams();
            $churchId = isset($queryParams['church_id']) ? (int)$queryParams['church_id'] : null;

            // 🎯 เงื่อนไขข้อที่ 3: ถ้าส่ง church_id = 0 มา ให้ return result ทันที ไม่ต้องไป query
            if ($churchId === 0) {
                $response->getBody()->write(json_encode([], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(200);
            }

            $dateFrom = $queryParams['date_from'] ?? null;
            $dateTo   = $queryParams['date_to'] ?? null;
            $year     = $queryParams['year'] ?? null;

            // เตรียมตัวแปรสำหรับผูก Parameter (Binding Params)
            $params = [];

            // Base SQL
            $sql = "SELECT c.name as label
                 , SUM(t.amount) as value
                 , c.group as category_group
            FROM transactions t 
            JOIN account_categories c ON t.category_id = c.id 
            WHERE 1=1
            AND c.id NOT IN (42,319) ";

            // 🎯 เงื่อนไขข้อที่ 1: ถ้ามีการส่ง date_from และ date_to มา ให้ใช้ date_from และ date_to ไม่ต้องสนใจปี
            if ($dateFrom && $dateTo) {
                $sql .= " AND t.transaction_date BETWEEN :date_from AND :date_to";
                $params[':date_from'] = $dateFrom;
                $params[':date_to']   = $dateTo;
            }
            // 🎯 เงื่อนไขข้อที่ 2: ถ้าไม่มี date_from/to แต่ส่งปีมา (หรือ fallback เป็นปีปัจจุบัน) ให้เปรียบเทียบแบบ between แทน
            else {
                $targetYear = $year ?? date('Y');
                $sql .= " AND t.transaction_date BETWEEN :year_start AND :year_end";
                $params[':year_start'] = "{$targetYear}-01-01";
                $params[':year_end']   = "{$targetYear}-12-31";
            }

            // ตรวจสอบเงื่อนไขโบสถ์ (กรณีไม่ใช่ 0)
            if ($churchId !== null) {
                $sql .= " AND t.church_id = :church_id";
                $params[':church_id'] = $churchId;
            }

            $sql .= " GROUP BY c.id, c.name 
              ORDER BY value DESC";

            // สั่งรัน Database Query
            $db = $this->get(PDO::class);
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // จัดฟอร์แมตข้อมูลตัวเลขให้เป็น float
            foreach ($data as &$row) {
                $row['value'] = (float)$row['value'];
            }

            $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(200);
        });

        $group->get('/report/by-department', function (Request $request, Response $response) {
            $queryParams = $request->getQueryParams();
            $churchId = isset($queryParams['church_id']) ? (int)$queryParams['church_id'] : null;

            // 🎯 เงื่อนไขข้อที่ 3: ถ้าส่ง church_id = 0 มา ให้ return result ว่างทันที ไม่ต้องไป query ให้เปลืองแรงเดต้าเบส
            if ($churchId === 0) {
                $response->getBody()->write(json_encode([], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(200);
            }

            $dateFrom = $queryParams['date_from'] ?? null;
            $dateTo   = $queryParams['date_to'] ?? null;
            $year     = $queryParams['year'] ?? null;

            // เตรียมตัวแปรสำหรับผูก Parameter (Binding Params)
            $params = [];

            // Base SQL ตามสูตรคำนวณ Income/Outcome แยกตามฝ่าย/หน่วยงาน
            $sql = "SELECT 
                        d.name AS department_name,
                        SUM(CASE WHEN ac.`group` = 9 THEN t.amount ELSE 0 END) AS income,
                        SUM(CASE WHEN ac.`group` <> 9 THEN t.amount ELSE 0 END) AS outcome
                    FROM transactions t
                    LEFT JOIN account_categories ac ON t.category_id = ac.id
                    LEFT JOIN departments d ON t.department_id = d.id 
                    WHERE 1=1";

            // 🎯 เงื่อนไขข้อที่ 1: ถ้ามีการส่ง date_from และ date_to มา ให้ใช้คู่นี้ค้นหาทันที
            if ($dateFrom && $dateTo) {
                $sql .= " AND t.transaction_date BETWEEN :date_from AND :date_to";
                $params[':date_from'] = $dateFrom;
                $params[':date_to']   = $dateTo;
            }
            // 🎯 เงื่อนไขข้อที่ 2: ถ้าไม่มีชุดวันที่มา แต่เลือกปี (หรือ fallback เป็นปีปัจจุบัน) ให้ตัดด้วยวันที่เริ่มต้น-สิ้นปี
            else {
                $targetYear = $year ?? date('Y');
                $sql .= " AND t.transaction_date BETWEEN :year_start AND :year_end";
                $params[':year_start'] = "{$targetYear}-01-01";
                $params[':year_end']   = "{$targetYear}-12-31";
            }

            // ตรวจสอบเงื่อนไขโบสถ์ (กรณีไม่ใช่ 0 และมีระบุส่งค่าเข้ามา)
            if ($churchId !== null) {
                $sql .= " AND t.church_id = :church_id";
                $params[':church_id'] = $churchId;
            }

            // สั่ง Group และ Order ตัวอักษรย้อนกลับตาม Query ต้นฉบับ
            $sql .= " GROUP BY d.id, d.name 
                      ORDER BY d.name DESC";

            // สั่งรัน Database Query
            $db = $this->get(PDO::class);
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // จัดฟอร์แมตข้อมูลตัวเลขรวม (Income / Outcome) ให้หลุดจาก String มาเป็น float ให้ใช้งานฝั่งหน้าจอง่ายๆ
            foreach ($data as &$row) {
                // เผื่อกรณีแถวไหนที่ธุรกรรมไม่มีฝ่าย (department_id เป็น null) ให้แสดงชื่อจัดกลุ่มเป็น "ไม่ระบุหน่วยงาน" แทน
                if ($row['department_name'] === null) {
                    $row['department_name'] = 'ไม่ระบุหน่วยงาน/ฝ่าย';
                }
                $row['income']  = (float)$row['income'];
                $row['outcome'] = (float)$row['outcome'];
            }

            $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(200);
        });

        // 1. รายงานแยกตามหมวดหมู่ (กราฟวงกลม - เวอร์ชันเจาะจงรายเดือน ประมวลผลเร็วพิเศษด้วย Index)
        $group->get('/report/category-monthly', function (Request $request, Response $response) {
            $queryParams = $request->getQueryParams();
            $year = $queryParams['year'] ?? date('Y');

            // หากไม่ได้ส่ง month มา ให้ใช้เดือนปัจจุบันเป็นค่าเริ่มต้น
            $month = $queryParams['month'] ?? date('n');
            $churchId = $queryParams['church_id'] ?? null;

            // 🛠️ คำนวณช่วงวันที่: วันแรกของเดือน จนถึง วันแรกของเดือนถัดไป (รีดประสิทธิภาพการใช้ Index)
            $startDate = sprintf("%04d-%02d-01", $year, $month);
            $dateObj = new DateTime($startDate);
            $dateObj->modify('+1 month');
            $endDate = $dateObj->format('Y-m-d');

            // เตรียม Parameter สำหรับผูกตัวแปร
            $params = [
                ':start_date' => $startDate,
                ':end_date'   => $endDate
            ];

            $sql = "SELECT c.name as label
                        , SUM(t.amount) as value
                        , c.group as category_group
                    FROM transactions t 
                    JOIN account_categories c ON t.category_id = c.id 
                    WHERE t.transaction_date >= :start_date 
                      AND t.transaction_date < :end_date "; // 👈 เปลี่ยนจาก YEAR() มาใช้ Index Range แทน

            if ($churchId) {
                $sql .= " AND t.church_id = :church_id";
                $params[':church_id'] = $churchId;
            }

            $sql .= " GROUP BY c.id, c.name 
                      ORDER BY value DESC";

            $db = $this->get(PDO::class);
            $stmt = $db->prepare($sql);

            // Execute ยิงคำสั่งพร้อมผูก Parameter เพื่อความปลอดภัยระดับสูงสุด
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($data as &$row) {
                $row['value'] = (float)$row['value'];
            }

            $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(200);
        });

        $group->get('/report/category-monthly-detail', function (Request $request, Response $response) {
            $queryParams = $request->getQueryParams();
            $year = $queryParams['year'] ?? date('Y');

            // หากไม่ได้ส่ง month มา ให้ใช้เดือนปัจจุบันเป็นค่าเริ่มต้น
            $month = $queryParams['month'] ?? date('n');
            $churchId = $queryParams['church_id'] ?? null;

            // 🛠️ คำนวณช่วงวันที่: วันแรกของเดือน จนถึง วันแรกของเดือนถัดไป (รีดประสิทธิภาพการใช้ Index)
            $startDate = sprintf("%04d-%02d-01", $year, $month);
            $dateObj = new DateTime($startDate);
            $dateObj->modify('+1 month');
            $endDate = $dateObj->format('Y-m-d');

            // เตรียม Parameter สำหรับผูกตัวแปร
            $params = [
                ':start_date' => $startDate,
                ':end_date'   => $endDate
            ];

            $sql = "SELECT 
                        CASE 
                            WHEN c.group = 9 THEN 'รายรับ' 
                            ELSE 'รายจ่าย' 
                        END AS transaction_type,
                        c.name AS cat_name,
                        t.bank_account, 
                        mc.name AS channel_name,
                        SUM(t.amount) AS value
                    FROM transactions t
                    LEFT JOIN account_categories c ON t.category_id = c.id
                    LEFT JOIN master_common mc ON t.channel_id = mc.id AND mc.master_for = 'TRN_CHANNEL'
                    WHERE t.transaction_date >= :start_date 
                      AND t.transaction_date < :end_date
                      AND c.id NOT IN (42 ,319) "; // 👈 เปลี่ยนจาก YEAR() มาใช้ Index Range แทน

            if ($churchId) {
                $sql .= " AND t.church_id = :church_id";
                $params[':church_id'] = $churchId;
            }

            $sql .= " GROUP BY c.group, c.name, t.bank_account, mc.name
                    ORDER BY transaction_type DESC, cat_name DESC";

            $db = $this->get(PDO::class);
            $stmt = $db->prepare($sql);

            // Execute ยิงคำสั่งพร้อมผูก Parameter เพื่อความปลอดภัยระดับสูงสุด
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($data as &$row) {
                $row['value'] = (float)$row['value'];
            }

            $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(200);
        });

        // 2. รายงานสรุปรายปี
        $group->get('/report/yearly', function (Request $request, Response $response) {
            $queryParams = $request->getQueryParams();
            $year = $queryParams['year'] ?? date('Y');
            $churchId = $queryParams['church_id'] ?? null;

            // 🎯 เช็กเงื่อนไขถ้าเป็น 0 (ดักทั้ง string '0' และเลข 0) ให้ return อาเรย์ว่างทันที ไม่ต้อง query
            if ($churchId === '0' || $churchId === 0) {
                $response->getBody()->write(json_encode([], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(200);
            }

            $sql = "SELECT 
                    DATE_FORMAT(MIN(t.transaction_date), '%M') AS name, 
                    SUM(CASE WHEN ac.`group` = 9 THEN t.amount ELSE 0 END) AS income,
                    SUM(CASE WHEN ac.`group` <> 9 THEN t.amount ELSE 0 END) AS outcome,
                    MONTH(t.transaction_date) AS month_num
                FROM transactions t
                LEFT JOIN account_categories ac ON t.category_id = ac.id
                WHERE YEAR(t.transaction_date) = :year ";

            if ($churchId) {
                $sql .= " AND t.church_id = :church_id ";
            }

            $sql .= " GROUP BY MONTH(t.transaction_date) 
                  ORDER BY month_num ASC";

            $db = $this->get(PDO::class);
            $stmt = $db->prepare($sql);

            $params = [':year' => $year];
            if ($churchId) {
                $params[':church_id'] = $churchId;
            }

            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(200);
        });

        // 2. รายงานสรุปรายเดือน (เจาะจงรายเดือน ประมวลผลเร็วพิเศษด้วย Index)
        $group->get('/report/monthly', function (Request $request, Response $response) {
            $queryParams = $request->getQueryParams();
            $year = $queryParams['year'] ?? date('Y');

            // หากไม่ได้ส่ง month มา ให้ใช้เดือนปัจจุบันเป็นค่าเริ่มต้น
            $month = $queryParams['month'] ?? date('n');
            $churchId = $queryParams['church_id'] ?? null;

            // 🛠️ คำนวณช่วงวันที่: วันแรกของเดือน จนถึง วันแรกของเดือนถัดไป (เพื่อให้ค้นหาผ่าน Index ได้เร็วที่สุด)
            $startDate = sprintf("%04d-%02d-01", $year, $month);
            $dateObj = new DateTime($startDate);
            $dateObj->modify('+1 month');
            $endDate = $dateObj->format('Y-m-d');

            // เตรียม Parameter สำหรับผูกตัวแปร
            $params = [
                ':start_date' => $startDate,
                ':end_date'   => $endDate
            ];

            $sql = "SELECT 
                    DATE_FORMAT(MIN(t.transaction_date), '%M') AS name, 
                    SUM(CASE WHEN ac.`group` = 9 THEN t.amount ELSE 0 END) AS income,
                    SUM(CASE WHEN ac.`group` <> 9 THEN t.amount ELSE 0 END) AS outcome,
                    MONTH(t.transaction_date) AS month_num
                FROM transactions t
                LEFT JOIN account_categories ac ON t.category_id = ac.id
                WHERE t.transaction_date >= :start_date 
                  AND t.transaction_date < :end_date ";

            // ตัวกรองโบสถ์ (ถ้ามี)
            if ($churchId) {
                $sql .= " AND t.church_id = :church_id ";
                $params[':church_id'] = $churchId;
            }

            $sql .= " GROUP BY MONTH(t.transaction_date) 
                  ORDER BY month_num ASC";

            $db = $this->get(PDO::class);
            $stmt = $db->prepare($sql);

            // Execute ยิงคำสั่งพร้อมผูก Parameter เพื่อความปลอดภัยจาก SQL Injection
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(200);
        });

        // 3. รายงานเงินคงเหลือในบัญชีธนาคาร
        $group->get('/report/bank-balance', function (Request $request, Response $response) {
            $queryParams = $request->getQueryParams();
            $churchId = isset($queryParams['church_id']) ? (int)$queryParams['church_id'] : null;

            // 🎯 เงื่อนไขข้อที่ 2: ถ้าส่ง church_id = 0 มา ให้ return result ทันที ไม่ต้องไป query
            if ($churchId === 0) {
                $response->getBody()->write(json_encode([], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
            }

            $dateFrom = $queryParams['date_from'] ?? null;
            $dateTo   = $queryParams['date_to'] ?? null;
            $year     = $queryParams['year'] ?? null;

            // เตรียมตัวแปรสำหรับผูก Parameter (Binding Params)
            $params = [];

            // Base SQL
            $sql = "SELECT 
                t.bank_account,
                SUM(CASE WHEN ac.`group` = 9 THEN t.amount ELSE 0 END) - 
                SUM(CASE WHEN ac.`group` <> 9 THEN t.amount ELSE 0 END) AS balance 
            FROM transactions t 
            LEFT JOIN account_categories ac ON t.category_id = ac.id 
            WHERE t.bank_account IS NOT NULL 
              AND t.bank_account != ''";

            // 🎯 เงื่อนไขข้อที่ 1: ถ้ามีการส่ง date_from และ date_to มา ให้ใช้คู่นี้ (ครอบคลุมรูปแบบ YYYY-MM-DD)
            if ($dateFrom && $dateTo) {
                // ใช้ >= และ <= เพื่อให้ครอบคลุมช่วงวัน หรือจะใช้ BETWEEN ก็ได้ครับ
                $sql .= " AND t.transaction_date >= :start_date AND t.transaction_date <= :end_date";

                // เพื่อความปลอดภัยของข้อมูลเวลา ปรับปลายทางให้คลุมถึงสิ้นวัน (23:59:59) กรณีที่ฟิลด์ใน DB เป็น datetime
                $params[':start_date'] = "{$dateFrom} 00:00:00";
                $params[':end_date']   = "{$dateTo} 23:59:59";
            }
            // Fallback: ถ้าไม่มีช่วงวันที่ แต่ส่งปีมา (หรือใช้ปีปัจจุบัน)
            else {
                $targetYear = $year ?? date('Y');
                $sql .= " AND t.transaction_date >= :start_date AND t.transaction_date < :end_date";
                $params[':start_date'] = "{$targetYear}-01-01 00:00:00";
                $params[':end_date']   = ($targetYear + 1) . "-01-01 00:00:00";
            }

            // ตรวจสอบเงื่อนไขโบสถ์
            if ($churchId !== null) {
                $sql .= " AND t.church_id = :church_id";
                $params[':church_id'] = $churchId;
            }

            $sql .= " GROUP BY t.bank_account";

            // สั่งรัน Database Query
            $db = $this->get(PDO::class);
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // ปรับ Data Type ของยอดคงเหลือให้เป็น float ก่อนแปลงเป็น JSON
            foreach ($data as &$row) {
                $row['balance'] = (float)$row['balance'];
            }

            $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        });

        // 3. รายงานยอดคงเหลือในบัญชีธนาคารประจำเดือน (คำนวณสะสมจนถึงสิ้นเดือนนั้นๆ ประมวลผลเร็วด้วย Index)
        // 3. รายงานยอดเงินเคลื่อนไหวในบัญชีธนาคาร (สรุปยอดเฉพาะเดือนที่เลือกเพียว ๆ เพื่อใช้คู่กับระบบปิดงบ)
        $group->get('/report/bank-balance-monthly', function (Request $request, Response $response) {
            $queryParams = $request->getQueryParams();
            $year = $queryParams['year'] ?? date('Y');

            // หากไม่ได้ส่ง month มา ให้ใช้เดือนปัจจุบันเป็นค่าเริ่มต้น
            $month = $queryParams['month'] ?? date('n');
            $churchId = $queryParams['church_id'] ?? null;

            // 🛠️ 1. คำนวณวันแรกของเดือน และวันแรกของเดือนถัดไป เพื่อควบคุมให้อยู่ในเดือนเดียว
            $startDate = sprintf("%04d-%02d-01 00:00:00", $year, $month);
            $dateObj = new DateTime($startDate);
            $dateObj->modify('+1 month');
            $endDate = $dateObj->format('Y-m-d 00:00:00');

            // 🛠️ 2. ปรับ SQL บล็อกช่วงเวลาหัวท้าย เพื่อเอาข้อมูลแค่เดือนเดียวตรง ๆ ไม่เอาอดีตมารวม
            $sql = "SELECT 
                t.bank_account,
                SUM(CASE WHEN ac.`group` = 9 THEN t.amount ELSE 0 END) - 
                SUM(CASE WHEN ac.`group` <> 9 THEN t.amount ELSE 0 END) AS balance 
            FROM transactions t 
            LEFT JOIN account_categories ac ON t.category_id = ac.id 
            WHERE t.bank_account IS NOT NULL 
              AND t.bank_account != ''
              AND t.transaction_date >= :start_date
              AND t.transaction_date < :end_date"; // 👈 จำกัดพื้นที่เฉพาะเดือนที่เลือก

            // เตรียม Parameter ให้ครบทั้งจุดเริ่มต้นและสิ้นสุด
            $params = [
                ':start_date' => $startDate,
                ':end_date'   => $endDate
            ];

            if ($churchId) {
                $sql .= " AND t.church_id = :church_id";
                $params[':church_id'] = $churchId;
            }

            $sql .= " GROUP BY t.bank_account";

            $db = $this->get(PDO::class);
            $stmt = $db->prepare($sql);

            // Execute ประมวลผลรวดเร็วผ่าน Index ของ transaction_date
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // ปรับ Data Type ของยอดคงเหลือให้เป็น float ก่อนแปลงเป็น JSON
            foreach ($data as &$row) {
                $row['balance'] = (float)$row['balance'];
            }

            $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(200);
        });

        
    })->add(new JwtAuthMiddleware()); // 👈 วางด่านตรวจความปลอดภัยท้ายกลุ่มจุดเดียวจบ
};
