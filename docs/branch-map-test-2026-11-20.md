# Kiểm thử chi nhánh và bản đồ shipper ngày 20/11/2026

Kết quả: PASS trong phạm vi đã kiểm tra. Bổ sung bản đồ nhiệm vụ cho shipper và kiểm tra quyền chéo sáu kho, cùng luồng chuyển hàng Hà Nội → TP.HCM trên trình duyệt. Đây không phải chứng nhận đã kiểm tra mọi màn hình hoặc chạy giao hàng ngoài đường.

## 1. Phạm vi và kỳ vọng: PASS

Đọc AGENTS.md, skill Laravel, skill test-agent, kiến trúc và nghiệm thu Sprint 03/04. Mỗi tài khoản chỉ đọc/thao tác trong kho được gán; shipper chỉ thấy nhiệm vụ của mình. Chuyển kho không tạo tiền thu. Người lập chuyến không tự nhận chuyến. Bản đồ chọn điểm tiếp theo theo loại nhiệm vụ và trạng thái giữ hàng.

Mã đơn và QR theo đơn đã triển khai trước đó, xem [thống kê action và luồng](actions-and-workflows.md) và [web test QR ngày 19/11](web-test-2026-11-19.md).

## 2. Môi trường và dữ liệu: PASS

- Laravel 12, PHP 8.3.35, MySQL trên `127.0.0.1:3308`, Node 24.22.1.
- PHPUnit dùng database riêng `quanlivanchuyen_testing`, rollback từng test.
- Trình duyệt dùng `http://127.0.0.1:8001`, database mới `quanlivanchuyen_verify_20261120`, seed bằng `DemoWebsiteSeeder`; không sửa `.env` hoặc database ứng dụng cũ.
- Migration đã chạy thành công trên database testing và database verify mới. Tính năng bản đồ không thay schema, không thêm dependency.
- Tài khoản demo nhận mật khẩu từ biến môi trường lúc seed; không ghi mật khẩu vào báo cáo hoặc commit.

## 3. Ma trận kiểm thử: PASS

| Trường hợp | Kết quả kỳ vọng và quan sát |
| --- | --- |
| Sáu kho: hai Hà Nội, hai TP.HCM, hai Đà Nẵng | Vận hành thấy đơn/nhiệm vụ kho mình; kho khác không có trong danh sách, đọc/thu hồi trực tiếp bị 403 |
| Shipper từng kho | Danh sách và bản đồ chỉ có việc của mình; task khác shipper/kho trả 404 trên bản đồ, 403 tại chi tiết |
| Hai kho cùng tỉnh | Không tự được quyền kho còn lại; tài khoản được gán hai kho xem được cả hai |
| Thu hồi quyền kho | Danh sách, bản đồ và quét QR kiểm lại quyền hiện tại, không còn mở nhiệm vụ cũ |
| Chưa đăng nhập, sai vai trò, bị khóa | Chuyển đăng nhập hoặc 403 theo middleware/Policy |
| Lấy/giao/hoàn, chưa nhận/đang giữ/thu hồi | Điểm đến đúng người gửi, kho hoặc người nhận theo bảng luồng |
| Lọc, tìm mã, phân trang, dữ liệu sai, thiếu địa chỉ | Kết quả đúng phạm vi; validation; trạng thái rỗng/thiếu địa chỉ dùng được |
| Mở bản đồ | Không đổi đơn/audit, không tạo khoản thu; cache private/no-store; URL Google chỉ chứa địa chỉ |
| Chuyển liên tỉnh | Nhận đủ mới đổi kho xử lý; chặn người tự nhận, sai kho, mất quyền, nhận lặp hoặc dữ liệu cũ; có kiểm thử giao và hoàn |

## 4. Kiểm tra tĩnh và build: PASS

| Lệnh | Kết quả |
| --- | --- |
| `php vendor/bin/pint --test` | PASS |
| `npm run build` | PASS |
| `git diff --check` | PASS |

Các lệnh PHP dùng binary 8.3 vì PHP mặc định của terminal là 8.1. Các kiểm tra này không thay thế kiểm thử nghiệp vụ.

## 5. Kiểm thử tự động: PASS

- Nhóm `ShipperMapTest`, `WarehouseTransferWorkflowTest`, `CustomerWarehouseAccessTest`, `LogisticsWorkflowTest`: **44 test, 669 assertion**.
- Toàn bộ `php artisan test --compact`: **261 test, 3.303 assertion**, hoàn tất trong khoảng 127 giây.
- Thêm sáu test bản đồ, gồm ma trận tài khoản sáu kho và các trường hợp biên/phân quyền; tái sử dụng test chuyển kho hiện có.
- Một lỗi fixture lúc phát triển do dùng mass assignment cho trường trạng thái tài khoản bị guarded đã được sửa trong test bằng phép gán trực tiếp; bộ test cuối đều đạt.

