# Luong nguoi gui, kho va giao hang

## Tai khoan

- Khach vao trang chu co Dang nhap va Dang ky gui hang. Ca nhan/cua hang dang ky thanh vai tro `shop`, ho so cho duyet; duoc luu nhap nhung chi gui duyet khi ho so approved.
- Admin tao tai khoan `operations` va `shipper`, gan kho phu trach. Nhan vien active can it nhat mot kho; admin co pham vi toan he thong.
- Shipper con nhiem vu hoac kien dang giu phai giu vai tro, trang thai active va pham vi kho can ban giao. Thu hoi, nhap tra va ket thuc nhiem vu truoc khi chuyen kho nhan su. Form van cho giu/khoi phuc kho da ngung hoat dong de nhan tra hang cu.
- Van hanh duyet ho so nguoi gui trong khu vuc cac kho duoc gan; Admin/xac minh ngan hang giu pham vi toan he thong. Shop hien co `area_id`, chua gan rieng mot kho.
- Dang nhap dua nguoi gui ve van don/ho so cho duyet, van hanh ve dieu phoi, shipper ve nhiem vu cua minh, admin ve tong quan.

## Lien tinh

1. Nguoi gui chon kho thuoc khu vuc lay hang. Kho gui duyet van don, phan shipper kho gui lay kien.
2. Shipper lap ban giao ve kho; nhan vien kho xac nhan kiem dem va can tung kien.
3. Neu kho hien tai khac khu vuc nguoi nhan, trang van hanh hien Xuat chuyen kho. Kho gui chon kho nhan cung khu vuc nguoi nhan, nhap nguoi/don vi van chuyen va bien so/ma chuyen.
4. Xuat chuyen doi custody tung kien sang `transfer`, bo vi tri ton kho, tang custody_version va ghi lich su/audit. Don `in_transit` khong duoc phan shipper giao.
5. Tai khoan kho nhan mo Chuyen kho, danh dau tung kien thuc nhan va vi tri nhap. Nguoi xuat khong duoc tu xac nhan nhan, ke ca co quyen hai kho/admin.
6. Khi nhan du, `orders.warehouse_id` chuyen sang kho nhan va don ve `at_warehouse`. Kho nhan phan shipper thuoc kho minh; hai ben xac nhan xuat giao, shipper ghi ket qua va COD/phi rieng.

Thong bao chuyen kho dung outbox/worker `notifications` hien co: kho dich nhan thong bao cho kiem dem, hai kho nhan ket qua thieu/du. Quyen kho duoc kiem tra lai khi gui va mo thong bao.

Noi tinh co the giao ngay sau khi nhap du kien. He thong hien ho tro chuyen truc tiep kho gui -> kho dich, chua co nhieu chang qua hub. Nguoi van chuyen lien kho duoc ghi tren phieu, khong dung tai khoan shipper giao cuoi lam nguoi nhan thay kho dich.

## Thieu, hong va hoan

- Thieu/hong phai co anh, ly do va sai lech chan phan cong. Kien chua nhan van o custody chuyen kho, khong bien thanh ton kho dich.
- Nhan mot phan giu don `in_transit`; kien da nhan vao ton kho dich. Lan kiem dem sau chi nhan cac kien con cho. Lich su don luu tung lan kiem dem/bang chung.
- Khi tim du kien, sai lech van can nguoi khac co quyen xac minh va bo giu. Nhan du khong tu xoa sai lech.
- Neu hoan lien tinh, dieu phoi kho nhan luu quyet dinh theo y kien nguoi gui, chuyen ve dung `origin_warehouse_id`, kho gui nhan du roi phan shipper hoan ve dia chi gui.
- Snapshot cuoc, COD va phi hoan giu nguyen khi chuyen kho; collection chi tao o ket qua giao/hoan, khong thu tien tren phieu chuyen kho.

## Mat hang va thay doi kho

