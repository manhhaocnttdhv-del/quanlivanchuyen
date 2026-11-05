# Sprint 07: Báo cáo và thống kê

Nhánh `sprint-07-bao-cao-thong-ke`, phạm vi FR-38, FR-39 và FR-41 trong `docs/sprints.json`. Kế thừa Sprint 01–06; các PR phụ thuộc cần review và merge trước khi merge Sprint 07 vào `main`.

Theo [ma trận bốn vai trò](role-matrix.md), Admin xem báo cáo vận hành và tài chính toàn hệ thống; Vận hành (`operations@guiviet.test`) chỉ xem báo cáo vận hành theo kho; Shop chỉ xem dữ liệu của mình. Shipper không có quyền báo cáo. Tệp xuất kiểm tra lại cùng quyền và phạm vi khi tải.

## Chức năng hoàn thành

| FR | Kết quả | Màn hình |
| --- | --- | --- |
| FR-38 | Dashboard 7 ngày, số đơn tạo/gửi/giao/hoàn, lượt thất bại, tồn và quá hạn; nhóm kho/shop/khu vực/shipper | `/tong-quan`, `/bao-cao/operations` |
| FR-39 | COD shipper giữ, đã nộp/chưa đối soát; phí và công nợ; số phải trả/đã trả shop; quỹ thực nhận | `/bao-cao/finance` |
| FR-41 | Excel thật, PDF tiếng Việt, cùng bộ lọc/quyền với màn hình; queue, lịch sử, link tải hết hạn | `/bao-cao/tep-xuat` |

Màn hình hỗ trợ desktop và mobile 375px; bảng rộng cuộn trong khung. Header cố định, sidebar và nội dung cuộn độc lập theo chiều cao cửa sổ như Sprint 06.

## Chuẩn bị và chạy demo

Sau khi đã tạo tài khoản demo và dữ liệu Sprint 01–06:

```shell
composer install
php artisan migrate
php artisan db:seed --class=CatalogSeeder
php artisan db:seed --class=DemoReportSeeder
npm run build
```

`DemoReportSeeder` chỉ chạy local/testing; cần shop đã duyệt, địa chỉ lấy hàng, biểu phí hiệu lực, kho Hà Nội và vị trí A-01 của DemoLogisticsSeeder. Seeder gọi Service tạo/duyệt/lấy/nhập/xuất/giao thật tại từng mốc thời gian giả lập, rồi khôi phục đồng hồ. Không viết lại timestamp của chứng từ gốc. Token cố định giúp chạy lại không trùng hoặc ghi đè thao tác demo.

Seeder in mã vận đơn và ngày tạo/giao để dùng đúng kỳ, kể cả khi chạy lại tháng khác. Lần tạo ngày 30/10/2026: đơn tạo 30/09/2026, giao 01/10/2026, COD 500.000đ, phí do shop chịu; ảnh kho chỉ minh họa. Đơn tạo tháng trước vẫn có trong giao thành công tháng này. Các dữ liệu nộp thiếu, trả từng phần, giao dịch thất bại và nợ phí dùng từ DemoFinanceSeeder.

Mở `http://127.0.0.1:8001/bao-cao`, đăng nhập bằng tài khoản demo hiện có. Worker riêng cho tệp lớn:

```shell
php artisan queue:work reports --queue=reports --tries=1 --timeout=240
```

Worker phải chạy để xử lý tệp trên 500 dòng; tệp nhỏ xử lý ngay. Queue `reports` dùng bảng `jobs`, retry_after 300 giây, job timeout 240 giây, một lần thử. Không cần đổi queue mặc định của project. Khi thay code worker, restart worker đang chạy. Ở local có thể dùng `php artisan schedule:work`; production gọi `schedule:run` mỗi phút. Lịch dọn tệp chạy hằng giờ; lệnh thủ công là `php artisan reports:prune-exports`.

## Kịch bản nghiệm thu

