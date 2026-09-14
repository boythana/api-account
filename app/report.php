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
            AND c.id NOT IN (42) ";
            //AND c.id NOT IN (42,319) ";

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
                      AND c.id NOT IN (42) ";
                      //AND c.id NOT IN (42 ,319) "; 

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
                WHERE YEAR(t.transaction_date) = :year
                AND ac.id NOT IN (42) ";

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
              AND t.transaction_date < :end_date
              AND ac.id NOT IN (42) "; 

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

        // 4. สั่งส่งออกไฟล์ Excel
        $group->get('/report/export-excel', function ($request, $response) {
            $params = $request->getQueryParams();
            $year = $params['year'] ?? date('Y');
            $church_id = $params['church_id'] ?? null;
            $db = $this->get(PDO::class);

            // 1. เตรียมช่วงเวลา (Date Range) เพื่อให้ Database สามารถใช้งาน Index ได้อย่างมีประสิทธิภาพ
            $startDate = "{$year}-01-01 00:00:00";
            $endDate = ($year + 1) . "-01-01 00:00:00";

            // 2. ปรับปรุง Query โดยดึงฟิลด์ให้ครบตามโครงสร้างแรก และเปลี่ยนไปใช้เงื่อนไขช่วงเวลา (BETWEEN-like range)
            $sql = "SELECT 
                t.id AS transaction_id, 
                t.transaction_date, 
                c.name AS category_name, 
                t.description AS note, 
                t.amount, 
                mc.name AS channel, 
                t.bank_account, 
                t.payee_payer, 
                t.ref_no, 
                ch.name AS church_name, 
                pw.firstname AS person_wallet
            FROM transactions t
            LEFT JOIN account_categories c ON t.category_id = c.id
            LEFT JOIN churches ch ON t.church_id = ch.id 
            LEFT JOIN master_common mc ON t.channel_id = mc.id AND mc.master_for = 'TRN_CHANNEL'
            LEFT JOIN users pw ON t.person_wallet = pw.id 
            WHERE t.transaction_date >= :start_date 
              AND t.transaction_date < :end_date";

            $queryData = [
                ':start_date' => $startDate,
                ':end_date' => $endDate
            ];

            if ($church_id) {
                $sql .= " AND t.church_id = :church_id";
                $queryData[':church_id'] = $church_id;
            }

            $sql .= " ORDER BY t.transaction_date ASC";

            $stmt = $db->prepare($sql);
            $stmt->execute($queryData);
            $transactions = $stmt->fetchAll();

            // 3. สร้าง Excel และแมพข้อมูลลงคอลัมน์ (ขยายคอลัมน์เพิ่มให้ครบถ้วน)
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // กำหนดหัวตาราง (Headers)
            $sheet->setCellValue('A1', 'Transaction ID');
            $sheet->setCellValue('B1', 'วันที่');
            $sheet->setCellValue('C1', 'โบสถ์');
            $sheet->setCellValue('D1', 'หมวดหมู่');
            $sheet->setCellValue('E1', 'รายละเอียด');
            $sheet->setCellValue('F1', 'จำนวนเงิน');
            $sheet->setCellValue('G1', 'ช่องทาง');
            $sheet->setCellValue('H1', 'บัญชีธนาคาร');
            $sheet->setCellValue('I1', 'ผู้รับ/ผู้จ่าย');
            $sheet->setCellValue('J1', 'เลขที่อ้างอิง');
            $sheet->setCellValue('K1', 'กระเป๋าเงิน (User)');

            $row = 2;
            foreach ($transactions as $t) {
                $sheet->setCellValue('A' . $row, $t['transaction_id']);
                $sheet->setCellValue('B' . $row, $t['transaction_date']);
                $sheet->setCellValue('C' . $row, $t['church_name']);
                $sheet->setCellValue('D' . $row, $t['category_name']);
                $sheet->setCellValue('E' . $row, $t['note']);
                $sheet->setCellValue('F' . $row, $t['amount']);
                $sheet->setCellValue('G' . $row, $t['channel']);
                $sheet->setCellValue('H' . $row, $t['bank_account']);
                $sheet->setCellValue('I' . $row, $t['payee_payer']);
                $sheet->setCellValue('J' . $row, $t['ref_no']);
                $sheet->setCellValue('K' . $row, $t['person_wallet']);
                $row++;
            }

            $writer = new Xlsx($spreadsheet);
            $tempFile = fopen('php://temp', 'r+');
            $writer->save($tempFile);
            rewind($tempFile);

            $response->getBody()->write(stream_get_contents($tempFile));
            fclose($tempFile);

            return $response
                ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->withHeader('Content-Disposition', 'attachment; filename="report-' . $year . '.xlsx"')
                ->withHeader('Cache-Control', 'max-age=0');
        });

        // 4. สั่งส่งออกไฟล์ Excel (เวอร์ชันรองรับทั้งแบบรายเดือนและรายปี ประมวลผลเร็วด้วย Index)
        $group->get('/report/export-excel-monthly', function ($request, $response) {
            $params = $request->getQueryParams();
            $year = $params['year'] ?? date('Y');
            $month = $params['month'] ?? null; // 👈 เพิ่มการรับค่าเดือนเข้ามา
            $church_id = $params['church_id'] ?? null;
            $db = $this->get(PDO::class);

            // ชื่อไฟล์เริ่มต้น
            $filename = "report-{$year}";

            // 🛠️ 1. จัดการช่วงเวลา (Date Range) ให้เหมาะสมและเป็นมิตรกับ Index
            if ($month) {
                // เคสกรองรายเดือน: หา วันแรกของเดือน จนถึง วันแรกของเดือนถัดไป
                $startDate = sprintf("%04d-%02d-01 00:00:00", $year, $month);
                $dateObj = new DateTime($startDate);
                $dateObj->modify('+1 month');
                $endDate = $dateObj->format('Y-m-d 00:00:00');

                // ต่อท้ายชื่อไฟล์ด้วยลำดับเดือน
                $filename .= "-month-" . sprintf("%02d", $month);
            } else {
                // เคสกรองรายปี (Fallback): วันแรกของปี จนถึง วันแรกของปีถัดไป
                $startDate = "{$year}-01-01 00:00:00";
                $endDate = ($year + 1) . "-01-01 00:00:00";
            }

            // 2. ปรับปรุง Query โดยดึงฟิลด์ให้ครบตามโครงสร้างแรก และเปลี่ยนไปใช้เงื่อนไขช่วงเวลา (Index Range)
            $sql = "SELECT 
                t.id AS transaction_id, 
                t.transaction_date, 
                c.name AS category_name, 
                t.description AS note, 
                t.amount, 
                mc.name AS channel, 
                t.bank_account, 
                t.payee_payer, 
                t.ref_no, 
                ch.name AS church_name, 
                pw.firstname AS person_wallet
            FROM transactions t
            LEFT JOIN account_categories c ON t.category_id = c.id
            LEFT JOIN churches ch ON t.church_id = ch.id 
            LEFT JOIN master_common mc ON t.channel_id = mc.id AND mc.master_for = 'TRN_CHANNEL'
            LEFT JOIN users pw ON t.person_wallet = pw.id 
            WHERE t.transaction_date >= :start_date 
              AND t.transaction_date < :end_date";

            $queryData = [
                ':start_date' => $startDate,
                ':end_date' => $endDate
            ];

            if ($church_id) {
                $sql .= " AND t.church_id = :church_id";
                $queryData[':church_id'] = $church_id;
            }

            $sql .= " ORDER BY t.transaction_date ASC";

            $stmt = $db->prepare($sql);
            $stmt->execute($queryData);
            $transactions = $stmt->fetchAll();

            // 3. สร้าง Excel และแมพข้อมูลลงคอลัมน์
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // กำหนดหัวตาราง (Headers)
            $sheet->setCellValue('A1', 'Transaction ID');
            $sheet->setCellValue('B1', 'วันที่');
            $sheet->setCellValue('C1', 'โบสถ์');
            $sheet->setCellValue('D1', 'หมวดหมู่');
            $sheet->setCellValue('E1', 'รายละเอียด');
            $sheet->setCellValue('F1', 'จำนวนเงิน');
            $sheet->setCellValue('G1', 'ช่องทาง');
            $sheet->setCellValue('H1', 'บัญชีธนาคาร');
            $sheet->setCellValue('I1', 'ผู้รับ/ผู้จ่าย');
            $sheet->setCellValue('J1', 'เลขที่อ้างอิง');
            $sheet->setCellValue('K1', 'กระเป๋าเงิน (User)');

            $row = 2;
            foreach ($transactions as $t) {
                $sheet->setCellValue('A' . $row, $t['transaction_id']);
                $sheet->setCellValue('B' . $row, $t['transaction_date']);
                $sheet->setCellValue('C' . $row, $t['church_name']);
                $sheet->setCellValue('D' . $row, $t['category_name']);
                $sheet->setCellValue('E' . $row, $t['note']);
                $sheet->setCellValue('F' . $row, $t['amount']);
                $sheet->setCellValue('G' . $row, $t['channel']);
                $sheet->setCellValue('H' . $row, $t['bank_account']);
                $sheet->setCellValue('I' . $row, $t['payee_payer']);
                $sheet->setCellValue('J' . $row, $t['ref_no']);
                $sheet->setCellValue('K' . $row, $t['person_wallet']);
                $row++;
            }

            $writer = new Xlsx($spreadsheet);
            $tempFile = fopen('php://temp', 'r+');
            $writer->save($tempFile);
            rewind($tempFile);

            $response->getBody()->write(stream_get_contents($tempFile));
            fclose($tempFile);

            // 🛠️ ส่ง Header กลับไปพร้อมชื่อไฟล์ที่เปลี่ยนตามเงื่อนไข (Dynamic Filename)
            return $response
                ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '.xlsx"')
                ->withHeader('Cache-Control', 'max-age=0');
        });

        $group->get('/report/export-excel-trn', function ($request, $response) {
            $params = $request->getQueryParams();

            // 🆕 1. รับเงื่อนไขช่วงเวลาจาก Frontend โดยตรง (ไม่สนใจตัวแปรปีหรือเดือนแล้ว)
            $dateFrom = $params['date_from'] ?? date('Y-01-01');
            $dateTo   = $params['date_to'] ?? date('Y-12-31');

            // 🆕 2. รับค่าโบสถ์ที่เลือกในรูปแบบ Array (จากระบบ MultiSelect)
            $churchIds = $params['church_ids'] ?? [];

            $db = $this->get(PDO::class);

            // Dynamic Filename ตามช่วงวันที่ใช้งานจริง
            $filename = "report-from-{$dateFrom}-to-{$dateTo}";

            // เตรียมช่วงเวลา Query ให้คลุมตั้งแต่ต้นวันแรก จนถึงวินาทีสุดท้ายของวันสิ้นสุด
            $startDate = "{$dateFrom} 00:00:00";
            $endDate   = "{$dateTo} 23:59:59";

            // Base SQL string
            $sql = "SELECT 
                t.id AS transaction_id, 
                t.transaction_date, 
                c.name AS category_name, 
                t.description AS note, 
                t.amount, 
                mc.name AS channel, 
                t.bank_account, 
                t.payee_payer, 
                t.ref_no, 
                ch.name AS church_name, 
                pw.firstname AS person_wallet,
                m.name as mission_name,
                d.name as department_name
            FROM transactions t
            LEFT JOIN account_categories c ON t.category_id = c.id
            LEFT JOIN churches ch ON t.church_id = ch.id 
            LEFT JOIN master_common mc ON t.channel_id = mc.id AND mc.master_for = 'TRN_CHANNEL'
            LEFT JOIN missions m on t.mission_id = m.id 
            LEFT JOIN departments d on t.department_id = d.id 
            LEFT JOIN users pw ON t.person_wallet = pw.id
            WHERE t.transaction_date >= :start_date 
              AND t.transaction_date <= :end_date";

            $queryData = [
                ':start_date' => $startDate,
                ':end_date'   => $endDate
            ];

            // 🆕 3. จัดการกรณีมีการระบุกลุ่มโบสถ์เข้ามา (วนลูปสร้างรหัสผ่านเพื่อใช้คำสั่ง IN)
            if (!empty($churchIds) && is_array($churchIds)) {
                $inClauses = [];
                foreach ($churchIds as $index => $id) {
                    $paramName = ":church_id_" . $index;
                    $inClauses[] = $paramName;
                    $queryData[$paramName] = (int)$id; // แปลงค่าเป็น integer เพื่อความปลอดภัย
                }

                // ต่อ String SQL เข้ากับคำสั่ง IN เช่น: AND t.church_id IN (:church_id_0, :church_id_1)
                if (!empty($inClauses)) {
                    $sql .= " AND t.church_id IN (" . implode(', ', $inClauses) . ")";
                }
            }

            $sql .= " ORDER BY t.transaction_date ASC";

            $stmt = $db->prepare($sql);
            $stmt->execute($queryData);
            $transactions = $stmt->fetchAll();

            // 4. สร้าง Excel และแมพข้อมูลลงคอลัมน์เหมือนเดิม
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // กำหนดหัวตาราง (Headers)
            $sheet->setCellValue('A1', 'Transaction ID');
            $sheet->setCellValue('B1', 'วันที่');
            $sheet->setCellValue('C1', 'โบสถ์');
            $sheet->setCellValue('D1', 'หมวดหมู่');
            $sheet->setCellValue('E1', 'รายละเอียด');
            $sheet->setCellValue('F1', 'จำนวนเงิน');
            $sheet->setCellValue('G1', 'ช่องทาง');
            $sheet->setCellValue('H1', 'บัญชีธนาคาร');
            $sheet->setCellValue('I1', 'ผู้รับ/ผู้จ่าย');
            $sheet->setCellValue('J1', 'เลขที่อ้างอิง');
            $sheet->setCellValue('K1', 'กระเป๋าเงิน (User)');
            $sheet->setCellValue('L1', 'พันธกิจ');
            $sheet->setCellValue('M1', 'หน่วยงาน/ฝ่าย');

            $row = 2;
            foreach ($transactions as $t) {
                $sheet->setCellValue('A' . $row, $t['transaction_id']);
                $sheet->setCellValue('B' . $row, $t['transaction_date']);
                $sheet->setCellValue('C' . $row, $t['church_name']);
                $sheet->setCellValue('D' . $row, $t['category_name']);
                $sheet->setCellValue('E' . $row, $t['note']);
                $sheet->setCellValue('F' . $row, $t['amount']);
                $sheet->setCellValue('G' . $row, $t['channel']);
                $sheet->setCellValue('H' . $row, $t['bank_account']);
                $sheet->setCellValue('I' . $row, $t['payee_payer']);
                $sheet->setCellValue('J' . $row, $t['ref_no']);
                $sheet->setCellValue('K' . $row, $t['person_wallet']);
                $sheet->setCellValue('L' . $row, $t['mission_name']);
                $sheet->setCellValue('M' . $row, $t['department_name']);
                $row++;
            }

            $writer = new Xlsx($spreadsheet);
            $tempFile = fopen('php://temp', 'r+');
            $writer->save($tempFile);
            rewind($tempFile);

            $response->getBody()->write(stream_get_contents($tempFile));
            fclose($tempFile);

            return $response
                ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '.xlsx"')
                ->withHeader('Cache-Control', 'max-age=0');
        });

        // 5. รายงานสรุปภาพรวมรายบุคคลของโบสถ์
        // 5. รายงานภาพรวมรายบุคคล (ตารางหลัก)
        $group->get('/report/person', function (Request $request, Response $response) {
            $userId   = $request->getAttribute('userId');
            $userRole = $request->getAttribute('userRole');

            $queryParams = $request->getQueryParams();
            $churchId = $queryParams['church_id'] ?? null;

            // 🆕 รับพารามิเตอร์ช่วงวันที่จาก Frontend
            $dateFrom = $queryParams['date_from'] ?? null;
            $dateTo   = $queryParams['date_to'] ?? null;

            $conditions = ["t.person_wallet is not NULL", "t.church_id = :church_id"];
            $params = [':church_id' => $churchId];

            if ($userRole == 3) {
                $conditions[] = "t.person_wallet = :userId";
                $params[':userId'] = $userId;
            }

            // 🆕 ถ้ามีการส่งช่วงวันที่มา ให้เพิ่มเงื่อนไข BETWEEN ใน WHERE Clause
            if (!empty($dateFrom) && !empty($dateTo)) {
                $conditions[] = "t.transaction_date BETWEEN :date_from AND :date_to";
                $params[':date_from'] = $dateFrom;
                $params[':date_to']   = $dateTo;
            }

            $whereSql = " WHERE " . implode(" AND ", $conditions);

            $sql = "SELECT u.id user_id, u.firstname 
                    , SUM(CASE WHEN t.category_id = 42 THEN amount ELSE 0 END) as transfer_in
                    , SUM(CASE WHEN t.category_id not in (42,319) THEN amount ELSE 0 END) as expense
                    , SUM(CASE WHEN t.category_id = 319 THEN amount ELSE 0 END) as transfer_out
                    from transactions t
                    left join account_categories ac on t.category_id = ac.id 
                    left join users u on t.person_wallet = u.id "
                . $whereSql .
                " group by t.person_wallet";

            $db = $this->get(PDO::class);
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(200);
        });

        // 6. รายงานเจาะลึกรายละเอียดธุรกรรมของรายบุคคล (หน้าย่อย)
        $group->get('/report/person-detail', function (Request $request, Response $response) {
            $queryParams = $request->getQueryParams();
            $userId = $queryParams['userId'] ?? null;

            // 🆕 รับพารามิเตอร์ช่วงวันที่จาก Frontend สำหรับ Modal รายละเอียด
            $dateFrom = $queryParams['date_from'] ?? null;
            $dateTo   = $queryParams['date_to'] ?? null;

            $conditions = ["t.person_wallet = :userId"];
            $params = [':userId' => $userId];

            // 🆕 เพิ่มเงื่อนไข Filter วันที่ให้กับเส้น Detail
            if (!empty($dateFrom) && !empty($dateTo)) {
                $conditions[] = "t.transaction_date BETWEEN :date_from AND :date_to";
                $params[':date_from'] = $dateFrom;
                $params[':date_to']   = $dateTo;
            }

            $whereSql = " WHERE " . implode(" AND ", $conditions);

            $sql = "SELECT DATE_FORMAT(t.transaction_date, '%d-%m-%Y') transaction_date , t.description , t.amount 
                            , ac.name category_name 
                            , (CASE 
                                WHEN t.category_id = 42 THEN 'transfer_in'
                                WHEN t.category_id = 319 THEN 'transfer_out' 
                                ELSE 'expense' END) as type
                            , t.category_id 
                    from transactions t
                    left join account_categories ac on t.category_id = ac.id 
                    left join users u on t.bank_account = u.id "
                . $whereSql .
                " order by t.transaction_date desc";

            $db = $this->get(PDO::class);
            $stmt = $db->prepare($sql);
            $stmt->execute($params); // 🆕 เปลี่ยนมาใช้ตัวแปร $params รวมแทนก้อนเดิม
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(200);
        });

        // 5. ดึงยอดยกมาจากตารางปิดงบของเดือนก่อนหน้า (อ้างอิงจากระบบปิดงบแบบ Master-Detail)
        $group->get('/report/monthly-closing-balance', function (Request $request, Response $response) {
            $queryParams = $request->getQueryParams();

            $churchId = $queryParams['church_id'] ?? null;
            $year = $queryParams['year'] ?? date('Y');
            $month = $queryParams['month'] ?? date('n');

            // 🛠️ คำนวณถอยหลังไป 1 เดือน เพื่อหายอดปิดงบของ "เดือนก่อนหน้า" มาเป็นยอดยกมาของเดือนนี้
            // เช่น ถ้าหน้าบ้านเลือก ปี 2026 เดือน 6 -> ตัวตั้งต้นของมันคือ ยอดปิดงบของปี 2026 เดือน 5
            $targetDate = sprintf("%04d-%02d-01", $year, $month);
            $dateObj = new DateTime($targetDate);
            $dateObj->modify('-1 month'); // 👈 ถอยกลับ 1 เดือน

            $prevYear = $dateObj->format('Y');
            $prevMonth = $dateObj->format('n'); // ดึงเลขเดือนแบบไม่มี 0 นำหน้า (1-12) ให้ตรงกับประเภทตัวเลขใน DB

            // 🛠️ SQL Query ตามโครงสร้างตารางที่คุณให้มา
            $sql = "SELECT 
                mca.bank_account, 
                mca.statement_balance
            FROM monthly_closings mc 
            INNER JOIN monthly_closing_accounts mca ON mc.id = mca.closing_id 
            INNER JOIN master_bank_account mba ON mca.bank_account = mba.bank_account 
            WHERE mc.year = :prev_year
              AND mc.month = :prev_month";

            $params = [
                ':prev_year'  => $prevYear,
                ':prev_month' => $prevMonth
            ];

            // ตรวจสอบเงื่อนไขโบสถ์ (ป้องกันเคสสลับโบสถ์ดูรายงาน)
            if ($churchId) {
                $sql .= " AND mc.church_id = :church_id";
                $params[':church_id'] = $churchId;
            }

            $db = $this->get(PDO::class);
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // ปรับ Data Type ของยอดเงินคงเหลือให้เป็น float เพื่อไม่ให้ติดปัญหา string ใน json
            foreach ($data as &$row) {
                $row['statement_balance'] = (float)$row['statement_balance'];
            }

            $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(200);
        });

        // 📅 API สำหรับหน้า Landing Page ภาพรวมปิดงบรายเดือน 12 เดือนประจำปี
        $group->get('/report/monthly-closings-yearly', function (Request $request, Response $response) {
            $queryParams = $request->getQueryParams();
            $year = $queryParams['year'] ?? date('Y');
            $churchId = $queryParams['church_id'] ?? null;

            if (empty($churchId)) {
                $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'ไม่พบรหัสโบสถ์ (church_id)']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $sql = "SELECT 
                        m.month_num,
                        IFNULL(mc.status, 'none') as status, 
                        mc.total_income,
                        mc.total_expense,
                        mc.book_balance
                    FROM (
                        SELECT 1 AS month_num UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 
                        UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 
                        UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12
                    ) m
                    LEFT JOIN monthly_closings mc ON m.month_num = mc.month 
                        AND mc.year = :year 
                        AND mc.church_id = :church_id
                    ORDER BY m.month_num ASC";

            try {
                $db = $this->get(PDO::class);
                $stmt = $db->prepare($sql);
                $stmt->execute([':year' => (int)$year, ':church_id' => (int)$churchId]);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($results as &$row) {
                    $row['month_num'] = (int)$row['month_num'];
                    $row['total_income'] = $row['total_income'] !== null ? (float)$row['total_income'] : null;
                    $row['total_expense'] = $row['total_expense'] !== null ? (float)$row['total_expense'] : null;
                    $row['book_balance'] = $row['book_balance'] !== null ? (float)$row['book_balance'] : null;
                }

                $status = 200;
                $payload = json_encode(['status' => 'success', 'data' => $results], JSON_UNESCAPED_UNICODE);
            } catch (\PDOException $e) {
                $status = 500;
                $payload = json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }

            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
        });

        // 🆕 1. API สำหรับดึงข้อมูลตั้งต้นประมวลผลเพื่อเปิดฟอร์มปิดงบประจำเดือน (เวอร์ชันดึงยอด Statement, ไฟล์ และ Audit Logs ครบถ้วน)
        $group->get('/report/monthly-closing-init', function (Request $request, Response $response) {
            $queryParams = $request->getQueryParams();
            $year = $queryParams['year'] ?? date('Y');
            $month = $queryParams['month'] ?? date('n');
            $churchId = $queryParams['church_id'] ?? null;
            $status = $queryParams['status'] ?? 'none'; // รับสถานะการเปิดจากหน้าบ้านเพื่อสลับโหมดการดึงข้อมูล
            $closingId = $queryParams['closing_id'] ?? null;

            try {
                $db = $this->get(PDO::class);

                if (!empty($closingId)) {
                    $stmtMonthlyClosing = $db->prepare("SELECT * FROM monthly_closings WHERE id = :id");
                    $stmtMonthlyClosing->execute([':id' => $closingId]);
                    $monthlyClosing = $stmtMonthlyClosing->fetch(PDO::FETCH_ASSOC);
                    $year = $monthlyClosing['year'];
                    $month = $monthlyClosing['month'];
                    $churchId = $monthlyClosing['church_id'];
                    $status = $monthlyClosing['status'];
                }

                if (empty($churchId)) {
                    $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'ไม่พบรหัสโบสถ์ (church_id)']));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
                }

                // 🏛️ A. ดึงชื่อโบสถ์
                $stmtChurch = $db->prepare("SELECT name FROM churches WHERE id = :church_id");
                $stmtChurch->execute([':church_id' => $churchId]);
                $churchName = $stmtChurch->fetchColumn() ?: "ไม่พบชื่อโบสถ์ในระบบ";

                // 🗓️ เตรียมตัวแปรสำหรับคำนวณหางบเดือนก่อนหน้า
                $currentMonthDate = new DateTime("{$year}-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01");
                $prevMonthDate = clone $currentMonthDate;
                $prevMonthDate->modify('-1 month');

                $prevYear = $prevMonthDate->format('Y');
                $prevMonth = $prevMonthDate->format('n');

                // 🔍 1. ดึงยอดยกมาจากงบเดือนก่อนหน้า (ตารางแม่) - ดึงมาใช้เป็นกรณีตั้งต้นเสมอ
                $stmtPrevClosing = $db->prepare("
                        SELECT id, book_balance FROM monthly_closings 
                        WHERE church_id = :church_id AND year = :year AND month = :month AND status = 'approved'
                    ");
                $stmtPrevClosing->execute([':church_id' => $churchId, ':year' => $prevYear, ':month' => $prevMonth]);
                $prevClosing = $stmtPrevClosing->fetch(PDO::FETCH_ASSOC);

                if (!$prevClosing) {
                    $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลการปิดงบของเดือนก่อนหน้าในระบบ']));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
                }

                // 🛠️ ประกาศแกะไอดีงบเดือนก่อนออกมารอไว้เลย เพื่อป้องกัน Error ในบล็อก else
                $prevClosingId = $prevClosing['id'];
                $carryForward = (float)$prevClosing['book_balance'];

                // 🔍 2. ค้นหาประวัติบันทึกของ "เดือนปัจจุบัน" (ถ้ามีข้อมูลถูกส่งเข้ามาแล้ว)
                $stmtCurrentClosing = $db->prepare("
                            SELECT 
                                mc.id, mc.carry_forward, mc.total_income, mc.total_expense, mc.book_balance, 
                                mc.submitted_at, mc.approved_at,
                                u_sub.firstname as submitter_name,
                                u_app.firstname as approver_name
                            FROM monthly_closings mc
                            LEFT JOIN users u_sub ON mc.submitted_by = u_sub.id
                            LEFT JOIN users u_app ON mc.approved_by = u_app.id
                            WHERE mc.church_id = :church_id AND mc.year = :year AND mc.month = :month
                        ");
                $stmtCurrentClosing->execute([':church_id' => $churchId, ':year' => $year, ':month' => $month]);
                $currentClosing = $stmtCurrentClosing->fetch(PDO::FETCH_ASSOC);

                //Get requester and approvers for the request form 
                $allApproversByForm = getAllApproversByForm($db, $churchId, 1);
                $allowedUser = [];

                // ตั้งค่าตัวแปรสรุปยอดเริ่มต้น
                $totalIncome = 0;
                $totalExpense = 0;
                $bookBalance = 0;
                $logsData = []; // อาเรย์เก็บประวัติสำหรับโยนให้หน้าบ้าน
                $attachments = [];

                // 👥 แยก Logic การแสดงผล
                if ($status !== 'none' && $currentClosing) {
                    // กรณีที่มี closing_id อยู่แล้ว
                    // โหมดรีวิว/ตรวจสอบ: ดึงยอดประวัติศาสตร์ที่เคยบันทึกไว้ในตารางแม่มาโชว์ตรงๆ
                    $totalIncome = (float)$currentClosing['total_income'];
                    $totalExpense = (float)$currentClosing['total_expense'];
                    $bookBalance = (float)$currentClosing['book_balance'];
                    $carryForward = (float)$currentClosing['carry_forward'];

                    // 🏦 🆕 ดึงยอดระบบ, ยอด Statement และเปลี่ยนฟิลด์ไฟล์แนบเป็น remark จากตารางลูก
                    $stmtAccounts = $db->prepare("
                                SELECT bank_account, system_balance, statement_balance, diff_balance, remark
                                FROM monthly_closing_accounts WHERE closing_id = :closing_id
                            ");
                    $stmtAccounts->execute([':closing_id' => $currentClosing['id']]);
                    $accountsData = $stmtAccounts->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($accountsData as &$acc) {
                        $acc['system_balance'] = (float)$acc['system_balance'];
                        $acc['statement_balance'] = (float)$acc['statement_balance'];
                        $acc['diff_balance'] = (float)$acc['diff_balance'];
                        $acc['remark'] = $acc['remark'] ?? '';
                    }

                    // ดึงประวัติ Audit Log ทั้งหมด มารวมไว้ตรงนี้เลยเพื่อความสะอาดของโค้ด
                    $stmtGetLogs = $db->prepare("
                                SELECT l.action_status, l.note, l.created_at
                                        , l.actor_id
                                        , u.firstname as actor_name
                                FROM monthly_closing_logs l
                                LEFT JOIN users u ON l.actor_id = u.id
                                WHERE l.closing_id = :closing_id
                                ORDER BY l.created_at ASC
                            ");
                    $stmtGetLogs->execute([':closing_id' => $currentClosing['id']]);
                    $logsData = $stmtGetLogs->fetchAll(PDO::FETCH_ASSOC);

                    $stmtFiles = $db->prepare("SELECT id, transaction_id, file_name, file_path FROM transaction_attachments WHERE transaction_id = :id AND data_for='TRN_CLOSING' ");
                    $stmtFiles->execute([':id' => $currentClosing['id']]);
                    $attachments = $stmtFiles->fetchAll(\PDO::FETCH_ASSOC);

                    $lastLog = end($logsData);
                    $allowedUser = getNextApproverDetails($allApproversByForm, $lastLog['actor_id'], $lastLog['action_status']);

                    //$result['attachments'] = $attachments ? $attachments : []; 
                } else {
                    // ✨ โหมดทั่วไป: เคสเหรัญญิกเพิ่งเริ่มกดทำเรื่องปิดงบสดๆ ร้อนๆ
                    // คำนวณรายรับ รายจ่าย จากตารางธุรกรรมจริงประจำเดือนนี้
                    $stmtSummary = $db->prepare("
                            SELECT 
                                SUM(CASE WHEN ac.`group` = 9 THEN t.amount ELSE 0 END) AS total_income,
                                SUM(CASE WHEN ac.`group` <> 9 THEN t.amount ELSE 0 END) AS total_expense
                            FROM transactions t
                            LEFT JOIN account_categories ac ON t.category_id = ac.id
                            WHERE YEAR(t.transaction_date) = :year 
                            AND MONTH(t.transaction_date) = :month 
                            AND t.church_id = :church_id
                            AND ac.id NOT IN (42)
                        ");
                    $stmtSummary->execute([':year' => $year, ':month' => $month, ':church_id' => $churchId]);
                    $summaryData = $stmtSummary->fetch(PDO::FETCH_ASSOC);

                    $totalIncome = (float)($summaryData['total_income'] ?? 0);
                    $totalExpense = (float)($summaryData['total_expense'] ?? 0);
                    $bookBalance = $carryForward + $totalIncome - $totalExpense;

                    // คำนวณหายอดระบบแยกรายบัญชีธนาคาร (ยอดยกมาเดือนก่อน + ยอดเคลื่อนไหวเดือนนี้)
                    $stmtPrevAcc = $db->prepare("SELECT bank_account, statement_balance FROM monthly_closing_accounts WHERE closing_id = :prev_id");
                    $stmtPrevAcc->execute([':prev_id' => $prevClosingId]);
                    $prevAccounts = $stmtPrevAcc->fetchAll(PDO::FETCH_KEY_PAIR);

                    $stmtCurrentMonthMove = $db->prepare("
                            SELECT 
                                IFNULL(t.bank_account, 'กระเป๋าเงินสด') as bank_account,
                                SUM(CASE WHEN ac.`group` = 9 THEN t.amount ELSE -t.amount END) as month_movement
                            FROM transactions t
                            LEFT JOIN account_categories ac ON t.category_id = ac.id
                            WHERE t.church_id = :church_id 
                            AND YEAR(t.transaction_date) = :year 
                            AND MONTH(t.transaction_date) = :month
                            AND ac.id NOT IN (42)
                            GROUP BY t.bank_account
                        ");
                    $stmtCurrentMonthMove->execute([':church_id' => $churchId, ':year' => $year, ':month' => $month]);
                    $currentMovements = $stmtCurrentMonthMove->fetchAll(PDO::FETCH_ASSOC);

                    $finalAccounts = [];
                    foreach ($prevAccounts as $bankAcc => $prevBalance) {
                        $finalAccounts[$bankAcc] = ['bank_account' => $bankAcc, 'balance' => (float)$prevBalance];
                    }

                    foreach ($currentMovements as $move) {
                        $bankAcc = $move['bank_account'];
                        $movement = (float)$move['month_movement'];
                        if (isset($finalAccounts[$bankAcc])) {
                            $finalAccounts[$bankAcc]['balance'] += $movement;
                        } else {
                            $finalAccounts[$bankAcc] = ['bank_account' => $bankAcc, 'balance' => $movement];
                        }
                    }

                    $accountsData = [];
                    foreach (array_values($finalAccounts) as $acc) {
                        $accountsData[] = [
                            'bank_account' => $acc['bank_account'],
                            'balance' => $acc['balance'],
                            'statement_balance' => 0,
                            'diff_balance' => $acc['balance'],
                            'note' => '' // 🆕 ใส่เป็นสตริงว่างรอไว้สำหรับเคสตั้งต้นเริ่มทำเรื่องใหม่
                        ];
                    }

                    //new closing transaction. No any record in the closing_log
                    $allowedUser = getNextApproverDetails($allApproversByForm, 0, 'none');
                }

                // 📦 จัดส่งข้อมูลกลับไปให้หน้าบ้านใช้งานอย่างสมบูรณ์แบบ
                $payload = [
                    'status' => 'success',
                    'data' => [
                        'summary' => [
                            'church_name' => $churchName,
                            'total_income' => $totalIncome,
                            'total_expense' => $totalExpense,
                            'book_balance' => $bookBalance,
                            'carry_forward' => $carryForward
                        ],
                        'accounts' => $accountsData,
                        'attachments' => $attachments,
                        'allowedUser' => $allowedUser,
                        'audit_logs' => $logsData
                    ]
                ];

                $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
            } catch (\Exception $e) {
                $response->getBody()->write(json_encode(['status' => 'error', 'message' => $e->getMessage()]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }
        });

        // 🆕 2. API สำหรับรับข้อมูลฟอร์มบันทึกปิดงบประจำเดือนเข้าสู่ระบบ (POST)
        $group->post('/monthly-closing/submit', function (Request $request, Response $response) {
            $userId = $request->getAttribute('userId'); // ไอดีผู้ใช้จาก Token

            $uploadedParams = $request->getParsedBody();
            $year = $uploadedParams['year'] ?? null;
            $month = $uploadedParams['month'] ?? null;
            $churchId = $uploadedParams['church_id'] ?? null;
            $noteByTreasurer = $uploadedParams['note_by_treasurer'] ?? '';
            $accountsDataRaw = $uploadedParams['accounts_data'] ?? '[]';

            // 🆕 รับค่าตัวแปรสั่งลบไฟล์ที่ส่งมาจากหน้าบ้าน
            $deleteFilesRaw = $uploadedParams['delete_files'] ?? '[]';

            $accountsData = json_decode($accountsDataRaw, true);

            if (!$year || !$month || !$churchId) {
                $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'พารามิเตอร์หลักไม่ครบถ้วน']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $uploadedFiles = $request->getUploadedFiles();
            $files = $uploadedFiles['attachments'] ?? [];

            try {
                $db = $this->get(PDO::class);

                $orgName = getChurchNameById($db, $churchId);

                $db->beginTransaction();

                // 🗓️ คำนวณหางบเดือนก่อนหน้าเพื่อเอาค่า carry_forward จริงมาบันทึก
                $currentMonthDate = new DateTime("{$year}-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01");
                $prevMonthDate = clone $currentMonthDate;
                $prevMonthDate->modify('-1 month');
                $prevYear = $prevMonthDate->format('Y');
                $prevMonth = $prevMonthDate->format('n');

                $stmtPrev = $db->prepare("SELECT book_balance FROM monthly_closings WHERE church_id = :church_id AND year = :year AND month = :month AND status = 'approved'");
                $stmtPrev->execute([':church_id' => $churchId, ':year' => $prevYear, ':month' => $prevMonth]);
                $carryForward = (float)($stmtPrev->fetchColumn() ?: 0);

                // 🧮 คำนวณสรุปยอดสดของเดือนนี้
                $bookBalance = 0;
                foreach ($accountsData as $acc) {
                    $bookBalance += $acc['system_balance'];
                }

                $stmtCalc = $db->prepare("
                                    SELECT 
                                        SUM(CASE WHEN ac.`group` = 9 THEN t.amount ELSE 0 END) AS inc,
                                        SUM(CASE WHEN ac.`group` <> 9 THEN t.amount ELSE 0 END) AS exp
                                    FROM transactions t
                                    LEFT JOIN account_categories ac ON t.category_id = ac.id
                                    WHERE YEAR(t.transaction_date) = :year AND MONTH(t.transaction_date) = :month AND t.church_id = :church_id
                                        ");
                $stmtCalc->execute([':year' => $year, ':month' => $month, ':church_id' => $churchId]);
                $calcRes = $stmtCalc->fetch(PDO::FETCH_ASSOC);
                $totalIncome = (float)($calcRes['inc'] ?? 0);
                $totalExpense = (float)($calcRes['exp'] ?? 0);

                // 📝 ตรวจสอบและบันทึกข้อมูลลงตารางแม่ `monthly_closings`
                $stmtCheck = $db->prepare("SELECT id FROM monthly_closings WHERE church_id = :church_id AND year = :year AND month = :month");
                $stmtCheck->execute([':church_id' => $churchId, ':year' => $year, ':month' => $month]);
                $existingId = $stmtCheck->fetchColumn();

                if ($existingId) {
                    $sqlMain = "UPDATE monthly_closings SET 
                            status = 'pending', carry_forward = :carry_forward, total_income = :total_income, total_expense = :total_expense 
                            ,book_balance = :book_balance, note_by_treasurer = :note, submitted_by = :user_id, submitted_at = NOW() 
                            ,waiting_level = 2
                        WHERE id = :id";
                    $stmtMain = $db->prepare($sqlMain);
                    $stmtMain->execute([
                        ':carry_forward' => $carryForward,
                        ':total_income' => $totalIncome,
                        ':total_expense' => $totalExpense,
                        ':book_balance' => $bookBalance,
                        ':note' => $noteByTreasurer,
                        ':user_id' => $userId,
                        ':id' => $existingId
                    ]);
                    $closingId = $existingId;

                    // ล้างตารางลูกเก่าออกก่อนเขียนทับ (ใช้ฟิลด์ closing_id)
                    $db->prepare("DELETE FROM monthly_closing_accounts WHERE closing_id = :id")->execute([':id' => $closingId]);
                } else {
                    $sqlMain = "INSERT INTO monthly_closings (church_id, year, month, status, waiting_level, carry_forward, total_income, total_expense, book_balance, note_by_treasurer, submitted_by, submitted_at) 
                        VALUES (:church_id, :year, :month, 'pending', 2 , :carry_forward, :total_income, :total_expense, :book_balance, :note, :user_id, NOW())";
                    $stmtMain = $db->prepare($sqlMain);
                    $stmtMain->execute([
                        ':church_id' => $churchId,
                        ':year' => $year,
                        ':month' => $month,
                        ':carry_forward' => $carryForward,
                        ':total_income' => $totalIncome,
                        ':total_expense' => $totalExpense,
                        ':book_balance' => $bookBalance,
                        ':note' => $noteByTreasurer,
                        ':user_id' => $userId
                    ]);
                    $closingId = $db->lastInsertId();
                }

                // 📑 บันทึกลงตารางลูก `monthly_closing_accounts` (ปรับปรุงเพิ่ม field remark และถอดไฟล์ออก)
                $sqlSub = "INSERT INTO monthly_closing_accounts (closing_id, bank_account, system_balance, statement_balance, diff_balance, remark) 
                   VALUES (:closing_id, :bank_account, :system_balance, :statement_balance, :diff_balance, :remark)";
                $stmtSub = $db->prepare($sqlSub);

                foreach ($accountsData as $acc) {
                    $stmtSub->execute([
                        ':closing_id' => $closingId,
                        ':bank_account' => $acc['bank_account'],
                        ':system_balance' => (float)$acc['system_balance'],
                        ':statement_balance' => (float)$acc['statement_balance'],
                        ':diff_balance' => (float)$acc['diff_balance'],
                        ':remark' => $acc['note'] ?? ''
                    ]);
                }

                // 🆕 >>> บล็อก Logic จัดการลบไฟล์เดิมที่ถูกส่ง ID สั่งลบมาจากหน้าบ้าน <<<
                if (!empty($deleteFilesRaw)) {
                    $deleteIds = json_decode($deleteFilesRaw, true);
                    if (is_array($deleteIds)) {
                        foreach ($deleteIds as $fileId) {
                            // ดึงที่อยู่ไฟล์เพื่อลบ Physical file ออกจาก Server 
                            // พี่เช็กชื่อตาราง (เช่น transaction_attachments) และชื่อคีย์เชื่อมโยง (เช่น transaction_id) ให้ตรงกับ DB จริงของพี่อีกครั้งนะครับ
                            $stmtFile = $db->prepare("SELECT file_path FROM transaction_attachments WHERE id = ? AND transaction_id = ? AND data_for='TRN_CLOSING' ");
                            $stmtFile->execute([$fileId, $closingId]);
                            $fileRecord = $stmtFile->fetch(PDO::FETCH_ASSOC);

                            if ($fileRecord) {
                                // สั่งลบไฟล์จริงออกจาก Storage Server
                                if (function_exists('deletePhysicalFile')) {
                                    deletePhysicalFile($fileRecord['file_path']);
                                } else {
                                    $physicalPath = __DIR__ . '/../public/uploads/' . $fileRecord['file_path'];
                                    if (file_exists($physicalPath)) {
                                        @unlink($physicalPath);
                                    }
                                }

                                // ลบแถวบันทึกออกจากตารางในฐานข้อมูล
                                $db->prepare("DELETE FROM transaction_attachments WHERE id = ?")->execute([$fileId]);
                            }
                        }
                    }
                }
                // 🆕 >>> สิ้นสุดบล็อกการสั่งลบไฟล์ <<<

                // ระบบจัดการไฟล์แนบรวม Multi-file (รับผ่าน attachments[])
                $currentDate = date('Y-m-d');
                $filePaths = handleFileUploads($files, $closingId, $churchId, $currentDate, 'TRN_CLOSING', $db);

                // [สำหรับ Audit] บันทึก Log ทุกครั้งที่มีการยื่นเรื่อง หรือยื่นเรื่องแก้ไขซ้ำ
                $stmtLog = $db->prepare("
                        INSERT INTO monthly_closing_logs (closing_id, approve_level, action_status, actor_id, note, created_at) 
                        VALUES (:closing_id, 1, 'pending', :actor_id, :note, NOW())
                    ");
                $stmtLog->execute([
                    ':closing_id' => $closingId,
                    ':actor_id' => $userId,
                    ':note' => $noteByTreasurer
                ]);

                $db->commit();

                //======================= Send mail to next approve =======================
                //Get next approvers 
                $allApproversByForm = getAllApproversByForm($db, $churchId, 1);
                $nextApprovers = getNextApproverDetails($allApproversByForm, $userId, 'pending');

                if (!empty($nextApprovers)) {
                    // 3. ววนลูปดึงข้อมูลรายบุคคลมาใช้งาน (เช่น แสดงผลบนหน้าจอ หรือส่งอีเมลแจ้งเตือน)
                    foreach ($nextApprovers as $approver) {
                        $firstname = $approver['firstname'];
                        $email     = $approver['email'];

                        //Send mail to next approver 
                        $tempDateClosing = DateTime::createFromFormat('!m', $month);
                        $closingMonthYear = $tempDateClosing->format('F') . "-" . $year;

                        $subject = "แจ้งเตือน : มีรายงานปิดบัญชีรายเดือนรอการอนุมัติ " . $closingMonthYear;
                        $appUrl = $_ENV['APP_URL'];

                        $mailBody = mailForNextApprover($firstname, $orgName, $closingMonthYear);

                        try {
                            //TODO
                            //$email = 'boy.thanaphon@gmail.com';
                            sendEmail($email, $firstname, $subject, $mailBody);
                        } catch (\Exception $mailEx) {
                            // บันทึก Log ความผิดพลาดเรื่องเมลไว้ แต่ปล่อยให้โปรแกรมทำงานต่อได้
                        }
                    }
                }

                $response->getBody()->write(json_encode(['status' => 'success', 'message' => 'บันทึกสำเร็จ']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
            } catch (\Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $response->getBody()->write(json_encode(['status' => 'error', 'message' => $e->getMessage()]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }
        });

        // 🆕 3. API สำหรับผู้อนุมัติกดปรับสถานะงบประจำเดือน (POST /monthly-closing/approve-action)
        $group->post('/monthly-closing-approve-action', function (Request $request, Response $response) {
            // 🔐 แกะไอดีผู้ใช้งานจากด่านตรวจ Token ของคุณบอยมาบันทึกเป็นผู้ลงนามอนุมัติ
            $userId = $request->getAttribute('userId');

            $body = $request->getParsedBody();
            $year = $body['year'] ?? null;
            $month = $body['month'] ?? null;
            $churchId = $body['church_id'] ?? null;
            $approvalStatus = $body['status'] ?? null; // ค่าที่ส่งมาต้องเป็น 'approved' หรือ 'rejected'
            $noteByApprover = $body['note_by_approver'] ?? '';
            $userLevel = $body['user_level'] ?? '';

            // ตรวจสอบความครบถ้วนของข้อมูลที่จำเป็น
            if (!$year || !$month || !$churchId || !in_array($approvalStatus, ['approved', 'rejected'])) {
                $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'ข้อมูลพารามิเตอร์ระบบไม่ครบถ้วน หรือสถานะไม่ถูกต้อง']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            try {
                $db = $this->get(PDO::class);

                $orgName = getChurchNameById($db, $churchId);

                //Get all user that relate with this form
                $allApproversByForm = getAllApproversByForm($db, $churchId, 1);
                $trnStatus = evaluateApprovalStatus($allApproversByForm, $userId, $approvalStatus);

                $waitingLevel = 0;
                if ($trnStatus == 'approved')
                    $waitingLevel = 0;
                elseif ($trnStatus == 'rejected')
                    $waitingLevel = 1;
                else
                    $waitingLevel = $userLevel + 1; //$trnStatus == 'pending'

                // 🔍 ตรวจเช็กดูว่าเอกสารปิดงบของเดือนนี้มีอยู่ในตารางจริงไหมก่อนทำการอัปเดต
                $stmtCheck = $db->prepare("
                    SELECT id, status FROM monthly_closings 
                    WHERE church_id = :church_id AND year = :year AND month = :month
                ");
                $stmtCheck->execute([
                    ':church_id' => $churchId,
                    ':year' => $year,
                    ':month' => $month
                ]);
                $closing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                if (!$closing) {
                    $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'ไม่พบเอกสารบันทึกปิดงบของเดือนที่ระบุในระบบ']));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
                }

                // 🛡️ ป้องกันกรณีงบการเงินผ่านการอนุมัติปิดตาย (approved) ไปเรียบร้อยแล้ว ห้ามมาปรับสถานะย้อนกลับเล่น
                if ($closing['status'] === 'approved') {
                    $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'งบการเงินเดือนนี้ผ่านการอนุมัติปิดรอบบัญชีเรียบร้อยแล้ว ไม่สามารถแก้ไขได้']));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
                }

                // 📝 สั่งอัปเดตสถานะและลงนามผู้อนุมัติ (อ้างอิงฟิลด์ตรง DDL ตารางของคุณบอย)
                $sql = "UPDATE monthly_closings SET 
                            status = :status,
                            waiting_level = :waiting_level,
                            note_by_approver = :note_by_approver,
                            approved_by = :approved_by,
                            approved_at = NOW()
                        WHERE id = :id";

                $stmtUpdate = $db->prepare($sql);
                $stmtUpdate->execute([
                    ':status' => $trnStatus,
                    ':waiting_level' => $waitingLevel,
                    ':note_by_approver' => $noteByApprover,
                    ':approved_by' => $userId,
                    ':id' => $closing['id']
                ]);

                // 🆕 [สำหรับ Audit] บันทึก Log ทุกครั้งที่มีการกดอนุมัติ (Approved) หรือปฏิเสธ (Rejected)
                $stmtLog = $db->prepare("
                    INSERT INTO monthly_closing_logs (closing_id, approve_level, action_status, actor_id, note, created_at) 
                    VALUES (:closing_id, :approve_level, :status, :actor_id, :note, NOW())
                ");
                $stmtLog->execute([
                    ':closing_id' => $closing['id'],
                    ':approve_level' => $userLevel,
                    ':status' => $approvalStatus,
                    ':actor_id' => $userId,
                    ':note' => $noteByApprover
                ]);

                //======================= Send mail to next approve =======================
                $nextApprovers = getNextApproverDetails($allApproversByForm, $userId, $approvalStatus);
                //Send mail to next approver 
                $tempDateClosing = DateTime::createFromFormat('!m', $month);
                $closingMonthYear = $tempDateClosing->format('F') . "-" . $year;

                $subject = "";
                $mailBody = "";
                if (!empty($nextApprovers)) {
                    // 3. ววนลูปดึงข้อมูลรายบุคคลมาใช้งาน (เช่น แสดงผลบนหน้าจอ หรือส่งอีเมลแจ้งเตือน)
                    foreach ($nextApprovers as $approver) {
                        $firstname = $approver['firstname'];
                        $email     = $approver['email'];

                        if ($trnStatus == 'rejected') {
                            //request form is rejected
                            $subject = "แจ้งเตือน : รายงานปิดบัญชีรายเดือน " . $closingMonthYear . " : ไม่ผ่านการอนุมัติ";

                            $mailBody = mailRejectForRequester($firstname, $orgName, $closingMonthYear);
                        } else {
                            //have next approver
                            $subject = "แจ้งเตือน : มีรายงานปิดบัญชีรายเดือนรอการอนุมัติ " . $closingMonthYear;

                            $mailBody = mailForNextApprover($firstname, $orgName, $closingMonthYear);
                        }

                        try {
                            //$email = 'boy.thanaphon@gmail.com';
                            sendEmail($email, $firstname, $subject, $mailBody);
                        } catch (\Exception $mailEx) {
                            // บันทึก Log ความผิดพลาดเรื่องเมลไว้ แต่ปล่อยให้โปรแกรมทำงานต่อได้
                        }
                    }
                } else {
                    //Case final approve
                    if ($trnStatus == 'approved') {
                        $subject = "แจ้งเตือน : รายงานปิดบัญชีรายเดือน " . $closingMonthYear . " : ผ่านการอนุมัติแล้ว";
                        $requester = reset($allApproversByForm);
                        $email = $requester['email'];

                        $mailBody = mailApproveForRequester($requester['firstname'], $orgName, $closingMonthYear);

                        try {
                            //$email = 'boy.thanaphon@gmail.com';
                            sendEmail($email, $requester['firstname'], $subject, $mailBody);
                        } catch (\Exception $mailEx) {
                            // บันทึก Log ความผิดพลาดเรื่องเมลไว้ แต่ปล่อยให้โปรแกรมทำงานต่อได้
                        }
                    }
                }

                $payload = [
                    'status' => 'success',
                    'message' => $approvalStatus === 'approved' ? 'อนุมัติปิดรอบบัญชีประจำเดือนเรียบร้อยแล้ว' : 'ส่งกลับให้เหรัญญิกแก้ไขเรียบร้อยแล้ว'
                ];

                $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
            } catch (\PDOException $e) {
                $response->getBody()->write(json_encode(['status' => 'error', 'message' => $e->getMessage()]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }
        });

        // 4. รายการปิดงบประจำเดือนที่รออนุมัติ (สำหรับผู้อนุมัติตามลำดับขั้น)
        $group->get('/report/my-pending-approvals', function (Request $request, Response $response) {
            // ดึง user_id จาก JWT Token (ตั้งต้นจาก Middleware/Attribute)
            // หรืออ่านจาก query params เผื่อกรณีการทดสอบ
            // $userId = $request->getAttribute('user_id') 
            //     ?? $request->getAttribute('token')->user_id 
            //     ?? $request->getQueryParams()['user_id'] 
            //     ?? null;
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                $payload = json_encode(['error' => 'Unauthenticated or missing user_id'], JSON_UNESCAPED_UNICODE);
                $response->getBody()->write($payload);
                return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(401);
            }

            $sql = "SELECT 
                        mc.id AS closing_id,
                        mc.status AS closing_status,
                        mc.year,
                        mc.month,
                        mc.church_id,
                        c.name AS church_name,
                        p.approve_level AS my_approve_level,
                        u.firstname,
                        u.email
                    FROM monthly_closings mc
                    INNER JOIN approve_permissions p ON mc.church_id = p.org_id
                    LEFT JOIN users u ON p.user_id = u.id
                    LEFT JOIN churches c ON mc.church_id = c.id
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

            $db = $this->get(PDO::class);
            $stmt = $db->prepare($sql);
            $stmt->execute([':user_id' => $userId]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Cast ข้อมูลที่เป็น ID และ Integer ให้เป็น int type
            foreach ($data as &$row) {
                $row['closing_id']       = (int)$row['closing_id'];
                $row['year']             = (int)$row['year'];
                $row['month']            = (int)$row['month'];
                $row['church_id']        = (int)$row['church_id'];
                $row['my_approve_level'] = (int)$row['my_approve_level'];
            }

            $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(200);
        });
    })->add(new JwtAuthMiddleware()); // 👈 วางด่านตรวจความปลอดภัยท้ายกลุ่มจุดเดียวจบ
};
