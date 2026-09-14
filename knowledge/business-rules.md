# Business Rules — Nexus Account API

ระบบบัญชีสำหรับองค์กรโบสถ์ (Church Accounting) สร้างด้วย PHP Slim 4 + MySQL

---

## 1. Authentication & JWT

### Login
- ตรวจสอบ email + password ด้วย `password_verify()`
- JWT payload: `uid`, `name`, `role` (role_id จาก `user_role`)
- Token หมดอายุใน **24 ชั่วโมง** (`exp = now + 86400`)
- Algorithm: **HS256** ใช้ `JWT_SECRET` จาก `.env`

### JwtAuthMiddleware (`src/Application/Middleware/JwtAuthMiddleware.php`)
- อ่าน Token จาก `Authorization: Bearer <token>`
- Decode แล้ว inject `userId` และ `userRole` เข้า request attributes
- ทุก route ที่ require JWT จะเข้าถึง `$request->getAttribute('userId')` ได้โดยตรง

### Forgot Password
- สร้าง token 32 bytes (hex) บันทึกใน `user_verifications`
- Token หมดอายุ **24 ชั่วโมง**
- ส่ง reset URL ไปทาง PHPMailer (URL = `WEB_URL + /reset-new-password?token=...`)
- หลัง reset สำเร็จ → **ลบ token ออกทันที** (one-time use)
- ใช้ DB transaction เพื่อ atomic update password + delete token

---

## 2. Permission System (3-tier Data Scope)

ระบบสิทธิ์แบ่งออกเป็น 3 ระดับผ่านตาราง `user_data_permissions`:

| scope_type | scope_id | ความหมาย |
|---|---|---|
| `system` | — | เข้าถึงทุกโบสถ์ทั้งระบบ |
| `region` | region_id | เข้าถึงทุกโบสถ์ในภาคนั้น |
| `church` | church_id | เข้าถึงโบสถ์เดียวที่ระบุ |

### `getAllowedChurchIds()` (common.php)
- Query หาทุก `church.id` ที่ user มีสิทธิ์ผ่าน EXISTS subquery
- เรียกใช้ทุกครั้งที่ search transactions
- **Security Guard**: ถ้า list ว่าง → return empty ทันที (ไม่ทำ query หลัก)
- ถ้า frontend ส่ง church_id ที่ไม่อยู่ใน allowed list → inject `1 = 0` ใน WHERE clause

### Menu Permission
- เมนู sidebar ดึงจากตาราง `menus` JOIN `role_permissions` ตาม roles ของ user
- ใช้ `MAX(rp.permission)` เพื่อรวม permission จาก roles หลายตัว

---

## 3. Role-Based Restrictions

### Role 3 (Staff) มีข้อจำกัดพิเศษ:

| Feature | Rule |
|---|---|
| หมวดหมู่ (`/master/categories`) | เห็นเฉพาะ `type = 'expense'` |
| ค้นหาธุรกรรม (`/transactions/search`) | filter เฉพาะ `person_wallet = userId` |
| รายงานรายบุคคล (`/report/person`) | filter เฉพาะ `person_wallet = userId` |

### Role 1 (Protected)
- ไม่สามารถลบออกจาก `user_role` ได้ → DELETE ใช้ `WHERE role_id != 1`

---

## 4. Transaction Rules

### Closed Month Guard (`isMonthClosed()`)
- ทุก INSERT/UPDATE/Import transaction ต้องผ่านการตรวจสอบก่อน
- Query ตาราง `monthly_closings` ว่า `status = 'approved'` หรือไม่
- ถ้าเดือนนั้นปิดงบแล้ว → **block ทันที** (400 error)

### File Attachments
- เก็บไฟล์จริงที่: `public/uploads/{YYMM}/{church_id}/{ch{id}_{timestamp}_{rand}.ext}`
- บันทึก path สั้น (`{YYMM}/{church_id}/{filename}`) ใน `transaction_attachments.file_path`
- `data_for = 'TRN'` สำหรับไฟล์ธุรกรรมปกติ
- `data_for = 'TRN_CLOSING'` สำหรับไฟล์งบปิดเดือน
- ลบไฟล์จริงผ่าน `deletePhysicalFile()` ก่อน DELETE record

