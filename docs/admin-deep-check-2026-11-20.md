# Kiem tra sau 4 vai tro va trien khai local ngay 20/11/2026

Ket qua: PASS cho pham vi kiem tra duoi day. Nhanh `codex/sprint-01-admin-validation-fixes`, PR [#15](https://github.com/manhhaocnttdhv-del/quanlivanchuyen/pull/15). Ke hoach va ba loi admin da sua truoc do: [admin-fix-plan-2026-11-20.md](admin-fix-plan-2026-11-20.md).

## Loi phat hien va cach sua

| Muc do | Tai hien / hanh vi cu | Sua va ket qua mong doi |
| --- | --- | --- |
| P1 | Ngay bat dau la mang, ngay ket thuc hop le: validator so sanh ngay nem TypeError/HTTP 500 tren bo loc tai khoan, shop, van don, audit, giao hang, tai chinh va bao cao | Chi them quy tac so sanh khi ngay bat dau la chuoi khong rong; moi truong van duoc kiem tra dinh dang. Tra HTTP 422, khoang dao nguoc van bi tu choi |
| P1 | `active_from` cua bieu phi hoac `period_start` cua doi soat la mang cung gay 500 | Bao ve so sanh trong FeeVersionRequest va FinanceRules. Test xac nhan khong tao/sua phien ban, quy tac phi, doi soat hoac audit khi input sai; doi soat duoc kiem tra ca HTTP va Service |
| P1 | `area_id` la mang/lop long tai dang ky, ho so shop, tao/sua dia chi lay hang vuot quy tac exists, gay 500 | Them bail va integer truoc exists; tra validation, khong tao tai khoan hay doi ho so/dia chi |
| P2 | Loc tai khoan/shop chi co ngay ket thuc bi chan vi quy tac so sanh bat buoc ngay bat dau | Cho phep bo loc mot dau ngay; giu so sanh khi co du hai dau ngay |
| P2 | `/admin` khong co diem vao; nguoi da dang nhap mo lai trang login bi day ve tong quan chung; mo trang admin truoc login co the ket thuc o 403 sau login dung thong tin | Them `/admin` yeu cau dang nhap, chuyen ve workspace cua vai tro. Middleware login dung workspace. Intended URL quan tri co Gate khong du quyen duoc bo qua; URL du quyen giu ca query. Policy/middleware van chan truy cap truc tiep |

Admin chuyen den `/tong-quan`, van hanh den `/dieu-phoi`, shipper den `/viec-cua-toi`, shop da duyet den `/don-hang`. `/admin` khong cap them quyen. Shop chua duyet tiep tuc vao ho so theo quy tac san co.

## Kiem thu tu dong

- Truoc sua: 8 test moi FAIL, 1 PASS, 36 assertions; xac nhan loi truoc khi chinh code.
- Sau sua: `AdminDeepValidationTest` va `RoleLoginAccessTest` dat 9/9, 411 assertions.
- Toan bo `php artisan test --compact`: **288 PASS, 3.973 assertions**, 119,03 giay.
- `php vendor/bin/pint --test`: PASS.
- `npm run build`: PASS, Vite 7.3.7; 4/4 test JavaScript vi tri shipper PASS.
- `git diff --check`: PASS.
- Khong thay schema; migration moi va tao lai database: SKIPPED. `migrate:status` tren database demo xac nhan du 20 migration. Bo PHPUnit su dung database rieng.

Test moi phu login bang email/dien thoai cua ca 4 vai tro, logout, khach mo `/admin`, nguoi da login mo lai login, intended URL du/khong du quyen, bo loc ngay sai kieu/dao nguoc/bang nhau/mot dau, va bao toan du lieu khi validation that bai. Cac test nghiep vu hien co cung PASS: khong tu duyet, khong thu tien hai lan, khong sua/xoa chung tu da ghi so, phan tach COD/cuoc, scope kho/shop, audit va rollback, giao/hoan, kho/kiem ke, su co/khieu nai, bao cao.

## Kiem tra trinh duyet

Database QA `quanlivanchuyen_verify_202611201134` tren MySQL 8.0.30, cong 3308; khong reset database cu. Tai khoan demo va mat khau chi dung local, khong ghi credential vao repo.

| Vai tro / tai khoan | Ket qua quan sat |
| --- | --- |
| Admin / admin demo | Login qua `/admin`, 19 muc menu hien heading dung; 6 so tai chinh mo duoc. Phieu nop da nhan co so tien, phan bo va lich su, khong co form sua truc tiep. Dieu chinh cho duyet co can cu, bang chung, nguoi lap va form duyet cho admin khac |
| Van hanh Ha Noi | Login dung workspace, 14 muc menu; scope Kho Ha Noi/Long Bien, truy cap quan ly tai khoan tra 403 |
| Shipper Ha Noi | Login bang dien thoai dung workspace, 9 muc menu; danh sach nhiem vu trong scope, COD va cuoc tach rieng. Quan ly tai khoan tra 403 |
| Shop | Login qua `/admin` ve van don, 10 muc menu; danh sach van don thuoc shop, quan ly tai khoan tra 403 |
| Admin tai chinh (nguoi lap dieu chinh) | Mo cung dieu chinh cho duyet: khong co nut tu duyet; trang thai van cho duyet, khong ghi tien moi |

52 luot trang theo menu khong gap 500 hoac tran ngang o desktop 1280px; khong co console warn/error duoc ghi nhan trong luot kiem tra. Loc so tien thu voi khoang dao nguoc hien loi tai truong; xoa ngay bat dau va giu ngay ket thuc loc duoc. Chi tiet phieu nop tren mobile 390px co scrollWidth=innerWidth=390, cac truong dai xuong dong.

Anh local tai `D:/codex-work/quanlivanchuyen-map-check/`: `deep-operations.png`, `deep-shipper.png`, `deep-shop.png`, `deep-admin-finance.png`, `deep-admin-mobile.png`. JUnit: `deep-before.xml`, `deep-after.xml`, `deep-full.xml`.

## Moi truong va gioi han

- Luot full test dau dat 287 PASS/1 FAIL do MySQL khong ghi duoc file tam khi o C het cho. Da khoi dong lai MySQL voi tmpdir tren D, giu nguyen datadir va du lieu; chay lai du 288 test PASS, khong them retry hay bo test.
- `vendor`, `node_modules`, build va storage local duoc giu tren D qua junction de giam ghi o C. Khong commit cac du lieu nay. O C van rat it cho trong, can don dung luong de van hanh may on dinh.
- Cau hinh `.env` hien tro MySQL 3307; dich vu nay khong chay. Du lieu MySQL cu da duoc bao toan o thu muc rieng tu dot truoc va co van de redo log, chua phuc hoi trong dot nay. Preview dung bien moi truong rieng tro database QA 3308; khong sua `.env` hay khoi tao lai du lieu cu.
- Ban local co the mo tai `http://127.0.0.1:8001/admin`. Server QA 8002 chi phuc vu kiem tra va duoc dung sau khi ban giao. Preview 8001 duoc giu de dung thu.
- Khong trien khai production do chua co dich den production. Khong khang dinh da test tay moi chuyen trang thai; nhieu nhanh nghiep vu duoc kiem chung bang PHPUnit. Camera QR, GPS, may quet/in va mobile vat ly: chua kiem tra thiet bi that.

Khoi dong lai preview khi MySQL 3308 da chay (PowerShell, khong luu credential):

```powershell
$env:Path='C:/laragon/bin/php/php-8.3.35-Win32-vs16-x64;' + $env:Path
$env:DB_PORT='3308'
$env:DB_DATABASE='quanlivanchuyen_verify_202611201134'
$env:APP_URL='http://127.0.0.1:8001'
$env:SESSION_COOKIE='quanlivanchuyen_local_verified'
$env:SESSION_DRIVER='file'
$env:CACHE_STORE='file'
$env:CACHE_DRIVER='file'
$env:PHP_INI_SCAN_DIR='D:/codex-work/quanlivanchuyen-map-check/php-ini'
$env:LARAVEL_STORAGE_PATH='D:/codex-work/quanlivanchuyen-map-check/storage'
$env:VIEW_COMPILED_PATH='D:/codex-work/quanlivanchuyen-map-check/views'
$env:TEMP='D:/codex-work/quanlivanchuyen-map-check/temp'
$env:TMP=$env:TEMP
php artisan serve --host=127.0.0.1 --port=8001 --no-reload
```

## Tong hop 7 buoc

| Buoc | Trang thai | Bang chung |
| --- | --- | --- |
| 1. Pham vi va hanh vi mong doi | PASS | Doi chieu 4 vai tro, Gate/Policy, Sprint 01 va ke hoach cu |
| 2. Moi truong va baseline | PASS | DB QA rieng, tai hien 8 test loi; van de disk duoc xu ly va test lai |
| 3. Kich ban | PASS | 9 test moi va 12 test admin tu dot truoc; happy path, quyen, gia tri bien va bao toan du lieu |
| 4. Static/build | PASS | Pint, Vite build, diff check |
| 5. Tu dong | PASS | 288 PHP test / 3.973 assertions, 4 JS test |
| 6. Luong thuc te | PASS trong pham vi da neu | Login 4 vai tro, 52 luot menu, phan quyen, chung tu, loc ngay, desktop/mobile |
| 7. Bao cao | PASS | Tai lieu nay, ke hoach truoc, JUnit va anh local |
