# API Design Documentation

โปรเจกต์นี้ใช้ **Slim 4 PHP Framework** + **PDO MySQL**

## Authentication

> 🔒 = ต้องส่ง JWT Token ใน `Authorization: Bearer <token>` header
> 🌐 = Public ไม่ต้อง Auth

---

## 1. Auth (`app/auth.php`)

### POST `/api/login` 🌐
เข้าสู่ระบบและรับ JWT Token

**Request Body**
```json
{
  "email": "user@example.com",
  "password": "secret"
}
```

**Response 200**
```json
{
  "status": "success",
  "message": "เข้าสู่ระบบสำเร็จ",
  "user": { "id": 1, "firstname": "...", "lastname": "...", "email": "..." },
  "token": "<jwt>"
}
```

**Response 401**
```json
{ "status": "error", "message": "อีเมลหรือรหัสผ่านไม่ถูกต้อง" }
```

---

### POST `/api/forgot-password` 🌐
ขอลิงก์รีเซ็ตรหัสผ่าน ระบบจะส่งอีเมลพร้อม token ที่มีอายุ 24 ชั่วโมง

**Request Body**
```json
{ "email": "user@example.com" }
```

**Response 200**
```json
{
  "status": "success",
  "message": "ระบบได้ส่งลิงก์สำหรับตั้งรหัสผ่านใหม่ไปยังอีเมลของคุณแล้ว",
  "debug_url": "https://..."
}
```

---

### GET `/api/verify-reset-token` 🌐
ตรวจสอบว่า token รีเซ็ตรหัสผ่านยังใช้ได้อยู่

**Query Parameters**
| Param | Required | Description |
|-------|----------|-------------|
| `token` | ✅ | Token จากอีเมล |

**Response 200**
```json
{ "status": "success", "message": "Token ถูกต้อง", "user_id": 1 }
```

---

### POST `/api/reset-password` 🌐
บันทึกรหัสผ่านใหม่ (token จะถูกลบหลังใช้งาน)

**Request Body**
```json
{ "token": "abc123...", "new_password": "newSecret" }
```

**Response 200**
```json
{ "status": "success", "message": "เปลี่ยนรหัสผ่านสำเร็จ..." }
```

---

### GET `/api/approve-permission` 🔒
ดึงรายการผู้มีสิทธิ์อนุมัติของโบสถ์และฟอร์มที่ระบุ

**Query Parameters**
| Param | Required |
|-------|----------|
| `church_id` | ✅ |
| `request_form_id` | ✅ |

**Response 200**
```json
[{ "user_id": 1, "approve_level": 2 }]
```

---

### GET `/api/user-roles/{userId}` 🔒
ดึง Role ของผู้ใช้งาน (ไม่รวม role_id = 1)

**Response 200**
```json
[{ "role_id": 2, "role_name": "Manager" }]
```

---

### GET `/api/permission-data-master/{userId}` 🔒
ดึงสิทธิ์ข้อมูลหลัก (ไม่รวม scope_type = system)

**Response 200**
```json
[{ "scope_type": "church", "scope_id": 3, "permission": "write" }]
```

---

### GET `/api/permission-data/{userId}` 🔒
ดึงรายการโบสถ์ที่ผู้ใช้มีสิทธิ์เข้าถึง พร้อมระดับสิทธิ์สูงสุด

**Response 200**
```json
[{ "church_id": 1, "church_name": "โบสถ์ A", "permission": "write" }]
```

---

### GET `/api/permission-menu/{userId}` 🔒
ดึงเมนูที่ผู้ใช้มีสิทธิ์เข้าถึง (สำหรับ Sidebar)

**Response 200**
```json
[{ "menu_id": 1, "menu_label": "รายงาน", "menu_key": "report", "sort_order": 1, "permission": "read" }]
```

---

### PUT `/api/user-permissions/{userId}` 🔒
อัปเดตสิทธิ์การเข้าถึงข้อมูลของผู้ใช้ (ล้างของเดิมแล้วบันทึกใหม่ทั้งหมด)

**Request Body**
```json
{
  "permissions": [
    { "scope_type": "church", "scope_id": 1, "permission": "write" },
    { "scope_type": "region", "scope_id": 2, "permission": "read" }
  ]
}
```

