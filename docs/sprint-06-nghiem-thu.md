# Sprint 06: COD và tài chính

Nhánh: `sprint-06-cod-tai-chinh`. Phạm vi FR-32 đến FR-37 trong `docs/sprints.json`. Nhánh kế thừa Sprint 01–05; PR các sprint phụ thuộc cần được review và merge trước khi merge Sprint 06 vào `main`.

## Phạm vi hoàn thành

| FR | Kết quả | Màn hình |
| --- | --- | --- |
| FR-32 | Sổ thu gốc, điều chỉnh hiệu lực, tiền đã về quỹ, shipper còn giữ; COD/cước/phí hoàn riêng | `/tai-chinh/collections` |
| FR-33 | Shipper đề nghị nộp, người khác xác nhận thực nhận; nộp thiếu, dư chưa phân bổ, phân bổ bổ sung | `/tai-chinh/deposits` |
| FR-34 | Đối soát COD đã về quỹ, phí shop chịu và bồi thường đã duyệt; snapshot ngân hàng | `/tai-chinh/reconciliations` |
| FR-35 | Nháp → gửi duyệt → duyệt/trả lại/từ chối; revision, lịch sử phiên bản và kiểm soát người duyệt | Chi tiết đối soát |
| FR-36 | Ghi kết quả ngân hàng, chứng từ private, thanh toán từng phần; thất bại không giảm số phải trả | Chi tiết đối soát |
| FR-37 | Thu nợ phí, điều chỉnh có sự cố được duyệt, đảo điều chỉnh; giữ khoản gốc và audit | `/tai-chinh/fees`, `/tai-chinh/receipts`, `/tai-chinh/adjustments` |

Đây là ghi nhận chứng từ ngân hàng do kế toán nhập, chưa tích hợp API chuyển tiền. Chỉ kết quả thành công mới tạo phân bổ chi trả.

## Chuẩn bị demo

Sau khi đã seed Sprint 01–05 và có tài khoản demo:

```shell
php artisan migrate
php artisan db:seed --class=DemoFinanceSeeder
php artisan finance:accrue-fees
npm run build
```

`DemoFinanceSeeder` tạo sáu tình huống qua các Service giao nhận thật: chưa nộp, nộp thiếu, đối soát chờ duyệt, đã thanh toán một phần sau một giao dịch thất bại, shop còn nợ phí và điều chỉnh giảm phí chờ duyệt. Mỗi tình huống có token cố định; chạy lại bỏ qua đơn đã có, không ghi đè thao tác demo. Kế toán/CSKH mẫu được gán Kho Hà Nội. Ảnh kho là minh họa cho đồ án.

Đăng nhập tại `http://127.0.0.1:8001/dang-nhap`, mở mục **COD & tài chính**. Tài khoản `{shipper,accountant,admin,shop}@guiviet.test` dùng mật khẩu đã đặt khi seed, không lưu mật khẩu trong repository. Muốn có hai kế toán cùng kho, admin tạo thêm tài khoản và gán vai trò/phạm vi kho.

## Kịch bản nghiệm thu

1. Shipper mở **Sổ tiền thu**, chọn khoản COD 500.000đ chưa nộp. Mở chi tiết để thấy khoản gốc, điều chỉnh, phân bổ quỹ và đối soát liên quan.
2. Shipper lập đề nghị nộp 500.000đ. Kế toán xác nhận thực nhận 200.000đ, phân bổ đúng khoản gốc, nhập mã biên nhận và tải ảnh/PDF. Sổ còn giữ 300.000đ, quỹ có 200.000đ; đề nghị nộp không tự tạo tiền quỹ.
3. Shipper lập phiếu bù 300.000đ; người khác xác nhận. Nếu thực nhận dư, để phần dư chưa phân bổ; sau xác minh có thể phân bổ thêm cho khoản thu cùng shipper/kho. Không tự ghi tăng doanh thu vì có tiền dư.
4. Kế toán lập đối soát đúng shop/kho/kỳ: COD 500.000đ, phí shop chịu theo nguồn phí đã ghi nhận. Chỉ nguồn không tranh chấp và còn khả dụng được chọn. Số phải trả = COD + bồi thường − phí. Biểu phí mẫu hiện tại tính 32.000đ cho đơn 1 kg; màn hình lấy số thật từ vận đơn, không gán cứng 30.000đ.
5. Gửi duyệt. Chính người lập bị chặn khi tự duyệt. Admin hoặc kế toán khác cùng kho duyệt; lịch sử ghi người/thời gian/lý do. Shop xem được bản chờ duyệt và đã duyệt, không xem nháp/nội dung sự cố nội bộ.
6. Ghi một giao dịch thất bại 50.000đ: số đã trả vẫn 0. Ghi thành công 100.000đ: chỉ giảm số còn phải trả 100.000đ. Gửi lại đúng token/nội dung/chứng từ trả giao dịch cũ; đổi nội dung hoặc dùng lại mã ngân hàng bị chặn.
7. Shop đổi ngân hàng: xác minh ngân hàng mới chưa đủ để tiếp tục chi. Người có quyền mở duyệt lại ngân hàng, kế toán cập nhật snapshot rồi gửi, người khác duyệt lại. Nguồn tiền/số tiền đã chốt và số đã trả giữ nguyên; không tạo nợ phí lần hai. Lịch sử lưu mã phiên bản ngân hàng đã dùng.
8. Với đối soát âm, hệ thống tạo **nợ sau đối soát**, không tạo khoản chi âm. Thu phí trực tiếp trên nợ mới; phần phí đã khấu trừ không thể thu lần hai.
9. Mở điều chỉnh giảm phí chờ duyệt. Người lập và người lập sự cố không được tự duyệt. Duyệt chỉ gỡ khóa của sự cố tương ứng; các sự cố khác vẫn khóa. Khoản gốc không thay đổi, tác động nằm trên dòng điều chỉnh.
10. Đảo một điều chỉnh chưa bị dùng vào đối soát/thu tiền. Chứng từ gốc vẫn giữ nguyên, chứng từ đảo ghi tác động ngược và mở lại căn cứ tài chính. Không cho đảo hồi tố làm thấp hơn tiền đã nộp/đối soát.

