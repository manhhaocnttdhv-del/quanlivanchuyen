# Quan Ly Van Chuyen COD

He thong quan ly van chuyen COD xay dung bang Laravel 12, MySQL, Bootstrap 5 va jQuery. Project duoc chia thanh 8 sprint nghiep vu de de phat trien, review va bao ve do an.

Sprint 01 đến 08 đã triển khai trên các nhánh riêng: tài khoản/danh mục, vận đơn, điều phối/kho, giao/hoàn hàng, sự cố/khiếu nại, COD/tài chính, báo cáo/thống kê, nhật ký/thông báo. Giao diện khách hàng **Gửi Việt** và khu làm việc hỗ trợ mobile. Sprint 08 có nhật ký quản trị, so sánh trước/sau và hộp thông báo theo phạm vi kho/shop; các PR vẫn cần review trước khi merge vào `main`.

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

Sprint 03: chạy `php artisan db:seed --class=DemoLogisticsSeeder` để thêm đơn lấy hàng, nhập/xuất kho, hàng hỏng và kiểm kê. Điều phối/shipper vào `/dieu-phoi`; kho dùng `/ton-kho` và `/kiem-ke`. Xem [nghiệm thu và câu hỏi bảo vệ Sprint 03](docs/sprint-03-nghiem-thu.md). Cước theo cân thực tế chỉ đổi sau đề nghị và người khác duyệt; Sprint 03 chưa ghi nhận COD đã thu.

Chạy `php artisan dev:prepare-test-db` trước `php artisan test`. Test sử dụng MySQL `quanlivanchuyen_testing` riêng và rollback từng test. Lệnh chuẩn bị chỉ tạo/migrate database; không xóa database ứng dụng. Xem `docs/sprint-01-nghiem-thu.md` và `docs/sprint-02-nghiem-thu.md` để demo và giải thích các quyết định thiết kế.

Trên máy hiện tại, mở `http://127.0.0.1:8001`, đăng nhập tại `/dang-nhap`. Shop tạo đơn tại `/gui-hang`; danh sách tại `/don-hang`. Điều phối chỉ xem và duyệt đơn thuộc kho được gán. Tra cứu công khai cần mã vận đơn đã gửi cùng 4 số cuối điện thoại nhận.

Giao diện và nguồn ảnh được ghi tại `docs/frontend-notes.md`.

Sprint 04: chạy `php artisan db:seed --class=DemoDeliverySeeder` sau DemoLogisticsSeeder để có 7 tình huống giao/hoàn. Shipper dùng `/viec-cua-toi`; sổ thu tại `/so-thu-giao-hang`. Có ảnh/người nhận, chống xác nhận trùng, giao lại/hoàn và phí hoàn theo snapshot. Xem [nghiệm thu và câu hỏi bảo vệ Sprint 04](docs/sprint-04-nghiem-thu.md). Tiền ghi nhận đã thu chưa đồng nghĩa quỹ đã nhận; nộp tiền/đối soát thuộc Sprint 06.

Sprint 05: chạy `php artisan db:seed --class=DemoCaseSeeder` sau DemoDeliverySeeder. Shop gửi và theo dõi `/khieu-nai`; CSKH tiếp nhận, phân công và trao đổi nội bộ/công khai. `/su-co` có xác minh, bằng chứng private, khóa giao/đối soát riêng, đề xuất và người khác duyệt kết luận. SLA mặc định 48 giờ; cho mở lại khiếu nại trong 7 ngày. Xem [nghiệm thu, thiết kế và câu hỏi bảo vệ Sprint 05](docs/sprint-05-nghiem-thu.md). Không xóa các khóa kho/tài chính có trước; khoản bồi thường đã duyệt vẫn chờ tài chính.