**Response 200**
```json
{ "status": "success", "message": "บันทึกสิทธิ์เรียบร้อยแล้ว" }
```

---

### PUT `/api/user-roles/{userId}` 🔒
อัปเดต Role ของผู้ใช้ (ล้างของเดิมแล้วบันทึกใหม่ ยกเว้น role_id = 1)

**Request Body**
```json
{ "role_ids": [2, 3] }
```

**Response 200**
```json
{ "status": "success", "message": "บันทึก Role เรียบร้อยแล้ว" }
```

---

## 2. Users (`app/users.php`)

### GET `/api/users` 🌐
ดึงผู้ใช้งานทั้งหมด

**Response 200**
```json
[{ "id": 1, "firstname": "สมชาย", "lastname": "ใจดี", "email": "...", "phone": "..." }]
```

---

### GET `/api/user/staffs` 🌐
ดึงรายชื่อพนักงานทั้งหมด (เรียงตามชื่อ)

**Response 200**
```json
[{ "id": 1, "firstname": "สมชาย", "lastname": "ใจดี" }]
```

---

### GET `/api/user/person-wallet-list` 🌐
ดึงรายชื่อผู้ใช้ที่ผูก Wallet ของโบสถ์

**Query Parameters**
| Param | Required |
|-------|----------|
| `church_id` | ✅ |

**Response 200**
```json
[{ "value": 5, "label": "สมชาย ใจดี" }]
```

---

### GET `/api/users/search` 🌐
ค้นหาผู้ใช้งานแบบมี Pagination

**Query Parameters**
| Param | Default | Description |
|-------|---------|-------------|
| `search` | `""` | ค้นจาก firstname หรือ email |
| `page` | `1` | หน้าที่ต้องการ |
| `limit` | `20` | จำนวนต่อหน้า |

**Response 200**
```json
{
  "data": [{ "id": 1, "firstname": "...", "lastname": "...", "email": "..." }],
  "total_count": 50,
  "total_pages": 3,
  "current_page": 1
}
```

---

### GET `/api/users/{id}` 🌐
ดึงข้อมูลผู้ใช้งานรายคน

**Response 200** — ข้อมูลทุกฟิลด์จากตาราง `users`

**Response 404**
```json
{ "error": "ไม่พบข้อมูลรายการนี้" }
```

---

### POST `/api/users` 🌐
สร้างผู้ใช้งานใหม่ (ส่งอีเมลยืนยัน)

**Request Body**
```json
{ "firstname": "สมชาย", "lastname": "ใจดี", "email": "user@example.com" }
```

**Response 201**
```json
{ "status": "success", "message": "สร้างผู้ใช้งานเรียบร้อย..." }
```

---

### PUT `/api/users/{id}` 🌐
แก้ไขข้อมูลผู้ใช้งาน (ส่งเฉพาะฟิลด์ที่ต้องการแก้)

**Request Body** (ทุก field เป็น optional)
```json
{
  "firstname": "สมชาย",
  "lastname": "ใจดี",
  "email": "new@example.com",
  "phone": "0812345678",
  "password": "newPass"
}
```

**Response 200**
```json
{ "status": "success", "message": "User updated successfully" }
```

---

## 3. Master Data (`app/master.php`) 🔒

### GET `/api/master/churches`
ดึงรายชื่อโบสถ์ทั้งหมด พร้อมภูมิภาค

**Response 200**
```json
[{ "value": 1, "label": "โบสถ์ A", "region_id": 2, "region_name": "ภาคเหนือ" }]
```

---

### GET `/api/master/categories`
ดึงหมวดหมู่บัญชีทั้งหมด (Role 3 เห็นเฉพาะ expense)

**Response 200**
```json
[{ "value": 10, "label": "ค่าใช้จ่ายทั่วไป" }]
```

---

### GET `/api/master/roles`
ดึง Role ทั้งหมดในระบบ

**Response 200**
```json
[{ "role_id": 1, "role_name": "Admin" }]
```

---

### GET `/api/master/missions`
ดึงรายการพันธกิจทั้งหมด

**Response 200**
```json
[{ "value": 1, "label": "พันธกิจเด็ก" }]
```

---

### GET `/api/master/departments`
ดึงรายการฝ่าย/หน่วยงานทั้งหมด

