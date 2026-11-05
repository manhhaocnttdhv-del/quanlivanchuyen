# Sprint 02: Quản lý vận đơn

Nhánh `sprint-02-quan-ly-van-don` nhận code Sprint 01 từ commit `976180c`. Phạm vi là FR-08 đến FR-15 trong `docs/sprints.json`. Phân công lấy/giao, bàn giao kho, thu tiền và đối soát thuộc các sprint tiếp theo.

## Chức năng nghiệm thu

| FR | Đầu ra | Kiểm soát |
| --- | --- | --- |
| FR-08 | Tạo nháp thiếu thông tin, sửa, xem trước, xác nhận gửi | Shop approved, địa chỉ chính chủ/active, khu vực active, kho tiếp nhận; ULID + unique; client_token + hash payload chống bấm lưu lặp |
| FR-09 | Phí server theo biểu phí hiệu lực, tuyến, dịch vụ và bậc cân | Không nhận shipping_fee từ client; tiền số nguyên đồng; giới hạn COD; COD/cước/người trả/tổng cần thu tách riêng |
| FR-10 | Điều phối duyệt/yêu cầu bổ sung/từ chối | Chỉ submitted; lý do bắt buộc; quyền theo kho; người tạo không tự duyệt; row lock + revision ngăn duyệt lặp |
| FR-11 | Ma trận sửa/hủy và đề nghị sau duyệt | Đề nghị có before/changes/reason, chỉ một pending; người khác xét; chấp nhận chỉnh sửa tính lại phí và đưa đơn về submitted để duyệt lại |
| FR-12 | Lọc mã/shop/ngày/trạng thái/khu vực/shipper | Scope visibleTo trước filter, eager loading, 15 đơn/trang, giữ query string; ngày theo giờ Việt Nam |
| FR-13 | Timeline actor/thời gian/trước/sau/lý do | History + audit + order cùng transaction; lịch sử chỉ ghi thêm qua model; rollback không để history giả |
| FR-14 | Tra cứu công khai có xác minh | Mã + 4 số cuối điện thoại, tối đa 10 request/phút/IP; lỗi chung; không lộ PII, COD hay lý do nội bộ; nháp không công khai |
| FR-15 | Tem barcode CODE128, bảng kê theo bộ lọc | Picqer library, không tự vẽ thuật toán mã; một tem/kiện; tổng thu ghi rõ một lần/đơn; policy, CSS print, tối đa 100 đơn/bảng kê |

## Ma trận sửa và hủy

| Trạng thái | Sửa trực tiếp | Hủy trực tiếp | Đề nghị sửa/hủy |
| --- | --- | --- | --- |
| draft, needs_info, rejected | Chủ shop; gửi lại phải đủ thông tin | Chủ shop, cần lý do | Không cần |
| submitted | Không; điều phối trả needs_info trước | Chủ shop nếu chưa lấy kiện, cần lý do | Không |
| approved và uncollected | Không | Không | Chủ shop tạo, điều phối khác xét; đề nghị sửa được chấp nhận thì về submitted |
| Đã lấy/tại kho/đang giao/đang hoàn | Không | Không | Phải qua thu hồi/bàn giao ở Sprint 03–04; Sprint 02 chặn thao tác |
| delivered, returned, cancelled | Không | Không | Không thay đổi trực tiếp |

Đơn có đề nghị pending tạm không in tem/bảng kê, tránh dùng thông tin sắp thay đổi. Một đề nghị được từ chối giữ nguyên dữ liệu và phí hiện tại.

## Cách demo

