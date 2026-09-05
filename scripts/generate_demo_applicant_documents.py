from __future__ import annotations

import json
import zipfile
from pathlib import Path

from pypdf import PdfReader
from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import mm
from reportlab.pdfbase.pdfmetrics import stringWidth
from reportlab.pdfgen import canvas
from reportlab.platypus import Paragraph, Table, TableStyle


ROOT = Path(__file__).resolve().parents[1]
OUTPUT_DIR = ROOT / "output" / "demo_applicant_uploads"
ZIP_PATH = ROOT / "output" / "Alex_M_Santos_Demo_Upload_Pack.zip"

PAGE_WIDTH, PAGE_HEIGHT = A4
NAVY = colors.HexColor("#08152C")
SLATE = colors.HexColor("#40516D")
MUTED = colors.HexColor("#6B7A91")
GOLD = colors.HexColor("#E6A700")
PALE_GOLD = colors.HexColor("#FFF6D8")
PALE_BLUE = colors.HexColor("#F3F7FB")
LINE = colors.HexColor("#D7E0EA")
WHITE = colors.white
BLACK = colors.HexColor("#111827")

STUDENT = {
    "name": "Alex M. Santos",
    "email": "student@scholarship.test",
    "contact": "09173456789",
    "birthdate": "September 15, 2008",
    "address": "Barangay Commonwealth, Quezon City, Metro Manila",
    "school": "Commonwealth Learning Center",
    "level": "Senior High School",
    "year": "Grade 12",
    "track": "STEM",
    "school_year": "2026-2027",
    "guardian": "Andrea Santos",
    "learner_number": "SAMPLE-2026-0001",
}

BODY = ParagraphStyle(
    "Body",
    fontName="Helvetica",
    fontSize=10.5,
    leading=16,
    textColor=SLATE,
    alignment=TA_LEFT,
)
BODY_CENTER = ParagraphStyle(
    "BodyCenter",
    parent=BODY,
    alignment=TA_CENTER,
)
SMALL = ParagraphStyle(
    "Small",
    parent=BODY,
    fontSize=8.5,
    leading=12,
)


def safe_text(value: object) -> str:
    return str(value).replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


def draw_wrapped(
    pdf: canvas.Canvas,
    text: str,
    x: float,
    top_y: float,
    width: float,
    style: ParagraphStyle = BODY,
) -> float:
    paragraph = Paragraph(safe_text(text), style)
    _, height = paragraph.wrap(width, PAGE_HEIGHT)
    paragraph.drawOn(pdf, x, top_y - height)
    return top_y - height


def draw_header(
    pdf: canvas.Canvas,
    title: str,
    document_kind: str,
    issuer: str,
    document_number: str,
) -> float:
    pdf.setTitle(title)
    pdf.setAuthor("Scholarship Finder Demo Data Generator")
    pdf.setSubject("Synthetic upload file for scholarship portal testing")

    pdf.setFillColor(colors.HexColor("#F8FAFC"))
    pdf.rect(0, 0, PAGE_WIDTH, PAGE_HEIGHT, stroke=0, fill=1)

    pdf.saveState()
    pdf.translate(PAGE_WIDTH / 2, PAGE_HEIGHT / 2)
    pdf.rotate(35)
    pdf.setFillColor(colors.HexColor("#E8EDF3"))
    pdf.setFont("Helvetica-Bold", 48)
    pdf.drawCentredString(0, 0, "SAMPLE ONLY")
    pdf.restoreState()

    pdf.setFillColor(NAVY)
    pdf.rect(0, PAGE_HEIGHT - 36 * mm, PAGE_WIDTH, 36 * mm, stroke=0, fill=1)
    pdf.setFillColor(GOLD)
    pdf.rect(0, PAGE_HEIGHT - 36 * mm, 8 * mm, 36 * mm, stroke=0, fill=1)

    pdf.setFillColor(PALE_GOLD)
    pdf.setFont("Helvetica-Bold", 8)
    pdf.drawString(17 * mm, PAGE_HEIGHT - 13 * mm, issuer.upper())
    pdf.setFillColor(WHITE)
    pdf.setFont("Helvetica-Bold", 20)
    pdf.drawString(17 * mm, PAGE_HEIGHT - 23 * mm, title)
    pdf.setFont("Helvetica", 8)
    pdf.setFillColor(colors.HexColor("#C7D2E4"))
    pdf.drawString(17 * mm, PAGE_HEIGHT - 30 * mm, document_kind.upper())
    pdf.drawRightString(PAGE_WIDTH - 16 * mm, PAGE_HEIGHT - 30 * mm, document_number)

    banner_y = PAGE_HEIGHT - 49 * mm
    pdf.setFillColor(PALE_GOLD)
    pdf.roundRect(16 * mm, banner_y, PAGE_WIDTH - 32 * mm, 8 * mm, 2 * mm, stroke=0, fill=1)
    pdf.setFillColor(colors.HexColor("#815B00"))
    pdf.setFont("Helvetica-Bold", 8)
    pdf.drawCentredString(PAGE_WIDTH / 2, banner_y + 2.8 * mm, "FOR DEMONSTRATION ONLY - NOT A VALID OFFICIAL DOCUMENT")

    return banner_y - 9 * mm


