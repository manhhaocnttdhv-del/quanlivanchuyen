# Sprint 04: Giao hàng và hoàn hàng

Nhánh `sprint-04-giao-hang-hoan-hang`, nhận Sprint 03 tại `9e55add`. Phạm vi FR-25–29. Khi PR Sprint 03 chưa merge, PR Sprint 04 chứa cả commit phụ thuộc; review riêng bằng diff từ nhánh Sprint 03.

Theo [ma trận bốn vai trò](role-matrix.md), Vận hành (`operations@guiviet.test`) làm điều phối/kho và xem sổ thu trong kho được gán; Shipper ghi kết quả giao/hoàn của mình, Shop xem đơn thuộc cửa hàng. Admin có quyền quản trị và kế toán; Sprint 04 chỉ ghi tiền thực thu, chưa xác nhận nộp quỹ hoặc thanh toán shop.

## Chức năng nghiệm thu

| FR | Đầu ra | Kiểm soát |
| --- | --- | --- |
| FR-25 | /viec-cua-toi: lấy/giao/hoàn, đang thực hiện/lịch sử, tìm mã, gọi điện, địa chỉ và tiền | Chỉ task của mình trong kho được gán; phân trang; kiểm tra mobile 375px |
| FR-26 | Người nhận thực tế, ảnh, giờ server, COD và cước thực thu | Transaction và row lock; một attempt/task; token + hash nội dung/ảnh; COD/cước tách dòng |
| FR-27 | Thất bại có mã lý do, ghi nhận, ảnh khi hỏng/mất | Tăng lượt khi ghi kết quả thực tế; không tạo collection; shipper vẫn giữ kiện, phải trả về kho |
| FR-28 | Điều phối quyết định giao lại/ngoại lệ/hoàn, có ý kiến shop và căn cứ | Đủ kiện về kho và kết thúc task cũ; revision; mặc định 3 lượt, logistics.approve cho phép thêm một lượt |
| FR-29 | Hoàn thành/thất bại, người nhận tại shop và ảnh, phí hoàn cố định | Không thu COD; phí từ snapshot gốc; nghĩa vụ phí chỉ phát sinh khi hoàn thành công |

## Cách demo

Chạy migration và các DemoSeeder/DemoLogisticsSeeder theo README, sau đó:

```shell
php artisan db:seed --class=DemoDeliverySeeder
```

Seeder tạo 7 tình huống qua service: đang giao, đã giao, giao thất bại còn giữ kiện, chờ giao lại tại kho, đang hoàn, đã hoàn, giao thành công nhưng thiếu tiền. Token cố định nên chạy lại không nhân đôi hoặc ghi đè kết quả đã thao tác. Ảnh kho chỉ minh họa, không phải bằng chứng giao nhận thực tế.

1. Shipper vào /viec-cua-toi, chọn Giao hàng, mở đơn đang giữ kiện. Kiểm tra COD/cước, tên người nhận, ảnh và ghi nhận; xác nhận thành công.
2. Mở kết quả: task hoàn tất, từng kiện ở người nhận, một lượt giao. /so-thu-giao-hang có COD/cước thành hai dòng.
3. Gửi lại cùng token/nội dung/ảnh: trả attempt đã có, không thêm tiền, ảnh hoặc lịch sử. Token khác cho task đã xử lý hoặc token cũ với nội dung khác đều bị từ chối.
4. Đơn khác ghi thất bại do vắng mặt. Không có collection; task chờ trả kho, kiện vẫn thuộc shipper. Shipper lập phiếu nhập; kho kiểm đếm đủ rồi xác nhận.
5. Điều phối mở /van-hanh/don/{id}, nhập ý kiến shop/căn cứ, chọn giao lại. Phân công và xuất kho như Sprint 03. Thu hồi trước khi có kết quả giao không dùng hết lượt được cho phép.
6. Sau 3 lần thất bại, giao lại thường bị chặn. Người có logistics.approve chọn ngoại lệ: tăng giới hạn lên 4; không có quyền thì 403. Mỗi lượt vượt tiếp theo cần quyết định mới, tối đa 100.
7. Chọn hoàn: chốt hồ sơ phí và cách thu, phân công hoàn, xuất cho shipper; shop nhận có tên/ảnh. Hoàn thành chuyển kiện về shop, không có dòng COD. Hoàn thất bại phải nhập lại kho trước task mới.
8. Mở đơn thiếu tiền để xem cờ chênh lệch và lý do. Sprint 04 chưa xác nhận nộp quỹ, đối soát hay trả shop.

## Dữ liệu và tiền