1. Chạy migration; tạo tài khoản bằng DemoSeeder (xem README), sau đó `php artisan db:seed --class=DemoOrderSeeder` để có 8 đơn mẫu. Seeder chạy lại không nhân đôi.
2. Đăng nhập `shop@guiviet.test`, mở Vận đơn. Tạo nháp thiếu người nhận để xem thông báo cần bổ sung; thêm đủ thông tin, chọn địa chỉ lấy hàng, nhập hàng hóa và tổng gram.
3. Lưu và xem trước: ví dụ HN → HCM, 501g tiêu chuẩn theo biểu phí demo là 45.000đ. COD 500.000đ, người nhận trả phí: cần thu 545.000đ; shop trả phí: cần thu 500.000đ.
4. Xác nhận gửi. Đăng nhập `operations@guiviet.test`, mở đơn thuộc kho được gán, yêu cầu bổ sung kèm lý do; shop sửa và gửi lại; điều phối duyệt.
5. Shop đề nghị thay COD/cân nặng. Điều phối chấp nhận đề nghị, xem phí mới và trạng thái chờ duyệt lại; duyệt lần nữa. Timeline giữ thông tin trước/sau.
6. Mở tem và bảng kê. Tra cứu công khai bằng mã cùng 4 số cuối điện thoại nhận; thử sai xác minh để thấy lỗi chung.
7. Dùng hai tab mở cùng đơn, cập nhật ở tab một rồi thao tác tab hai: revision cũ bị từ chối; tải lại để tiếp tục.

## Kiểm tra kỹ thuật

- MySQL migration chạy trên database có dữ liệu và database mới `quanlivanchuyen_verify_2026102802`, không dùng migrate:fresh trên database ứng dụng.
- `php artisan test`: 40 bài, 327 assertions đã đạt. Nhóm IdentityCatalogTest và OrderWorkflowTest kiểm tra phân quyền, chốt snapshot, biên cân, COD, lịch biểu phí, stale revision, request lặp, ma trận hủy, đề nghị, rollback, tra cứu riêng tư và tem/bảng kê.
- Kiểm tra danh sách 601 vận đơn: trả 15 dòng, tổng đúng, số truy vấn bị chặn ngưỡng để phát hiện N+1. Đây là kiểm tra hồi quy truy vấn, không phải benchmark tải sản xuất.
- `php vendor/bin/pint --test`, `npm run build`, Blade compile và kiểm tra UI desktop/mobile trước khi push.
- Đã thử trực tiếp trên trình duyệt ngày 29/10/2026: shop tạo đơn 501g/COD 500.000đ, xem tổng 545.000đ khi người nhận trả phí, gửi duyệt, tra cứu công khai, điều phối duyệt và mở tem barcode. Form/chi tiết ở 390px không tràn ngang; menu mobile mở/đóng được.

## Giải thích khi bảo vệ

- Snapshot giữ nội dung đã thỏa thuận tại thời điểm gửi; foreign key giữ liên kết gốc để tra cứu. Đổi danh mục không được làm đổi địa chỉ/cước của đơn cũ.
- `client_token` đại diện một lần tạo đơn. Cùng token và nội dung trả về đơn cũ; cùng token khác nội dung báo lỗi. Unique composite là lớp chặn cuối ở MySQL.
- Khóa shop → order → change trong transaction, cùng thứ tự ở các luồng; revision bảo vệ thông tin cũ từ trình duyệt. Row lock ngăn thao tác đồng thời cùng đọc trạng thái hợp lệ; revision giúp người dùng hiểu vì sao cần tải lại.
- Tiền không dùng float: phí cơ bản + ceil(phần gram vượt cân cơ bản / bước gram) × phí bước. Cân kg là chuỗi DECIMAL suy ra từ gram, không có hai input cân độc lập.
- Cần thu người nhận = COD + cước nếu recipient trả; nếu sender trả thì chỉ COD. Các khoản này chưa phải tiền đã thu; chỉ giao hàng thực tế ở Sprint 04 mới tạo collection.
- Mỗi kho có phạm vi riêng. Có role điều phối không đồng nghĩa có quyền xem mọi đơn; query và policy cùng kiểm tra kho.
- Model append-only bảo vệ thao tác ứng dụng, không thay thế quyền MySQL. Không cung cấp UI sửa/xóa history/audit; người có quyền SQL trực tiếp vẫn cần kiểm soát riêng khi triển khai thực tế.
- Bộ lọc shipper đã có, hiện rỗng khi chưa được phân công; nghiệp vụ gán shipper không được giả lập trong Sprint 02.
