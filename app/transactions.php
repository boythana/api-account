<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;
use App\Application\Middleware\JwtAuthMiddleware;
use PhpOffice\PhpSpreadsheet\IOFactory;

return function (App $app) {

    // 🔒 รวบรวมทุกเส้นเข้ามาอยู่ใน Group เดียวกัน เพื่อแชร์ด่านตรวจ JWT ร่วมกันจุดเดียวจบ
    $app->group('/api', function (Group $group) {

        // 1. Route สำหรับ Insert Transaction
        $group->post('/transactions', function (Request $request, Response $response) {
            $data = $request->getParsedBody();
            $db = $this->get(PDO::class);

            // 🛡️ ด่านตรวจความปลอดภัย: ห้ามคีย์เข้าเดือนที่ปิดงบแล้ว
            if (isMonthClosed($db, $data['church_id'], $data['transaction_date'])) {
                $response->getBody()->write(json_encode([
                    'status' => 'error',
                    'message' => 'ไม่สามารถบันทึกธุรกรรมได้ เนื่องจากรอบบัญชีประจำเดือนนี้ได้รับการอนุมัติปิดงบเรียบร้อยแล้ว'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $uploadedFiles = $request->getUploadedFiles();
            $files = $uploadedFiles['files'] ?? [];

            // 🆕 เพิ่ม mission_id และ department_id ใน SQL String
            $sql = "INSERT INTO transactions (
                        church_id, category_id, channel_id, bank_account,
                        person_wallet, amount, ref_no, payee_payer,
                        transaction_date, description, recorded_by,
                        mission_id, department_id
                    ) VALUES (
                        :church_id, :category_id, :channel_id, :bank_account,
                        :person_wallet, :amount, :ref_no, :payee_payer,
                        :transaction_date, :description, :recorded_by,
                        :mission_id, :department_id
                    )";

            try {
                $db->beginTransaction();

                // 🆕 เพิ่ม Mapping Params รองรับทั้ง 2 ฟิลด์ใหม่ (หากส่งค่าว่างมาจะแปลงเป็น null เพื่อความปลอดภัยใน DB)
                $buildParams = function (array $override) use ($data) {
                    return array_merge([
                        ':church_id'        => $data['church_id'],
                        ':category_id'      => $data['category_id'],
                        ':channel_id'       => $data['channel_id'],
                        ':bank_account'     => !empty($data['bank_account']) ? $data['bank_account'] : null,
                        ':person_wallet'    => !empty($data['person_wallet']) ? $data['person_wallet'] : null,
                        ':amount'           => $data['amount'],
                        ':ref_no'           => !empty($data['ref_no']) ? $data['ref_no'] : null,
                        ':payee_payer'      => !empty($data['payee_payer']) ? $data['payee_payer'] : null,
                        ':transaction_date' => $data['transaction_date'],
                        ':description'      => $data['description'] ?? null,
                        ':recorded_by'      => $data['recorded_by'] ?? '1',
                        ':mission_id'       => !empty($data['mission_id']) ? $data['mission_id'] : null,
                        ':department_id'    => !empty($data['department_id']) ? $data['department_id'] : null,
                    ], $override);
                };

                $stmt = $db->prepare($sql);
                $stmt->execute($buildParams([]));
                $transactionId = $db->lastInsertId();

                $filePaths = handleFileUploads($files, $transactionId, $data['church_id'], $data['transaction_date'], 'TRN', $db);

                $db->commit();

                $result = ['status' => 'success', 'message' => 'Transaction and files recorded', 'id' => $transactionId, 'files' => $filePaths];
                $status = 201;
            } catch (\Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $result = ['status' => 'error', 'message' => $e->getMessage()];
                $status = 500;
            }

            $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
        });

        // 2. Route สำหรับ Update Transaction
        $group->post('/transaction/{id}/update', function (Request $request, Response $response, array $args) {
            $id = $args['id'];
            $data = $request->getParsedBody();
            $uploadedFiles = $request->getUploadedFiles();
            $db = $this->get(PDO::class);

            try {
                $db->beginTransaction();

                $allowedFields = [
                    'category_id',
                    'amount',
                    'channel_id',
                    'bank_account',
                    'person_wallet',
                    'ref_no',
                    'payee_payer',
                    'transaction_date',
                    'description',
                    'recorded_by',
                    'mission_id',      // 🆕 รองรับการอัปเดต พันธกิจ
                    'department_id'   // 🆕 รองรับการอัปเดต หน่วยงาน/ฝ่าย
                ];

                $fields = [];
                $params = [':id' => $id];

                foreach ($allowedFields as $field) {
                    if (array_key_exists($field, $data)) {
                        $fields[] = "$field = :$field";
                        $value = $data[$field];

                        if ($value === '' || $value === null || (is_string($value) && (strtolower($value) === 'null' || strtolower($value) === 'undefined'))) {
                            $params[":$field"] = null;
                        } else {
                            $params[":$field"] = is_string($value) ? trim($value) : $value;
                        }
                    }
                }

                if (!empty($fields)) {
                    $sql = "UPDATE transactions SET " . implode(', ', $fields) . " WHERE id = :id";
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                }

                if (!empty($data['delete_files'])) {
                    $deleteIds = json_decode($data['delete_files'], true);
                    if (is_array($deleteIds)) {
                        foreach ($deleteIds as $fileId) {
                            $stmt = $db->prepare("SELECT file_path FROM transaction_attachments WHERE id = ? AND transaction_id = ?");
                            $stmt->execute([$fileId, $id]);
                            $fileRecord = $stmt->fetch();

                            if ($fileRecord) {
                                deletePhysicalFile($fileRecord['file_path']);
                                $db->prepare("DELETE FROM transaction_attachments WHERE id = ?")->execute([$fileId]);
                            }
                        }
                    }
                }

                $files = $uploadedFiles['files'] ?? [];
                if (!empty($files)) {
                    $c_id = $data['church_id'] ?? null;
                    $t_date = $data['transaction_date'] ?? null;
                    handleFileUploads($files, $id, $c_id, $t_date, 'TRN', $db);
                }

                $db->commit();
                $result = ['status' => 'success', 'message' => 'Transaction updated successfully'];
                $status = 200;
            } catch (\Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $result = ['status' => 'error', 'message' => $e->getMessage()];
                $status = 500;
            }

            $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        });

        // 3. Route สำหรับ Delete Transaction
        $group->delete('/transaction/{id}', function (Request $request, Response $response, array $args) {
            $id = $args['id'];
            $db = $this->get(PDO::class);

            try {
                $stmt = $db->prepare("DELETE FROM transactions WHERE id = :id ");
                $stmt->execute([':id' => $id]);

                if ($stmt->rowCount() === 0) {
                    $result = ['status' => 'error', 'message' => 'Transaction not found'];
                    $status = 404;
                } else {
                    $result = ['status' => 'success', 'message' => 'Transaction deleted'];
                    $status = 200;
                }
            } catch (\PDOException $e) {
                $result = ['status' => 'error', 'message' => $e->getMessage()];
                $status = 500;
            }

            $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
        });

        // 4. Route สำหรับ Get Single Transaction
        $group->get('/transaction/{id}', function (Request $request, Response $response, array $args) {
            $id = $args['id'];
            $db = $this->get(PDO::class);

            try {
                if (!is_numeric($id)) {
                    throw new \Exception("Invalid ID format");
                }

                $stmt = $db->prepare("SELECT * FROM transactions WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $id]);
                $result = $stmt->fetch(\PDO::FETCH_ASSOC);

                if ($result) {
                    $stmtFiles = $db->prepare("SELECT id, transaction_id, file_name, file_path FROM transaction_attachments WHERE transaction_id = :id AND data_for='TRN' ");
                    $stmtFiles->execute([':id' => $id]);
                    $attachments = $stmtFiles->fetchAll(\PDO::FETCH_ASSOC);

                    $result['attachments'] = $attachments ? $attachments : [];
                    $status = 200;
                    $payload = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                } else {
                    $status = 404;
                    $payload = json_encode(['error' => 'ไม่พบข้อมูลรายการนี้'], JSON_UNESCAPED_UNICODE);
                }
            } catch (\Exception $e) {
                $status = 500;
                $payload = json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            }

            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
        });

        // 5. Route สำหรับ Search Transactions
        $group->get('/transactions/search', function (Request $request, Response $response) {
            $userId   = $request->getAttribute('userId');
            $userRole = $request->getAttribute('userRole');

            $queryParams = $request->getQueryParams();
            $db = $this->get(PDO::class);

            $page = (int)($queryParams['page'] ?? 1);
            $perPage = (int)($queryParams['per_page'] ?? 100);
            $offset = ($page - 1) * $perPage;

            $conditions = [];
            $params = [];

            // 1. ตรวจสอบสิทธิ์ผู้ใช้งาน (Wallet Control)
            if ($userRole == 3) {
                $conditions[] = "t.person_wallet = :person_wallet";
                $params[':person_wallet'] = $userId;
            }

            // 2. 🆕 เรียกใช้งานฟังก์ชันกลางเพื่อดึงกลุ่มโบสถ์ที่ได้รับสิทธิ์
            $allowedChurches = getAllowedChurchIds($db, (int)$userId);

            // 🛡️ Security Guard: ถ้าสแกนแล้วไม่พบสิทธิ์ในโบสถ์ใด ๆ เลย ให้ตัดบทส่งตารางว่างกลับทันที ไม่ต้องรัน Query หลักให้เปลืองทรัพยากร
            if (empty($allowedChurches)) {
                $payload = [
                    'status' => 'success',
                    'data' => [],
                    'pagination' => [
                        'total' => 0,
                        'per_page' => $perPage,
                        'current_page' => $page,
                        'last_page' => 0
                    ]
                ];
                $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json');
            }

            // 3. จัดการ Data Boundary Filter ตามเงื่อนไขที่ส่งมาจากหน้าจอ
            if (!empty($queryParams['church_id'])) {
                // เคสที่ 1: หน้าจอส่งโบสถ์เจาะจงมา -> เช็กความปลอดภัยก่อนว่าโบสถ์นั้นอยู่ในกลุ่มที่ได้รับสิทธิ์จริงไหม
                if (in_array($queryParams['church_id'], $allowedChurches)) {
                    $conditions[] = "t.church_id = :church_id";
                    $params[':church_id'] = $queryParams['church_id'];
                } else {
                    // บล็อกทันทีถ้าแอบส่ง ID โบสถ์ที่ตนเองไม่มีสิทธิ์เข้ามา โดยบังคับให้ SQL ค้นหาไม่เจอข้อมูล (1 = 0)
                    $conditions[] = "1 = 0";
                }
            } else {
                // เคสที่ 2: หน้าจอไม่ได้ส่งโบสถ์มา (เลือก "ทั้งหมด") -> บังคับสโคปเจอเฉพาะชุด ID โบสถ์ที่มีสิทธิ์ผ่าน IN (...)
                $idsString = implode(',', array_map('intval', $allowedChurches));
                $conditions[] = "t.church_id IN ($idsString)";
            }

            // 4. บรรจุพารามิเตอร์การกรอกข้อมูลอื่น ๆ ตามปกติ
            if (!empty($queryParams['category_id'])) {
                $conditions[] = "t.category_id = :category_id";
                $params[':category_id'] = $queryParams['category_id'];
            }

            if (!empty($queryParams['bank_account'])) {
                $conditions[] = "t.bank_account = :bank_account";
                $params[':bank_account'] = $queryParams['bank_account'];
            }

            if (!empty($queryParams['start_date']) && !empty($queryParams['end_date'])) {
                $conditions[] = "t.transaction_date BETWEEN :start_date AND :end_date";
                $params[':start_date'] = $queryParams['start_date'];
                $params[':end_date'] = $queryParams['end_date'];
            }

            if (!empty($queryParams['ref_no'])) {
                $conditions[] = "t.ref_no LIKE :ref_no";
                $params[':ref_no'] = "%" . $queryParams['ref_no'] . "%";
            }

            if (!empty($queryParams['payee_payer'])) {
                $conditions[] = "t.payee_payer LIKE :payee_payer";
                $params[':payee_payer'] = "%" . $queryParams['payee_payer'] . "%";
            }

            if (!empty($queryParams['description'])) {
                $conditions[] = "t.description LIKE :description";
                $params[':description'] = "%" . $queryParams['description'] . "%";
            }

            $whereSql = "";
            if (!empty($conditions)) {
                $whereSql = " WHERE " . implode(" AND ", $conditions);
            }

            try {
                // นับจำนวนแถวทั้งหมดตามฟิลเตอร์ (Pagination Count)
                $countSql = "SELECT COUNT(*) as total FROM transactions t " . $whereSql;
                $stmtCount = $db->prepare($countSql);
                $stmtCount->execute($params);
                $totalCount = (int)$stmtCount->fetchColumn();

                // 🆕 คิวรีข้อมูลหลัก: สังเกตว่า SQL ตัวนี้จะคลีนและอ่านง่ายมาก เพราะไม่มี Subquery เงื่อนไขสิทธิ์มาซ้อนให้รกตา
                $sql = "SELECT t.id, DATE_FORMAT(t.transaction_date, '%d-%m-%Y') transaction_date, t.amount, t.description, 
                       t.ref_no, t.payee_payer, 
                       c.name as church_name, ac.name as category_name
                FROM transactions t 
                LEFT JOIN churches c ON t.church_id = c.id 
                LEFT JOIN account_categories ac ON t.category_id = ac.id "
                    . $whereSql .
                    " ORDER BY t.transaction_date DESC, t.id DESC 
                LIMIT :limit OFFSET :offset";

                $stmt = $db->prepare($sql);

                // ผูกค่าพารามิเตอร์แบบไดนามิก
                foreach ($params as $key => $val) {
                    $stmt->bindValue($key, $val);
                }

                $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

                $stmt->execute();
                $results = $stmt->fetchAll();

                $payload = [
                    'status' => 'success',
                    'data' => $results,
                    'pagination' => [
                        'total' => $totalCount,
                        'per_page' => $perPage,
                        'current_page' => $page,
                        'last_page' => ceil($totalCount / $perPage)
                    ]
                ];

                $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json');
            } catch (\PDOException $e) {
                $response->getBody()->write(json_encode([
                    'status' => 'error',
                    'message' => $e->getMessage()
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }
        });

        // 6. 🚀 Route สำหรับ Excel Upload Preview (ปรับปรุง: ตรวจสอบด้วยชื่อหัวคอลัมน์ดั้งเดิม + Ignore คอลัมน์โบสถ์ออกจาก Excel)
        $group->post('/transaction/upload-preview', function (Request $request, Response $response) {
            $uploadedFiles = $request->getUploadedFiles();
            if (empty($uploadedFiles['file'])) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'ไม่พบไฟล์ที่อัปโหลด'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $uploadedFile = $uploadedFiles['file'];
            if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'เกิดข้อผิดพลาดในการอัปโหลดไฟล์ชั่วคราว'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }

            try {
                $inputFilePath = $uploadedFile->getStream()->getMetadata('uri');
                $spreadsheet = IOFactory::load($inputFilePath);
                $worksheet = $spreadsheet->getSheet(0);
                // $worksheet = $spreadsheet->getActiveSheet();
                $highestRow = $worksheet->getHighestRow();
                $highestColumn = $worksheet->getHighestColumn();
                $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

                // 🔍 สแกนหาคอลัมน์จากแถว Header (แถวที่ 1) บันทึกเฉพาะฟิลด์ใช้งาน (ไม่มีฟิลด์ church แล้ว)
                $colMap = [
                    'transaction_id' => null,
                    'date'           => null,
                    'cat_name'       => null,
                    'remark'         => null,
                    'value'          => null,
                    'channel_name'   => null,
                    'bank_account'   => null,
                    'payee_payer'    => null,
                    'reference_no'   => null,
                    'person_wallet'  => null,
                    'mission'        => null,
                    'department'     => null,
                ];

                for ($col = 1; $col <= $highestColumnIndex; $col++) {
                    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                    $headerText = trim($worksheet->getCell($colLetter . '1')->getValue() ?? '');

                    if (str_contains($headerText, 'Transaction ID')) $colMap['transaction_id'] = $col;
                    if (str_contains($headerText, 'วันที่')) $colMap['date'] = $col;
                    if (str_contains($headerText, 'หมวดหมู่')) $colMap['cat_name'] = $col;
                    if (str_contains($headerText, 'รายละเอียด')) $colMap['remark'] = $col;
                    if (str_contains($headerText, 'จำนวนเงิน')) $colMap['value'] = $col;
                    if (str_contains($headerText, 'ช่องทาง')) $colMap['channel_name'] = $col;
                    if (str_contains($headerText, 'บัญชีธนาคาร')) $colMap['bank_account'] = $col;
                    if (str_contains($headerText, 'ผู้รับ')) $colMap['payee_payer'] = $col;
                    if (str_contains($headerText, 'เลขที่อ้างอิง')) $colMap['reference_no'] = $col;
                    if (str_contains($headerText, 'กระเป๋าเงิน')) $colMap['person_wallet'] = $col;
                    if (str_contains($headerText, 'mission')) $colMap['mission'] = $col;
                    if (str_contains($headerText, 'department')) $colMap['department'] = $col;
                }

                // ตรวจสอบคอลัมน์บังคับขั้นต่ำ
                if (!$colMap['cat_name'] || !$colMap['value'] || !$colMap['channel_name']) {
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'message' => 'โครงสร้างไฟล์ไม่ถูกต้อง กรุณาตรวจสอบคอลัมน์ หมวดหมู่, จำนวนเงิน หรือช่องทาง'
                    ], JSON_UNESCAPED_UNICODE));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
                }

                $db = $this->get(PDO::class);

                // ดึงข้อมูลและทำ Map ของข้อมูลหลัก (Master Data Maps)
                $masterCatMap = [];
                foreach ($db->query("SELECT id, name FROM account_categories")->fetchAll(PDO::FETCH_ASSOC) as $rowCat) {
                    $masterCatMap[trim($rowCat['name'])] = $rowCat['id'];
                }
                $masterMissionMap = [];
                foreach ($db->query("SELECT id, name FROM missions")->fetchAll(PDO::FETCH_ASSOC) as $rowMission) {
                    $masterMissionMap[trim($rowMission['name'])] = $rowMission['id'];
                }
                $masterDeptMap = [];
                foreach ($db->query("SELECT id, name FROM departments")->fetchAll(PDO::FETCH_ASSOC) as $rowDept) {
                    $masterDeptMap[trim($rowDept['name'])] = $rowDept['id'];
                }
                $masterChannelMap = [];
                foreach ($db->query("SELECT id, name FROM master_common where master_for='TRN_CHANNEL'")->fetchAll(PDO::FETCH_ASSOC) as $rowChannel) {
                    $masterChannelMap[trim($rowChannel['name'])] = $rowChannel['id'];
                }

                $getCellValue = function ($worksheet, $colIndex, $row) {
                    if (!$colIndex) return null;
                    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex);
                    return $worksheet->getCell($colLetter . $row)->getValue();
                };

                $previewItems = [];

                for ($row = 2; $row <= $highestRow; $row++) {
                    $txId        = trim(($getCellValue($worksheet, $colMap['transaction_id'], $row) ?? '') . '');
                    $dateVal     = trim(($getCellValue($worksheet, $colMap['date'], $row) ?? '') . '');
                    $catName     = trim(($getCellValue($worksheet, $colMap['cat_name'], $row) ?? '') . '');
                    $remark      = trim(($getCellValue($worksheet, $colMap['remark'], $row) ?? '') . '');
                    $value       = $getCellValue($worksheet, $colMap['value'], $row);
                    $channelName = trim(($getCellValue($worksheet, $colMap['channel_name'], $row) ?? '') . '');
                    $bankAccount = trim(($getCellValue($worksheet, $colMap['bank_account'], $row) ?? '') . '');
                    $payeePayer  = trim(($getCellValue($worksheet, $colMap['payee_payer'], $row) ?? '') . '');
                    $refNo       = trim(($getCellValue($worksheet, $colMap['reference_no'], $row) ?? '') . '');
                    $wallet      = trim(($getCellValue($worksheet, $colMap['person_wallet'], $row) ?? '') . '');
                    $mission     = trim(($getCellValue($worksheet, $colMap['mission'], $row) ?? '') . '');
                    $department  = trim(($getCellValue($worksheet, $colMap['department'], $row) ?? '') . '');

                    if (empty($catName) && empty($channelName) && ($value === '' || $value === null)) {
                        continue;
                    }

                    $valueNum = is_numeric($value) ? (float)$value : 0;
                    $isError = false;
                    $errorReasons = [];

                    $catId = null;
                    $missionId = null;
                    $departmentId = null;
                    $channelId = null;

                    if (empty($catName)) {
                        $isError = true;
                        $errorReasons[] = 'ไม่มีข้อมูลหมวดหมู่';
                    }
                    if (empty($channelName)) {
                        $isError = true;
                        $errorReasons[] = 'ไม่มีข้อมูลช่องทาง';
                    }
                    if (!is_numeric($value)) {
                        $isError = true;
                        $errorReasons[] = 'จำนวนเงินไม่ถูกต้องหรือไม่เป็นตัวเลข';
                    }

                    if (!empty($catName)) {
                        if (isset($masterCatMap[$catName])) {
                            $catId = $masterCatMap[$catName];
                        } else {
                            $isError = true;
                            $errorReasons[] = "หมวดหมู่ '{$catName}' ไม่มีในระบบ";
                        }
                    }
                    if (!empty($mission)) {
                        if (isset($masterMissionMap[$mission])) {
                            $missionId = $masterMissionMap[$mission];
                        } else {
                            $isError = true;
                            $errorReasons[] = "Mission '{$mission}' ไม่มีในระบบ";
                        }
                    }
                    if (!empty($department)) {
                        if (isset($masterDeptMap[$department])) {
                            $departmentId = $masterDeptMap[$department];
                        } else {
                            $isError = true;
                            $errorReasons[] = "Department '{$department}' ไม่มีในระบบ";
                        }
                    }
                    if (!empty($channelName)) {
                        if (isset($masterChannelMap[$channelName])) {
                            $channelId = $masterChannelMap[$channelName];
                        } else {
                            $isError = true;
                            $errorReasons[] = "ช่องทาง '{$channelName}' ไม่มีในระบบ";
                        }
                    }

                    $previewItems[] = [
                        'row_index'        => $row,
                        'transaction_id'   => !empty($txId) ? $txId : null,
                        'transaction_date' => $dateVal,
                        'church_name'      => null, // ดึงจากหน้าจอ ไม่เก็บจากคอลัมน์โบสถ์ใน Excel
                        'cat_id'           => $catId,
                        'cat_name'         => $catName,
                        'mission_id'       => $missionId,
                        'mission'          => $mission,
                        'department_id'    => $departmentId,
                        'department'       => $department,
                        'channel_id'       => $channelId,
                        'channel_name'     => $channelName,
                        'remark'           => !empty($remark) ? $remark : null,
                        'value'            => $valueNum,
                        'bank_account'     => !empty($bankAccount) ? $bankAccount : null,
                        'payee_payer'      => !empty($payeePayer) ? $payeePayer : null,
                        'reference_no'     => !empty($refNo) ? $refNo : null,
                        'person_wallet'    => !empty($wallet) ? $wallet : null,
                        'is_error'         => $isError,
                        'error_reason'     => implode(', ', $errorReasons)
                    ];
                }

                $totalRows  = count($previewItems);
                $errorRows  = count(array_filter($previewItems, function ($item) {
                    return $item['is_error'];
                }));
                $totalValue = array_reduce($previewItems, function ($sum, $item) {
                    return $sum + ($item['is_error'] ? 0 : $item['value']);
                }, 0);

                $result = [
                    'success' => true,
                    'summary' => [
                        'total_rows'  => $totalRows,
                        'error_rows'  => $errorRows,
                        'total_value' => $totalValue
                    ],
                    'data' => $previewItems
                ];

                $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
            } catch (\Exception $e) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'เกิดข้อผิดพลาดในการประมวลผลไฟล์ Excel',
                    'error'   => $e->getMessage()
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }
        });

        // 7. Route สำหรับการยืนยันบันทึกข้อมูล Bulk Insert (Confirm Upload)
        $group->post('/transaction/upload-confirm', function (Request $request, Response $response) {
            $parsedBody = $request->getParsedBody();
            $items = $parsedBody['items'] ?? [];
            $selectedChurchId = $parsedBody['church_id'] ?? null; // 🎯 รับค่าโบสถ์ปลายทางที่ผู้ใช้เลือกจากหน้าจอ
            $userId = $request->getAttribute('userId') ?? '1';

            if (!is_array($items) || empty($items)) {
                $response->getBody()->write(json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลรายการที่ส่งมาบันทึก'], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $db = $this->get(PDO::class);
            $churchIdFromPermissions = $request->getAttribute('churchId') ?? null;

            $sql = "INSERT INTO transactions (
                        church_id, category_id, channel_id, bank_account,
                        person_wallet, amount, mission_id, department_id, ref_no, payee_payer,
                        transaction_date, description, recorded_by
                    ) VALUES (
                        :church_id, :category_id, :channel_id, :bank_account,
                        :person_wallet, :amount, :mission_id, :department_id, :ref_no, :payee_payer,
                        :transaction_date, :description, :recorded_by
                    )";

            try {
                $db->beginTransaction();
                $stmt = $db->prepare($sql);
                $insertedCount = 0;

                foreach ($items as $item) {
                    if (!empty($item['is_error'])) {
                        continue;
                    }

                    // 🎯 ใช้โบสถ์ที่เลือกมาจากหน้าจอเป็นหลัก หากไม่มีจะ fallback ไปตามสิทธิ์ JWT ของผู้ใช้
                    $finalChurchId = !empty($selectedChurchId) ? $selectedChurchId : $churchIdFromPermissions;
                    if (empty($finalChurchId)) {
                        $finalChurchId = '1';
                    }

                    $txDate = !empty($item['transaction_date']) ? trim($item['transaction_date']) : date('Y-m-d');

                    if (isMonthClosed($db, $finalChurchId, $txDate)) {
                        throw new \Exception("แถวที่ {$item['row_index']}: ไม่สามารถนำเข้าข้อมูลได้ เนื่องจากรอบบัญชีประจำเดือนนี้ได้รับการอนุมัติปิดงบเรียบร้อยแล้ว");
                    }

                    $params = [
                        ':church_id'        => $finalChurchId,
                        ':category_id'      => !empty($item['cat_id']) ? $item['cat_id'] : null,
                        ':channel_id'       => !empty($item['channel_id']) ? $item['channel_id'] : '1',
                        ':bank_account'     => !empty($item['bank_account']) ? trim($item['bank_account']) : null,
                        ':person_wallet'    => !empty($item['person_wallet']) ? trim($item['person_wallet']) : null,
                        ':amount'           => !empty($item['value']) ? (float)$item['value'] : 0,
                        ':mission_id'       => !empty($item['mission_id']) ? $item['mission_id'] : null,
                        ':department_id'    => !empty($item['department_id']) ? $item['department_id'] : null,
                        ':ref_no'           => !empty($item['reference_no']) ? trim($item['reference_no']) : null,
                        ':payee_payer'      => !empty($item['payee_payer']) ? trim($item['payee_payer']) : null,
                        ':transaction_date' => $txDate,
                        ':description'      => !empty($item['remark']) ? trim($item['remark']) : null,
                        ':recorded_by'      => $userId,
                    ];

                    $stmt->execute($params);
                    $insertedCount++;
                }

                $db->commit();

                $response->getBody()->write(json_encode([
                    'success' => true,
                    'message' => "นำเข้าข้อมูลรายการบัญชีสำเร็จทั้งหมดเรียบร้อยแล้ว จำนวน {$insertedCount} รายการ"
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
            } catch (\Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'บันทึกข้อมูลแบบกลุ่มไม่สำเร็จ ข้อมูลทั้งหมดถูกยกเลิกเพื่อความปลอดภัย',
                    'error'   => $e->getMessage()
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }
        });
    })->add(new JwtAuthMiddleware());
};