def draw_footer(pdf: canvas.Canvas, document_number: str) -> None:
    pdf.setStrokeColor(LINE)
    pdf.line(16 * mm, 15 * mm, PAGE_WIDTH - 16 * mm, 15 * mm)
    pdf.setFont("Helvetica", 7.5)
    pdf.setFillColor(MUTED)
    pdf.drawString(16 * mm, 10 * mm, "Synthetic test file for the Scholarship Finder platform")
    pdf.drawRightString(PAGE_WIDTH - 16 * mm, 10 * mm, document_number)
    pdf.save()


def section_title(pdf: canvas.Canvas, title: str, y: float) -> float:
    pdf.setFillColor(GOLD)
    pdf.roundRect(16 * mm, y - 4.5 * mm, 3 * mm, 3 * mm, 1 * mm, stroke=0, fill=1)
    pdf.setFillColor(NAVY)
    pdf.setFont("Helvetica-Bold", 10)
    pdf.drawString(22 * mm, y - 3.8 * mm, title.upper())
    return y - 10 * mm


def info_grid(pdf: canvas.Canvas, rows: list[list[tuple[str, str]]], y: float) -> float:
    table_data: list[list[Paragraph]] = []
    for row in rows:
        table_row = []
        for label, value in row:
            html = (
                f'<font size="7" color="#6B7A91"><b>{safe_text(label.upper())}</b></font><br/>'
                f'<font size="10" color="#111827"><b>{safe_text(value)}</b></font>'
            )
            table_row.append(Paragraph(html, SMALL))
        table_data.append(table_row)

    columns = len(rows[0]) if rows else 1
    available_width = PAGE_WIDTH - 32 * mm
    table = Table(table_data, colWidths=[available_width / columns] * columns, hAlign="LEFT")
    table.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), WHITE),
        ("BOX", (0, 0), (-1, -1), 0.6, LINE),
        ("INNERGRID", (0, 0), (-1, -1), 0.6, LINE),
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
        ("LEFTPADDING", (0, 0), (-1, -1), 10),
        ("RIGHTPADDING", (0, 0), (-1, -1), 10),
        ("TOPPADDING", (0, 0), (-1, -1), 8),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 8),
    ]))
    _, height = table.wrap(available_width, PAGE_HEIGHT)
    table.drawOn(pdf, 16 * mm, y - height)
    return y - height - 7 * mm


