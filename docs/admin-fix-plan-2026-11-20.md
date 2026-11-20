# Kế hoạch sửa lỗi quản trị ngày 20/11/2026

Phạm vi thuộc Sprint 01: tài khoản và danh mục. Nhánh triển khai: `codex/sprint-01-admin-validation-fixes`, tạo từ `main`.

## Lỗi đã xác nhận

1. Danh sách tài khoản cho sửa khách hàng nhưng form chỉ có vai trò nội bộ; không lưu được thay đổi hoặc khóa tài khoản với vai trò `shop`.
2. Kho thuộc khu vực ngừng phục vụ mất lựa chọn khu vực hiện tại và bị validation chặn khi sửa tên hoặc đóng kho.
3. `area_id` dạng mảng vượt qua quy tắc `exists`, gây lỗi 500 khi lưu kho.

## Quyết định triển khai

- Khách hàng hiện có được sửa thông tin và trạng thái. Vai trò và phạm vi kho hiển thị chỉ đọc, được giữ nguyên từ dữ liệu đã khóa trong Service. Không tạo khách hàng từ form nhân sự hoặc cấp vai trò nội bộ mới qua form sửa khách hàng.
- Tài khoản nội bộ tiếp tục chỉ nhận ba vai trò nội bộ; giữ các chốt bảo vệ admin cuối, tự khóa và trách nhiệm hàng/tiền của shipper.
- Form kho giữ khu vực hiện tại dù đã ngừng phục vụ, có nhãn phân biệt. Validation chỉ cho giữ khu vực này hoặc chọn một khu vực đang phục vụ; tạo kho mới và chuyển sang khu vực ngừng phục vụ khác bị từ chối.
- `area_id` phải là số nguyên hợp lệ trước khi kiểm tra tồn tại. Dữ liệu sai kiểu trả lỗi validation, không đi vào thao tác lưu.
- Không đổi schema, không cần migration, không sửa dữ liệu hay chứng từ tài chính. Các thao tác hợp lệ vẫn ghi audit và thu hồi phiên tài khoản theo cơ chế hiện có.

## Tiến độ

- [x] Đọc quy ước, kế hoạch Sprint 01 và đối chiếu ba lỗi.
- [x] Thêm test hồi quy: thành công, phân quyền, dữ liệu sai kiểu và bảo toàn quyền/lịch sử.
- [x] Sửa Form Request, Service, Controller và Blade trong phạm vi đã nêu.
- [x] Chạy test tập trung, toàn bộ PHPUnit, Pint, Vite build và kiểm tra diff.
- [x] Kiểm tra trình duyệt trên database QA riêng; lưu bằng chứng.
- [x] Ghi kết quả, commit Conventional Commits và mở PR vào `main`.

