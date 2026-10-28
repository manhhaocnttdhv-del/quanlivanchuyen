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
