# Ghi chú giao diện người dùng

## Định hướng

Giao diện khách hàng tạm dùng tên **Gửi Việt** để không nhầm với một đơn vị vận chuyển thật. Cấu trúc thông tin được tham khảo từ cách các website giao hàng phổ biến, đặc biệt là việc đưa tra cứu, ước tính cước và tạo đơn lên gần đầu trang. Logo, nội dung, màu sắc, bố cục và hình ảnh đã được xây riêng cho project.

Tham khảo thông tin: [GHTK](https://ghtk.vn/) (truy cập ngày 28/10/2026, chỉ tham khảo kiến trúc thông tin).

## Tài sản hình ảnh

- `public/images/logistics-warehouse.jpg`: ảnh kho vận từ Unsplash, nguồn: `https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?auto=format&fit=crop&w=2000&q=85`.
- Ảnh được dùng làm nền hero để người dùng nhận biết ngay đây là sản phẩm quản lý vận chuyển. Không sử dụng logo hoặc tài sản nhận diện của website tham khảo.

## Phạm vi hiện tại

- Tra cứu chỉ xác minh bản nháp trong session hiện tại bằng mã và 4 số cuối điện thoại người nhận. Chưa giả lập đơn đã gửi hoặc timeline giao hàng thật.
- Tính cước dùng biểu phí minh họa version `tham-khao-2026-01`, tính bằng số nguyên VND. Đây chưa phải biểu phí chính thức của hệ thống.
- Bản nháp chỉ lưu trong session, giới hạn 20 bản nháp mỗi phiên. Sprint tài khoản và vận đơn sẽ chuyển dữ liệu này sang database, gắn với user/shop và audit log.
- Giao diện dùng Bootstrap 5, Blade, jQuery, Vite, Be Vietnam Pro và Lucide icons.

## Kiểm tra giao diện

- Desktop: kiểm tra ảnh hero, tab tra cứu/tính cước, kết quả AJAX và footer.
- Đã kiểm tra chiều rộng 320px, 390px, 768px và 1440px; nội dung trang không tràn ngang. Bảng cước có vùng cuộn riêng trên mobile.
- Đã thử trực tiếp: lỗi nhập liệu, tính cước 501g Hà Nội → TP. Hồ Chí Minh = 45.000đ, tạo/sửa/tìm/xóa bản nháp, hủy xác nhận xóa, tra cứu đúng/sai thông tin, menu mobile và FAQ.
