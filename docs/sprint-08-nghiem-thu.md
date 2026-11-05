# Sprint 08: Nhật ký và thông báo

Nhánh `sprint-08-nhat-ky-thong-bao`, phạm vi FR-40.1 đến FR-40.5 trong `docs/sprints.json`. Kế thừa Sprint 01–07 tại commit `5e766af`; review/merge các PR phụ thuộc trước khi merge Sprint 08 vào `main`.

Phân quyền theo [ma trận bốn vai trò](role-matrix.md): chỉ Admin xem nhật ký; Admin, Vận hành, Shipper và Shop nhận thông báo đúng quyền hiện tại. Dùng `operations@guiviet.test` cho công việc điều phối/kho/CSKH. Thông báo tài chính dành cho Admin và chủ shop liên quan; Vận hành không nhận thông báo đối soát.

## Chức năng hoàn thành

| FR | Kết quả | Màn hình / thành phần |
| --- | --- | --- |
| FR-40.1 | Actor, thời gian, event, đối tượng, kết quả thành công, lý do và request ID; rollback không để lại audit thành công | AuditService, AssignRequestId |
| FR-40.2 | Before/after theo allowlist đệ quy; che credential và số tài khoản; model không cho sửa/xóa | AuditData, AuditLog |
| FR-40.3 | Lọc người dùng/ngày/event/đối tượng/request, tìm mã vận đơn; phân trang, chi tiết trước/sau và liên kết hồ sơ có kiểm tra quyền | `/quan-tri/nhat-ky` |
| FR-40.4 | Đơn mới, duyệt đơn, phân công, giao/hoàn thất bại, sự cố và đối soát; queue sau commit, outbox phục hồi, retry không trùng | NotificationService, DeliverBusinessNotification |
| FR-40.5 | Hộp thư cá nhân, nhóm nghiệp vụ, tất cả/đã đọc/chưa đọc, số chưa đọc, đọc từng dòng/tất cả; mở link kiểm tra quyền hiện tại | `/thong-bao`, chuông trên header |

Giữ header cố định và chiều cao khung theo cửa sổ; sidebar/nội dung cuộn độc lập. Bảng nhật ký rộng cuộn trong khung, hộp thông báo hỗ trợ mobile 375px. Số chưa đọc cập nhật mỗi 30 giây khi tab hiển thị; chưa dùng WebSocket hoặc push của trình duyệt.

## Chuẩn bị demo

Sau khi đã tạo tài khoản, shop, địa chỉ lấy hàng, kho/phạm vi shipper và biểu phí bằng các seeder Sprint 01–07:

```shell
php artisan migrate
php artisan db:seed --class=DemoNotificationSeeder
npm run build
```

Seeder chỉ chạy local/testing, gọi CatalogSeeder để cập nhật quyền và tạo hai vận đơn qua OrderService/DispatchService: một đơn chờ duyệt, một đơn đã duyệt và phân công lấy hàng. Token cố định giúp chạy lại không tạo trùng, không ghi đè thao tác demo đã có. Seeder in mã đơn để tra cứu; không tạo thông báo giả hoặc phát lại toàn bộ audit cũ.

Chạy worker riêng ở terminal thứ hai:

```shell
php artisan queue:work notifications --queue=notifications --tries=3 --timeout=60
```

Connection `notifications` dùng database/jobs, queue `notifications`, retry_after 120 giây. Job thử tối đa 3 lần, backoff 10/30/60 giây, timeout 60 giây. Worker báo cáo vẫn chạy bằng connection/queue `reports` riêng, không cần đổi QUEUE_CONNECTION. Khi cập nhật code, restart worker để nhận code mới.

Chạy lịch local ở terminal thứ ba, nếu chưa có scheduler đang chạy:

```shell
php artisan schedule:work
```

Production gọi `php artisan schedule:run` mỗi phút. Scheduler chạy `notifications:dispatch-pending` mỗi 5 phút, withoutOverlapping; có thể gọi thủ công khi kiểm tra. Lệnh nhận outbox chưa enqueue hoặc claim đã quá 10 phút mà chưa delivered. Khi queue phục hồi, worker có thể nhận job lặp nhưng không tạo thông báo trùng. Xem `php artisan queue:failed` và application log khi có lỗi kéo dài; chỉ retry failed job sau khi đã xử lý nguyên nhân. Outbox vẫn là căn cứ phục hồi độc lập với dòng failed_jobs.

## Kịch bản nghiệm thu