### Bulk Import (Excel)
1. **Preview** (`/transaction/upload-preview`) — parse Excel แต่ไม่บันทึก
   - ตรวจสอบคอลัมน์จาก header text (ภาษาไทย): "หมวดหมู่", "จำนวนเงิน", "ช่องทาง" บังคับ
   - Map ชื่อหมวดหมู่/ช่องทาง/mission/department → ID ผ่าน in-memory map
   - คืน flag `is_error` + `error_reason` ทุกแถว
2. **Confirm** (`/transaction/upload-confirm`) — INSERT เฉพาะแถวที่ `is_error = false`
   - ตรวจ Closed Month ทุกแถวก่อน insert
   - Wrap ทั้งหมดใน single transaction → rollback ทั้งหมดถ้ามีแถวใดผิดพลาด

---

## 5. Category & Account Group Logic

### Special Category IDs
| Category ID | ความหมาย | ใช้ใน |
|---|---|---|
| 42 | โอนเข้า (Transfer In) | Person Wallet รับเงิน |
| 319 | โอนออก (Transfer Out) | Person Wallet จ่ายเงิน |

### account_group = 9 → รายรับ (Income)
- ทุก `account_categories.group = 9` ถือเป็น **income**
- ทุก group อื่น ถือเป็น **expense**
- ใช้ใน SQL: `CASE WHEN ac.group = 9 THEN income ELSE expense END`

### รายงาน Pie Chart
- **ยกเว้น** category_id 42 และ 319 จากการแสดงผล (ธุรกรรมโอนภายใน)

---

## 6. Person Wallet (กระเป๋าเงิน)

- ตาราง `user_person_wallet` ผูก user กับ church
- `transactions.person_wallet` = user_id ของคนถือเงิน
- รายงาน `/report/person` สรุปยอดแยกเป็น:
  - `transfer_in` (category_id = 42)
  - `expense` (category_id ไม่ใช่ 42, 319)
  - `transfer_out` (category_id = 319)
- Master dropdown `/api/user/person-wallet-list?church_id=X` ใช้ JOIN ตาราง `user_person_wallet`

---

## 7. Monthly Closing Workflow

### State Machine
```
none → pending → approved (ปิดถาวร)
              ↘ rejected → pending (เหรัญญิกแก้ไขแล้ว re-submit)
```

### ตาราง
- `monthly_closings` — Master record (church, year, month, status, totals)
- `monthly_closing_accounts` — Detail per bank account (system_balance, statement_balance, diff_balance)
- `monthly_closing_logs` — Audit trail ทุก action
- `approve_permissions` — Config ว่าใครอนุมัติ level ไหน per (org_id, request_form_id)

### Submit Flow (เหรัญญิก)
1. ดึง `carry_forward` จาก previous month's **approved** closing
   - ถ้าไม่มี previous approved closing → **block** (400 error)
2. คำนวณ `total_income` / `total_expense` จากตาราง `transactions` ของเดือนนั้น
3. คำนวณ `book_balance = carry_forward + total_income - total_expense`
4. บันทึก `monthly_closings` (status = `pending`) + `monthly_closing_accounts` ต่อบัญชี
5. บันทึก Log (approve_level=1, action_status=`pending`)
6. ส่งอีเมล notify ผู้อนุมัติ Level ถัดไป

### Approve/Reject Flow (ผู้อนุมัติ)
- `evaluateApprovalStatus()` ตรวจว่า user เป็น max_level หรือไม่
  - ใช่ → final `approved`
  - ไม่ใช่ → `pending` ส่งต่อ level ถัดไป
  - rejected → `rejected`
- `getNextApproverDetails()` หา level ถัดไปสำหรับส่งอีเมล
  - ถ้า rejected → ส่งกลับ Level 1 (เหรัญญิก)
  - ถ้า final approved → ส่งอีเมลแจ้ง requester (Level 1)
- **Guard**: ถ้า status = `approved` แล้ว → ห้าม update (400 error)

### `waiting_level` field
- `0` = ปิดงบสมบูรณ์ (approved) หรือ initial
- `1` = รอ Level 1 (rejected กลับมา)
- `2, 3, ...` = รอ Level ถัดไป (= last_actor_level + 1)