**Response 200**
```json
[{ "value": 1, "label": "ฝ่ายการเงิน" }]
```

---

### GET `/api/master/categories/type`
ดึงหมวดหมู่แยกตามประเภท พร้อมกลุ่ม

**Query Parameters**
| Param | Description |
|-------|-------------|
| `type` | `income` หรือ `expense` |

**Response 200**
```json
[{ "category_id": 1, "category_name": "รายรับทั่วไป", "category_group": "กลุ่มรายรับ" }]
```

---

### GET `/api/master/common`
ดึง Common Master ลิสต์กลาง

**Query Parameters**
| Param | Description |
|-------|-------------|
| `masterFor` | เช่น `TRN_CHANNEL` |

**Response 200**
```json
[{ "value": 1, "label": "โอนธนาคาร" }]
```

---

### GET `/api/master-bank-account/{churchId}`
ดึงบัญชีธนาคารของโบสถ์ (ไม่รวมประเภท PERSON)

**Response 200**
```json
[{ "bank_account": "123-4-56789-0", "bank_account_name": "(123-4-56789-0) บัญชีออมทรัพย์", "bank_name": "SCB" }]
```

---

## 4. Transactions (`app/transactions.php`) 🔒

### POST `/api/transactions`
บันทึกธุรกรรมใหม่ พร้อมแนบไฟล์ได้

**Request Body** (`multipart/form-data`)
| Field | Required | Description |
|-------|----------|-------------|
| `church_id` | ✅ | รหัสโบสถ์ |
| `category_id` | ✅ | รหัสหมวดหมู่ |
| `channel_id` | ✅ | รหัสช่องทาง |
| `amount` | ✅ | จำนวนเงิน |
| `transaction_date` | ✅ | วันที่ (YYYY-MM-DD) |
| `recorded_by` | ✅ | รหัสผู้บันทึก |
| `bank_account` | — | หมายเลขบัญชีธนาคาร |
| `person_wallet` | — | รหัส User กระเป๋าเงิน |
| `ref_no` | — | เลขที่อ้างอิง |
| `payee_payer` | — | ผู้รับ/ผู้จ่าย |
| `description` | — | รายละเอียด |
| `mission_id` | — | รหัสพันธกิจ |
| `department_id` | — | รหัสฝ่าย/หน่วยงาน |
| `files[]` | — | ไฟล์แนบ (multiple) |

**Response 201**
```json
{ "status": "success", "message": "Transaction and files recorded", "id": 99, "files": ["..."] }
```

---

### POST `/api/transaction/{id}/update`
อัปเดตธุรกรรม พร้อมเพิ่ม/ลบไฟล์แนบ

**Request Body** (`multipart/form-data`) — ส่งเฉพาะฟิลด์ที่แก้ไข
| Field | Description |
|-------|-------------|
| `category_id`, `amount`, `channel_id`, ... | ฟิลด์ที่ต้องการอัปเดต |
| `delete_files` | JSON array ของ attachment ID ที่ต้องการลบ เช่น `[1,2]` |
| `files[]` | ไฟล์แนบใหม่ |

**Response 200**
```json
{ "status": "success", "message": "Transaction updated successfully" }
```

---

### DELETE `/api/transaction/{id}`
ลบธุรกรรม

**Response 200**
```json
{ "status": "success", "message": "Transaction deleted" }
```

**Response 404**
```json
{ "status": "error", "message": "Transaction not found" }
```

---

### GET `/api/transaction/{id}`
ดึงรายละเอียดธุรกรรมรายการเดียว พร้อมไฟล์แนบ

**Response 200**
```json
{
  "id": 1,
  "church_id": 1,
  "category_id": 5,
  "amount": "1000.00",
  "transaction_date": "2026-01-15",
  "...",
  "attachments": [{ "id": 1, "transaction_id": 1, "file_name": "slip.pdf", "file_path": "2601/1/ch1_..." }]
}
```

---

### GET `/api/transactions/search`
ค้นหาธุรกรรมแบบ Pagination + กรองตามสิทธิ์ผู้ใช้

