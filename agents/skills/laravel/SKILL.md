---
name: laravel
description: Phat trien va review Laravel 12, Blade, JavaScript, jQuery, Bootstrap va MySQL trong project quanlivanchuyen; ap dung khi thay doi nghiep vu COD, migration hoac UI cua project nay.
---

# Laravel COD Skill

## Pham vi

Ap dung cho tat ca code PHP, migration, Blade, JavaScript, jQuery, Bootstrap va test trong project.

## Kien truc uu tien

- Route chi dieu phoi request den Controller.
- Controller xu ly request/response, khong chua quy tac tinh phi hoac chuyen trang thai dai.
- Form Request validate input va authorize nguoi dung.
- Service xu ly quy trinh nhieu buoc va transaction.
- Model khai bao cast, relation va scope co ten ro rang.
- Policy kiem tra quyen theo vai tro, kho, shop va pham vi nghiep vu.
- Blade dung layout, component va partial de tranh lap HTML.
- jQuery chi xu ly tuong tac nho; thao tac thay doi du lieu di qua route co CSRF.

## CSDL

- Dung migration co foreign key va index theo bo loc thuong dung.
- Bang nghiep vu phai co `created_at`, `updated_at`; bang lich su co them `created_by` va `reason` khi phu hop.
- Trang thai dung hang so o layer ung dung va index.
- Tien dung decimal, thoi gian dung datetime, ma nghiep vu dung unique index.
- Su dung `DB::transaction()` cho tao don, xuat kho, giao hang, nop tien va doi soat.

## UI/UX

- Giao dien quan tri uu tien kha nang loc, bang du lieu, trang thai va thao tac nhanh.
- Form chia nhom theo thong tin, tai chinh, van chuyen va xac nhan.
- Luon hien thi loi validation tai truong; khong chi dung toast.
- Nut thao tac nguy hiem phai co xac nhan va neu ly do.
- Mobile view cua shipper uu tien nut lon, thong tin dia chi, COD va trang thai kien.

## Kiem tra truoc khi merge

- `php artisan test`
- `php vendor/bin/pint --test`
- `npm run build`
- Kiem tra migration tren database sach va database co du lieu mau.
