# Rà soát tổng thể ngày 30/10/2026

## Phạm vi

Rà soát trên nhánh `sprint-08-nhat-ky-thong-bao`, kế thừa chức năng Sprint 01–08. Đọc Controller, Form Request, Policy, Service, Model, route và Blade của các luồng tài khoản, shop, vận đơn, kho, giao/hoàn, khiếu nại/sự cố, tài chính, báo cáo, audit và thông báo.

## Lỗi đã sửa

| Vị trí | Nguyên nhân và ảnh hưởng | Cách sửa |
| --- | --- | --- |
| `Order::scopeFiltered`, `DeliveryController::collections` | Mốc ngày Việt Nam bị đổi sang UTC trước khi so với DATETIME lưu theo `config('app.timezone')` (hiện là Asia/Ho_Chi_Minh). Danh sách có thể nhận nhầm đơn/khoản thu tối hôm trước và bỏ sót tối ngày được chọn; tổng tiền cũng bị ảnh hưởng. | Chuyển mốc về timezone lưu thực tế rồi truyền chuỗi DATETIME vào SQL. Khoảng lọc là `[đầu ngày, đầu ngày kế tiếp)`, không dùng `DATE(column)` để giữ khả năng dùng index. |
| `DeliveryFilterRequest` | Luôn so sánh ngày kết thúc với trường ngày bắt đầu, kể cả khi không nhập ngày bắt đầu. | Chỉ áp dụng `after_or_equal:from` khi `from` có giá trị. Vẫn báo lỗi khi nhập khoảng ngày đảo ngược. |
| Hồ sơ cá nhân, hồ sơ shop, trang duyệt shop | Bootstrap `g-5` có nửa gutter 24 px, lớn hơn padding nội dung 20 px trên mobile, gây tràn ngang 4 px. | Dùng `g-4 g-lg-5`: giảm khoảng cách trên mobile và giữ khoảng cách desktop. Không che nội dung bằng cách ẩn overflow. |

Không chuyển dữ liệu lịch sử sang UTC và không đổi timezone ứng dụng. Tài liệu nghiệm thu Sprint 04 được sửa cho khớp cách lưu DATETIME thực tế. Không có thay đổi schema trong đợt rà soát này.

## Comment và cách đọc code

- 23 Controller nghiệp vụ có comment mô tả trách nhiệm; các đoạn khó có giải thích về phạm vi dữ liệu, snapshot, preview, revision, chống thao tác trùng, file private và tổng hợp số liệu. `Controller.php` cơ sở chỉ dùng trait của Laravel, không có nghiệp vụ.
- Cả 75 file Blade có comment riêng theo mục đích và quy tắc của màn hình. Các đoạn liên quan token/revision, phiên bản dòng đối soát, quyền xem, checkbox bàn giao và bộ lọc báo cáo có chú thích tại chỗ.
- Dùng comment Blade `{{-- ... --}}` để phần giải thích không xuất hiện trên giao diện. Không chú thích lại từng câu lệnh đã rõ nghĩa.

Khi giải thích một thao tác, lần lượt đọc route → Form Request → Controller → Policy/Service → Model/migration → Blade. Controller nhận dữ liệu đã kiểm tra và trả response; các bước cần transaction, khóa bản ghi và ghi lịch sử nằm ở Service.

| Câu hỏi khi bảo vệ | Điểm cần giải thích |
| --- | --- |
| Ẩn nút trên Blade có đủ phân quyền không? | Không. Route/middleware, Form Request và Policy/Service kiểm tra lại quyền và phạm vi shop/kho ở server. |
| Vì sao không cộng tiền COD với phí vận chuyển? | Đây là hai nghĩa vụ khác nhau, được ghi sổ và phân bổ riêng. Tiền dùng DECIMAL, không tính nghiệp vụ bằng float. |
| Vì sao kiểm tra lại dữ liệu khi bấm lưu dù đã có preview? | Preview có thể cũ. Service khóa và đọc lại nguồn, trạng thái, revision và phần tiền khả dụng trong transaction. |
| Vì sao không dùng 23:59:59 làm cận cuối ngày? | Cận trên mở là đầu ngày kế tiếp, nên không phụ thuộc độ chính xác phần giây của dữ liệu. |
| Vì sao không thêm comment cho mọi dòng? | Comment giải thích quy tắc và lý do khó suy ra từ code; tên method/biến mô tả thao tác thông thường. |

## Kiểm chứng

- `php artisan test`: 164 bài đạt, 1.208 assertions, trên database MySQL testing riêng.
- Test ngày tạo đơn và ngày thu tiền dùng Service tạo dữ liệu thật tại bốn mốc: trước đầu ngày, đúng đầu ngày, cuối ngày và đúng đầu ngày kế tiếp. Kiểm tra danh sách, tổng COD, lọc chỉ ngày kết thúc và khoảng ngày đảo ngược. Đã tái hiện lỗi trước khi sửa.
- `php vendor/bin/pint --test`, `npm run build`, `php artisan view:cache` và `git diff --check` được dùng để kiểm tra định dạng, build, biên dịch Blade và khoảng trắng.
- Kiểm tra cú pháp 268 file PHP trong app/config/routes/database/tests đạt. Composer audit không có advisory; npm audit không có lỗ hổng được báo cáo tại thời điểm kiểm tra.

- Playwright/Chrome: 263 lượt mở trang đạt (260 lượt nội bộ theo 7 vai trò ở 1440 px và 375 px, cùng 3 lượt công khai cơ bản). Bao gồm các danh sách/form chính và trang chi tiết đầu tiên có dữ liệu ở từng nhóm. Không gặp HTTP lỗi, lỗi JS/tài nguyên, icon thiếu, ID trùng hoặc tràn ngang main. Khung nội bộ nằm trong viewport 900 px, header giữ ở đầu trang.
- Kiểm tra thêm 6 lượt trang công khai ở desktop/mobile: trang chủ, đăng nhập và đăng ký. Ảnh tải được; menu mobile mở/đóng, backdrop biến mất và cuộn main không đẩy header đi. Đã xem ảnh chụp màn hình desktop/mobile.

Kết quả trên áp dụng cho các luồng và dữ liệu được kiểm tra; chưa đo tải hay kiểm thử trên môi trường triển khai thực tế. Kiểm tra trình duyệt trong đợt này chỉ đọc dữ liệu, không gửi chứng từ nghiệp vụ; các thao tác ghi được kiểm tra bằng PHPUnit trên database testing riêng.