**Query Parameters**
| Param | Description |
|-------|-------------|
| `church_id` | กรองตามโบสถ์ |
| `category_id` | กรองตามหมวดหมู่ |
| `bank_account` | กรองตามบัญชีธนาคาร |
| `start_date` | วันเริ่มต้น (YYYY-MM-DD) |
| `end_date` | วันสิ้นสุด (YYYY-MM-DD) |
| `ref_no` | ค้นหาเลขอ้างอิง (LIKE) |
| `payee_payer` | ค้นหาผู้รับ/จ่าย (LIKE) |
| `description` | ค้นหารายละเอียด (LIKE) |
| `page` | หน้าที่ต้องการ (default: 1) |
| `per_page` | จำนวนต่อหน้า (default: 100) |

**Response 200**
```json
{
  "status": "success",
  "data": [{ "id": 1, "transaction_date": "15-01-2026", "amount": "1000.00", "church_name": "...", "category_name": "..." }],
  "pagination": { "total": 200, "per_page": 100, "current_page": 1, "last_page": 2 }
}
```

---

### POST `/api/transaction/upload-preview`
อ่านไฟล์ Excel และคืนค่า Preview (ยังไม่บันทึก)

**Request Body** (`multipart/form-data`)
| Field | Required |
|-------|----------|
| `file` | ✅ ไฟล์ Excel (.xlsx) |

**หัวคอลัมน์ที่รองรับใน Excel:** Transaction ID, วันที่, หมวดหมู่, รายละเอียด, จำนวนเงิน, ช่องทาง, บัญชีธนาคาร, ผู้รับ, เลขที่อ้างอิง, กระเป๋าเงิน, mission, department

**Response 200**
```json
{
  "success": true,
  "summary": { "total_rows": 10, "error_rows": 1, "total_value": 9500.00 },
  "data": [{ "row_index": 2, "cat_name": "...", "value": 1000, "is_error": false, "error_reason": "" }]
}
```

---

### POST `/api/transaction/upload-confirm`
ยืนยันบันทึก Bulk Import จาก Excel Preview

**Request Body** (`application/json`)
```json
{
  "church_id": 1,
  "items": [
    {
      "cat_id": 5,
      "channel_id": 2,
      "value": 1000,
      "transaction_date": "2026-01-15",
      "remark": "...",
      "bank_account": "123-4-56789-0",
      "is_error": false
    }
  ]
}
```

**Response 200**
```json
{ "success": true, "message": "นำเข้าข้อมูลรายการบัญชีสำเร็จทั้งหมดเรียบร้อยแล้ว จำนวน 9 รายการ" }
```

---

## 5. Reports (`app/report.php`) 🔒

### GET `/api/report/category`
รายงานแยกตามหมวดหมู่ (สำหรับ Pie Chart) — รายปีหรือช่วงวันที่

**Query Parameters**
| Param | Description |
|-------|-------------|
| `church_id` | กรองโบสถ์ (0 = คืนผลว่าง) |
| `date_from` | วันเริ่มต้น — ถ้าส่งคู่นี้จะใช้แทน year |
| `date_to` | วันสิ้นสุด |
| `year` | ปี (default: ปัจจุบัน) |

**Response 200**
```json
[{ "label": "ค่าน้ำค่าไฟ", "value": 15000.00, "category_group": 2 }]
```

---

### GET `/api/report/by-department`
รายงาน รายรับ/รายจ่าย แยกตามฝ่าย/หน่วยงาน

**Query Parameters** เหมือน `/report/category`

**Response 200**
```json
[{ "department_name": "ฝ่ายการเงิน", "income": 50000.00, "outcome": 30000.00 }]
```

---

### GET `/api/report/category-monthly`
รายงานหมวดหมู่รายเดือน (ประมวลผลเร็วด้วย Index Range)

**Query Parameters**
| Param | Default |
|-------|---------|
| `year` | ปีปัจจุบัน |
| `month` | เดือนปัจจุบัน |
| `church_id` | — |

**Response 200**
```json
[{ "label": "รายรับทั่วไป", "value": 20000.00, "category_group": 9 }]
```

---

### GET `/api/report/category-monthly-detail`
รายงานหมวดหมู่รายเดือนแบบละเอียด แยก รายรับ/รายจ่าย, บัญชี, ช่องทาง

**Query Parameters** เหมือน `/report/category-monthly`