Sprint 06: chạy `php artisan db:seed --class=DemoFinanceSeeder` sau các seeder trên, rồi `php artisan finance:accrue-fees` để bổ sung phí từ đơn cũ. Mở `/tai-chinh`: shipper đề nghị nộp, kế toán xác nhận; đối soát có người khác duyệt; trả shop từng phần theo chứng từ ngân hàng. Tiền gốc và tài liệu đã ghi sổ không sửa/xóa; thay ngân hàng sau duyệt phải duyệt lại. Xem [nghiệm thu, công thức và câu hỏi bảo vệ Sprint 06](docs/sprint-06-nghiem-thu.md). Đây là ghi nhận kết quả ngân hàng, chưa tự chuyển tiền thật.

Sprint 07: chạy `php artisan migrate`, `php artisan db:seed --class=CatalogSeeder` để cập nhật quyền, rồi `php artisan db:seed --class=DemoReportSeeder` sau các seeder trên. Mở `/bao-cao`: phân biệt ngày tạo/gửi và ngày giao/hoàn, xem tồn/quá hạn hiện tại, đối chiếu COD/quỹ/phí/phải trả từ sổ gốc. Seeder tạo một đơn cuối tháng trước, giao đầu tháng này; chạy lại không trùng. Báo cáo tài chính lọc nguồn phát sinh trong kỳ và hiển thị số dư hiện tại, chưa dựng lại số dư chốt cuối kỳ. Xem [nghiệm thu, cách đọc báo cáo và câu hỏi bảo vệ Sprint 07](docs/sprint-07-nghiem-thu.md).

Xuất Excel tối đa 10.000 dòng, PDF 1.500 dòng; tệp trên 500 dòng chạy qua queue database riêng. Khởi động worker và lịch dọn tệp ở hai terminal riêng:

```shell
php artisan queue:work reports --queue=reports --tries=1 --timeout=240
php artisan schedule:work
```

Tệp private hết hạn sau 24 giờ từ lúc yêu cầu, chỉ người yêu cầu còn đúng quyền/phạm vi mới tải được. `reports:prune-exports` chạy hằng giờ qua scheduler; có thể chạy lệnh này thủ công. Không cần đổi `QUEUE_CONNECTION` cho queue báo cáo.

Sprint 08: chạy `php artisan migrate`, rồi `php artisan db:seed --class=DemoNotificationSeeder` sau các seeder nghiệp vụ trên. Admin tra cứu `/quan-tri/nhat-ky` theo người dùng, ngày, event, đối tượng, mã vận đơn hoặc request ID; mở chi tiết để xem thay đổi trước/sau. Mỗi tài khoản có hộp `/thong-bao`, số chưa đọc và nút đánh dấu đã đọc/chưa đọc. Mở thông báo kiểm tra lại quyền hiện tại, không tự đổi trạng thái đã đọc. Xem [nghiệm thu, luồng queue và câu hỏi bảo vệ Sprint 08](docs/sprint-08-nghiem-thu.md).

Worker thông báo chạy ở terminal riêng, độc lập với worker báo cáo:

```shell
php artisan queue:work notifications --queue=notifications --tries=3 --timeout=60
```

Không cần đổi `QUEUE_CONNECTION`. Thông báo tạo sau commit, có outbox và khóa chống trùng khi retry. Scheduler đã chạy ở trên sẽ phục hồi sự kiện chưa gửi mỗi 5 phút; có thể gọi `php artisan notifications:dispatch-pending` thủ công. Seeder chạy lại không tạo trùng, không phát lại toàn bộ lịch sử cũ. Đây là thông báo trong ứng dụng, chưa gửi email/SMS.

## Tai lieu ky thuat

- [Rà soát tổng thể 30/10/2026](docs/ra-soat-tong-the-2026-10-30.md): lỗi đã sửa, phạm vi comment Controller/Blade, kiểm thử và cách đọc code khi bảo vệ.
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

Sprint 08 đã hoàn thiện tra cứu audit và thông báo trên nền helper audit có từ Sprint 01. Nhánh Sprint 08 kế thừa Sprint 01–07; sau khi các PR phụ thuộc được merge, cập nhật từ `main` trước khi merge PR Sprint 08.