## Cách đọc code

- `FinanceRequest` và `FinanceFilterRequest`: validation/authorize ở HTTP; `FinanceRules` dùng lại cho Service để CLI/seeder cũng được kiểm tra.
- `FinancePolicy`: quyền theo vai trò, kho, chủ shop, người lập/người duyệt. Query lọc quyền trước phân trang; tệp kiểm tra lại quyền chứng từ cha khi tải.
- `CashDepositService`, `ReconciliationService`, `PaymentService`, `FeeReceiptService`, `AdjustmentService`: mỗi Service theo một quy trình nghiệp vụ. Controller nhận request và trả response.
- `FinanceLedger`: công thức tiền, không có cột balance cập nhật thủ công. `FinanceQueries`: tổng hợp SQL cho danh sách và drill-down; không cộng riêng trang đang xem.
- `ReceivableService`: ghi phí người gửi (`fee_payer=sender`) khi giao/hoàn xong, phí hoàn theo snapshot và cách thu. `finance:accrue-fees` bổ sung dữ liệu cũ, chạy lại không trùng; ghi audit khi phát sinh phí mới.
- `FinancePosting`: transaction, khóa ghi tài chính, khóa shop/order, token/hash, revision, mã giao dịch dùng một lần và audit. Các đoạn quy tắc đặc biệt có comment ngắn ngay tại chỗ.
- `FinanceEvidence`: kiểm tra MIME/dung lượng, lưu private, SHA-256; rollback/replay dọn tệp mới không được dùng.
- Blade tách partial điều hướng, lịch sử, chứng từ và hành động; Bootstrap, jQuery chỉ đổi các trường căn cứ phù hợp loại điều chỉnh. Server kiểm tra độc lập với JavaScript.

## Công thức và giới hạn dữ liệu

```text
Thực thu hiệu lực = khoản thu gốc + điều chỉnh đã duyệt có dấu
Shipper còn giữ = thực thu hiệu lực − phân bổ nộp quỹ
COD khả dụng = min(thực thu hiệu lực, đã nộp quỹ) − nguồn đang giữ đối soát
Phí khả dụng = phí gốc + điều chỉnh phí − đã thu trực tiếp − nguồn giữ đối soát
Phải trả shop = max(0, COD + bồi thường − phí)
Nợ sau đối soát = max(0, phí − COD − bồi thường)
Còn phải trả = phải trả shop − phân bổ thanh toán thành công
```

Tiền lưu `DECIMAL(15,2)` nhưng đồ án chỉ nhận VND nguyên đồng. `Money` dùng số nguyên PHP 64 bit, không dùng float, kiểm tra tổng không vượt 9.999.999.999.999đ. Chấp nhận số thực thu thấp hơn dự kiến phải có căn cứ xác minh; không tạo tiền để bù phần thiếu trên giấy.

Đối soát giữ chỗ nguồn ở `draft`, `submitted`, `returned`, `approved`; chỉ dòng thuộc `line_version` hiện tại có hiệu lực. Cập nhật nháp ghi phiên bản dòng mới, không xóa dòng cũ. Từ chối/hủy nháp giải phóng nguồn; bản từng được duyệt chỉ được duyệt lại ngân hàng, không sửa tiền/nguồn hoặc hủy.

Lưu ý thời gian: cấu hình thực tế hiện tại là `Asia/Ho_Chi_Minh`; các `DATETIME` ghi bằng `now()` theo múi giờ đó. Phần tài chính lọc cùng múi giờ cấu hình và ngày Việt Nam. Mô tả UTC ở tài liệu cũ là định hướng; chưa có migration chuyển đổi toàn bộ dữ liệu lịch sử sang UTC. Không đổi timezone toàn hệ thống trên database có sẵn mà không chuyển dữ liệu.