- Su co mat hang luon khoa van chuyen. Duyet boi thuong dong thoi chuyen kien lien quan sang `holder_type=lost`, `condition=lost`, bo vi tri ton/nguoi giu, tang phien ban custody va ghi lich su. Khong co kien cu the thi ket luan ap dung cho toan bo kien cua don. Kien da giao khach/shop hoac da xac nhan mat khong duoc ghi mat lai.
- Mat toan bo kien dua don sang `lost`; huy nhiem vu va phieu ban giao cho. Mat mot phan thi thu hoi shipper de nhap tra so kien con lai. Don tiep tuc giu xu ly, khong duoc tu dong giao/hoan phan hang con lai. Chua co luong quyet dinh giao mot phan.
- Chuyen kho co kien mat chi nhan cac kien con cho thuc te. `closed_loss` la ket thuc chuyen co mat kien; kien mat khong co `received_at` gia. Lich su/thong bao phan biet voi nhan du.
- Ket luan khong boi thuong cho su co mat chi duoc duyet khi kien lien quan da o kho va khong con sai lech thieu cho xac minh. Duyet tai chinh khong go khoa van chuyen cua ket luan mat.
- Su co mat da duyet boi thuong tu phien ban cu van chan van chuyen ke ca co khoa cu bi tat. Khong tu ghi lai custody cua du lieu lich su; can kiem ke va doi chieu tung ho so truoc khi sua du lieu cu.
- Kho da phat sinh van don, kien hoac chuyen kho khong duoc doi khu vuc; tao kho moi de giu dung tuyen va lich su. Ten/dia chi/trang thai van sua duoc.
- Kho hoac khu vuc ngung hoat dong chan phan cong moi, lay hang thanh cong, lap va xac nhan phieu xuat, xuat chuyen. Van cho thu hoi, nhap tra, nhan chuyen da xuat va hoan tat hang da giao cho shipper truoc do. Khong tu go quyen hay huy chung tu cu.
- Khieu nai giu kho tiep nhan ban dau. Nhan vien xu ly duoc lien ket ma/trang thai su co cung van don tai kho dich; quyen doc ho so/tai tep su co van theo kho cua su co.

## Migration va du lieu da co

Migration `2026_11_05_120000_create_warehouse_transfers` giu `warehouse_id` la kho van hanh hien tai va backfill `origin_warehouse_id` tu kho cu. Du lieu cu chua co lich su lien kho nen khong suy dien/tai tao cac chuyen da xay ra.

Nhiem vu giao lien tinh cu dang assigned/in_custody o sai khu vuc se bi chan xuat/hoan tat. Dieu phoi thu hoi, nhap du kien ve kho, lap phieu chuyen kho roi giao lai theo dung tuyen. Van cho ghi that bai/nhap tra de khong ket custody cu.

## Tai khoan demo tung kho

Sau demo hien co, chay `php artisan db:seed --class=DemoWarehouseAccountsSeeder` voi PHP 8.2+ va `DEMO_PASSWORD` da dat (toi thieu 10 ky tu). Seeder chi chay local/testing, them 12 tai khoan va khong sua tai khoan cu.

Moi ma kho `kho-hn`, `kho-hn-lb`, `kho-hcm`, `kho-hcm-td`, `kho-dn`, `kho-dn-lc` co:

- `operations.<ma-kho>@guiviet.test`: van hanh dung mot kho.
- `shipper.<ma-kho>@guiviet.test`: shipper dung mot kho.

Mat khau lay tu `DEMO_PASSWORD`; chay lai khong reset mat khau hay pham vi nguoi dung da sua. Cac tai khoan chi nhanh cu van duoc giu de demo tong hop.

Kiem tra tranh chap MySQL tren database verify rieng da migrate: `php tests/Support/warehouse-transfer-race.php quanlivanchuyen_verify_<so>`. Hai tien trinh cung xuat/nhan phai cho mot accepted, mot rejected, chi mot phieu va hai kien nhap kho. Script chi tao fixture trong database verify, khong xoa du lieu.