---

## 8. Report Logic

### Date Range Priority
ทุก report endpoint ใช้ priority เดียวกัน:
1. ถ้ามี `date_from` + `date_to` → ใช้ range นั้น
2. ถ้ามีเฉพาะ `year` → ใช้ช่วง `{year}-01-01` ถึง `{year+1}-01-01`
3. ถ้าไม่มี → default ปีปัจจุบัน

### Bank Balance
- `/report/bank-balance` — ยอดสะสมตามช่วงเวลา (income - expense per bank account)
- `/report/bank-balance-monthly` — ยอดเคลื่อนไหวเฉพาะเดือน
- `/report/monthly-closing-balance` — ดึง `statement_balance` จาก previous month's closing

### Excel Export
| Endpoint | คอลัมน์พิเศษ |
|---|---|
| `/report/export-excel` | Standard 11 cols |
| `/report/export-excel-monthly` | Standard 11 cols (รองรับ monthly/yearly) |
| `/report/export-excel-trn` | +พันธกิจ +หน่วยงาน/ฝ่าย, รับ church_ids[] หลายโบสถ์ |

---

## 9. User Management

- สร้าง user → INSERT + สร้าง token ใน `user_verifications` (ส่ง email TODO)
- `is_verified = 0` ตอนสร้าง
- แก้ไข user: password hash ด้วย `password_hash(..., PASSWORD_DEFAULT)`
- ถ้า `password` field ว่าง → ไม่ update password (safe default)

---

## 10. Master Data Rules

### Bank Account Filter
- `master_bank_account` endpoint กรองออก `bank_name = 'PERSON'`  
  (บัญชีประเภทกระเป๋าเงินส่วนตัวไม่แสดงใน dropdown)

### Church Hierarchy
- โบสถ์ → ภาค (region) → ระบบ (system)
- ใช้ region เป็นกลุ่มจัดเรียง: `ORDER BY r.id, ch.name`

---

## 11. Email Notifications (PHPMailer)

| Event | Template Function | ส่งถึง |
|---|---|---|
| Forgot Password | `mailForNewPassword()` | User ที่ขอ |
| Monthly Closing Submit | `mailForNextApprover()` | Approver Level ถัดไป |
| Closing Approved (final) | `mailApproveForRequester()` | Requester (Level 1) |
| Closing Rejected | `mailRejectForRequester()` | Requester (Level 1) |

- Mail error ถูก catch ไว้ ไม่ทำให้ API fail
- Config ผ่าน ENV: `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_PORT`, `MAIL_FROM`

---

## 12. Database Tables (อ้างอิงจาก Query)

| ตาราง | หน้าที่ |
|---|---|
| `users` | ผู้ใช้งาน |
| `user_role` | Many-to-many users ↔ roles (role_id 1 = protected) |
| `user_data_permissions` | สิทธิ์ข้อมูล (scope_type, scope_id, permission) |
| `user_verifications` | Token สำหรับ email verify / password reset |
| `user_person_wallet` | ผูก user กับ church สำหรับ personal wallet |
| `roles` | บทบาท |
| `menus` | รายการเมนู sidebar |
| `role_permissions` | Many-to-many roles ↔ menus |
| `approve_permissions` | Config ผู้อนุมัติ per (org_id, request_form_id, approve_level) |
| `churches` | โบสถ์ |
| `regions` | ภาค |
| `account_categories` | หมวดหมู่บัญชี (type: income/expense, group: 9=รายรับ) |
| `account_group` | กลุ่มหมวดหมู่ |
| `master_common` | ข้อมูล master ทั่วไป (เช่น TRN_CHANNEL) |
| `master_bank_account` | บัญชีธนาคารของโบสถ์ |
| `missions` | พันธกิจ |
| `departments` | ฝ่าย/หน่วยงาน |
| `transactions` | ธุรกรรมการเงิน |
| `transaction_attachments` | ไฟล์แนบ (data_for: TRN / TRN_CLOSING) |
| `monthly_closings` | งบปิดเดือน (master) |
| `monthly_closing_accounts` | ยอดต่อบัญชีธนาคาร (detail) |
| `monthly_closing_logs` | Audit trail การอนุมัติ |
