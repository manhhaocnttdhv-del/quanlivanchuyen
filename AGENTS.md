# Quy uoc project Quan Ly Van Chuyen COD

## Muc tieu

Day la project Laravel 12 cho he thong van chuyen COD. Code phai de doc, de demo va de giai thich khi bao ve do an.

## Stack bat buoc

- Laravel 12, PHP 8.2 tro len.
- MySQL 8.x voi migration va foreign key.
- Blade, Bootstrap 5, jQuery va Vite.
- PHPUnit cho test nghiep vu quan trong.

## Quy tac code

- Dat ten class, method, migration va route bang tieng Anh theo chuan Laravel.
- Mot method chi nen xu ly mot muc dich; tach nghiep vu nhieu buoc sang Service.
- Validation dat trong Form Request, phan quyen dat trong Policy hoac middleware.
- Moi thay doi trang thai van don, kho va tai chinh phai co lich su.
- Tien dung `decimal(15,2)`, can nang dung `decimal(10,3)`, khong dung float cho nghiep vu tai chinh.
- Khong xoa cung chung tu tai chinh; dung trang thai va giao dich dieu chinh.
- Comment ngan gon cho quy tac nghiep vu va doan code kho hieu.
- Khong commit `.env`, file build hoac credential.

## Quy trinh sprint

1. Doc tai lieu ke hoach truoc khi code.
2. Lam viec tren nhanh sprint tuong ung.
3. Viet migration truoc model va UI.
4. Them test cho happy path, phan quyen va cac truong hop bien.
5. Chay `php artisan test`, `php vendor/bin/pint --test` va `npm run build`.
6. Commit theo Conventional Commits, mo PR vao `main`.

## Quy tac nghiep vu can nho

- COD thu ho va phi van chuyen la hai khoan rieng.
- Shipper khong duoc tu xac nhan da nop tien.
- Nguoi lap khong duoc tu duyet chung tu cua minh.
- Khong ghi nhan mot khoan tien hai lan.
- Moi boi thuong, giam phi va dieu chinh phai co can cu, nguoi duyet va audit log.

## Skill noi bo

Doc `agents/skills/laravel/SKILL.md` khi thay doi code Laravel, Blade, JavaScript, jQuery, Bootstrap hoac migration.
Ke hoach du lieu trong `docs/database-design.md` la thiet ke du kien; chi migration moi mo ta schema da trien khai.
Moi nhanh sprint duoc tao tu commit nen, can cap nhat tu `main` sau khi sprint phu thuoc da merge.