1. Admin mở dashboard, bấm chỉ tiêu để tới báo cáo. Vận hành chỉ thấy báo cáo vận hành trong kho được gán và bị chặn khỏi tài chính nội bộ; Shop chỉ thấy dữ liệu của mình. Shipper không có quyền báo cáo.
2. Chọn ngày giao mà DemoReportSeeder in ra. Chọn “Giao thành công”, tìm mã đơn demo; đổi sang “Đơn tạo trong kỳ” thì mã đó không có. Ngày lọc giao dựa trên lần giao, không dựa ngày tạo vận đơn.
3. Nhóm theo shop, kho, khu vực, shipper; đổi bộ lọc. Hai shop trùng tên vẫn là hai dòng độc lập. Kết quả giao quy cho shipper thực hiện lần giao, kể cả sau đó đơn được phân công lại.
4. Xem tỷ lệ thành công và mẫu số; một lần giao thành công cùng một lần thất bại tương ứng 1/2 = 50%. Hoàn không nằm trong mẫu số lượt giao.
5. Chọn tồn/quá hạn, lưu ý đây là dữ liệu hiện tại. Một đơn nhiều kiện trong kho tính một đơn tồn. Đơn delivered/returned/cancelled/rejected không tính quá hạn.
6. Mở tài chính, chọn “COD và tiền thu”. Với COD 500.000đ nộp 200.000đ, kiểm tra COD shipper giữ 300.000đ và đã nộp 200.000đ. Phí thu hộ riêng không tăng tổng COD.
7. Mở “Phải trả shop”, đối chiếu số đã trả và còn phải trả với đối soát. Giao dịch failed 50.000đ không giảm công nợ; succeeded 100.000đ chỉ giảm 100.000đ. Đối soát đang duyệt lại ngân hàng vẫn giữ nghĩa vụ đã được duyệt.
8. Mở “Phí và công nợ”. Nợ sau đối soát được theo dõi riêng nhưng không cộng thành phí phát sinh lần hai. “Tiền nộp quỹ” phân biệt thực nhận, đã phân bổ và dư chưa phân bổ; shop không được xem sổ này.
9. Mở chứng từ từ bảng chi tiết để đối chiếu khoản gốc và phân bổ. Tổng là toàn bộ dữ liệu lọc, không phải tổng của 25 dòng trang hiện tại.
10. Xuất Excel/PDF với cùng bộ lọc. Excel có sheet tổng hợp và chi tiết đầy đủ; PDF có tên chỉ tiêu, ngày, bộ lọc, bảng và số trang ở chân trang. Tệp dài hơn 500 dòng chuyển sang “Đang chờ”, worker xử lý thành “Sẵn sàng tải”.
11. Tài khoản khác không tải được tệp; bỏ chữ ký URL, đổi phạm vi kho hoặc khóa tài khoản đều bị chặn. Sau 24 giờ không tải được, dọn tệp vẫn giữ dòng lịch sử.

## Đọc đúng ngày và chỉ tiêu

| Chỉ tiêu / sổ | Ngày dùng lọc | Ý nghĩa |
| --- | --- | --- |
| Đơn tạo | orders.created_at | Chỉ các đơn đã được gửi, không tính nháp chưa gửi |
| Đơn gửi duyệt | orders.submitted_at | Tiếp nhận ở đây là shop gửi duyệt, chưa phải nhập kho |
| Giao, hoàn, thất bại | delivery_attempts.attempted_at | Lọc loại giao/hoàn và kết quả; thất bại tính lượt, không đếm đơn duy nhất |
| Tồn hiện tại | Không lọc theo kỳ | Có ít nhất một kiện với holder_type=warehouse |
| Quá hạn hiện tại | Không lọc theo kỳ | Duyệt quá 72 giờ, trạng thái chưa kết thúc |
| COD và tiền thu | delivery_collections.collected_at | Nguồn thu trong kỳ, điều chỉnh/phân bổ/số dư hiện tại |
| Phí và công nợ | receivables.accrued_at | Nghĩa vụ phát sinh trong kỳ, số còn nợ hiện tại |
| Phải trả shop | reconciliations.created_at | Bản được lập trong kỳ, đã duyệt ít nhất một lần |
| Giao dịch chi shop | payments.paid_at | Ngày giao dịch ngân hàng; thành công mới giảm phải trả |
| Tiền nộp quỹ | cash_deposits.received_at | Chỉ phiếu confirmed; tiền thực nhận riêng phần đã phân bổ |

Khoảng ngày là `[đầu ngày từ, đầu ngày sau ngày đến)` theo Việt Nam; tối đa 366 ngày. DATETIME của project hiện ghi theo `config('app.timezone')=Asia/Ho_Chi_Minh`. Không dùng DATE(cột) để lọc nên giữ khả năng sử dụng index. Không tự đổi timezone trên dữ liệu lịch sử.

Đây là báo cáo nguồn phát sinh trong kỳ với trạng thái/số dư hiện tại, **chưa phải báo cáo số dư chốt tại cuối kỳ**. Ví dụ COD thu tháng 9, nộp tháng 10: chọn tháng 9 vẫn thấy số đã nộp cập nhật hiện tại. Hai lần xuất khác thời điểm có thể khác số; tệp ghi thời điểm dựng dữ liệu. Không gọi tổng phí này là doanh thu kế toán đã ghi nhận.

## Công thức tài chính

