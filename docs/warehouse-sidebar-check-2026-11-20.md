# Kiem tra lien kho va sidebar ngay 20/11/2026

Ket qua: PASS trong pham vi duoi day. Nhanh `codex/sprint-03-warehouse-receipts-sidebar`, nen `41033fb`. Ke thua [kiem tra admin](admin-deep-check-2026-11-20.md), uu tien luong nhan hang giua cac kho theo [warehouse-workflow.md](warehouse-workflow.md).

## Ke hoach va ket qua

| Buoc | Trang thai | Bang chung |
| --- | --- | --- |
| 1. Xac dinh pham vi | PASS | Doc luong lien tinh/hoan, Service, Policy, Form Request, inventory, custody, history va test hien co |
| 2. Moi truong va baseline | PASS | PHP 8.3, MySQL 8.0.30:3308; DB verify rieng, PHPUnit tren `quanlivanchuyen_testing`; da tai hien 3 test FAIL truoc sua |
| 3. Ca kiem thu | PASS | Du/thieu/hong, dung kho/nguoi nhan, nhan lai, revision cu, dinh danh dong sai, custody, giu kien, tien va tranh chap |
| 4. Static/build | PASS | Pint, Vite build, `git diff --check` |
| 5. Tu dong | PASS | 293 test / 4.161 assertions; 4 test JavaScript; 3 lan chay race xuat/nhan MySQL |
| 6. Luong that | PASS | Dang nhap kho HN, HCM, DN, admin va shipper; nhan tung kien, xu ly doc lap, ban giao shipper; 3 vong UI x 4 vai tro |
| 7. Bao cao | PASS | Ghi loi, ket qua, bang chung va gioi han trong tai lieu nay |

## Loi da sua

| Muc do | Tai hien | Ket qua sau sua |
| --- | --- | --- |
| P2 | Dinh danh dong nhap kho, nhan chuyen kho hoac kiem ke dang `123invalid`, `0123`, `123.0` bi ep so; sau do truy cap bang khoa goc gay HTTP 500 | So sanh dung tap ID va kieu cua khoa; tu choi HTTP 422 truoc thay doi du lieu. Ca 3 test regression FAIL truoc sua, PASS sau sua; du lieu hop le van nhan/kiem ke duoc |
| P2 | Menu phang dai, chan danh tinh cung cuon; kho tim nhom nghiep vu tren man hinh hep | Gom theo Van chuyen, Kho hang, Tai chinh & bao cao, Ho tro & su co, Quan tri/Nguoi gui, Tai khoan. Nhom hien tai tu mo; dung native details/summary, Enter, Escape, focus va reduced motion; danh tinh o cuoi rieng |
| P3 | Cot nguoi nhan hien ca nguoi kiem dem kien chua nhan; vi tri va thoi gian nhan de doc lien nhau | Doi thanh Kiem dem / vi tri, tach dong vi tri va thoi gian, chi hien thoi gian thuc nhan khi co `received_at` |

Khong thay schema. Giu nguyen cac Gate/Policy va dich den menu. Khong them dependency.

## Kho nhan hang nhu the nao

1. Kho gui phai giu du kien; khong co nhiem vu dang mo, de nghi cho hay khoa van chuyen. Xuat chuyen dua kien sang custody `transfer`, bo vi tri ton; don `in_transit`.
2. Nhan vien dung kho dich mo Chuyen kho, kiem dem tung kien va chon vi tri thuoc kho dich. Nguoi xuat khong tu nhan, ke ca co pham vi hai kho/admin.
3. Chi kien danh dau thuc nhan moi vao ton dich. Thieu/hong can anh va can cu, tao sai lech chan xuat. Kien chua nhan van thuoc chuyen; nhan mot phan khong doi don thanh tai kho.
4. Lan sau chi nhan cac kien con cho. Nhan du moi chuyen kho van hanh cua don; sai lech van can nguoi khac xac minh va bo giu.
5. Kho dich phan shipper trong pham vi. Lap phieu xuat chua doi nguoi giu; shipper xac nhan tung kien moi doi custody. COD/cuoc khong duoc thu tren phieu chuyen kho.
6. Hang hoan lien tinh phai chuyen ve dung kho gui ban dau, nhan du roi moi phan shipper hoan.

## Bang chung nghiep vu

Database browser: `quanlivanchuyen_verify_202611201134`. Don QA #131, 2 kien, tu Kho Ha Noi #1 sang Kho TP.HCM #2; fixture chuan bi qua Service, khong sua custody truc tiep.