1. Đăng nhập Vận hành (`operations@guiviet.test`) hoặc Admin tại `/dang-nhap`. Chạy seeder và worker; hộp `/thong-bao` có đơn mới chờ duyệt. Vận hành kho khác không nhận được đơn ngoài phạm vi.
2. Admin mở `/quan-tri/nhat-ky`, tìm đúng mã vận đơn seeder in ra. Mở event `order.reviewed` để xem status/revision trước và sau; bấm “Cùng đối tượng” để đọc chuỗi lịch sử. Bấm request ID để xem các audit cùng request.
3. Với đơn phân công, shipper vào hộp thư và mở nhiệm vụ lấy hàng. Shop có thông báo kết quả duyệt; người thực hiện thao tác không nhận lại thông báo của chính mình.
4. Dùng nút đánh dấu đã đọc, kiểm tra số chuông giảm; đánh dấu chưa đọc để tăng lại. Chọn nhóm, tab đã/chưa đọc và chuyển trang. “Đọc tất cả” chỉ tác động hộp thư tài khoản hiện tại.
5. Bấm tiêu đề thông báo để tới hồ sơ. Việc mở dùng GET, không tự đánh dấu đã đọc; đổi trạng thái đọc là thao tác PATCH có CSRF. Sau khi thu hồi phạm vi kho, link cũ trả về hộp thư kèm lỗi quyền, không mở được hồ sơ.
6. Ghi một kết quả giao thất bại bằng quy trình Sprint 04: shop và điều phối đúng kho nhận tiêu đề chung, không có lý do/bằng chứng nội bộ trong payload. Giao thành công không phát thông báo thất bại.
7. Mở sự cố theo Sprint 05: nhân viên xử lý/duyệt đúng kho nhận thông báo; shop không nhận nội dung sự cố nội bộ. Gửi đối soát theo Sprint 06: người duyệt và shop nhận thông báo, người lập không tự nhận hoặc tự duyệt.
8. Dùng test tự động cho tình huống khó demo an toàn: transaction rollback, queue không khả dụng, outbox ghi lỗi, hai worker cùng xử lý, tài khoản bị khóa và sửa UUID thông báo người khác.

## Phạm vi audit

Audit writer đã được gọi trong các luồng nghiệp vụ từ những sprint trước; Sprint 08 hoàn thiện context, tra cứu và đối chiếu. Không dùng observer lưu mọi thuộc tính model vì dễ đưa credential/snapshot nhạy cảm vào log.

| Module | Ví dụ event được ghi | Căn cứ |
| --- | --- | --- |
| Tài khoản | auth.logged_in, auth.logged_out, user.saved, profile.updated, password.changed | Đăng nhập/đăng xuất thành công, quyền/kho trước-sau; không ghi mật khẩu |
| Shop/danh mục | shop.registered/updated/reviewed, bank.replaced/verified, pickup.saved, area.saved, warehouse.saved, fee.saved/published, location.created | Trạng thái/danh mục; ngân hàng chỉ tên, version và last_four |
| Vận đơn | order.created/updated, order.submitted/reviewed/cancelled, order.change_requested/change_reviewed/change_applied/change_rejected | Timeline, revision, trạng thái và thay đổi được phép |
| Điều phối/kho | logistics.task_assigned/task_recalled/pickup_confirmed/pickup_failed/handover_prepared/handover_confirmed, stocktake.*, discrepancy.resolved | Người giữ kiện, task ID, shipper, kiểm kê và xử lý sai lệch |
| Giao/hoàn | delivery.attempt_recorded, delivery.collection_recorded, return.recorded, logistics.* | Lượt thực tế, kết quả và khoản COD/phí tách riêng |
| Sự cố/khiếu nại | incident.*, complaint.*, logistics.incident_opened/incident_updated | Thay đổi hồ sơ/khóa; không sao chép tệp bằng chứng vào audit |
| Tài chính | finance.deposit_*, finance.reconciliation_*, finance.payment_*, finance.adjustment_*, finance.fee_received/fee_accrued/collection_hold_reviewed | Chứng từ và finance_events cùng transaction; before lấy payload sự kiện trước |
| Báo cáo | report.export_requested/export_ready/export_expired | Yêu cầu xuất, tệp sẵn sàng và hết hạn |

HTTP request dùng UUID do server tạo; không tin X-Request-ID do client gửi. Response trả X-Request-ID, các audit liên quan dùng cùng mã. CLI dùng mã chung cho một lần chạy process; không coi đó là mã riêng của từng vận đơn hoặc từng job trong worker sống lâu. Actor null biểu thị hệ thống/lệnh chạy nền.

`result=succeeded` chỉ ghi cho nghiệp vụ thành công. Validation bị từ chối, đăng nhập sai hoặc transaction rollback không tạo audit thành công; đây chưa phải hệ thống security log cho mọi request thất bại. Log cũ có thể thiếu request ID hoặc một phần before vì thời điểm đó chưa ghi; không sửa lịch sử để giả lập dữ liệu chưa tồn tại.