```text
Thực thu hiệu lực = thu gốc + điều chỉnh đã duyệt có dấu
COD shipper giữ = COD hiệu lực - phân bổ nộp quỹ
COD chưa vào đối soát = COD hiệu lực - phần đang giữ đối soát
COD có thể đối soát = min(COD hiệu lực, đã nộp quỹ) - phần giữ đối soát
Phí còn nợ = phí gốc + điều chỉnh - thu trực tiếp - phần đối soát đã duyệt
Phí khả dụng = phí gốc + điều chỉnh - thu trực tiếp - phần đang giữ đối soát
Còn phải trả shop = nghĩa vụ phải trả - phân bổ thanh toán thành công
Quỹ chưa phân bổ = quỹ thực nhận - tổng phân bổ nộp tiền
Tỷ lệ giao thành công = lượt giao thành công / (thành công + thất bại) * 100%
```

Chưa có lượt giao thì tỷ lệ hiển thị dấu gạch, không giả định 0%. COD và phí tách theo type. Báo cáo COD chỉ cộng category=cod; bảng nhóm vẫn tách từng loại tiền. Phí gốc phát sinh loại trừ reconciliation_debt, tránh tính nợ chuyển tiếp thành nguồn thu mới. Phí thu người nhận nằm trong sổ khoản thu, còn sổ nghĩa vụ phí phản ánh phí shop chịu; hai sổ có đối tượng khác nhau.

Tiền lưu DECIMAL(15,2), nghiệp vụ VND nguyên đồng, đọc DECIMAL bằng chuỗi rồi số nguyên 64 bit. Không cast qua float để cộng tiền. Tệp Excel giữ ô tiền chi tiết dạng số; tổng vượt 15 chữ số lưu text để không mất chính xác của Excel. Tỷ lệ dùng số thực vì không phải tiền.

## Cách đọc code và phân quyền

- `ReportRequest` / `ReportExportRequest`: ngày, ID, kiểu nhóm, định dạng và token; `ReportPolicy`: quyền xem/xuất theo tài khoản hiện tại.
- `ReportScope`: giới hạn đơn/kho/shop trong SQL, danh mục lọc cùng phạm vi; hash phạm vi để vô hiệu tệp cũ khi quyền đổi. Nhân viên cần reports.operations hoặc reports.finance; tài chính cần thêm finance.manage. Admin xem toàn hệ thống, shop chỉ của mình.
- `OperationalReport`: từng query theo chỉ tiêu, nhóm theo ID, EXISTS cho tồn; dùng shipper của sự kiện khi thống kê giao/hoàn.
- `FinancialReport`: tái sử dụng FinanceQueries và phân bổ đã có, thu hẹp thêm quyền báo cáo. Không cập nhật chứng từ/số dư từ báo cáo.
- `ReportService`: cùng transaction đọc cho tổng, nhóm và chi tiết. Tổng SUM ở SQL trước phân trang, tránh N+1 theo vận đơn.
- `ReportExportService` / `GenerateReportExport`: token/hash chống lặp, claim trạng thái trước xử lý, kiểm tra lại quyền trước/sau tạo tệp và lúc tải; audit yêu cầu/sẵn sàng/hết hạn.
- `ReportFileWriter`: PhpSpreadsheet và Dompdf, chuỗi Excel được gán kiểu text chống formula injection; PDF dùng DejaVu Sans, tắt PHP/JS/remote assets.
- `ReportPeriod` / `ReportValue`: dùng chung xử lý ngày và định dạng, giữ số nguyên tiền lớn chính xác.

Sổ ngoài collections không lọc khu vực/shipper vì không có nguồn đơn đồng nhất. Phiếu nộp có thể chứa nhiều shop nên chỉ nhóm/lọc kho, không nhận shop_id; phía server cũng từ chối các tổ hợp sai, không chỉ ẩn ô lọc bằng JavaScript. Quyền download phải đúng owner, signed URL, hạn, tài khoản active và scope hiện tại; admin cũng không tự tải tệp của người khác.

## Xuất tệp và giới hạn

Tệp lưu private ở `storage/app/report-exports`, không đặt public và không cần storage:link. Excel tối đa 10.000 dòng; PDF tối đa 1.500 dòng; vượt giới hạn trả lỗi yêu cầu thu hẹp lọc, không cắt bớt dữ liệu. A4 ngang cho bảng gọn, A3 ngang khi trên 10 cột, lặp tiêu đề và giữ từng dòng không tách trang.

