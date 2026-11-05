# Quan Ly Van Chuyen COD

He thong quan ly van chuyen COD xay dung bang Laravel 12, MySQL, Bootstrap 5 va jQuery. Project duoc chia thanh 8 sprint nghiep vu de de phat trien, review va bao ve do an.

Sprint 01 đến 06 đã triển khai trên các nhánh riêng: tài khoản/danh mục, vận đơn, điều phối/kho, giao/hoàn hàng, sự cố/khiếu nại, COD/tài chính. Giao diện khách hàng **Gửi Việt** và khu làm việc hỗ trợ mobile. Sprint 06 có nộp quỹ, đối soát, thanh toán shop từng phần, thu nợ phí và điều chỉnh có căn cứ được duyệt.

## Cong nghe

- PHP 8.2 tro len va Laravel 12.
- MySQL 8.x, Eloquent ORM va migration.
- Node.js 22.17 tro len, Bootstrap 5, jQuery, Vite 7.
- PHPUnit va Laravel Pint.

## Khoi dong nhanh voi Laragon

1. Chon PHP 8.2 hoac moi hon; kiem tra `php -v` truoc khi chay Composer/Artisan.
2. Chay `composer install`, `npm ci`, sao chep `.env.example` thanh `.env`.
3. Tao database `quanlivanchuyen` trong MySQL, cau hinh DB_* trong `.env`.
4. Chay `php artisan key:generate`, sau do `php artisan migrate --seed` de tao schema, vai tro va danh muc tinh/thanh.
5. Chay `npm run build`, sau do `php artisan serve --host=127.0.0.1 --port=8000`.
6. Mo `http://127.0.0.1:8000`. Khi sua frontend, chay them `npm run dev` o terminal khac.

Trên máy Laragon hiện tại có thể chọn PHP 8.2 cho riêng terminal PowerShell:

```powershell
$env:Path = 'C:\laragon\bin\php\php-8.2.29-nts-Win32-vs16-x64;' + $env:Path
php -v
```

Kiem tra: `php artisan test`, `php vendor/bin/pint --test`, `npm run build`.

## Dữ liệu mẫu và kiểm thử

Chạy `php artisan db:seed` để tạo vai trò, quyền và khu vực. Dữ liệu demo chỉ dùng ở môi trường local/testing; đặt mật khẩu riêng qua biến môi trường, không commit mật khẩu:

```powershell
$env:DEMO_PASSWORD = 'ThayBangMatKhauDemoCuaBan'
php artisan db:seed --class=DemoSeeder
php artisan db:seed --class=DemoOrderSeeder
Remove-Item Env:DEMO_PASSWORD
```

Tài khoản mẫu: `admin@guiviet.test`, `operations@guiviet.test`, `shipper@guiviet.test`, `shop@guiviet.test`, `pending@guiviet.test`. Mật khẩu là giá trị đã đặt khi seed lần đầu; chạy lại seeder không đổi mật khẩu cũ.

Hệ thống chỉ có **4 vai trò**:

Phân công chi tiết của cả tám sprint nằm trong [ma trận phân quyền](docs/role-matrix.md); `docs/sprints.json` ghi tác nhân và điều kiện phân quyền riêng cho từng sprint.

| Vai trò | Công việc |
| --- | --- |
| Admin (kiêm kế toán) | Quản lý tài khoản, danh mục, biểu phí, xác minh ngân hàng và công việc kế toán |
| Nhân viên vận hành | Gộp điều phối, kho và CSKH; duyệt shop, duyệt đơn, phân công shipper, nhập/xuất và kiểm kê trong kho được gán |
| Shipper | Nhận nhiệm vụ và xác nhận bàn giao của chính mình |
| Chủ cửa hàng | Quản lý hồ sơ, địa chỉ và vận đơn của cửa hàng mình |

Khi cập nhật database cũ, chạy `php artisan migrate` trước khi seed: điều phối/kho/CSKH chuyển thành vận hành, kế toán chuyển thành admin. Tài khoản, mật khẩu, phạm vi kho và chứng từ được giữ lại; người có quyền thay đổi phải đăng nhập lại. Migration ghi lịch sử đổi vai trò và không tự tách lại vai trò khi rollback; cần bản sao lưu nếu muốn khôi phục mô hình cũ.

Để demo chỉ cần 3 tài khoản nội bộ (admin, vận hành, shipper) và 1 tài khoản shop. Người lập vẫn không được tự duyệt chứng từ; dùng admin hoặc một tài khoản vận hành khác để duyệt kiểm kê. Shipper không được tự xác nhận đã nộp tiền. Nghiệp vụ tài chính ở các sprint sau tiếp tục áp dụng quy tắc này theo người thao tác, dù admin kiêm kế toán.

`DemoOrderSeeder` tạo 8 vận đơn với lịch sử thật qua service; chạy lại không tạo trùng. Cần chạy DemoSeeder trước. Đơn mẫu có nháp, chờ duyệt, cần bổ sung, đã duyệt, đã hủy và đề nghị chỉnh sửa chờ xử lý.

Sprint 03: chạy `php artisan db:seed --class=DemoLogisticsSeeder` để thêm đơn lấy hàng, nhập/xuất kho, hàng hỏng và kiểm kê. Điều phối/shipper vào `/dieu-phoi`; kho dùng `/ton-kho` và `/kiem-ke`. Xem [nghiệm thu và câu hỏi bảo vệ Sprint 03](docs/sprint-03-nghiem-thu.md). Cước theo cân thực tế chỉ đổi sau đề nghị và người khác duyệt; Sprint 03 chưa ghi nhận COD đã thu.

