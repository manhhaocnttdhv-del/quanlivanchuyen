# Thiet ke database

Day la schema du kien cho 8 sprint. Ban khoi tao chi co cac migration mac dinh cua Laravel; cac bang nghiep vu se duoc them theo sprint. Backlog chuan nam trong `docs/sprints.json`.

## Nguyen tac

- MySQL 8, InnoDB, utf8mb4.
- Khoa chinh BIGINT unsigned; ma van don va ma chung tu co unique index rieng.
- Tien dung `DECIMAL(15,2)`, can nang dung `DECIMAL(10,3)`.
- Khong xoa cung du lieu tai chinh va audit; dung status, `voided_at` hoac giao dich dieu chinh.
- Foreign key dung `restrict` cho chung tu va `cascade` co kiem soat cho bang con khong co gia tri doc lap.

## Nhom bang chinh

| Nhom | Bang de xuat | Muc dich |
| --- | --- | --- |
| Identity | users, roles, permissions, role_user, permission_role, warehouses, warehouse_user | Tai khoan, vai tro, pham vi |
| Catalog | shops, shop_bank_accounts, pickup_addresses, areas, fee_versions, fee_rules | Danh muc shop, khu vuc, bieu phi |
| Orders | orders, order_items, order_status_histories, order_change_requests | Van don va lich su |
| Dispatch | delivery_tasks, task_assignments, handover_documents | Phan cong, ban giao |
| Warehouse | warehouse_items, warehouse_locations, stocktakes, stocktake_lines, discrepancies | Ton kho, sai lech |
| Delivery | delivery_attempts, delivery_proofs, return_records, delivery_collections | Giao, giao lai, hoan va khoan thu goc |
| Finance | cash_deposits, deposit_allocations, reconciliations, reconciliation_lines, payments, payment_allocations, fee_receipts, receivables, adjustments, adjustment_lines | Nop tien, doi soat, thanh toan va dieu chinh |
| Control | incidents, complaints, audit_logs, notifications, attachments | Su co, khieu nai, nhat ky |

## Index quan trong

- `orders(tracking_code)` unique.
- `orders(shop_id, status, created_at)`.
- `orders(receiver_phone)`, `orders(area_id, status)`.
- `delivery_tasks(shipper_id, status, scheduled_at)`.
- `warehouse_items(warehouse_id, status, location_id)`.
- `delivery_collections(shipper_id, collected_at, status)`.
- `cash_deposits(shipper_id, status, received_at)`.
- `reconciliations(shop_id, period_start, period_end, status)`.
- `audit_logs(actor_id, event_type, created_at)` va index polymorphic object.

## Quy tac tai chinh

COD thu ho, phi van chuyen, phi hoan, tien shipper nop va tien tra shop la cac ledger/cong no rieng. Cac bang phan bo phai tham chieu giao dich goc de truy vet va ngan ghi trung.

## Truong va rang buoc cot loi

| Bang | Truong quan trong | Rang buoc |
| --- | --- | --- |
| orders | shop_id, tracking_code, status, receiver_phone, weight_kg, cod_amount, shipping_fee, fee_payer, fee_version_id, submitted_at, approved_at, delivered_at | tracking_code unique, FK shop/bieu phi; snapshot dia chi va gia da chot |
| delivery_tasks | order_id, type, shipper_id, warehouse_id, status, scheduled_at | FK khong xoa day chuyen lich su; lockForUpdate tren order khi phan cong |
| warehouse_items | order_id, warehouse_id, location_id, holder_user_id, custody_status, hold_reason | Moi kien chi co mot holder; chuyen holder cung transaction ban giao |
| delivery_attempts | task_id, attempt_number, result, attempted_at, reason_code | unique(task_id, attempt_number); khong tinh luot chi vi tao nhiem vu |
| delivery_collections | order_id, delivery_attempt_id, shipper_id, type, amount, collected_at, idempotency_key | amount >= 0; unique(delivery_attempt_id, type); COD va phi tach dong |
| cash_deposits | shipper_id, created_by, confirmed_by, requested_amount, received_amount, status, received_at | Nguoi xac nhan khac shipper; chi confirmed moi tru cong no |
| deposit_allocations | cash_deposit_id, delivery_collection_id, amount | unique(deposit_id, collection_id); tong phan bo <= tien thuc nhan va khoan thu con lai |
| reconciliations | shop_id, period_start, period_end, created_by, approved_by, status, bank_snapshot, net_amount | creator != approver; khoa chi tiet sau approved |
| reconciliation_lines | reconciliation_id, collection_id/fee_id/adjustment_id, amount | Nguon tai chinh co FK; dieu kien XOR chi mot nguon hop le; khoa dong nguon de ngan chon trung |
| payments | reconciliation_id, amount, transaction_reference, idempotency_key, paid_at, status | unique(transaction_reference), unique(idempotency_key); khong chi vuot so con phai tra |
| audit_logs | actor_id, event, subject_type, subject_id, old_values, new_values, reason, request_id, created_at | append-only; khong ghi credential; cung transaction voi thay doi nghiep vu |

Ten cot FK phai day du va nhat quan khi viet migration, vi du `cash_deposit_id` trong unique composite. Khong dung index don `status` neu da co composite index dap ung query; kiem tra bang EXPLAIN tren du lieu seed lon truoc khi them index.

## Cong thuc va dong thoi

- Tien shipper dang giu = tong collection posted - tong deposit allocation confirmed + dieu chinh da duyet.
- COD du dieu kien doi soat = phan COD da ve quy va khong tranh chap - phan da dua vao doi soat hieu luc.
- Phai tra shop = COD du dieu kien - phi shop chiu + dieu chinh co dau. So am chuyen thanh no phi, khong tao thanh toan am.
- `DB::transaction()` bao boc chung tu, phan bo, chuyen trang thai va audit. `lockForUpdate()` tren cac dong nguon theo thu tu ID co dinh de giam deadlock.
- Idempotency key va unique constraint xu ly gui lai request; khi unique violation thi tra ket qua da co sau khi kiem tra payload va quyen.
- Tien VND duoc xu ly bang so nguyen don vi dong hoac decimal string/BCMath; khong cast DECIMAL thanh float trong PHP/JavaScript.
- Notification va queue/export phat sau commit; audit bat buoc luu trong transaction, khong doi den sprint 08 moi ghi.