Audit tổng hợp theo module khác timeline của từng hồ sơ. Các trạng thái vận đơn, kiện và chứng từ vẫn có bảng lịch sử nghiệp vụ riêng. Chỉ admin đang active và có audit.view được xem nhật ký toàn hệ thống; cấp riêng audit.view cho nhân viên thường vẫn không mở được. Link hồ sơ dùng allowlist model và Policy hiện tại; một số danh mục không có màn hình chi tiết riêng nên chỉ hiển thị tham chiếu, không có nút mở.

## Dữ liệu trước và sau

AuditData lọc key theo allowlist ở mọi cấp, kể cả changes/items/rules/filters; không lưu password, token, session, account_number, bank_snapshot hoặc đường dẫn private. Các trường tiền giữ chuỗi DECIMAL chính xác, không chuyển float hoặc che nhầm số tiền lớn thành số tài khoản. Đổi quyền ghi cả role_ids/warehouse_ids trước-sau; finance event mới bổ sung trạng thái, revision, kỳ và tổng tiền để các lần sau đối chiếu được.

Lý do và trường văn bản được lọc dạng `password=...`, `token:...` và chuỗi số dài có thể là tài khoản/điện thoại. Đây là quy tắc cú pháp, chưa phát hiện mọi bí mật bị dán tùy ý vào câu tự do; người nhập vẫn phải dùng đúng trường nghiệp vụ. Khi xem log cũ áp dụng lại sanitizer và Blade escape nội dung, không tự rewrite dữ liệu cũ.

AuditLog chặn update/delete qua Eloquent, không có route sửa/xóa nhật ký. Nội dung/người nhận thông báo cũng bất biến, chỉ read_at được cập nhật qua chức năng nghiệp vụ. Query SQL trực tiếp có thể vượt model; quyền DB, backup và chính sách vận hành vẫn là trách nhiệm triển khai. Sprint này chưa xây kho log chống sửa ở cấp hạ tầng.

## Luồng thông báo và chống trùng

```text
Transaction nghiệp vụ
  -> thay đổi hồ sơ + lịch sử nghiệp vụ
  -> audit_logs + notification_dispatches (cùng transaction)
Commit ngoài cùng
  -> enqueue job notifications
Worker
  -> khóa outbox -> chọn người nhận hiện tại -> kiểm tra Policy
  -> ghi notifications -> đánh delivered_at (cùng transaction)
Người dùng mở thông báo
  -> kiểm tra owner -> tìm target trong allowlist -> kiểm tra lại Policy -> redirect
```

Không gửi trước commit vì job có thể chạy khi dữ liệu chưa có hoặc nghiệp vụ sau đó rollback. Chỉ afterCommit chưa đủ: process có thể dừng sau commit trước enqueue. Outbox bền vững giữ lại sự kiện để scheduler khôi phục. Queue enqueue lỗi không xóa nghiệp vụ đã commit; ghi warning với dispatch ID và tên exception, không ghi payload nhạy cảm.

Khóa outbox tuần tự hóa hai worker; delivered_at giúp job xử lý lại thoát sớm. Unique người nhận/audit bảo vệ thêm tại DB. Ghi người nhận và delivered trong một transaction nên lỗi giữa chừng rollback toàn bộ fan-out, retry không bỏ sót nửa danh sách. Đây là xử lý job ít nhất một lần với hiệu ứng ghi hộp thư không trùng, không cam kết email/SMS exactly-once.

## Ma trận người nhận

| Sự kiện | Người nhận dự kiến |
| --- | --- |
| order.submitted | Người có orders.review ở kho phụ trách; admin nếu có quyền |
| order.reviewed | Chủ shop của đơn; tiêu đề phản ánh trạng thái xét duyệt |
| logistics.task_assigned | Đúng shipper của task ID đã ghi trong audit |
| delivery.attempt_recorded, outcome=failed | Người duyệt đơn đúng kho và chủ shop |
| incident.created | Người có incidents.manage hoặc incidents.approve ở kho phụ trách |
| finance.reconciliation_submit | Người có finance.approve đúng kho và chủ shop |
| finance.reconciliation_approve | Người lập đối soát và chủ shop |
| finance.reconciliation_return/reject | Người lập đối soát |

Tất cả còn phải active, khác actor và có Policy xem hồ sơ tại thời điểm worker chạy. Người dùng nhiều vai trò vẫn nhận tối đa một dòng. Không gửi sự cố nội bộ cho shop, không mang lý do/số tiền/ngân hàng trong notification data. Chỉ thông báo trong ứng dụng; không gửi email/SMS hoặc tự chuyển tiền.