Chạy `php artisan dev:prepare-test-db` trước `php artisan test`. Test sử dụng MySQL `quanlivanchuyen_testing` riêng và rollback từng test. Lệnh chuẩn bị chỉ tạo/migrate database; không xóa database ứng dụng. Xem `docs/sprint-01-nghiem-thu.md` và `docs/sprint-02-nghiem-thu.md` để demo và giải thích các quyết định thiết kế.

Trên máy hiện tại, mở `http://127.0.0.1:8001`, đăng nhập tại `/dang-nhap`. Shop tạo đơn tại `/gui-hang`; danh sách tại `/don-hang`. Điều phối chỉ xem và duyệt đơn thuộc kho được gán. Tra cứu công khai cần mã vận đơn đã gửi cùng 4 số cuối điện thoại nhận.

Giao diện và nguồn ảnh được ghi tại `docs/frontend-notes.md`.

Sprint 04: chạy `php artisan db:seed --class=DemoDeliverySeeder` sau DemoLogisticsSeeder để có 7 tình huống giao/hoàn. Shipper dùng `/viec-cua-toi`; sổ thu tại `/so-thu-giao-hang`. Có ảnh/người nhận, chống xác nhận trùng, giao lại/hoàn và phí hoàn theo snapshot. Xem [nghiệm thu và câu hỏi bảo vệ Sprint 04](docs/sprint-04-nghiem-thu.md). Tiền ghi nhận đã thu chưa đồng nghĩa quỹ đã nhận; nộp tiền/đối soát thuộc Sprint 06.

Sprint 05: chạy `php artisan db:seed --class=DemoCaseSeeder` sau DemoDeliverySeeder. Shop gửi và theo dõi `/khieu-nai`; CSKH tiếp nhận, phân công và trao đổi nội bộ/công khai. `/su-co` có xác minh, bằng chứng private, khóa giao/đối soát riêng, đề xuất và người khác duyệt kết luận. SLA mặc định 48 giờ; cho mở lại khiếu nại trong 7 ngày. Xem [nghiệm thu, thiết kế và câu hỏi bảo vệ Sprint 05](docs/sprint-05-nghiem-thu.md). Không xóa các khóa kho/tài chính có trước; khoản bồi thường đã duyệt vẫn chờ tài chính.

Sprint 06 dùng hai tài khoản cùng vai trò Admin: `finance-admin@guiviet.test` lập chứng từ, `admin@guiviet.test` xét duyệt. Giữ `DEMO_PASSWORD` trong môi trường terminal khi chạy seeder để tạo tài khoản Admin tài chính; không tạo vai trò kế toán riêng.

Sprint 06: chạy `php artisan db:seed --class=DemoFinanceSeeder` sau các seeder trên, rồi `php artisan finance:accrue-fees` để bổ sung phí từ đơn cũ. Mở `/tai-chinh`: shipper đề nghị nộp, kế toán xác nhận; đối soát có người khác duyệt; trả shop từng phần theo chứng từ ngân hàng. Tiền gốc và tài liệu đã ghi sổ không sửa/xóa; thay ngân hàng sau duyệt phải duyệt lại. Xem [nghiệm thu, công thức và câu hỏi bảo vệ Sprint 06](docs/sprint-06-nghiem-thu.md). Đây là ghi nhận kết quả ngân hàng, chưa tự chuyển tiền thật.

## Tai lieu ky thuat

- `docs/Ke-hoach-chi-tiet-he-thong-quan-ly-van-chuyen-COD.docx`: ke hoach nghiep vu, database, phan quyen, test va tieu chi nghiem thu.
- `docs/architecture.md`: kien truc va quy uoc code.
- `docs/database-design.md`: dinh huong thiet ke database va index.
- `docs/sprints.json`: backlog chi tiet theo FR, dau ra va tieu chi nghiem thu tung sprint.
- `AGENTS.md`: quy tac lam viec cua project.
- `agents/skills/laravel/SKILL.md`: skill Laravel noi bo cho cac sprint tiep theo.

## Quy trinh Git theo sprint

- `main`: code nen da duoc kiem tra.
- `sprint-01-tai-khoan-danh-muc`
- `sprint-02-quan-ly-van-don`
- `sprint-03-dieu-phoi-kho`
- `sprint-04-giao-hang-hoan-hang`
- `sprint-05-su-co-khieu-nai`
- `sprint-06-cod-tai-chinh`
- `sprint-07-bao-cao-thong-ke`
- `sprint-08-nhat-ky-thong-bao`

Các nhánh được khởi tạo từ cùng commit nền. Sprint 01 nằm trên `sprint-01-tai-khoan-danh-muc`; Sprint 02 trên `sprint-02-quan-ly-van-don` đã nhận dependency Sprint 01. Khi PR phụ thuộc chưa merge, nhánh sprint kế tiếp nhận merge từ nhánh phụ thuộc và ghi rõ trong PR; sau khi review mới merge vào `main`.

Truoc khi bat dau sprint sau, cap nhat `main` da merge sprint phu thuoc:

```shell
git switch main
git pull --ff-only origin main
git switch sprint-02-quan-ly-van-don
git merge main
```

Sprint 08 trien khai man hinh tra cuu va thong bao day du; helper ghi audit phai duoc tao tu sprint 01 va goi trong moi nghiep vu quan trong.
