"""Update the project plan and render optional page PNGs for document QA."""

import argparse
import json
import shutil
from pathlib import Path

from docx import Document
from docx.enum.table import WD_CELL_VERTICAL_ALIGNMENT
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Inches, Pt, RGBColor


ROOT = Path(__file__).resolve().parents[1]
PLAN = ROOT / "docs/Ke-hoach-chi-tiet-he-thong-quan-ly-van-chuyen-COD.docx"
MARKER = "14 Kế hoạch thực hiện và nghiệm thu từng sprint"


def paragraphs(document):
    yield from document.paragraphs
    for table in document.tables:
        for row in table.rows:
            for cell in row.cells:
                yield from cell.paragraphs


def text_replace(paragraph, old, new):
    if old not in paragraph.text:
        return
    if any(old in run.text for run in paragraph.runs):
        for run in paragraph.runs:
            run.text = run.text.replace(old, new)
    else:
        text = paragraph.text.replace(old, new)
        paragraph.clear()
        paragraph.add_run(text)


def header_repeat(row):
    properties = row._tr.get_or_add_trPr()
    if properties.find(qn("w:tblHeader")) is None:
        properties.append(OxmlElement("w:tblHeader"))


def table(document, rows):
    result = document.add_table(rows=1, cols=3)
    result.autofit = False
    widths = [0.78, 3.48, 2.78]
    for column, width in zip(result.columns, widths):
        column.width = Inches(width)
    for cell, label in zip(result.rows[0].cells, ["Yêu cầu", "Công việc và đầu ra", "Điều kiện nghiệm thu"]):
        cell.text = label
    for row in rows:
        cells = result.add_row().cells
        for cell, text in zip(cells, [row["fr"], row["work"], row["accept"]]):
            cell.text = text
    for index, row in enumerate(result.rows):
        for cell, width in zip(row.cells, widths):
            cell.width = Inches(width)
            cell.vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.CENTER
            properties = cell._tc.get_or_add_tcPr()
            margins = OxmlElement("w:tcMar")
            for edge in ["top", "bottom", "left", "right"]:
                element = OxmlElement("w:" + edge)
                element.set(qn("w:w"), "70")
                element.set(qn("w:type"), "dxa")
                margins.append(element)
            properties.append(margins)
            shading = OxmlElement("w:shd")
            shading.set(qn("w:fill"), "E7EBEF" if index == 0 else "FFFFFF")
            properties.append(shading)
            borders = OxmlElement("w:tcBorders")
            for edge in ["top", "bottom", "left", "right"]:
                border = OxmlElement("w:" + edge)
                border.set(qn("w:val"), "single")
                border.set(qn("w:sz"), "4")
                border.set(qn("w:color"), "D9D9D9")
                borders.append(border)
            properties.append(borders)
            for paragraph in cell.paragraphs:
                paragraph.paragraph_format.space_after = Pt(2)
                paragraph.paragraph_format.line_spacing = 1.05
                for run in paragraph.runs:
                    run.font.name = "Arial"
                    run.font.size = Pt(8.5)
                    run.font.bold = index == 0
                    run.font.color.rgb = RGBColor(0, 0, 0)
    header_repeat(result.rows[0])
    return result