def data_table(
    pdf: canvas.Canvas,
    headers: list[str],
    rows: list[list[str]],
    y: float,
    column_widths: list[float] | None = None,
) -> float:
    available_width = PAGE_WIDTH - 32 * mm
    if column_widths is None:
        column_widths = [available_width / len(headers)] * len(headers)

    table_data = [[Paragraph(f"<b>{safe_text(value)}</b>", SMALL) for value in headers]]
    table_data.extend([[Paragraph(safe_text(value), SMALL) for value in row] for row in rows])
    table = Table(table_data, colWidths=column_widths, hAlign="LEFT", repeatRows=1)
    table.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), NAVY),
        ("TEXTCOLOR", (0, 0), (-1, 0), WHITE),
        ("BACKGROUND", (0, 1), (-1, -1), WHITE),
        ("ROWBACKGROUNDS", (0, 1), (-1, -1), [WHITE, PALE_BLUE]),
        ("BOX", (0, 0), (-1, -1), 0.6, LINE),
        ("INNERGRID", (0, 0), (-1, -1), 0.6, LINE),
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
        ("ALIGN", (1, 0), (-1, -1), "CENTER"),
        ("LEFTPADDING", (0, 0), (-1, -1), 8),
        ("RIGHTPADDING", (0, 0), (-1, -1), 8),
        ("TOPPADDING", (0, 0), (-1, -1), 7),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 7),
    ]))
    _, height = table.wrap(available_width, PAGE_HEIGHT)
    table.drawOn(pdf, 16 * mm, y - height)
    return y - height - 7 * mm


def status_box(pdf: canvas.Canvas, label: str, value: str, y: float) -> float:
    height = 17 * mm
    pdf.setFillColor(PALE_GOLD)
    pdf.setStrokeColor(colors.HexColor("#E8C85E"))
    pdf.roundRect(16 * mm, y - height, PAGE_WIDTH - 32 * mm, height, 2 * mm, stroke=1, fill=1)
    pdf.setFillColor(colors.HexColor("#815B00"))
    pdf.setFont("Helvetica-Bold", 8)
    pdf.drawString(22 * mm, y - 6 * mm, label.upper())
    pdf.setFillColor(NAVY)
    pdf.setFont("Helvetica-Bold", 15)
    pdf.drawString(22 * mm, y - 12.5 * mm, value)
    return y - height - 7 * mm


def signature(pdf: canvas.Canvas, name: str, role: str, x: float, y: float, width: float = 65 * mm) -> None:
    pdf.setStrokeColor(SLATE)
    pdf.line(x, y, x + width, y)
    pdf.setFillColor(BLACK)
    pdf.setFont("Helvetica-Bold", 9)
    pdf.drawString(x, y - 5 * mm, name)
    pdf.setFillColor(MUTED)
    pdf.setFont("Helvetica", 8)
    pdf.drawString(x, y - 9 * mm, role)


def create_document(path: Path, title: str, kind: str, issuer: str, doc_no: str) -> tuple[canvas.Canvas, float]:
    pdf = canvas.Canvas(str(path), pagesize=A4, pageCompression=1)
    return pdf, draw_header(pdf, title, kind, issuer, doc_no)


def make_report_card(path: Path) -> None:
    doc_no = "DEMO-RC-2026-001"
    pdf, y = create_document(path, "Learner Report Card", "Academic record", STUDENT["school"], doc_no)
    y = info_grid(pdf, [
        [("Learner", STUDENT["name"]), ("Learner number", STUDENT["learner_number"])],
        [("Grade and track", f'{STUDENT["year"]} - {STUDENT["track"]}'), ("School year", STUDENT["school_year"])],
    ], y)
    y = section_title(pdf, "Academic results", y)
    rows = [
        ["Oral Communication", "91", "Passed"],
        ["General Mathematics", "88", "Passed"],
        ["Earth and Life Science", "92", "Passed"],
        ["Physical Education and Health", "89", "Passed"],
        ["Practical Research", "90", "Passed"],
        ["Empowerment Technologies", "90", "Passed"],
    ]
    y = data_table(pdf, ["Learning area", "Final grade", "Result"], rows, y, [100 * mm, 34 * mm, 29 * mm])
    y = status_box(pdf, "Overall academic result", "GENERAL AVERAGE: 90.00", y)
    y = draw_wrapped(pdf, "This sample record is designed to test academic-result extraction and reviewer verification. It is not issued by a real school.", 16 * mm, y, PAGE_WIDTH - 32 * mm)
    signature(pdf, "Lina R. Cruz", "Sample Class Adviser", 16 * mm, 38 * mm)
    signature(pdf, "Marco D. Reyes", "Sample School Registrar", 112 * mm, 38 * mm)
    draw_footer(pdf, doc_no)


