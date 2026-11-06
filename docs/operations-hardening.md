# Chuan hoa van hanh giao hang

## Pham vi

Ke thua Sprint 01-08 va luong lien kho trong `warehouse-workflow.md`. Giu bon vai tro trong `role-matrix.md`; moi thao tac duoc kiem tra lai tai Service/Policy. Khong thay doi credential hoac reset du lieu dang dung.

## Luong nghiep vu

1. Nguoi gui dang ky, hoan thien ho so va dia chi; van hanh duyet ho so.
2. Luu nhap, kiem tra noi lay/noi giao co kho dang hoat dong, chot cuoc va gui duyet. Don tu choi/can bo sung quay ve nguoi gui.
3. Van hanh duyet, phan shipper kho gui lay hang. Ket qua lay that bai khong tao kien va co the phan cong lai.
4. Shipper lay du kien, lap phieu ve kho. Tai khoan kho khac kiem dem/can tung kien. Thieu, hong, lech can co bang chung va khoa xuat.
5. Don lien tinh xuat chuyen, kho dich kiem tung kien. Nhan mot phan chua duoc phan giao. Don noi tinh co the di thang buoc giao.
6. Van hanh phan shipper kho dich, kho lap phieu xuat, shipper xac nhan nhan du truoc khi giao.
7. Thanh cong can nguoi nhan, bang chung va COD/phi rieng. That bai khong phat sinh thu; shipper tra kien ve kho, van hanh ghi y kien shop va quyet dinh giao lai/hoan.
8. Hoan lien tinh quay ve kho gui goc, shipper kho gui hoan cho nguoi gui. Phi hoan dung snapshot; khong tao COD moi.
9. Shipper de nghi nop theo cac khoan thu; admin khac xac nhan tien. Doi soat, duyet va thanh toan dung chung tu rieng, khong tu duyet/ghi trung.

## Giao dien van hanh

- Bang cong viec phan nhom: cho duyet, lay hang, ban giao, chuyen di, kho nhan, giao hang, xu ly giao lai, hoan hang va dang giu.
- Moi dong co kho, tuyen, so kien, buoc hien tai va lien ket den dung ho so. So luong va bo loc cung pham vi kho; kho nhan mo phieu chuyen, khong mo don kho gui truoc khi nhan.
- Chi hien form phan cong/xuat chuyen khi du dieu kien. Hien ly do dang chan, khong de nguoi dung bam mot nut chac chan bi tu choi.
- Nhiem vu co phieu cho xac nhan chi dan ve phieu do; khong lap phieu trung hoac ghi ket qua trong luc ban giao.
- Hien tien trinh don va tach ro trang thai van chuyen voi trang thai tien. Bo cuc bang tren desktop va dong cong viec tren mobile; giu thuong hieu hien co.

## Nghiem thu

Happy path noi tinh/lien tinh; ngoai pham vi kho; tu duyet; tuyen chua phuc vu; trang thai cu; sai lech/giu; nhan mot phan; giao that bai/giao lai/hoan; gui lap; nop tien/doi soat. PHPUnit toan bo, Pint, Vite, Blade cache, migration sach/co du lieu va thao tac trinh duyet desktop/mobile tren database verify rieng.

## Ket qua kiem tra 05/11/2026

- Pham vi, moi truong va ma tran ca kiem thu: PASS. Giu database dang demo, su dung database verify rieng cho thao tac ghi.
- Static/build: PASS `php vendor/bin/pint --test`, `npm run build`, `php artisan view:cache`, `git diff --check`.
- Tu dong: PASS `php artisan test`, 221 tests / 2,643 assertions. Nhom OperationsWorklist co 10 tests / 107 assertions; bao gom kho gui ban dau ngung hoat dong khi chuyen hoan.
- Migration va seed: PASS tren database sach, 123 don / 28 tai khoan mau; migrate lai tren database da co du lieu khong co thay doi schema.
- Tranh chap MySQL: PASS hai tien trinh phan cong, lay hang, nhap/xuat, ket qua giao va chuyen kho. Chi mot thao tac tao ket qua; replay cung token tra lai ket qua cu, khong tao trung khoan thu.
- Trinh duyet: PASS xuat HN, kho HCM nhan du, phan shipper HCM, lap/xac nhan ban giao, giao thanh cong va tai lai du lieu. Hai kien den nguoi nhan; COD 500,000 va phi 45,000 duoc ghi rieng.
- UI: PASS desktop 1280, tablet 768, mobile 390/375; loc hang doi, menu, huy/mo lai/xac nhan, giu input va khong tran ngang. Khong thay loi console hoac anh hong tren cac trang da kiem tra.