**Response 200**
```json
[{ "transaction_type": "รายรับ", "cat_name": "...", "bank_account": "...", "channel_name": "...", "value": 5000.00 }]
```

---

### GET `/api/report/yearly`
รายงานสรุป รายรับ/รายจ่าย รายปี แยกตามเดือน

**Query Parameters**
| Param | Default |
|-------|---------|
| `year` | ปีปัจจุบัน |
| `church_id` | — |

**Response 200**
```json
[{ "name": "January", "income": 100000.00, "outcome": 80000.00, "month_num": 1 }]
```

---

### GET `/api/report/monthly`
รายงานสรุปรายเดือน (ระบุเดือนได้)

**Query Parameters**
| Param | Default |
|-------|---------|
| `year` | ปีปัจจุบัน |
| `month` | เดือนปัจจุบัน |
| `church_id` | — |

**Response 200** — โครงสร้างเดียวกับ `/report/yearly`

---

### GET `/api/report/bank-balance`
รายงานยอดคงเหลือในบัญชีธนาคาร (รายปีหรือช่วงวันที่)

**Query Parameters** เหมือน `/report/category`

**Response 200**
```json
[{ "bank_account": "123-4-56789-0", "balance": 250000.00 }]
```

---

### GET `/api/report/bank-balance-monthly`
รายงานยอดเคลื่อนไหวบัญชีธนาคารเฉพาะเดือนที่เลือก

**Query Parameters** เหมือน `/report/category-monthly`

**Response 200** — โครงสร้างเดียวกับ `/report/bank-balance`

---

### GET `/api/report/export-excel`
ส่งออกรายการธุรกรรมเป็นไฟล์ Excel รายปี

**Query Parameters**
| Param | Default |
|-------|---------|
| `year` | ปีปัจจุบัน |
| `church_id` | — |

**Response** — ไฟล์ `report-{year}.xlsx`

---

### GET `/api/report/export-excel-monthly`
ส่งออกรายการธุรกรรมเป็น Excel รายเดือนหรือรายปี

**Query Parameters**
| Param | Description |
|-------|-------------|
| `year` | ปี (default: ปัจจุบัน) |
| `month` | เดือน (ถ้าไม่ส่ง = ทั้งปี) |
| `church_id` | — |

**Response** — ไฟล์ `report-{year}.xlsx` หรือ `report-{year}-month-{mm}.xlsx`

---

### GET `/api/report/export-excel-trn`
ส่งออก Excel ตามช่วงวันที่ + กรองหลายโบสถ์ได้

**Query Parameters**
| Param | Default | Description |
|-------|---------|-------------|
| `date_from` | วันแรกของปี | วันเริ่มต้น |
| `date_to` | วันสุดท้ายของปี | วันสิ้นสุด |
| `church_ids[]` | — | Array ของ church_id |

**Response** — ไฟล์ `report-from-{date_from}-to-{date_to}.xlsx` (มีคอลัมน์เพิ่ม: พันธกิจ, หน่วยงาน/ฝ่าย)

---

### GET `/api/report/person`
รายงานภาพรวมรายบุคคล แสดงยอด transfer_in, expense, transfer_out

**Query Parameters**
| Param | Required |
|-------|----------|
| `church_id` | ✅ |
| `date_from` | — |
| `date_to` | — |

**Response 200**
```json
[{ "user_id": 1, "firstname": "สมชาย", "transfer_in": 5000.00, "expense": 3000.00, "transfer_out": 2000.00 }]
```

---

### GET `/api/report/person-detail`
รายละเอียดธุรกรรมของบุคคลคนเดียว

**Query Parameters**
| Param | Required |
|-------|----------|
| `userId` | ✅ |
| `date_from` | — |
| `date_to` | — |

**Response 200**
```json
[{ "transaction_date": "15-01-2026", "description": "...", "amount": "1000.00", "category_name": "...", "type": "expense", "category_id": 5 }]
```

---

### GET `/api/report/monthly-closing-balance`
ดึงยอดยกมา (carry-forward) จากงบปิดของเดือนก่อนหน้า

**Query Parameters**
| Param | Default |
|-------|---------|
| `church_id` | — |
| `year` | ปีปัจจุบัน |
| `month` | เดือนปัจจุบัน |