def make_enrollment_certificate(path: Path) -> None:
    doc_no = "DEMO-COE-2026-001"
    pdf, y = create_document(path, "Certificate of Enrollment", "School enrollment proof", STUDENT["school"], doc_no)
    y = info_grid(pdf, [
        [("Learner", STUDENT["name"]), ("Learner number", STUDENT["learner_number"])],
        [("Education level", STUDENT["level"]), ("Grade and track", f'{STUDENT["year"]} - {STUDENT["track"]}')],
        [("School year", STUDENT["school_year"]), ("Enrollment status", "Currently enrolled")],
    ], y)
    y = section_title(pdf, "Certification", y)
    y = draw_wrapped(pdf, f'This sample certifies that {STUDENT["name"]} is listed as an enrolled Grade 12 STEM learner for School Year {STUDENT["school_year"]}. It may be used only to test document upload, preview, and pre-screening functions in the Scholarship Finder platform.', 16 * mm, y, PAGE_WIDTH - 32 * mm)
    y -= 8 * mm
    y = info_grid(pdf, [[("Date issued", "September 1, 2026"), ("Purpose", "Scholarship portal demonstration")]], y)
    signature(pdf, "Marco D. Reyes", "Sample School Registrar", 16 * mm, 42 * mm)
    draw_footer(pdf, doc_no)


def draw_avatar(pdf: canvas.Canvas, center_x: float, center_y: float, radius: float) -> None:
    pdf.setFillColor(colors.HexColor("#DCE5EF"))
    pdf.circle(center_x, center_y, radius, stroke=0, fill=1)
    pdf.setFillColor(colors.HexColor("#7690AD"))
    pdf.circle(center_x, center_y + radius * 0.25, radius * 0.28, stroke=0, fill=1)
    pdf.roundRect(center_x - radius * 0.48, center_y - radius * 0.55, radius * 0.96, radius * 0.55, radius * 0.15, stroke=0, fill=1)


def make_id_page(path: Path, title: str, holder: str, relation: str, id_number: str, filename_code: str) -> None:
    doc_no = f"DEMO-{filename_code}-2026-001"
    pdf, y = create_document(path, title, "Identity document placeholder", "Scholarship Finder Demo Registry", doc_no)
    y = draw_wrapped(pdf, "This intentionally generic card is provided only to test an ID upload field. It is not modeled on a real government or school credential.", 16 * mm, y, PAGE_WIDTH - 32 * mm)
    card_x = 24 * mm
    card_y = 88 * mm
    card_w = PAGE_WIDTH - 48 * mm
    card_h = 92 * mm
    pdf.setFillColor(WHITE)
    pdf.setStrokeColor(NAVY)
    pdf.setLineWidth(1.2)
    pdf.roundRect(card_x, card_y, card_w, card_h, 4 * mm, stroke=1, fill=1)
    pdf.setFillColor(NAVY)
    pdf.roundRect(card_x, card_y + card_h - 23 * mm, card_w, 23 * mm, 4 * mm, stroke=0, fill=1)
    pdf.rect(card_x, card_y + card_h - 23 * mm, card_w, 6 * mm, stroke=0, fill=1)
    pdf.setFillColor(PALE_GOLD)
    pdf.setFont("Helvetica-Bold", 8)
    pdf.drawString(card_x + 9 * mm, card_y + card_h - 10 * mm, "SAMPLE IDENTIFICATION CARD")
    pdf.setFillColor(WHITE)
    pdf.setFont("Helvetica-Bold", 14)
    pdf.drawString(card_x + 9 * mm, card_y + card_h - 17 * mm, title)
    draw_avatar(pdf, card_x + 34 * mm, card_y + 36 * mm, 18 * mm)
    pdf.setFillColor(NAVY)
    pdf.setFont("Helvetica-Bold", 16)
    pdf.drawString(card_x + 64 * mm, card_y + 52 * mm, holder)
    pdf.setFillColor(MUTED)
    pdf.setFont("Helvetica-Bold", 8)
    pdf.drawString(card_x + 64 * mm, card_y + 42 * mm, relation.upper())
    pdf.setFillColor(BLACK)
    pdf.setFont("Helvetica", 10)
    pdf.drawString(card_x + 64 * mm, card_y + 32 * mm, f"ID number: {id_number}")
    pdf.drawString(card_x + 64 * mm, card_y + 24 * mm, "Valid for testing through June 2027")
    pdf.setFillColor(colors.HexColor("#B91C1C"))
    pdf.setFont("Helvetica-Bold", 12)
    pdf.drawCentredString(card_x + card_w / 2, card_y + 8 * mm, "NOT VALID - DEMONSTRATION FILE")
    draw_footer(pdf, doc_no)