Giao that bai, quyet dinh giao lai/hoan, nhan thieu/hong va quyen tai chinh co kiem thu tu dong; luot trinh duyet nay tap trung luong lien tinh thanh cong. Van ho tro chuyen truc tiep hai kho, chua co hub nhieu chang hoac ket noi hang van chuyen thuc te.

## Sua loi sau QA 06/11/2026

1. PASS pham vi: sua sau diem QA ve mat hang/boi thuong, chuyen kho shipper, khieu nai lien kho, doi khu vuc kho, quyen duyet shop va kho ngung hoat dong. Quy tac chi tiet nam trong `warehouse-workflow.md`.
2. PASS moi truong: PHP 8.3.35/MySQL, PHPUnit dung `quanlivanchuyen_testing` va rollback tung test. Migration sach tren `quanlivanchuyen_verify_2026110602`, seed DemoWebsiteSeeder va DemoWarehouseAccountsSeeder, migrate lai tren database da co du lieu deu dat. Khong sua credential/database demo dang dung. Mot lan seed chay cung CatalogSeeder cua race bi trung pivot; seed lai tuan tu dat, khong thay doi code de che loi setup.
3. PASS ca hoi quy: 18 test moi bao phu duyet mat toan bo/mot phan, kien mat khi dang chuyen, khong nhan gia, khong duyet mat lap, rollback audit, giu scope shipper, kho cu dong, tuyen, quyen shop, khieu nai cung don khac kho va phan biet hang da xuat/chua xuat khi dong kho.
4. PASS static/build: `php vendor/bin/pint --test`, `npm run build`, `php artisan view:cache`, `git diff --check`.
5. PASS tu dong: `php artisan test --compact`, 239 tests / 2,800 assertions. Hai test reseed cu duoc dieu chinh/sua tuong thich: cap nhat kho mot phan khong can area_id; test doi vai tro dung nhan su khong giu hang, giu test chan shipper dang giu hang rieng.
6. BLOCKED phan UI truc quan: ca nam script MySQL hai tien trinh `logistics-race`, `warehouse-transfer-race`, `case-race`, `finance-race`, `notification-race` deu PASS tren database verify. HTTP feature test kiem tra form, thong bao, quyen va du lieu sau thao tac deu dat. Cong cu CUA va node runtime deu loi `failed to write kernel assets: The system cannot find the path specified. (os error 3)`; chua kiem tra lai desktop/mobile, console/network hoac chup anh giao dien cua ban sua nay. Ket qua trinh duyet 05/11 o tren khong phai bang chung cho ban sua 06/11.
7. PASS bao cao: luu ket qua, gioi han va quy tac van hanh tai day va `warehouse-workflow.md`; khong khang dinh khong con loi toan he thong.

Du lieu mat hang da duyet tu phien ban cu duoc chan van chuyen ngay ca khi co khoa cu bang false, nhung khong tu viet lai ton/custody lich su. Can kiem ke, doi chieu chung tu tung ho so truoc khi sua du lieu cu. Mat mot phan tiep tuc giu xu ly; chua co phe duyet giao/hoan rieng phan hang con lai. Quyen duyet shop dang theo khu vuc cac kho duoc gan vi schema shop chua gan mot kho rieng. Khong can migration moi cho ban sua nay; trang thai/custody/tinh trang kien dung cac cot chuoi hien co.