## 6. Luồng web thực tế và trạng thái lưu: PASS

1. Đăng nhập vận hành và shipper Hà Nội, TP.HCM, Đà Nẵng; đối chiếu tên kho/phạm vi hiện trên trang. Vận hành mỗi chi nhánh chỉ có hai kho tương ứng trong bộ lọc; bản đồ shipper tương ứng có nhiệm vụ thuộc hai kho đó.
2. Tạo fixture đơn `GV01M7V75WQ5C68QYEMZ2JW9154D` (ID 124), hai kiện, COD 500.000đ, từ kho Hà Nội đến TP.HCM. Các bước nháp, duyệt, lấy và nhập kho đầu được chuẩn bị qua Service nghiệp vụ; không ghi trực tiếp giả trạng thái. Mã này thuộc database verify ngày 20, khác dữ liệu ngày 19.
3. Trên trình duyệt, vận hành Hà Nội xuất hai kiện qua chuyến `CK01M7V782KAGSVSYC25QP8QGQ41` (ID 1); modal xác nhận hoạt động. Người lập không có form nhận.
4. Vận hành Đà Nẵng mở trực tiếp `/chuyen-kho/1`: 403. Vận hành TP.HCM kiểm và nhận đủ hai kiện vào A-01: chuyến `received`, đơn chuyển sang `warehouse_id=2`, vẫn giữ `origin_warehouse_id=1`.
5. Vận hành TP.HCM phân nhiệm vụ giao 94 cho shipper TP.HCM, lập phiếu xuất `BG01M7V7B3PF2K8GPVYRB6P2MPW5` (ID 81). Shipper đăng nhập, xác nhận nhận cả hai kiện: task `in_custody`, đơn `out_for_delivery`, cả hai kiện do shipper ID 11 giữ.
6. Từ nhiệm vụ mở bản đồ: hiển thị địa chỉ kiểm thử công khai `2 Cong xa Paris, Ben Nghe, Quan 1, TP. Ho Chi Minh`, nền bản đồ khu vực Bưu điện TP.HCM tải thật. Có gọi điện, liên kết chỉ đường đúng địa chỉ và mở nhiệm vụ. Phân trang giữ nhiệm vụ đã chọn; console không có lỗi được ghi nhận.
7. Vận hành Hà Nội mở `/van-hanh/don/124` sau chuyển kho: 403. Shipper Đà Nẵng mở `/ban-do-shipper?task=94`: 404; bản đồ riêng chỉ hiện Đà Nẵng/Liên Chiểu.
8. Kiểm tra desktop 1280×720 và mobile 390×844. Mobile có chiều rộng document 390px, vùng main 375px bằng client width 375px, không tràn ngang; nút gọi/chỉ đường/mở nhiệm vụ xuống dòng và vẫn truy cập được. Đã trả viewport về mặc định.
9. Đọc lại database: chuyến nhận đủ, đơn ở kho đích, shipper đang giữ hai kiện, **0 khoản thu**. Luồng web dừng tại đây để giữ mẫu demo bản đồ; chưa bấm giao thành công/nộp tiền cho đơn này. Giao/hoàn liên tỉnh tiếp tục được kiểm bằng bộ test tự động; luồng giao/thu/nộp trên web có báo cáo riêng ngày 19.

Bằng chứng local được giữ ở `D:/codex-work/quanlivanchuyen-qr/evidence/`: `branch-dn-forbidden.jpg`, `branch-transfer-received.jpg`, `shipper-dn-foreign-task.jpg`, `shipper-map-desktop.jpg`, `shipper-map-mobile.jpg`, `shipper-map-mobile-actions.jpg`. Các ảnh này nằm ngoài Git. Preview và MySQL được giữ chạy để demo.

## 7. Kết luận và giới hạn: PASS trong phạm vi

Không còn lỗi được xác nhận trong các ca trên. Bản đồ dùng Google Maps theo địa chỉ, cần Internet; địa chỉ demo giả có thể ra kết quả rộng hoặc sai. URL nhúng/chỉ đường gửi địa chỉ điểm đến cho Google, không kèm tên, điện thoại, mã đơn, tiền hoặc referrer. Chưa có GPS thời gian thực, theo dõi nền, ETA hay tối ưu tuyến nhiều điểm; chưa kiểm thử lái xe ngoài thực tế, gọi điện thật hoặc dẫn đường từ GPS thiết bị.

Không chạy audit dependency mới trong đợt này; các cảnh báo Composer Laravel/Symfony có từ lần kiểm tra trước chưa được xử lý trong phạm vi bản đồ. Không coi kiểm tra này là PASS về audit dependency.