def make_school_id(path: Path) -> None:
    make_id_page(path, "School ID", STUDENT["name"], f'{STUDENT["year"]} {STUDENT["track"]} learner', STUDENT["learner_number"], "SID")


def make_income_proof(path: Path) -> None:
    doc_no = "DEMO-INC-2026-001"
    pdf, y = create_document(path, "Household Income Statement", "Proof of income", "Sample Community Cooperative", doc_no)
    y = info_grid(pdf, [
        [("Employee or earner", STUDENT["guardian"]), ("Relationship", "Parent or guardian")],
        [("Applicant", STUDENT["name"]), ("Reporting period", "August 2026")],
    ], y)
    y = section_title(pdf, "Income summary", y)
    y = data_table(pdf, ["Income source", "Monthly amount", "Notes"], [
        ["Community cooperative work", "PHP 16,500.00", "Regular earnings"],
        ["Occasional home-based work", "PHP 2,000.00", "Variable estimate"],
        ["Total estimated household income", "PHP 18,500.00", "For demo use only"],
    ], y, [72 * mm, 42 * mm, 49 * mm])
    y = draw_wrapped(pdf, "The amounts above are fictional and exist only to test financial-need document upload and provider review.", 16 * mm, y, PAGE_WIDTH - 32 * mm)
    signature(pdf, "Ramon P. Flores", "Sample Records Officer", 16 * mm, 42 * mm)
    draw_footer(pdf, doc_no)


def make_certificate(path: Path, title: str, kind: str, issuer: str, doc_no: str, body: str, facts: list[list[tuple[str, str]]], signer: str, role: str) -> None:
    pdf, y = create_document(path, title, kind, issuer, doc_no)
    y = info_grid(pdf, facts, y)
    y = section_title(pdf, "Certification", y)
    y = draw_wrapped(pdf, body, 16 * mm, y, PAGE_WIDTH - 32 * mm)
    y -= 8 * mm
    y = draw_wrapped(pdf, "Issued solely as a synthetic file for testing the Scholarship Finder upload and review workflow.", 16 * mm, y, PAGE_WIDTH - 32 * mm)
    signature(pdf, signer, role, 16 * mm, 42 * mm)
    draw_footer(pdf, doc_no)


def make_indigency(path: Path) -> None:
    make_certificate(
        path,
        "Certificate of Indigency",
        "Financial need proof",
        "Sample Barangay Commonwealth Office",
        "DEMO-IND-2026-001",
        f'This sample states that {STUDENT["name"]}, represented by parent or guardian {STUDENT["guardian"]}, resides in Barangay Commonwealth and belongs to a household that may be considered for needs-based educational assistance, subject to the provider\'s independent verification.',
        [[("Applicant", STUDENT["name"]), ("Guardian", STUDENT["guardian"])], [("Address", STUDENT["address"]), ("Date issued", "September 1, 2026")]],
        "Elena M. Dizon",
        "Sample Barangay Records Officer",
    )


def make_birth_certificate(path: Path) -> None:
    doc_no = "DEMO-BIRTH-2008-001"
    pdf, y = create_document(path, "Birth Record Test Placeholder", "Identity record", "Fictional Civil Registry", doc_no)
    y = draw_wrapped(pdf, "This is a deliberately simplified placeholder for testing a birth-certificate upload. It is not a replica of a Philippine Statistics Authority document.", 16 * mm, y, PAGE_WIDTH - 32 * mm)
    y -= 5 * mm
    y = section_title(pdf, "Registrant information", y)
    y = info_grid(pdf, [
        [("Full name", STUDENT["name"]), ("Date of birth", STUDENT["birthdate"])],
        [("Place of birth", "Quezon City, Metro Manila"), ("Citizenship", "Filipino - sample entry")],
        [("Parent or guardian", STUDENT["guardian"]), ("Record reference", doc_no)],
    ], y)
    y = status_box(pdf, "Document status", "TEST PLACEHOLDER - NOT OFFICIAL", y)
    draw_footer(pdf, doc_no)


