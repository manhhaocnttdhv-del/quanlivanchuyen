# Phân quyền thống nhất cho tám sprint

Hệ thống dùng bốn vai trò: **Admin kiêm kế toán**, **Nhân viên vận hành** (điều phối, kho, CSKH), **Shipper** và **Khách hàng gửi hàng**. Vai trò xác định nhóm quyền; kho được gán và người lập/người duyệt tiếp tục giới hạn từng thao tác.

Khách hàng chọn loại hồ sơ **Cá nhân** hoặc **Cửa hàng** khi đăng ký; cả hai dùng vai trò kỹ thuật `shop`. Loại cá nhân dùng họ tên làm tên người gửi, không yêu cầu tên cửa hàng. `shops.account_type` lưu loại hồ sơ; hồ sơ cũ mặc định là `business`. Cả hai chỉ quản lý dữ liệu của mình, lưu được đơn nháp và phải được duyệt hồ sơ trước khi gửi đơn. Người gửi không tự đổi loại hồ sơ hoặc tự duyệt.

| Sprint | Phân công theo vai trò mới |
| --- | --- |
| 01 — Tài khoản và danh mục | Admin quản lý nhân viên, danh mục, biểu phí và xác minh ngân hàng. Vận hành duyệt shop. Shop đăng ký và cập nhật hồ sơ của mình. |
| 02 — Vận đơn | Shop tạo/gửi đơn. Vận hành duyệt và xử lý đề nghị trong kho được giao. |
| 03 — Điều phối và kho | Vận hành phân công, nhập/xuất và kiểm kê. Shipper xác nhận phía giao/nhận. Admin hoặc Vận hành khác duyệt biên bản. |
| 04 — Giao và hoàn | Shipper giao/hoàn, ghi kết quả và tiền thực thu. Vận hành quyết định giao lại/hoàn, xem sổ thu theo kho. |
| 05 — Sự cố và khiếu nại | Vận hành tiếp nhận, lập và xử lý hồ sơ. Admin hoặc Vận hành khác duyệt kết luận. Shop chỉ theo dõi hồ sơ của mình. |
| 06 — COD và tài chính | Admin kiêm kế toán xác nhận tiền, lập đối soát và ghi thanh toán. Admin khác duyệt chứng từ. Shipper chỉ đề nghị nộp và xem số tiền của mình. |
| 07 — Báo cáo | Admin xem cả vận hành và tài chính; Vận hành chỉ xem vận hành trong kho; Shop chỉ xem số liệu của cửa hàng mình. |
| 08 — Audit và thông báo | Chỉ Admin xem audit toàn hệ thống. Bốn vai trò nhận thông báo đúng đối tượng, phạm vi và quyền hiện tại. |

## Số vai trò và số tài khoản

Bốn vai trò không có nghĩa chỉ được có bốn tài khoản. Demo vận hành dùng Admin, Vận hành, Shipper và Shop. Demo tài chính cần thêm một tài khoản cùng vai trò Admin để người lập và người duyệt là hai người khác nhau. Không tạo lại vai trò kế toán, điều phối, kho hoặc CSKH riêng.

Vận hành được phân công theo từng chi nhánh; một chi nhánh có thể có nhiều kho. `DemoShippingSeeder` tạo 3 tài khoản `operations.hn`, `operations.hcm`, `operations.dn` tại `@guiviet.test`, lần lượt phụ trách Hà Nội (KHO-HN, KHO-HN-LB), TP.HCM (KHO-HCM, KHO-HCM-TD) và Đà Nẵng (KHO-DN, KHO-DN-LC). Mỗi tài khoản thao tác được cả hai kho trong chi nhánh, không truy cập dữ liệu kho của chi nhánh khác. Phạm vi hiện lưu qua `warehouse_user`, chưa có danh mục chi nhánh riêng; Admin gán danh sách kho cho từng nhân sự. Tài khoản chi nhánh không mang vai trò Admin toàn hệ thống. Tài khoản `operations@guiviet.test` là mẫu vận hành tổng hợp để demo nhiều kho; dùng các tài khoản chi nhánh khi kiểm tra việc cách ly dữ liệu. Các tài khoản kho từ phiên bản seeder cũ được giữ lại.

`DemoWebsiteSeeder` bổ sung shipper và shop theo chi nhánh (`shipper.hn/hcm/dn@guiviet.test`, `shop.hn/hcm/dn@guiviet.test`). Shipper chỉ xem nhiệm vụ được giao trong hai kho của chi nhánh; shop chỉ xem đơn của mình. Mỗi kho có mẫu vận đơn, nhiệm vụ, tồn, sai lệch và kiểm kê để kiểm tra phạm vi qua cả danh sách và URL chi tiết. Admin xét duyệt kiểm kê do Vận hành lập; đề nghị cân thực tế của Vận hành chờ người khác duyệt.

## Quy tắc phải giữ

- Người lập không tự duyệt chứng từ, kết luận sự cố, kiểm kê hoặc điều chỉnh của mình.
- Shipper không tự xác nhận đã nộp tiền, dù được gán thêm quyền khác.
- COD và phí có nguồn riêng; request lặp không ghi nhận tiền hai lần.
- Nhân viên Vận hành không được quản lý tài khoản, xác minh ngân hàng, xử lý tài chính nội bộ hoặc đọc audit toàn hệ thống.
- Bộ lọc, Policy, API, báo cáo, file xuất và thông báo đều phải áp dụng cùng phạm vi dữ liệu.

## Cập nhật nhánh sprint

Sprint 01–03 đã merge vào `main`; thay đổi chung về bốn vai trò nằm ở PR #9. Nhánh Sprint 04–08 phải nhận thay đổi chung và phần cập nhật của sprint phụ thuộc trước khi kiểm thử. Khi cập nhật từ nhánh phụ thuộc chưa merge, giữ merge commit và ghi rõ dependency trong PR; sau khi các PR phụ thuộc đã merge mới cập nhật tiếp từ `main`.

Mỗi sprint chạy migration trên database kiểm thử riêng, seed bằng bốn vai trò mới rồi chạy PHPUnit, Pint và Vite build. Không sửa lại commit lịch sử của sprint đã merge và không reset database đang dùng để thay đổi vai trò.