| Thao tac tren giao dien | Quan sat va doi chieu history |
| --- | --- |
| Van hanh HN xuat chuyen #1 | 2 kien `transfer`, don `in_transit`; nguoi xuat khong co form nhan |
| Van hanh HCM nhan kien #1, bao thieu #2 kem anh | 1 kien ton HCM, 1 kien van tren chuyen; don `in_transit`, history `transfer_partial` |
| Van hanh HCM nhan bo sung #2 | 2 kien HCM tai A-01/B-01, chuyen `received`, don `at_warehouse`; van giu do sai lech |
| Van hanh DN mo chuyen HN-HCM | HTTP 403 |
| Admin khac nguoi kiem dem xac minh | Sai lech #20 `resolved`, created_by=7, resolved_by=1; mo phan cong |
| Van hanh HCM phan shipper va lap phieu #94 | Kien van o kho, chua doi nguoi giu |
| Shipper HCM dang nhap va nhan du | 2 kien custody shipper #11; don `out_for_delivery`; 14 history tu tao don den ban giao |

COD `500000.00`, cuoc `45000.00`, tong du kien thu `545000.00` giu nguyen; **0 delivery collections**. Khong ghi nhan giao thanh cong hoac thuc hien giao dich tien that.

Test tu dong con phu nhan nham kho/vi tri, tu nhan, revision cu, nhan trung, anh thieu/hong, rollback khi audit loi, mat kien, hoan ve kho goc, kho dong van nhan chuyen da xuat, pham vi shop/kho va bat bien chung tu. Cac nhanh nay khong duoc khang dinh da test tay tat ca.

Tranh chap: `php tests/Support/warehouse-transfer-race.php quanlivanchuyen_verify_202611201430` chay **3 lan**. Moi lan hai worker xuat cho 1 accepted/1 rejected, hai worker nhan cho 1 accepted/1 rejected. Doi chieu sau chay: 3 chuyen received, 6 dong received, khong nhan/xuat lap. DB verify moi da migrate du 20 migration.

## Ba vong dang nhap va giao dien

| Viewport | Admin | Van hanh HN | Shipper HN | Shop |
| --- | --- | --- | --- | --- |
| 1366 x 900 | 19/19 trang | 14/14 trang | 9/9 trang | 10/10 trang |
| 768 x 1024 | 19/19 trang | 14/14 trang | 9/9 trang | 10/10 trang |
| 375 x 812 | 19/19 trang | 14/14 trang | 9/9 trang | 10/10 trang |

12 luot dang nhap thanh cong va 156 luot trang theo URL lay tu sidebar thuc te: moi trang co dung 1 `aria-current`, nhom hien tai mo, khong loi trang hay tran ngang tai lieu. Test PHP kiem tra danh sach menu, khong trung URL, khong nhom rong, chi admin co link quan tri. `/admin` cua shop tra ve workspace van don; test login email/dien thoai va intended URL ca 4 vai tro PASS.

Menu tablet/mobile: Enter dong/mo nhom, Escape dong drawer; lien ket cao toi thieu 44px; cuon doc lap, navBottom=footerTop, khong che danh tinh. Da mo tat ca nhom de kiem tra cuon. Console warn/error cuoi luot: khong ghi nhan. Anh desktop/mobile da xem truc tiep. Bang du lieu rong dung vung cuon rieng.

## Tep bang chung local

Thu muc `D:/codex-work/quanlivanchuyen-map-check/` (khong commit anh, build, credential):

- `warehouse-before.xml`, `stocktake-before.xml`: tai hien 500 truoc sua.
- `warehouse-after.xml`: 37 test / 318 assertions PASS.
- `warehouse-sidebar-full.xml`: 293 test / 4.161 assertions PASS.
- `warehouse-ui-final.xml`: 12 test / 241 assertions PASS sau chinh cach hien thi phieu nhan.
- `warehouse-browser-state.json`: history va custody tung buoc cua don #131.
- `sidebar-three-rounds.json`: ket qua tung trang va menu.
- `warehouse-partial-receipt.png`, `warehouse-fully-received-held.png`: nhan thieu va nhan du van giu.
- `warehouse-receipt-final.png`: phieu nhan du voi vi tri va thoi gian tach ro.
- `sidebar-admin-1366.png`, `sidebar-mobile-final.png`: menu desktop/mobile.

## Gioi han moi truong

- Preview local `http://127.0.0.1:8001/admin` dung DB QA 3308. Khong thay `.env` dang tro DB cu 3307, khong reset hay phuc hoi DB cu trong dot nay.
- O C rat it dung luong. Dependency, storage, build, PHPUnit cache va Git local duoc dat tren D qua junction. Git status/log va cac check chay duoc; can giai phong them dung luong tren may.
- He thong hien ho tro chuyen truc tiep kho gui -> kho dich; chua co nhieu chang qua hub.
- Chua co dich den production; chi trien khai preview local va code tren GitHub. GPS/camera/may quet, may in va dien thoai vat ly chua duoc kiem tra thiet bi that.