- delivery_attempts: unique task; unique (shipper, token); unique (order, type, attempt_number). Lưu dự kiến/thực thu, lý do, giờ server và hash. Attempt/proof/collection/decision append-only qua Eloquent.
- delivery_proofs: một dòng/attempt, ảnh private và SHA-256. Chỉ shipper của task còn thuộc kho, nhân viên vận hành đúng phạm vi, Admin hoặc shop sở hữu đơn được xem. Quyền đọc sổ thu riêng không thay thế Policy xem bằng chứng.
- delivery_decisions: actor, quyết định, số lượt lúc quyết định, lượt cho phép, ý kiến shop và căn cứ.
- return_records: một hồ sơ/đơn, người cho phép, số tiền/phương thức/snapshot phí, ngày hoàn và ngày phát sinh nghĩa vụ. Khóa các trường căn cứ/phí sau khi tạo; cho phép cập nhật trạng thái.
- delivery_collections: unique (attempt, type) và idempotency_key; FK restrict delete, DECIMAL(15,2), CHECK không âm. Dòng 0đ thể hiện loại tiền đã được đối chiếu nhưng không có thực thu.
- COD giao dự kiến = COD đã duyệt. Cước thu tại chỗ = shipping_fee nếu người nhận trả, shop trả thì 0. Hoàn luôn COD=0 và không nhận input COD.
- Phí hoàn lấy fee_snapshot.return_fee đã chốt trên đơn, không đọc giá mới. Snapshot cũ thiếu trường này được miễn 0đ (legacy_waived), tránh tạo nghĩa vụ hồi tố.
- reconciliation: phí hoàn có thể >0 nhưng dự kiến thu tại chỗ 0. collect_on_return: dự kiến thu đúng phí hoàn. Chỉ hoàn thành công mới có fee_charged_at.
- Thu thiếu/thừa cần financial_reason và financial_hold/disputed. Hàng vẫn có thể delivered; tiền cần xử lý riêng ở sprint tài chính.
- Tiền số nguyên đồng, không float. DATETIME lưu theo timezone ứng dụng Asia/Ho_Chi_Minh; giao diện và bộ lọc ngày dùng cùng múi giờ. Mốc SQL không đổi sang UTC khi đối chiếu với DATETIME này.
- Thành công cần shipper giữ toàn bộ kiện, không có bàn giao pending/đề nghị thay đổi/hold hàng mở. Lỗi rollback trạng thái, kiện, lượt, tiền, history, audit và xóa file mới.
- Khóa shop → order → task → shipper; unique là lớp bảo vệ DB cuối cùng. Shipper lock bảo vệ token khi thao tác hai vận đơn.

## Kiểm tra kỹ thuật

- Kiểm tra lại mô hình bốn vai trò ngày 05/11/2026 trên MySQL quanlivanchuyen_testing: 86 bài / 678 assertions đạt, gồm 19 bài Sprint 04, chạy bằng php artisan test. Pint và Vite build đạt.
- Migration trên database có dữ liệu và database mới quanlivanchuyen_verify_2026102904; không xóa database ứng dụng.
- php tests/Support/logistics-race.php quanlivanchuyen_verify_2026102904: hai tiến trình tranh khóa phân công, lấy, nhập, xuất, giao, thất bại. Hai token cùng task chỉ một accepted. Replay đúng token cả hai accepted nhưng tổng vẫn một attempt, hai collection, một event giao.
- php vendor/bin/pint --test; npm run build; php artisan view:cache; git diff --check.
- Trình duyệt desktop/375px: tab lấy/giao/hoàn, tìm kiếm, gọi điện, form thành công/thất bại, upload ảnh demo, xác nhận giao, kết quả và sổ thu. Không tràn ngang; bảng rộng cuộn trong khung.

## Câu hỏi bảo vệ

**COD có phải doanh thu?** Không. COD là tiền hàng thu hộ shop; cước/phí hoàn là nghĩa vụ dịch vụ riêng nên phải có loại và dòng nguồn riêng.

**Giao thất bại có thu COD không?** Không. Attempt lưu diễn biến, không tạo collection. Trách nhiệm giữ kiện ở shipper đến khi kho xác nhận nhận lại.

**Transaction đủ chống bấm hai lần chưa?** Chưa. Transaction bảo đảm cùng thành công/rollback; lock tuần tự hóa; unique ngăn trùng; token/hash nhận biết gửi lại đúng yêu cầu.

**Một task giao nhiều lần không?** Không. Một task ghi một kết quả. Sau thất bại cần trả kho, quyết định, task mới để truy vết trách nhiệm.

**Sửa biểu phí có đổi phí hoàn đơn cũ?** Không. Dùng snapshot của đơn; snapshot cũ chưa quy định phí thì miễn, không áp hồi tố.

**Giao đủ nhưng thu thiếu?** Hàng là delivered; tiền là disputed/financial_hold có lý do. Không sửa COD gốc hoặc coi tiền thiếu là đã nộp.

**Đã thu nghĩa là quỹ đã nhận?** Không. Collection là nguồn shipper báo thu. Sprint 06 thêm nộp tiền, người khác xác nhận, phân bổ và đối soát.

**Ảnh có công khai không?** Không. Private storage, kiểm tra quyền mỗi lần xem, private/no-store. Public tracking chỉ có mã/trạng thái/thời gian.

**Giới hạn hiện tại?** Chưa có OTP/chữ ký số người nhận, quét virus ảnh hay GPS ngoại tuyến. Guard bảo vệ ở ứng dụng; SQL trực tiếp vẫn cần quyền DB hạn chế. Đây là đồ án local, chưa phải hệ thống tài chính sản xuất đã kiểm toán.
