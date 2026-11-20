# Kiểm tra giao diện và dữ liệu kho ngày 20/11/2026

Phạm vi: tồn kho, nhận chuyển kho một phần, báo cáo vận hành và phạm vi tài khoản. Nhánh `codex/sprint-03-inventory-data-display`, từ `main` sau PR #16. Không thay schema; kiểm tra dữ liệu QA bằng truy vấn đọc, không sửa lịch sử hoặc chứng từ để làm đẹp số liệu.

## Lỗi đã tái hiện và sửa

1. Nhận một trong hai kiện ở kho đích: báo cáo tồn trước đây vẫn ghi kho nguồn vì dùng `orders.warehouse_id`. Báo cáo nay đếm theo kho thực giữ kiện, mỗi vận đơn một lần tại kho đó. Kho nguồn không còn tồn sau khi xuất; kho đích thấy phần đã nhận; kiện đang trên đường không tính tồn. Chỉ khi nhận đủ và xử lý khóa mới được phân công giao.
2. Chi tiết tồn/báo cáo của kho đích có thể dẫn đến trang vận đơn bị 403 khi đơn chưa chuyển kho phụ trách. Liên kết nay mở phiếu chuyển kho trong trường hợp này; khi nhận đủ thì mở vận đơn. Quyền xem toàn bộ vận đơn vẫn theo policy hiện có.
3. Bộ lọc giữ kiện trước đây chỉ lấy kiện có sai lệch trực tiếp, bỏ sót kiện cùng đơn hoặc đơn bị sự cố khóa vận chuyển. Nay hiển thị khóa theo cả vận đơn, đồng nhất với quy tắc chặn xuất hàng. Không hiển thị nội dung sự cố của kho khác.
4. Bảng tồn thiếu tên kho, không phân biệt số kiện với số đơn, và ép mã vận đơn xuống nhiều dòng trên điện thoại. Nay có kho / vị trí, tổng kiện / vận đơn và bảng cuộn ngang bằng cảm ứng hoặc bàn phím. Cân chưa ghi nhận hiển thị “Chưa cân”, không biến thành 0 g.

## Đối chiếu dữ liệu hiện tại

Database preview: `quanlivanchuyen_verify_202611201134`, MySQL local cổng 3308. Các kiểm tra dưới đây là ảnh chụp dữ liệu tại thời điểm kiểm tra, không phải xác nhận kiểm kê vật lý ngoài thực tế.

| Kiểm tra | Kết quả |
| --- | --- |
| Tồn toàn hệ thống | 54 kiện / 27 vận đơn tại 6 kho |
| Kho Hà Nội | 14 kiện / 7 vận đơn |
| TP.HCM, Long Biên, Thủ Đức, Đà Nẵng, Liên Chiểu | Mỗi kho 8 kiện / 4 vận đơn |
| Phạm vi tài khoản TP.HCM | TP.HCM + Thủ Đức: 16 kiện / 8 vận đơn; báo cáo khớp |
| Kiện ghi nhận và số kiện khai báo | Không thấy lệch ở các đơn đã phát sinh kiện |
| Vị trí thuộc sai kho hoặc thiếu vị trí của kiện tồn | 0 |
| Kiện kho đang giữ nhưng gán người giữ là shipper | 0 |
| Dòng chuyển kho đã nhận thiếu người nhận / sai vị trí | 0 |
| Khoản thu trùng theo lần giao và loại tiền | 0 |
| COD thực thu | 2.510.000đ = 800.000đ shipper giữ + 1.710.000đ đã nộp quỹ |
| Phí thu tại chỗ của 6 lần giao thành công | 0đ; cả 6 đơn do người gửi chịu phí, khớp số dự kiến |

Giới hạn dữ liệu mẫu: cả 131 vận đơn được tạo ngày 20/11/2026. Bộ mẫu đủ để kiểm tra nhiều trạng thái, nhưng chưa đa dạng ngày để demo xu hướng, mùa vụ hoặc quá hạn. Địa chỉ, nội dung và một số hồ sơ có nhãn demo/QA. Chưa thay ngày hoặc sửa hồ sơ cũ.

## Kiểm chứng

| Bước | Trạng thái | Bằng chứng |
| --- | --- | --- |
| 1. Phạm vi và kỳ vọng | PASS | Tồn theo kiện thực giữ; báo cáo theo đơn/kho; giới hạn quyền kho |
| 2. Môi trường và baseline | PASS | QA riêng; PHPUnit dùng `quanlivanchuyen_testing`, rollback mỗi test |
| 3. Kịch bản | PASS | Hai test mới: nhận một phần, nguồn/đích/admin/shop/ngoài phạm vi, bộ lọc và dạng xuất báo cáo; khóa bởi sai lệch/sự cố; cân chưa có |
| 4. Static và build | PASS | `php vendor/bin/pint --test`, `npm run build`, `git diff --check` |
| 5. Test tự động | PASS | Trước sửa 2 test mới thất bại; sau sửa 34 test tập trung / 279 assertions và toàn bộ 295 test / 4.199 assertions |
| 6. Trình duyệt | PASS | Đăng nhập admin, vận hành TP.HCM, shop, shipper; đối chiếu tồn/báo cáo, mở chi tiết shop, shipper vào tồn bị 403; desktop 1280, tablet 768, mobile 375 và 320px |
| 7. Bằng chứng và giới hạn | PASS | Báo cáo này, ảnh và JSON đối chiếu trên ổ D; các giới hạn dữ liệu mẫu ghi ở trên |

Không có migration mới nên kiểm tra migration thay đổi được SKIPPED. Luồng nhận một phần và đường dẫn kho đích được xác minh qua HTTP feature test dùng các Service nghiệp vụ thực; không tạo thêm phiếu QA trong lần rà giao diện này. Bảng mobile giữ cuộn trong vùng bảng; trang không tràn ngang ở các kích thước đã kiểm tra. Đã khôi phục viewport mặc định và phiên admin.

Bằng chứng cục bộ tại `D:/codex-work/quanlivanchuyen-map-check/`: `inventory-before.xml`, `inventory-after.xml`, `inventory-full.xml`, `inventory-data-audit.json`, `inventory-desktop.png`, `inventory-mobile.png`, `inventory-report-operations-tablet.png`, `inventory-report-shop-mobile.png`. Preview vẫn tại `http://127.0.0.1:8001/ton-kho`.
