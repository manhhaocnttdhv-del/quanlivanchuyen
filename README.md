# Quan Ly Van Chuyen COD

He thong quan ly van chuyen COD xay dung bang Laravel 12, MySQL, Bootstrap 5 va jQuery. Project duoc chia thanh 8 sprint nghiep vu de de phat trien, review va bao ve do an.

Sprint 01 và 02 đã triển khai trên các nhánh riêng: tài khoản/phân quyền, shop/ngân hàng/địa chỉ, danh mục/biểu phí; vận đơn MySQL, tính phí, duyệt đơn, đề nghị thay đổi, timeline, tra cứu công khai, barcode và bảng kê. Giao diện khách hàng **Gửi Việt** và khu làm việc hỗ trợ mobile. Kho, phân công shipper, giao/hoàn hàng và tài chính thuộc Sprint 03 trở đi.

## Cong nghe

- PHP 8.2 tro len va Laravel 12.
- MySQL 8.x, Eloquent ORM va migration.
- Node.js 22.17 tro len, Bootstrap 5, jQuery, Vite 7.
- PHPUnit va Laravel Pint.

## Khoi dong nhanh voi Laragon

1. Chon PHP 8.2 hoac moi hon; kiem tra `php -v` truoc khi chay Composer/Artisan.
2. Chay `composer install`, `npm ci`, sao chep `.env.example` thanh `.env`.
3. Tao database `quanlivanchuyen` trong MySQL, cau hinh DB_* trong `.env`.
4. Chay `php artisan key:generate`, sau do `php artisan migrate`.
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

Tài khoản mẫu: `admin@guiviet.test`, `dispatcher@guiviet.test`, `warehouse@guiviet.test`, `shipper@guiviet.test`, `accountant@guiviet.test`, `support@guiviet.test`, `shop@guiviet.test`, `pending@guiviet.test`. Mật khẩu là giá trị đã đặt khi seed lần đầu; chạy lại seeder không đổi mật khẩu cũ.

`DemoOrderSeeder` tạo 8 vận đơn với lịch sử thật qua service; chạy lại không tạo trùng. Cần chạy DemoSeeder trước. Đơn mẫu có nháp, chờ duyệt, cần bổ sung, đã duyệt, đã hủy và đề nghị chỉnh sửa chờ xử lý.

Chạy `php artisan dev:prepare-test-db` trước `php artisan test`. Test sử dụng MySQL `quanlivanchuyen_testing` riêng và rollback từng test. Lệnh chuẩn bị chỉ tạo/migrate database; không xóa database ứng dụng. Xem `docs/sprint-01-nghiem-thu.md` và `docs/sprint-02-nghiem-thu.md` để demo và giải thích các quyết định thiết kế.

Trên máy hiện tại, mở `http://127.0.0.1:8001`, đăng nhập tại `/dang-nhap`. Shop tạo đơn tại `/gui-hang`; danh sách tại `/don-hang`. Điều phối chỉ xem và duyệt đơn thuộc kho được gán. Tra cứu công khai cần mã vận đơn đã gửi cùng 4 số cuối điện thoại nhận.

Giao diện và nguồn ảnh được ghi tại `docs/frontend-notes.md`.

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