def author(download_copy=None):
    document = Document(PLAN)
    # Keep the source requirements, replacing only the previous generated appendix.
    remove = False
    for child in list(document._element.body):
        if child.tag == qn("w:sectPr"):
            continue
        text = "".join(child.itertext())
        if MARKER in text:
            remove = True
        if remove:
            document._element.body.remove(child)
    for paragraph in list(paragraphs(document)):
        text_replace(paragraph, "Laravel 10", "Laravel 12")
        text_replace(paragraph, "PHP 8.1", "PHP 8.2")
        text_replace(paragraph, "php artisan pint --test", "php vendor/bin/pint --test")
        text_replace(paragraph, "8 spints", "8 sprint")
        text_replace(paragraph, "Spint 3", "Sprint 3")
        text_replace(paragraph, "spint 3", "Sprint 3")
        if paragraph.text.startswith("Phiên bản Laravel 12 được chọn"):
            paragraph.text = "Laravel 12 chạy với PHP 8.2 đã có trong Laragon. Chọn PHP 8.2 trở lên cho terminal và máy chủ web trước khi chạy Composer hoặc Artisan; Node.js 22.17 được dùng cho Vite 7."
        text_replace(paragraph, "Audit log tạo sau thao tác thành công", "Audit log ghi cùng transaction nghiệp vụ")
        if "order_status_histories" in paragraph.text:
            text_replace(paragraph, "user_roles", "role_user")
        text_replace(paragraph, "user_roles", "role_user")
        text_replace(paragraph, "money_receipt_allocations", "deposit_allocations")
        text_replace(paragraph, "money_receipts", "cash_deposits")
        text_replace(paragraph, "report_queries, export_logs", "Truy vấn bảng nghiệp vụ, export_logs")
    for item in document.tables:
        if item.rows:
            header_repeat(item.rows[0])
    document.add_heading(MARKER, level=1).paragraph_format.page_break_before = True
    document.add_paragraph("Các mục dưới đây là backlog cần triển khai. Nhánh main và tám nhánh sprint hiện chứa code nền, chưa thể hiện chức năng đã hoàn thành. Dự kiến 47 đến 63 ngày công cho một sinh viên, chưa tính thời gian học thêm và phản hồi của giảng viên. Ưu tiên làm P0 trước; P1 triển khai khi luồng chính đã chạy ổn định.")
    document.add_paragraph("Trình tự 01 đến 08 theo phụ thuộc dữ liệu. Ghi audit phải bắt đầu từ sprint 01, collection thu tiền có từ sprint 04; sprint 08 hoàn thiện giao diện audit và thông báo. Trước khi bắt đầu một sprint đã tạo sẵn, merge main sau khi sprint phụ thuộc đã được nghiệm thu.")
    data = json.loads((ROOT / "docs/sprints.json").read_text(encoding="utf-8"))
    for index, sprint in enumerate(data):
        heading = document.add_heading(f"Sprint {sprint['number']} {sprint['name']}", level=2)
        if index:
            heading.paragraph_format.page_break_before = True
        for label, key in [("Nhánh", "branch"), ("Điều kiện bắt đầu", "depends_on"), ("Ước lượng", "estimate"), ("Dữ liệu dự kiến", "tables")]:
            paragraph = document.add_paragraph()
            paragraph.paragraph_format.space_after = Pt(4)
            paragraph.add_run(label + ": ").bold = True
            paragraph.add_run(sprint[key])
        table(document, sprint["tasks"])
        for label, key in [("Kịch bản demo", "demo"), ("Nội dung cần giải thích", "defense")]:
            paragraph = document.add_paragraph()
            paragraph.paragraph_format.space_before = Pt(5)
            paragraph.paragraph_format.space_after = Pt(3)
            paragraph.add_run(label + ": ").bold = True
            paragraph.add_run(sprint[key])
    document.add_heading("15 Ràng buộc dữ liệu và kiểm soát tài chính", level=1).paragraph_format.page_break_before = True
    rules = [
        "Mỗi vận đơn lưu tracking_code unique, shop_id, trạng thái, cân nặng, COD, bên trả phí, snapshot địa chỉ và version biểu phí. Thời gian tạo, duyệt, giao, hoàn và trả tiền là các mốc riêng.",
        "Một vận đơn có nhiều nhiệm vụ theo thời gian và nhiều lần giao; chỉ một người giữ kiện tại một thời điểm. Phân công cần khóa dòng order, kiểm tra nhiệm vụ hiệu lực và xác nhận bàn giao thực tế.",
        "collection gốc sinh khi giao thành công; COD và phí là hai dòng khác loại. unique(delivery_attempt_id, type) và idempotency_key chống ghi trùng khi bấm hai lần.",
        "deposit_allocations liên kết cash_deposits với delivery_collections; tổng phân bổ không vượt tiền thực nhận và phần còn lại của khoản thu. Người thu không tự xác nhận đã nộp.",
        "reconciliation_lines tham chiếu nguồn COD/phí/điều chỉnh bằng khóa ngoại; tiền COD chưa về quỹ hoặc đang tranh chấp không đủ điều kiện. Khóa nguồn khi chọn để không đối soát trùng.",
        "payments có transaction_reference và idempotency_key unique; chỉ chi từ đối soát đã duyệt và không vượt số còn phải trả. creator và approver phải khác nhau.",
        "Không sửa số dư hay xóa chứng từ đã posted. Hủy/đảo nghiệp vụ bằng giao dịch mới có căn cứ, người duyệt, nguồn gốc và audit. Tiền VND xử lý bằng integer đơn vị đồng hoặc decimal string/BCMath, không dùng float.",
        "Tiền shipper giữ bằng khoản thực thu trừ phần nộp đã xác nhận. Tiền trả shop bằng COD đủ điều kiện trừ phí shop chịu cộng điều chỉnh. Kết quả âm là nợ phí, không phải khoản chi âm.",
        "Transaction bao trọn chứng từ, phân bổ, trạng thái và audit bắt buộc. Notification/queue phát sau commit; rollback không được phát thông báo thành công.",
        "Index bắt đầu từ query thực tế: orders(shop_id,status,created_at), delivery_tasks(shipper_id,status,scheduled_at), warehouse_items(warehouse_id,status,location_id), audit_logs(actor_id,event,created_at). Dùng EXPLAIN với dữ liệu mẫu lớn; tránh thêm index đơn status dư thừa.",
        "Khóa ngoại RESTRICT cho tài chính/lịch sử. Cascade chỉ dùng cho dữ liệu con phụ thuộc thực sự. Attachment có kiểm tra MIME/kích thước, lưu private và tải qua quyền truy cập.",
        "Các bảng trong kế hoạch là thiết kế dự kiến; migration trong từng sprint là schema thực tế. Đối chiếu docs/database-design.md và docs/sprints.json khi mở rộng để giữ tên bảng/quan hệ nhất quán.",
    ]
    for rule in rules:
        paragraph = document.add_paragraph(rule)
        paragraph.paragraph_format.space_after = Pt(7)
    for paragraph in document.paragraphs:
        if paragraph.style.name == "Title" or paragraph.style.name.startswith("Heading"):
            for run in paragraph.runs:
                run.font.color.rgb = RGBColor(0, 0, 0)
    document.save(PLAN)
    if download_copy:
        shutil.copyfile(PLAN, download_copy)
    print(PLAN)


def render(pdf, output):
    import fitz

    output.mkdir(parents=True, exist_ok=True)
    with fitz.open(pdf) as document:
        for index, page in enumerate(document):
            page.get_pixmap(matrix=fitz.Matrix(1.5, 1.5)).save(output / f"page-{index + 1:02d}.png")
        print(f"Rendered {len(document)} pages")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--download-copy", type=Path)
    parser.add_argument("--render-pdf", type=Path)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()
    if args.render_pdf:
        render(args.render_pdf, args.output)
    else:
        author(args.download_copy)
