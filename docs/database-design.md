# Thiet ke database

Day la schema du kien cho 8 sprint. Sprint 01 da trien khai identity, roles/permissions, pham vi kho, shop, phien ban ngan hang, dia chi, khu vuc, kho, phien ban bieu phi va audit. Sprint 02 them orders, order_items, order_status_histories, order_change_requests. Migration la nguon schema da trien khai; cac nhom con lai duoc them theo sprint. Backlog chuan nam trong `docs/sprints.json`.

## Nguyen tac

Sprint 03 đã triển khai delivery_tasks, task_assignments, pickup_attempts, handover_documents/lines, warehouse_items/locations, stocktakes/lines và discrepancies. Thiết kế chi tiết, unique key, chuỗi người giữ và cách kiểm tra nằm trong [nghiệm thu Sprint 03](sprint-03-nghiem-thu.md). Các nhóm tài chính/giao hàng tiếp theo vẫn là dự kiến.

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
- `orders(warehouse_id, status, created_at)`, `orders(area_id, status)`.
- `orders(assigned_shipper_id, status)`, `orders(created_at)`.
- `orders(shop_id, client_token)` unique chong gui lap; `tracking_code` la ma ULID co unique rieng.
- `order_change_requests(pending_order_id)` generated unique chi cho mot de nghi pending tren moi don.
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

## Du lieu van don da trien khai

- `weight_grams` la so nguyen dung tinh bac can; `weight_kg DECIMAL(10,3)` duoc suy ra bang chia nguyen, khong nhan can nang tu hai input doc lap.
- `sender_snapshot`, `recipient_snapshot`, `fee_snapshot` luu tai luc gui. Dia chi hoac bieu phi danh muc thay doi khong sua lai snapshot cu.
- `revision` tang moi lan cap nhat; request voi revision cu bi tu choi. Khoa shop -> order -> change trong transaction de tranh hai thao tac cung vuot qua kiem tra trang thai.
- Ngay gio luu UTC; bo loc ngay theo Asia/Ho_Chi_Minh chuyen ve khoang UTC [dau ngay, dau ngay ke tiep), khong dung DATE(created_at) de giu kha nang dung index.
- `custody_status` va `assigned_shipper_id` chuan bi cho Sprint 03; Sprint 02 khong co endpoint gia lap nhan/giao kien. Don da giu kien khong duoc huy truc tiep.
- Timeline luu before/after, actor, revision va ly do trong cung transaction voi order va audit. Model tu choi sua/xoa; tai khoan DB van phai duoc bao ve, SQL truc tiep co the vuot qua Eloquent.

## Du lieu Sprint 04 da trien khai

- `delivery_attempts`: unique delivery_task_id, unique (shipper_id, client_token), unique (order_id, type, attempt_number). Moi task ghi mot ket qua; tang luot khi co ket qua thuc te.
- `delivery_proofs`: mot bang chung/attempt; ten nguoi nhan, anh private va SHA-256; route kiem tra quyen hien tai.
- `delivery_decisions`: quyet dinh giao lai/ngoai le/hoan, actor, y kien shop, can cu va luot duoc phep; append-only.
- `return_records`: unique order_id; phi hoan va cach thu chot tu fee_snapshot, khong sua can cu/phi sau tao. fee_charged_at chi co khi hoan thanh cong. Snapshot cu khong co return_fee duoc mien 0d.
- `delivery_collections`: DECIMAL(15,2), CHECK khong am; unique (delivery_attempt_id, type) va idempotency_key. Index (shipper_id, status, collected_at), (warehouse_id, financial_status, collected_at). COD, shipping_fee, return_fee tach dong, khong xoa/sua khoan goc.
- `orders`: delivery_attempt_limit mac dinh 3, retry_authorized_attempt, return_attempt_count, financial_hold, delivered_at, returned_at. Holder cuoi la recipient/shop; chi holder_type=warehouse moi tinh ton kho.
- Khoa theo shop -> order -> task -> shipper; token dung lai dung payload va anh tra ket qua cu. Giao/hoan that bai khong tao collection. Hoan khong thu COD. Tien thieu/thua phai co ly do va financial_hold.
- `fee_rules.return_fee` co mac dinh 0; quote luu vao fee_snapshot khi gui/duyet. Phi hoan la nghia vu rieng; reconciliation khong thu tai cho, collect_on_return thu tai luc shop nhan.

