# Kien truc he thong

## Muc tieu

Project dung Laravel 12 tren PHP 8.2 theo mo hinh MVC co them Service va Policy de giu Controller ngan, de doc va de giai thich trong buoi bao ve do an.

## Luong xu ly chuan

Request -> Route -> Middleware/Policy -> Form Request -> Controller -> Service -> Model/Database -> Response/Blade.

- Route chi khai bao URL, ten route va middleware.
- Form Request kiem tra du lieu va quyen co ban.
- Controller goi Service, tra ve view hoac redirect kem message.
- Service dung transaction cho nghiep vu co nhieu buoc.
- Model khai bao relation, cast va scope; tranh viet query phuc tap trong Blade.
- Policy kiem tra quyen theo vai tro va pham vi kho/shop.

## Bốn vai trò

- `admin`: quản trị và kiêm kế toán; có quyền xác minh ngân hàng, quản lý tài khoản/danh mục/biểu phí và đọc audit.
- `operations`: gộp điều phối, kho, CSKH; có `shops.review`, `orders.review`, `logistics.dispatch`, `logistics.warehouse`, `logistics.approve`. Thao tác vận đơn/kho vẫn giới hạn theo phạm vi kho được gán.
- `shipper`: chỉ làm nhiệm vụ được phân công và bàn giao của chính mình; không có quyền quản trị hoặc xác nhận tiền đã nộp.
- `shop`: chỉ quản lý hồ sơ, địa chỉ và vận đơn của mình; không tự duyệt hồ sơ.

Số vai trò không thay thế việc kiểm tra người lập/người duyệt. Hai nhân viên cùng vai trò vận hành vẫn là hai người khác nhau; người lập kiểm kê không được tự duyệt. Nghiệp vụ tài chính triển khai sau phải giữ điều kiện người lập khác người duyệt kể cả khi cả hai là admin.

Migration `2026_11_05_100000_consolidate_staff_roles` chuyển vai trò cũ, giữ ID tài khoản/phạm vi kho, ghi audit và tăng `auth_version`. Không dùng alias vai trò cũ trong Policy hoặc tái tạo vai trò cũ khi seed. Danh mục vai trò hiển thị và validation của tài khoản nội bộ cùng dùng whitelist trong model `Role`.

## Module de xuat

`Identity`, `Catalog`, `Orders`, `Dispatch`, `Warehouse`, `Delivery`, `Incidents`, `Finance`, `Reports`, `Audit`.

## Quy uoc trang thai

Trang thai la chuoi co hang so o model/service, duoc kiem tra bang transition method. Khong cho phep view hoac controller gan trang thai tuy y. Moi transition quan trong tao them ban ghi lich su.

## Bao mat

- Dung `auth`, `verified` va middleware role/scope.
- Dung Policy cho resource-level authorization.
- Dung CSRF cho form va rate limit cho tra cuu cong khai, dang nhap.
- Khong log mat khau, token, so tai khoan ngan hang day du.
- Audit log luu nguoi thao tac, doi tuong, before/after va reason.

## Phu thuoc va su kien

- Audit writer va bang `audit_logs` duoc dat tu sprint 01. Sprint 08 hoan thien man hinh tra cuu va notification.
- Sprint 04 tao `delivery_collections` khi giao thanh cong. Sprint 06 phan bo tien tu collection nay, khong tao lai khoan thu.
- Audit cung transaction voi nghiep vu; notification phat sau commit de khong thong bao thao tac da rollback.
- Giu cau truc MVC va HTTP Kernel/providers hien co. Laravel 12 ho tro cau truc ung dung duoc nang cap; khong tach module/Repository khi chua co nhu cau cu the.