def make_guardian_id(path: Path) -> None:
    make_id_page(path, "Parent or Guardian ID", STUDENT["guardian"], f'Guardian of {STUDENT["name"]}', "SAMPLE-GDN-001", "GID")


def make_transcript(path: Path) -> None:
    doc_no = "DEMO-TOR-2026-001"
    pdf, y = create_document(path, "Transcript of Records", "Academic record", STUDENT["school"], doc_no)
    y = info_grid(pdf, [[("Learner", STUDENT["name"]), ("Learner number", STUDENT["learner_number"])], [("Program", "Senior High School - STEM"), ("Coverage", "Grade 11 to Grade 12")]], y)
    y = section_title(pdf, "Completed learning areas", y)
    y = data_table(pdf, ["Learning area", "Grade 11", "Grade 12", "Result"], [
        ["English and Communication", "89", "91", "Passed"],
        ["Mathematics", "88", "90", "Passed"],
        ["Science", "91", "92", "Passed"],
        ["Filipino", "90", "90", "Passed"],
        ["Research", "89", "90", "Passed"],
        ["Technology", "90", "87", "Passed"],
    ], y, [82 * mm, 27 * mm, 27 * mm, 27 * mm])
    y = status_box(pdf, "Cumulative result", "GENERAL AVERAGE: 90.00", y)
    signature(pdf, "Marco D. Reyes", "Sample School Registrar", 16 * mm, 38 * mm)
    draw_footer(pdf, doc_no)


def make_good_moral(path: Path) -> None:
    make_certificate(
        path,
        "Certificate of Good Moral Character",
        "School character record",
        STUDENT["school"],
        "DEMO-GMC-2026-001",
        f'This sample states that {STUDENT["name"]}, a Grade 12 STEM learner, has demonstrated satisfactory conduct in the fictional school record used for this portal demonstration. Any real provider must verify an authentic certificate with the issuing school.',
        [[("Learner", STUDENT["name"]), ("Grade and track", "Grade 12 - STEM")], [("School year", STUDENT["school_year"]), ("Date issued", "September 1, 2026")]],
        "Lina R. Cruz",
        "Sample Guidance Coordinator",
    )


def make_residency(path: Path) -> None:
    make_certificate(
        path,
        "Barangay Certificate of Residency",
        "Location proof",
        "Sample Barangay Commonwealth Office",
        "DEMO-RES-2026-001",
        f'This sample states that {STUDENT["name"]} resides at {STUDENT["address"]}. The information is fictional and should be used only to test location-coverage review in the Scholarship Finder platform.',
        [[("Resident", STUDENT["name"]), ("Guardian", STUDENT["guardian"])], [("Address", STUDENT["address"]), ("Date issued", "September 1, 2026")]],
        "Elena M. Dizon",
        "Sample Barangay Records Officer",
    )


def make_government_id(path: Path) -> None:
    make_id_page(path, "Government-issued ID Test File", STUDENT["name"], "Applicant identity placeholder", "SAMPLE-GOV-001", "GOV")


def make_photo(path: Path) -> None:
    doc_no = "DEMO-PHOTO-2026-001"
    pdf, y = create_document(path, "Recent 2x2 ID Photo", "Applicant photo", "Scholarship Finder Demo Studio", doc_no)
    y = draw_wrapped(pdf, "A generic illustrated portrait is used to test photo upload and applicant preview. It does not depict a real person.", 16 * mm, y, PAGE_WIDTH - 32 * mm)
    photo_w = 58 * mm
    photo_h = 58 * mm
    photo_x = (PAGE_WIDTH - photo_w) / 2
    photo_y = 105 * mm
    pdf.setFillColor(WHITE)
    pdf.setStrokeColor(NAVY)
    pdf.setLineWidth(1)
    pdf.rect(photo_x, photo_y, photo_w, photo_h, stroke=1, fill=1)
    draw_avatar(pdf, PAGE_WIDTH / 2, photo_y + photo_h / 2 + 2 * mm, 21 * mm)
    pdf.setFillColor(colors.HexColor("#B91C1C"))
    pdf.setFont("Helvetica-Bold", 8)
    pdf.drawCentredString(PAGE_WIDTH / 2, photo_y + 5 * mm, "SAMPLE PORTRAIT")
    y = photo_y - 12 * mm
    y = info_grid(pdf, [[("Applicant", STUDENT["name"]), ("Captured", "September 1, 2026")]], y)
    draw_footer(pdf, doc_no)