Snapshot dựng khi xử lý, không đóng băng từ lúc bấm xuất. Tệp hết hạn 24 giờ từ yêu cầu; chờ queue quá hạn thì tạo yêu cầu mới. Job lặp không ghi đè tệp ready; lỗi writer dọn tệp dở và chuyển failed. Job bị dừng cưỡng bức có thể chờ worker nhận lại để đánh failed hoặc scheduler dọn khi hết hạn; xem log/worker nếu trạng thái không tiến triển.

Không có bảng tổng hợp vật hóa; chưa benchmark tải production hoặc truy vấn hàng triệu chứng từ. Index mới phục vụ ngày gửi/duyệt theo kho và sự kiện loại/kết quả/ngày; cần EXPLAIN trên dữ liệu đại diện trước tối ưu thêm. Báo cáo không phải sổ kế toán kép hoặc tích hợp ngân hàng.

## Kiểm tra kỹ thuật

```shell
php artisan dev:prepare-test-db
php artisan test
php vendor/bin/pint --test
npm run build
php artisan view:cache
git diff --check
```

22 test báo cáo gồm: đơn tạo trước kỳ, ranh nửa đêm, shipper theo sự kiện, tỷ lệ theo lượt, tồn nhiều kiện, trạng thái kết thúc, shop trùng tên, quyền kho/shop và user nhiều vai trò, tổng khớp sổ, fee/COD riêng, failed/partial payment, duyệt lại ngân hàng, nợ phí không lặp, dư quỹ, xuất mọi trang, chuỗi công thức, biên tiền lớn, replay, queue/retry, thu hồi scope, signed download, hết hạn, lỗi writer/audit và validation. Database kiểm thử riêng, không dùng migrate:fresh trên dữ liệu ứng dụng.

Nghiệm thu còn gồm migrate trên database mới và database demo, seeder chạy hai lần, worker database thật chuyển queued → ready, tải XLSX/PDF qua trình duyệt, kiểm tra bốn vai trò ở 1440px/375px và render PDF nhiều trang để kiểm tra chữ tiếng Việt/cột/số trang.

Kết quả đồng bộ bốn vai trò ngày 05/11/2026: toàn bộ **151 test, 1.151 assertions** đạt; Pint và build đạt. Các kiểm tra nghiệm thu trước đó gồm Blade cache, whitespace, Composer audit, worker thật tạo Excel, seeder không trùng và trình duyệt không lỗi JavaScript/assets hoặc tràn viewport. PDF hai trang giữ đủ cột, tiếng Việt và số trang ở chân trang.

## Câu hỏi bảo vệ

**Vì sao số tạo và số giao trong cùng kỳ khác nhau?** Đây là hai sự kiện độc lập. Đơn tháng trước có thể giao tháng này; ép cả hai theo created_at làm mất kết quả giao.

**Tỷ lệ thành công tính theo đơn hay lượt?** Theo lượt giao thực tế. Giao thất bại hai lần rồi thành công một lần tạo tỷ lệ 1/3 cho kỳ chứa cả ba lượt. Hoàn có loại riêng.

**Vì sao đổi ngày mà tồn kho không đổi?** Tồn là người giữ kiện hiện tại. Muốn tồn tại cuối ngày cũ phải dựng lại toàn bộ chuỗi bàn giao theo thời điểm, chưa thuộc báo cáo này.

**Tại sao COD không phải doanh thu?** Doanh nghiệp thu hộ shop; nộp quỹ chỉ đổi nơi giữ tiền. Cước và phí hoàn là nguồn khác, không cộng vào tổng COD.

**Vì sao phí gốc và tổng công nợ có thể khác?** Công nợ còn chịu tác động thu trực tiếp, khấu trừ đối soát và điều chỉnh; nợ sau bù trừ là nghĩa vụ chuyển tiếp, không là phí phát sinh mới.

**Tại sao không cộng các dòng trang hiện tại?** Phân trang chỉ phục vụ hiển thị. Tổng dùng SUM toàn query đã lọc/quyền, xuất dùng cùng query lấy toàn bộ trong giới hạn.

**Có signed URL thì vì sao còn Policy?** Chữ ký chỉ xác nhận URL chưa bị sửa và còn hạn; không chứng minh người tải còn được xem dữ liệu. Cần kiểm tra owner, tài khoản và phạm vi hiện tại.

**Vì sao dùng queue và giới hạn dòng?** Excel/PDF tiêu tốn RAM và thời gian; queue tránh giữ HTTP lâu. Giới hạn rõ ràng bảo vệ tài nguyên và buộc chia kỳ, không âm thầm cắt dữ liệu.

**Vì sao chưa thêm bảng report tổng hợp?** Dùng nguồn và công thức đã kiểm thử tránh lệch số do đồng bộ hai nơi. Khi đo tải cho thấy cần, thiết kế snapshot/refresh và cơ chế đối chiếu trước khi thêm.
