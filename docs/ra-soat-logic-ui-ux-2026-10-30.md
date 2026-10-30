# Rà soát logic và UI/UX lần hai, 30/10/2026

Đợt này tập trung vào lỗi khi thao tác, khôi phục form sau validation, quyền điều hướng và màn hình nhỏ. Tiếp nối [bản rà soát tổng thể](ra-soat-tong-the-2026-10-30.md).

## Các lỗi đã xác nhận và sửa

| Mức | Vị trí | Lỗi và kết quả sau sửa |
| --- | --- | --- |
| P2 | `components/finance-amount.blade.php`, các form có `client_token` | Dữ liệu không hợp lệ dạng mảng bị flash vào old input, khiến Blade lỗi `htmlspecialchars()` và trả HTTP 500 khi mở lại form. Ô tiền chỉ hiển thị scalar; component `client-token` giữ UUID hợp lệ, tạo lại token sai kiểu/định dạng. Đã kiểm tra POST lỗi → GET lại form 200, có lỗi tại ô và không sinh chứng từ. |
| P2 | `portal/fees/form.blade.php`, `portal/fees/rule-row.blade.php`, `app.js`, `FeeVersionRequest` | Xóa hết bậc cân rồi thêm không tạo được dòng vì JS clone dòng đầu không còn tồn tại. Xóa dòng còn làm chỉ số lỗi và input lệch nhau khi trang được dựng lại. Dùng template riêng, chỉ số tăng độc lập, chuẩn hóa chỉ số trước validation và hiện lỗi từng ô. Chặn thêm quá 60 dòng; xóa một dòng sẽ cho thêm lại. |
| P2 | `layouts/portal.blade.php`, `portal/logistics/inventory.blade.php` | Người chỉ có quyền điều phối thấy link kiểm kê nhưng vào bị 403; người chỉ có quyền duyệt kiểm kê lại thiếu link. Điều kiện menu/nút nay khớp quyền `logistics.warehouse` hoặc `logistics.approve` của trang đích. Phân quyền server vẫn giữ nguyên. |
| P2 | `portal.css`, header portal | Ở viewport 320 px, header làm chiều rộng trang tăng thành 365 px. Giới hạn track grid, giảm khoảng cách trên mobile, dùng icon đăng xuất có nhãn truy cập. Tên người dùng dài có giới hạn chiều rộng; tiêu đề dài được xuống dòng. Header và nội dung vẫn nằm trong chiều cao viewport, phần nội dung cuộn riêng. |

Các ô ngày báo cáo dùng lại `x-field` để xử lý old input an toàn và liên kết lỗi đúng ô. Danh sách chọn bồi thường chịu được old input sai kiểu. Ô tiền luôn có phần tử đích cho `aria-describedby`; lỗi có `aria-invalid`. Portal có link bỏ qua menu bằng bàn phím; icon trang trí được ẩn khỏi trình đọc màn hình.

Menu tính các quyền kho/điều phối/kiểm kê một lần trong layout. Test danh sách 601 vận đơn vẫn giữ giới hạn dưới 35 query, không nâng ngưỡng để bỏ qua hồi quy.

## Kiểm chứng

- Thêm 3 test hồi quy cho POST/GET dữ liệu tài chính không hợp lệ, lỗi dòng biểu phí sau xóa và quyền điều hướng độc lập. Các test đều tái hiện lỗi trước khi sửa.
- Toàn bộ `php artisan test --compact`: **167 test đạt, 1.235 assertions** trên MySQL testing riêng.
- `php vendor/bin/pint --test`, `npm run build`, biên dịch Blade và `git diff --check` dùng để xác minh định dạng, asset và template.
- Chrome/Playwright: 263 lượt mở trang, gồm 7 vai trò ở 1440 px và 375 px. Danh sách, form và trang chi tiết mẫu không có HTTP lỗi, lỗi JS/asset, icon thiếu, ID trùng hay tràn main.
- Thêm 26 lượt ở 320 px và 768 px: các form chính của admin/shop và 3 trang công khai; kiểm tra nhãn input, đích mô tả lỗi, tên người dùng/tiêu đề dài. Thêm/xóa đến 60 bậc cân vẫn có tên input duy nhất và khôi phục được sau khi xóa hết.
- Đã xem ảnh chụp giao diện thật. Kiểm tra menu mobile, ảnh công khai và thao tác bàn phím được thực hiện riêng.

Không đổi schema hoặc dữ liệu nghiệp vụ của database ứng dụng. Test ghi dữ liệu dùng database testing; kiểm tra trình duyệt chỉ thao tác DOM và đọc trang, không gửi chứng từ thật. Đợt này không phát hiện thêm lỗi ghi tiền/trạng thái trong các ca đã kiểm tra; chưa thực hiện đo tải trên môi trường triển khai.