def make_admission_letter(path: Path) -> None:
    doc_no = "DEMO-ADM-2026-001"
    pdf, y = create_document(path, "Admission and Acceptance Letter", "School admission proof", "Commonwealth Polytechnic College", doc_no)
    y = info_grid(pdf, [[("Applicant", STUDENT["name"]), ("Application reference", "CPC-SAMPLE-2027-001")], [("Proposed program", "Bachelor of Science in Information Technology"), ("Academic year", "2027-2028")]], y)
    y = section_title(pdf, "Admission notice", y)
    y = draw_wrapped(pdf, f'Dear {STUDENT["name"]},', 16 * mm, y, PAGE_WIDTH - 32 * mm)
    y -= 3 * mm
    y = draw_wrapped(pdf, "This fictional letter confirms a sample offer of admission to the Bachelor of Science in Information Technology program for Academic Year 2027-2028. Enrollment remains subject to completing the institution's own formal requirements.", 16 * mm, y, PAGE_WIDTH - 32 * mm)
    y -= 5 * mm
    y = draw_wrapped(pdf, "This file exists only for Scholarship Finder upload testing and does not grant admission to any real institution.", 16 * mm, y, PAGE_WIDTH - 32 * mm)
    signature(pdf, "Maya T. Navarro", "Sample Admissions Officer", 16 * mm, 42 * mm)
    draw_footer(pdf, doc_no)


def make_other_school_proof(path: Path) -> None:
    doc_no = "DEMO-REG-2026-001"
    pdf, y = create_document(path, "Learner Registration Confirmation", "Other identity or school proof", STUDENT["school"], doc_no)
    y = info_grid(pdf, [
        [("Learner", STUDENT["name"]), ("Learner number", STUDENT["learner_number"])],
        [("Registered level", "Grade 12 - STEM"), ("School year", STUDENT["school_year"])],
        [("Registration status", "Confirmed - sample"), ("Recorded contact", STUDENT["contact"])],
    ], y)
    y = section_title(pdf, "Use of this file", y)
    y = draw_wrapped(pdf, "This generic registration confirmation can be uploaded under Other identity or school proof when testing a scholarship requirement that does not match another built-in document type.", 16 * mm, y, PAGE_WIDTH - 32 * mm)
    signature(pdf, "Marco D. Reyes", "Sample School Registrar", 16 * mm, 42 * mm)
    draw_footer(pdf, doc_no)


def make_recommendation(path: Path) -> None:
    doc_no = "DEMO-REC-2026-001"
    pdf, y = create_document(path, "Recommendation Letter", "Program-specific supporting document", STUDENT["school"], doc_no)
    y = info_grid(pdf, [[("Recommended learner", STUDENT["name"]), ("Grade and track", "Grade 12 - STEM")], [("Recommender", "Lina R. Cruz"), ("Date", "September 1, 2026")]], y)
    y = section_title(pdf, "Recommendation", y)
    paragraphs = [
        "To the Scholarship Review Committee,",
        f'I recommend {STUDENT["name"]} for consideration in a scholarship pre-screening process. In this fictional demonstration record, Alex consistently completes school work, participates responsibly in group activities, and shows interest in science and technology learning.',
        "The applicant would benefit from educational support for transportation, learning materials, and continued preparation for college. Please evaluate the applicant using your published eligibility rules and authentic supporting records in a real application.",
        "This letter is synthetic and is provided only to test the platform's upload, preview, and review functions.",
    ]
    for text in paragraphs:
        y = draw_wrapped(pdf, text, 16 * mm, y, PAGE_WIDTH - 32 * mm)
        y -= 4 * mm
    signature(pdf, "Lina R. Cruz", "Sample Class Adviser", 16 * mm, 42 * mm)
    draw_footer(pdf, doc_no)