Link được dựng lại từ target_type/target_id, không nhận URL từ người dùng. Thu hồi quyền không xóa tham chiếu chung đã nhận, nhưng chặn mở hồ sơ; số chưa đọc vẫn đếm thông báo cá nhân được lưu. Quyền được xét khi xử lý nên người được gán quyền sau thời điểm event có thể nhận nếu job còn chờ; đây không phải snapshot người nhận tại thời điểm thao tác. Không phát bù audit trước khi triển khai.

## Kiểm tra kỹ thuật

```shell
php artisan dev:prepare-test-db
php artisan test
php vendor/bin/pint --test
npm run build
php artisan view:cache
git diff --check
```

Test dùng database MySQL kiểm thử riêng, không migrate:fresh database ứng dụng. Riêng `AuditNotificationTest` có 16 test, 98 assertions: lọc và biên ngày Việt Nam; admin/owner/scope; credential lồng nhau và tiền lớn; UUID tương quan; rollback; enqueue sau commit; retry; task thật; khóa tài khoản/thu hồi quyền; sự cố/giao thất bại/đối soát; phân trang/đọc hàng loạt; queue lỗi và outbox lỗi; model bất biến. Vận hành không đọc được audit dù được gán thêm audit.view, không nhận thông báo tài chính; hai Admin khác nhau nhận đúng thông báo lập/duyệt.

Kiểm tra đồng thời dùng database verify riêng đã migrate:

```shell
php tests/Support/notification-race.php quanlivanchuyen_verify_2026103008
```

Script chỉ chấp nhận tên `quanlivanchuyen_verify_` kèm chữ số, tạo fixture riêng và chạy hai PHP process cùng delivery một outbox trên MySQL thật. Kiểm tra rollback không phát job và commit có job, kết quả một thông báo cho mỗi người nhận. Chạy lại được; không dùng tên database ứng dụng hoặc testing đang chạy PHPUnit.

Kết quả đồng bộ bốn vai trò ngày 05/11/2026: toàn bộ **171 test, 1.289 assertions** đạt; riêng Sprint 08 **16 test, 98 assertions**. Pint, Vite build, Blade cache và whitespace đạt. Test chạy chuỗi seeder đến DemoNotificationSeeder với bốn vai trò mới. Các kiểm tra nghiệm thu trước đó gồm migrate database mới/demo, seeder không trùng, worker thật và hai process cạnh tranh trên MySQL verify; trình duyệt ở 1440px/375px xác nhận hộp thư, số đếm, đọc/chưa đọc, bộ lọc, link hồ sơ, chi tiết audit và header cố định hoạt động, không lỗi JS/assets hoặc tràn viewport.

## Câu hỏi bảo vệ

**Audit khác timeline thế nào?** Timeline trả lời chuyện gì xảy ra với một hồ sơ; audit đối chiếu ai thay đổi gì xuyên module, với cùng request ID và dữ liệu trước/sau đã lọc. Hai nguồn có mục tiêu riêng nhưng cùng transaction.

**Vì sao không log toàn bộ request hoặc model?** Request/model có thể chứa mật khẩu, token, tài khoản ngân hàng và tệp private. Chỉ chọn các trường phục vụ truy vết, lọc tiếp ở mọi cấp thay vì lưu mọi thứ rồi chỉ che trên giao diện.

**Vì sao không gửi thông báo ngay trong transaction?** Worker có thể đọc quá sớm hoặc thông báo thao tác đã rollback. Ghi outbox cùng nghiệp vụ, rồi enqueue sau commit mới bảo đảm có căn cứ hợp lệ.

**Vì sao afterCommit vẫn cần outbox?** Có khoảng thời gian process có thể dừng sau commit trước enqueue. Outbox còn trong DB để scheduler phục hồi, không mất sự kiện chỉ vì web process dừng.

**Vì sao retry không tạo trùng?** Job khóa một dòng outbox, kiểm tra delivered, ghi người nhận và delivered trong cùng transaction; unique người nhận/audit là lớp bảo vệ DB. Retry không đồng nghĩa tạo event mới.

**Vì sao notification chỉ có mã tham chiếu?** Quyền có thể bị thu hồi sau lúc gửi. Payload chung giảm dữ liệu bị giữ lại; link luôn kiểm tra quyền hiện tại trước khi mở hồ sơ đầy đủ.

**Vì sao không cho sửa/xóa nhật ký?** Nhật ký là căn cứ kiểm tra. Sửa nghiệp vụ bằng quy trình/chứng từ điều chỉnh tạo lịch sử mới; không xóa căn cứ cũ. Model bảo vệ luồng ứng dụng nhưng không thay thế giới hạn quyền DB.

**Các index đã tối ưu tuyệt đối chưa?** Chưa benchmark production. Index theo actor/event/subject/time phục vụ bộ lọc chính; không tạo index cho mọi trường JSON. Ở tải lớn cần EXPLAIN, dữ liệu đại diện, chính sách lưu trữ và đo thời gian job trước khi thay đổi.
