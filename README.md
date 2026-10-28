# Quan Ly Van Chuyen COD

He thong quan ly van chuyen COD xay dung bang Laravel 12, MySQL, Bootstrap 5 va jQuery. Project duoc chia thanh 8 sprint nghiep vu de de phat trien, review va bao ve do an.

Hiện tại sprint 01 đã có giao diện khách hàng mẫu **Gửi Việt** với tra cứu bản nháp, ước tính cước server-side, tạo/chỉnh sửa/xóa bản nháp theo session và responsive mobile. Các chức năng tài khoản, vận đơn thật, duyệt đơn, kho, giao hàng và tài chính vẫn được triển khai theo các sprint nghiệp vụ tương ứng; chưa có tài khoản demo hay đơn vận chuyển thật.

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

## Chạy giao diện mẫu

Sau khi chọn PHP 8.2, chạy `php artisan serve --host=127.0.0.1 --port=8001` và mở `http://127.0.0.1:8001`. Màn hình khách hàng có thể dùng ngay mà chưa cần chạy migration nghiệp vụ. Biểu phí trong `config/shipping.php` là dữ liệu minh họa; khi làm sprint 01 cần thay bằng bảng version biểu phí trong MySQL.

Giao diện, nguồn ảnh và giới hạn session được ghi tại `docs/frontend-notes.md`.

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

8 nhanh hien cung xuat phat tu commit nen, chua phai code hoan thanh 8 sprint. Moi sprint can co migration, model, request validation, policy, service, controller, view va test tuong ung truoc khi merge vao `main`.

Truoc khi bat dau sprint sau, cap nhat `main` da merge sprint phu thuoc:

```shell
git switch main
git pull --ff-only origin main
git switch sprint-02-quan-ly-van-don
git merge main
```

Sprint 08 trien khai man hinh tra cuu va thong bao day du; helper ghi audit phai duoc tao tu sprint 01 va goi trong moi nghiep vu quan trong.