**Response 200**
```json
[{ "bank_account": "123-4-56789-0", "statement_balance": 100000.00 }]
```

---

### GET `/api/report/monthly-closings-yearly`
ภาพรวมสถานะปิดงบ 12 เดือนประจำปี

**Query Parameters**
| Param | Required |
|-------|----------|
| `year` | ✅ |
| `church_id` | ✅ |

**Response 200**
```json
{
  "status": "success",
  "data": [{ "month_num": 1, "status": "approved", "total_income": 100000.00, "total_expense": 80000.00, "book_balance": 20000.00 }]
}
```

> `status` มีค่าเป็น: `none` | `pending` | `approved` | `rejected`

---

### GET `/api/report/monthly-closing-init`
ดึงข้อมูลตั้งต้นสำหรับเปิดฟอร์มปิดงบประจำเดือน

**Query Parameters**
| Param | Description |
|-------|-------------|
| `closing_id` | ถ้าส่งมาจะ override year/month/church_id จาก DB |
| `year` | ปี |
| `month` | เดือน |
| `church_id` | รหัสโบสถ์ |
| `status` | สถานะปัจจุบัน (none / pending / approved / rejected) |

**Response 200**
```json
{
  "status": "success",
  "data": {
    "summary": { "church_name": "...", "total_income": 0.0, "total_expense": 0.0, "book_balance": 0.0, "carry_forward": 0.0 },
    "accounts": [{ "bank_account": "...", "balance": 0.0, "statement_balance": 0.0, "diff_balance": 0.0, "note": "" }],
    "attachments": [],
    "allowedUser": [{ "approve_level": 2, "user_id": 3, "firstname": "...", "email": "..." }],
    "audit_logs": [{ "action_status": "pending", "note": "...", "created_at": "...", "actor_name": "..." }]
  }
}
```

---

### POST `/api/monthly-closing/submit`
บันทึกหรืออัปเดตงบปิดประจำเดือน (เปลี่ยนสถานะเป็น `pending`)

**Request Body** (`multipart/form-data`)
| Field | Required | Description |
|-------|----------|-------------|
| `year` | ✅ | ปี |
| `month` | ✅ | เดือน |
| `church_id` | ✅ | รหัสโบสถ์ |
| `note_by_treasurer` | — | หมายเหตุจากเหรัญญิก |
| `accounts_data` | ✅ | JSON string ของ `[{bank_account, system_balance, statement_balance, diff_balance, note}]` |
| `delete_files` | — | JSON array ของ attachment ID ที่ต้องการลบ |
| `attachments[]` | — | ไฟล์แนบใหม่ |

**Response 200**
```json
{ "status": "success", "message": "บันทึกสำเร็จ" }
```

---

### POST `/api/monthly-closing-approve-action`
ผู้อนุมัติกด Approve หรือ Reject งบปิดประจำเดือน

**Request Body**
```json
{
  "year": 2026,
  "month": 1,
  "church_id": 1,
  "status": "approved",
  "note_by_approver": "ตรวจสอบแล้ว ถูกต้อง",
  "user_level": 2
}
```

> `status` รับเฉพาะ `approved` หรือ `rejected`

**Response 200**
```json
{ "status": "success", "message": "อนุมัติปิดรอบบัญชีประจำเดือนเรียบร้อยแล้ว" }
```

---

### GET `/api/report/my-pending-approvals`
รายการงบปิดที่รออนุมัติ ตรงกับ Level ของผู้ใช้ปัจจุบัน (ดึง userId จาก JWT)

**Response 200**
```json
[{
  "closing_id": 10,
  "closing_status": "pending",
  "year": 2026,
  "month": 1,
  "church_id": 1,
  "church_name": "โบสถ์ A",
  "my_approve_level": 2,
  "firstname": "สมชาย",
  "email": "..."
}]
```

---

## Error Response Format

| Status | ความหมาย |
|--------|-----------|
| `400` | ข้อมูลไม่ครบหรือไม่ถูกต้อง |
| `401` | ไม่ผ่านการยืนยันตัวตน / Token หมดอายุ |
| `404` | ไม่พบข้อมูล |
| `500` | เกิดข้อผิดพลาดภายใน Server |

```json
{ "status": "error", "message": "รายละเอียดข้อผิดพลาด" }
```