DOCUMENTS = [
    ("Latest report card or grades", "01_Latest_Report_Card_or_Grades.pdf", make_report_card),
    ("Certificate of enrollment", "02_Certificate_of_Enrollment.pdf", make_enrollment_certificate),
    ("School ID", "03_School_ID.pdf", make_school_id),
    ("Proof of income", "04_Proof_of_Income.pdf", make_income_proof),
    ("Certificate of indigency", "05_Certificate_of_Indigency.pdf", make_indigency),
    ("Birth certificate", "06_Birth_Certificate_Test_Placeholder.pdf", make_birth_certificate),
    ("Parent or guardian valid ID", "07_Parent_or_Guardian_Valid_ID.pdf", make_guardian_id),
    ("Transcript of records", "08_Transcript_of_Records.pdf", make_transcript),
    ("Good moral certificate", "09_Good_Moral_Certificate.pdf", make_good_moral),
    ("Barangay certificate of residency", "10_Barangay_Certificate_of_Residency.pdf", make_residency),
    ("Government-issued ID", "11_Government_ID_Test_Placeholder.pdf", make_government_id),
    ("Recent 2x2 ID photo", "12_Recent_2x2_ID_Photo.pdf", make_photo),
    ("Admission or acceptance letter", "13_Admission_or_Acceptance_Letter.pdf", make_admission_letter),
    ("Other identity or school proof", "14_Other_Identity_or_School_Proof.pdf", make_other_school_proof),
    ("Recommendation letter", "15_Recommendation_Letter.pdf", make_recommendation),
]


def main() -> None:
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    ZIP_PATH.parent.mkdir(parents=True, exist_ok=True)

    manifest = []
    for upload_label, filename, builder in DOCUMENTS:
        path = OUTPUT_DIR / filename
        builder(path)
        page_count = len(PdfReader(str(path)).pages)
        if page_count != 1:
            raise RuntimeError(f"Expected one page in {filename}, found {page_count}.")
        size = path.stat().st_size
        if size > 1024 * 1024 and upload_label == "Latest report card or grades":
            raise RuntimeError(f"Academic record exceeds the OCR upload limit: {filename}.")
        if size > 5 * 1024 * 1024:
            raise RuntimeError(f"File exceeds the platform upload limit: {filename}.")
        manifest.append({
            "upload_label": upload_label,
            "filename": filename,
            "format": "PDF",
            "pages": page_count,
            "size_bytes": size,
            "sample_only": True,
        })

    readme_lines = [
        "Scholarship Finder Demo Applicant Upload Pack",
        "",
        "Applicant: Alex M. Santos (fictional demo record)",
        "Purpose: Test applicant uploads, previews, OCR, matching, and document review.",
        "",
        "Every file is synthetic and marked for demonstration only. Do not present any file as an authentic school, barangay, civil-registry, government, employer, or admissions record.",
        "",
        "Upload mapping:",
    ]
    readme_lines.extend(f"- {item['upload_label']}: {item['filename']}" for item in manifest)
    readme_lines.extend([
        "",
        "The Recommendation letter is a provider-selectable program requirement. The other files match the reusable applicant document library.",
        "The Latest report card or grades file contains GENERAL AVERAGE: 90.00 for OCR testing.",
    ])
    readme_path = OUTPUT_DIR / "README.txt"
    readme_path.write_text("\n".join(readme_lines), encoding="utf-8")
    manifest_path = OUTPUT_DIR / "manifest.json"
    manifest_path.write_text(json.dumps(manifest, indent=2), encoding="utf-8")

    with zipfile.ZipFile(ZIP_PATH, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        for item in manifest:
            path = OUTPUT_DIR / item["filename"]
            archive.write(path, arcname=path.name)
        archive.write(readme_path, arcname=readme_path.name)
        archive.write(manifest_path, arcname=manifest_path.name)

    print(json.dumps({
        "output_directory": str(OUTPUT_DIR),
        "zip": str(ZIP_PATH),
        "documents": len(manifest),
        "zip_size_bytes": ZIP_PATH.stat().st_size,
        "largest_document_bytes": max(item["size_bytes"] for item in manifest),
    }, indent=2))


if __name__ == "__main__":
    main()
