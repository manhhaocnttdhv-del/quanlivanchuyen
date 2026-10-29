# Ghi chú giao diện người dùng

## Định hướng

Giao diện khách hàng tạm dùng tên **Gửi Việt** để không nhầm với một đơn vị vận chuyển thật. Cấu trúc thông tin được tham khảo từ cách các website giao hàng phổ biến, đặc biệt là việc đưa tra cứu, ước tính cước và tạo đơn lên gần đầu trang. Logo, nội dung, màu sắc, bố cục và hình ảnh đã được xây riêng cho project.

Tham khảo thông tin: [GHTK](https://ghtk.vn/) (truy cập ngày 28/10/2026, chỉ tham khảo kiến trúc thông tin).

## Tài sản hình ảnh

- `public/images/logistics-warehouse.jpg`: ảnh kho vận từ Unsplash, nguồn: `https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?auto=format&fit=crop&w=2000&q=85`.
- Ảnh được dùng làm nền hero để người dùng nhận biết ngay đây là sản phẩm quản lý vận chuyển. Không sử dụng logo hoặc tài sản nhận diện của website tham khảo.

## Phạm vi hiện tại

- Tra cứu vận đơn đã gửi trong MySQL bằng mã và 4 số cuối điện thoại người nhận. Kết quả chỉ có trạng thái và thời gian công khai, không có địa chỉ/điện thoại/actor/lý do nội bộ. Có rate limit theo IP.
- Tính cước đọc phiên bản đã công bố và còn hiệu lực trong MySQL; số nguyên đồng, bước cân làm tròn lên. `config/shipping.php` chỉ chứa nhãn hiển thị và mức khởi tạo dữ liệu mẫu.
- Nháp lưu trong MySQL theo shop, cho phép thiếu thông tin; có xem trước và xác nhận gửi. Khi đã duyệt, chỉnh sửa phải tạo đề nghị và đưa đơn về chờ duyệt lại sau khi đề nghị được chấp nhận.
- Khu làm việc có bộ lọc, phân trang, timeline, kiểm tra dữ liệu ở từng trường, tem CODE128 theo từng kiện và bảng kê có CSS in. Menu dùng offcanvas trên mobile; bảng dữ liệu cuộn trong vùng riêng.
- Giao diện dùng Bootstrap 5, Blade, jQuery, Vite, Be Vietnam Pro và Lucide icons.

## Kiểm tra giao diện

- Desktop: kiểm tra ảnh hero, tab tra cứu/tính cước, kết quả AJAX và footer.
- Đã kiểm tra chiều rộng 320px, 390px, 768px và 1440px; nội dung trang không tràn ngang. Bảng cước có vùng cuộn riêng trên mobile.
- Những kiểm tra của bản prototype session đã được thay bằng kiểm thử OrderWorkflowTest cho vận đơn MySQL. Không còn endpoint lưu/xóa nháp theo session.