Commit triển khai ban đầu: `44885ae` (`fix(admin): preserve customer access and validate warehouse areas`). PR vào `main`: [#15](https://github.com/manhhaocnttdhv-del/quanlivanchuyen/pull/15). Đợt kiểm tra sâu và triển khai tiếp theo được ghi tại [admin-deep-check-2026-11-20.md](admin-deep-check-2026-11-20.md), với kết quả mới nhất và giới hạn môi trường local.

## Tiêu chí nghiệm thu

- Admin sửa/khóa/mở lại khách hàng mà role `shop`, hồ sơ shop và phạm vi kho không đổi; tài khoản bị khóa không tiếp tục dùng phiên cũ.
- Payload cố cấp role nội bộ cho khách hàng bị từ chối; Service không đổi quyền khách hàng kể cả khi được gọi trực tiếp.
- Vận hành, shipper và khách hàng không được sửa tài khoản hoặc danh mục quản trị.
- Kho giữ được khu vực ngừng phục vụ khi sửa/đóng; kho mới và việc chuyển sang khu vực ngừng phục vụ khác vẫn bị chặn.
- Mảng, đối tượng lồng, chuỗi sai, số thập phân và ID không tồn tại không gây lỗi 500 hoặc lưu kho.
- Không làm mất bảo vệ admin cuối, người giữ hàng/tiền, audit và lịch sử tuyến kho.

## Kiểm chứng

PHPUnit dùng MySQL `quanlivanchuyen_testing`, transaction rollback từng test. Trình duyệt dùng database QA riêng; không reset database ứng dụng. PHP 8.3, ZIP extension và thư mục cache/tạm trên ổ D được cấu hình riêng cho tiến trình kiểm thử do ổ C gần đầy. Không commit `.env`, credential hoặc file build.

### Kết quả tự động

- Trước sửa: 6/12 test hồi quy thất bại (57 assertions), xác nhận lỗi giao diện, quyền khách hàng, khu vực kho và lỗi 500 với mảng `area_id`.
- Sau sửa: `AdminAccountCatalogTest` đạt 12/12 test, 182 assertions; bao gồm phân quyền ba vai trò còn lại, audit, thu hồi phiên, payload đổi quyền, dữ liệu cũ đã tải và tám dạng ID sai trên cả tạo/sửa kho.
- `php artisan test --compact`: 279 test, 3.562 assertions PASS (116,36 giây).
- `php vendor/bin/pint --test`: PASS.
- `npm run build`: PASS, Vite 7.3.7.
- `node --test tests/JavaScript/shipper-position.test.mjs`: 4/4 PASS.
- Migration mới: SKIPPED, vì không có thay đổi schema. `dev:prepare-test-db` xác nhận database kiểm thử đã đủ migration.

### Kết quả trình duyệt

- PASS: Admin khóa rồi mở lại `pending@guiviet.test`; danh sách và form sau tải lại giữ vai trò khách hàng, không có input đổi role hoặc phạm vi kho. Database xác nhận `auth_version` tăng từ 1 lên 3 và có hai audit `user.saved`.
- PASS: Tạo kho `QA-ADMIN-FIX`, ngừng phục vụ khu vực hiện tại, sửa tên và đóng kho thành công. Mở lại form vẫn chọn đúng khu vực với nhãn ngừng phục vụ; có đủ hai audit `warehouse.saved` cho tạo và cập nhật.
- PASS: Form tạo kho mới không liệt kê khu vực đã ngừng phục vụ.
- PASS: Desktop 1280px và mobile 390px; không tràn ngang trên hai form. Console không có lỗi hoặc cảnh báo trong luồng kiểm tra.
- Dữ liệu QA: đã mở lại khách hàng và khôi phục khu vực Cà Mau; chỉ giữ kho kiểm thử mới ở trạng thái ngừng dùng. Server QA và tab kiểm thử đã dừng/đóng. Server preview có sẵn không bị dừng.
- Ảnh bằng chứng cục bộ: `D:/codex-work/quanlivanchuyen-map-check/admin-fix-customer-desktop.png`, `admin-fix-customer-mobile.png`, `admin-fix-warehouse-desktop.png`, `admin-fix-warehouse-mobile.png`.

### Tổng hợp 7 bước kiểm thử

| Bước | Kết quả | Bằng chứng |
| --- | --- | --- |
| 1. Phạm vi và hành vi mong đợi | PASS | Ba lỗi, quyết định triển khai và tiêu chí nghiệm thu ở trên |
| 2. Môi trường và baseline | PASS | Database kiểm thử riêng; trước sửa 6 test thất bại, 6 test đạt |
| 3. Thiết kế kịch bản | PASS | 12 test phủ thành công, phân quyền, bảo toàn dữ liệu và giá trị biên |
| 4. Static/build | PASS | Pint, Vite build và `git diff --check` |
| 5. Kiểm thử tự động | PASS | 279 test PHP và 4 test JavaScript |
| 6. Luồng thực tế và hồi quy | PASS | Trình duyệt desktop/mobile, database và audit QA |
| 7. Báo cáo bằng chứng | PASS | Kết quả trước/sau, giới hạn và ảnh được ghi trong tài liệu này |

Phạm vi sửa không bao gồm triển khai production. Không thay schema hoặc chứng từ tài chính. Luồng giao diện chỉ được kiểm tra trên database QA; kết quả PHPUnit bao phủ các nghiệp vụ theo bộ test hiện có, không khẳng định đã kiểm tra thủ công mọi màn hình của hệ thống.