## Du lieu Sprint 05 da trien khai

- `incidents`: FK den order, warehouse va cac tham chieu tuy chon warehouse_item, delivery_task, delivery_collection, discrepancy. Service kiem tra tat ca tham chieu thuoc cung order. Ma ULID unique; unique (created_by, client_token) va creation_hash de nhan replay.
- `incident_actions`: lich su append-only, actor/reason/payload; proposed_amount DECIMAL(15,2), CHECK khong am. Tien de xuat va tien duoc duyet luu trong cot nay, khong dung JSON/float lam nguon so tien. proposal_action_id/approved_action_id tren incidents tro toi action bat bien.
- `blocks_dispatch`, `blocks_reconciliation` nam tren tung incident. Ket luan duoc duyet chi go khoa giao cua chinh ho so do; pending_finance giu khoa doi soat. Khong sua orders.financial_hold hay discrepancies khi duyet incident. Order::reconciliationBlocked() tong hop khoa tai chinh va incident cho Sprint 06.
- `complaints`: FK shop/order/warehouse/incident; revision, due_at, first_response_at, closed_at, resolution. SLA 48 gio do server quy dinh; mo lai khong reset han. Unique (created_by, client_token), code unique. Loai tac dong tien/hang can incident cung order da duyet truoc khi dong.
- `complaint_messages`: append-only, actor, type, body, is_internal. Loc is_internal ngay trong query cho shop, truoc paginate; khong chi an bang CSS/Blade.
- `attachments`: bang chung private, path unique, SHA-256, MIME, size, original_name, created_by. Hai FK nullable den incident_action/complaint_message va CHECK XOR bat buoc dung mot cha. Tai lieu/anh khong sua/xoa qua Eloquent; path/hash an khoi serialization.
- Index incidents (warehouse_id, status, created_at), (assigned_to, status, created_at), (order_id, blocks_dispatch), (order_id, blocks_reconciliation); complaints (warehouse_id, status, due_at), (shop_id, status, created_at), (assigned_to, status, due_at); messages (complaint_id, is_internal, id).
- Giao dich khoa shop -> order -> incident/complaint. Tao ho so khoa them creator de tuan tu hoa token tren hai don khac nhau; duyet khoa proposal. Phan cong/nhap/xuat/giao dung cung shop/order lock nen khong co khoang trong giua kiem tra khoa va ghi ket qua.

## Cong thuc va dong thoi

- Tien shipper dang giu = tong collection posted - tong deposit allocation confirmed + dieu chinh da duyet.
- COD du dieu kien doi soat = phan COD da ve quy va khong tranh chap - phan da dua vao doi soat hieu luc.
- Phai tra shop = COD du dieu kien - phi shop chiu + dieu chinh co dau. So am chuyen thanh no phi, khong tao thanh toan am.
- `DB::transaction()` bao boc chung tu, phan bo, chuyen trang thai va audit. `lockForUpdate()` tren cac dong nguon theo thu tu ID co dinh de giam deadlock.
- Idempotency key va unique constraint xu ly gui lai request; khi unique violation thi tra ket qua da co sau khi kiem tra payload va quyen.
- Tien VND duoc xu ly bang so nguyen don vi dong hoac decimal string/BCMath; khong cast DECIMAL thanh float trong PHP/JavaScript.
- Notification va queue/export phat sau commit; audit bat buoc luu trong transaction, khong doi den sprint 08 moi ghi.