## Kiểm tra kỹ thuật

```shell
php artisan dev:prepare-test-db
php artisan test
php vendor/bin/pint --test
npm run build
php artisan view:cache
git diff --check
php artisan dev:prepare-test-db --database=quanlivanchuyen_verify_2026102906
php tests/Support/finance-race.php quanlivanchuyen_verify_2026102906
```

Test gồm happy path, phân quyền shop/kho, người lập khác người duyệt, replay, stale revision, nộp thiếu/dư, giữ chỗ nguồn, thanh toán thất bại/từng phần, đổi ngân hàng sau duyệt, thu phí không trùng, xác minh thực thu, đảo điều chỉnh, biên DECIMAL, mốc nửa đêm Việt Nam, audit/file rollback và append-only. Test tích hợp từ giao hàng thật bảo đảm `sender` phát sinh công nợ đúng và không lặp khi replay.

Script race dùng database verify riêng và hai tiến trình PHP thật tranh khóa MySQL: replay đề nghị/xác nhận, chọn cùng nguồn đối soát, duyệt cùng revision, chi vượt phần còn lại và thu vượt nợ phí. Không chạy script trên database ứng dụng. Migration được kiểm tra cả database mới và database demo đang có dữ liệu.

Kiểm tra UI gồm kế toán, shipper, shop ở desktop và mobile 375px; các sổ, chi tiết, form, trường căn cứ điều chỉnh, tài nguyên CSS/JS và tràn ngang trang. Bảng rộng cuộn trong khung.

Kết quả nghiệm thu: toàn bộ **125 test, 979 assertions** đạt; riêng tài chính **21 test, 166 assertions**. Pint, frontend build, Blade cache và kiểm tra whitespace đều đạt. Script tranh chấp hai tiến trình trên MySQL verify đạt; seeder chạy lại không tạo trùng. Trình duyệt kiểm tra ba vai trò ở 1440px/375px không có lỗi JavaScript, tài nguyên hoặc tràn ngang trang. Trên điện thoại, tab tài chính chia ba cột và bảng nguồn trong form chuyển thành các dòng có nhãn để ô nhập tiền luôn nhìn thấy.

## Câu hỏi bảo vệ

**COD có phải doanh thu không?** Không. COD là khoản phải trả shop; cước là nguồn phí riêng. Nộp quỹ chỉ chuyển tiền từ shipper về quỹ, không tạo khoản thu mới.

**Vì sao không cho shipper tự xác nhận nộp?** Đề nghị nộp là lời khai của người giao; xác nhận phải do người khác kiểm đếm. Quyền quản trị không bỏ qua kiểm soát này.

**Vì sao transaction chưa đủ chống trùng?** Transaction giúp dữ liệu cùng thành công hoặc rollback. Row lock tuần tự hóa kiểm tra/ghi, unique ràng buộc khóa nghiệp vụ, token/hash nhận replay, revision chặn trang cũ. Cần phối hợp các lớp.

**Tại sao có một khóa tài chính chung?** Đồ án ít tải; một dòng `business_locks` giúp đoạn ghi tiền ngắn dễ kiểm chứng và tránh tranh chéo giữa các loại chứng từ. Đọc không chờ khóa đó. Khi cần tăng tải, có thể chia khóa theo quỹ/shop sau khi thiết kế và test thứ tự khóa; không bỏ khóa tùy ý.

**Đổi ngân hàng sau khi đã trả một phần xử lý thế nào?** Tạm dừng trả, mở quy trình duyệt lại snapshot ngân hàng; giữ số đã trả và giữ nguyên nguồn/số phải trả. Không sửa chứng từ thanh toán cũ.

**Vì sao đối soát âm tạo nợ mới?** Phần phí gốc đã dùng vào đối soát, còn lại là nghĩa vụ thu sau bù trừ. Thu tiếp nợ mới giúp không thu lại toàn bộ phí gốc và không tạo giao dịch chi âm.

**Điều chỉnh khác sửa dữ liệu thế nào?** Dòng mới giải thích tác động có dấu, tham chiếu gốc, người đề xuất/duyệt, bằng chứng và lịch sử. Số gốc vẫn truy nguyên được. Đảo tạo dòng ngược, không xóa giao dịch cũ.

**Giới hạn hiện tại?** Chưa có API ngân hàng, sổ kế toán kép, hoàn tiền thực tế/reversal khoản chi hoặc tiền quỹ đã ghi nhận, quét virus, lưu trữ ngoài hay báo cáo xuất file. Reversal hiện áp dụng cho adjustment; khoản nộp/thu/chi đã posted giữ bất biến. Các nghiệp vụ bổ sung cần quy trình/chứng từ riêng; không sửa SQL trực tiếp để cân số. Append-only ở Eloquent không thay thế quyền DB hạn chế khi triển khai thật.
