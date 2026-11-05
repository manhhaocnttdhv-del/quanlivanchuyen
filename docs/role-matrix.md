# Phân quyền thống nhất cho tám sprint

Hệ thống dùng bốn vai trò: **Admin kiêm kế toán**, **Nhân viên vận hành** (điều phối, kho, CSKH), **Shipper** và **Chủ cửa hàng**. Vai trò xác định nhóm quyền; kho được gán và người lập/người duyệt tiếp tục giới hạn từng thao tác.

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

## Quy tắc phải giữ

- Người lập không tự duyệt chứng từ, kết luận sự cố, kiểm kê hoặc điều chỉnh của mình.
- Shipper không tự xác nhận đã nộp tiền, dù được gán thêm quyền khác.
- COD và phí có nguồn riêng; request lặp không ghi nhận tiền hai lần.
- Nhân viên Vận hành không được quản lý tài khoản, xác minh ngân hàng, xử lý tài chính nội bộ hoặc đọc audit toàn hệ thống.
- Bộ lọc, Policy, API, báo cáo, file xuất và thông báo đều phải áp dụng cùng phạm vi dữ liệu.

## Cập nhật nhánh sprint

Sprint 01–03 đã merge vào `main`; thay đổi chung về bốn vai trò nằm ở PR #9. Nhánh Sprint 04–08 phải nhận thay đổi chung và phần cập nhật của sprint phụ thuộc trước khi kiểm thử. Khi cập nhật từ nhánh phụ thuộc chưa merge, giữ merge commit và ghi rõ dependency trong PR; sau khi các PR phụ thuộc đã merge mới cập nhật tiếp từ `main`.

Mỗi sprint chạy migration trên database kiểm thử riêng, seed bằng bốn vai trò mới rồi chạy PHPUnit, Pint và Vite build. Không sửa lại commit lịch sử của sprint đã merge và không reset database đang dùng để thay đổi vai trò.
