# Sprint 01: Tài khoản và danh mục

Vai trò đã được gộp còn 4: Admin kiêm kế toán, Vận hành (điều phối/kho/CSKH), Shipper và Khách hàng gửi hàng. Chạy migration trước khi seed khi cập nhật database cũ. Chỉ có 3 vai trò nội bộ trong màn hình tạo tài khoản; cá nhân và cửa hàng tự đăng ký ở trang khách hàng, dùng chung vai trò kỹ thuật `shop`.

## Các chức năng đã triển khai

| Yêu cầu | Màn hình / luồng | Kiểm soát chính |
| --- | --- | --- |
| FR-01 | Đăng nhập email/điện thoại, đăng xuất POST | Hash mật khẩu, giới hạn thử sai, đổi session ID; kiểm tra trạng thái và phiên bản quyền mỗi request |
| FR-02 | Hồ sơ cá nhân, đổi mật khẩu | Chỉ sửa chính mình, kiểm tra mật khẩu hiện tại, email/phone unique, thu hồi các phiên cũ |
| FR-03 | Tài khoản nội bộ, vai trò, kho | Quyền kiểm tra ở server, khóa tài khoản thay xóa, không khóa admin cuối; vai trò gắn danh sách permission |
| FR-04 | Đăng ký và cập nhật hồ sơ người gửi | Cá nhân không cần tên cửa hàng; cửa hàng bắt buộc tên; liên kết chủ hồ sơ, pending/approved/rejected/suspended; đổi hồ sơ cần duyệt lại |
| FR-05 | Duyệt shop và xác minh ngân hàng | Có lý do và người duyệt; số tài khoản mã hóa; đổi ngân hàng tạo phiên bản mới, giữ bản cũ và phải xác minh lại |
| FR-06 | Địa chỉ lấy hàng | Chỉ chủ shop được sửa, có khu vực hoạt động, ngừng dùng thay xóa; unique generated column bảo vệ một mặc định |
| FR-07 | Khu vực, kho, biểu phí có ngày hiệu lực | Bậc cân liên tục, không trùng khoảng; không sửa mức phí đã công bố; tiền DECIMAL và số nguyên đồng trong PHP |

Audit writer có từ sprint này. Mọi thay đổi nghiệp vụ trong service ghi audit trong cùng transaction, loại mật khẩu và số ngân hàng đầy đủ. Model audit không cho sửa/xóa. Trang nhật ký cơ bản dành cho admin; các bộ lọc nâng cao và thông báo vẫn thuộc sprint 08.

## Cách chạy và demo

1. Chọn PHP 8.2+, chạy `php artisan migrate`, `php artisan db:seed`.
2. Tạo dữ liệu mẫu trên môi trường local: đặt biến `DEMO_PASSWORD` dài ít nhất 10 ký tự rồi chạy `php artisan db:seed --class=DemoSeeder`. Seeder không thay mật khẩu của tài khoản đã có.
3. Tài khoản mẫu: `admin@guiviet.test`, `operations@guiviet.test`, `shipper@guiviet.test`, `shop@guiviet.test`, `pending@guiviet.test`. Mật khẩu là giá trị DEMO_PASSWORD lúc tạo.
4. Admin mở Tài khoản, Kho/khu vực, Duyệt người gửi & ngân hàng, Biểu phí và Nhật ký. Khách mở Người gửi & địa chỉ hoặc Cửa hàng & địa chỉ theo loại hồ sơ. Hồ sơ pending chưa được gửi vận đơn khi tích hợp sprint 02.
5. Mở `/dang-ky`, chọn Cá nhân để đăng ký không cần tên cửa hàng; chọn Cửa hàng để nhập tên cửa hàng. Sau đăng ký phải vào được hồ sơ, tạo được nháp; admin/vận hành duyệt hồ sơ rồi mới gửi được đơn. Kiểm tra không xem hoặc sửa được đơn của người gửi khác.

## Kiểm thử

`php artisan dev:prepare-test-db` tạo database `quanlivanchuyen_testing`, chạy migration mà không xóa dữ liệu. PHPUnit bắt buộc dùng database này. Các bài test nghiệp vụ dùng transaction và rollback sau từng bài, không reset database đang sử dụng.

Các trường hợp đã phủ: đăng nhập email/điện thoại, thử sai, tài khoản khóa và phiên bị thu hồi; bảo vệ admin cuối; sửa hồ sơ đúng chủ; ngân hàng giữ lịch sử và mã hóa; phạm vi shop/địa chỉ; biểu phí trùng lịch hoặc trùng cân bị từ chối; audit cùng rollback; render các màn hình theo quyền.

## Các quyết định cần giải thích khi bảo vệ

- Quyền chức năng khác phạm vi dữ liệu: được điều phối không đồng nghĩa xem được mọi kho. Sprint 02 dùng lại danh sách kho để lọc vận đơn.
- `business_locks` chứa các dòng khóa cố định cho việc công bố biểu phí và bảo vệ admin cuối. Khóa chung trong transaction tránh hai request đồng thời cùng vượt qua kiểm tra.
- Đặt địa chỉ mặc định khóa dòng shop để các request cùng cửa hàng tuần tự. Unique generated column là lớp bảo vệ cuối trong MySQL.
- Khoảng hiệu lực biểu phí là `[active_from, active_until)`: bản sau có thể bắt đầu đúng thời điểm bản trước kết thúc.
- Mỗi lần đổi ngân hàng tạo một record mới. Chứng từ tài chính sau này phải tham chiếu phiên bản đã xác minh; không đọc ngân hàng hiện tại để sửa lịch sử.
- Seed chỉ là dữ liệu demo, không phải kết nối ngân hàng hay cước của một hãng vận chuyển thật.
