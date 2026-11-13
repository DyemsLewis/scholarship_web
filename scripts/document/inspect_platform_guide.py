"""Read-only DOCX inventory for matching reset screenshots to existing figures."""

from __future__ import annotations

import argparse
import hashlib
import io
import json
import posixpath
import re
from pathlib import Path
from zipfile import ZipFile

from lxml import etree
from PIL import Image, ImageDraw


ROOT = Path(__file__).resolve().parents[2]
GUIDE = ROOT / "deliverables/Platform_Process_Guide_With_Accounts_Expanded.docx"
NS = {
    "w": "http://schemas.openxmlformats.org/wordprocessingml/2006/main",
    "a": "http://schemas.openxmlformats.org/drawingml/2006/main",
    "r": "http://schemas.openxmlformats.org/officeDocument/2006/relationships",
    "wp": "http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing",
    "v": "urn:schemas-microsoft-com:vml",
}
ACCOUNT = re.compile(
    r"\b(?:demoops|novacore|demo\.student|demo\.process|cohort\.learner|"
    r"cohort\.process|talunaw|sidlakbay|hirayalon|nuvareya|velasora|kinoraya)"
    r"(?:\.[a-z0-9_-]+)*\b", re.I
)


def text_of(node):
    return "".join(node.xpath(".//w:t/text()", namespaces=NS))


def inspect(source):
    figures, blocks, tables, images = [], [], [], {}
    with ZipFile(source) as package:
        document = etree.fromstring(package.read("word/document.xml"))
        relationships = etree.fromstring(package.read("word/_rels/document.xml.rels"))
        targets = {
            rel.get("Id"): posixpath.normpath(posixpath.join("word", rel.get("Target")))
            for rel in relationships
            if rel.get("Type", "").endswith("/image") and rel.get("TargetMode") != "External"
        }
        section, subsection, account, page_label = "", "", "", ""
        body = document.find("w:body", NS)
        for index, node in enumerate(body):
            if etree.QName(node).localname not in ("p", "tbl"):
                continue
            text = text_of(node)
            style = node.xpath("./w:pPr/w:pStyle/@w:val", namespaces=NS)
            style = style[0] if style else ""
            if style == "Heading1":
                section, subsection, account, page_label = text, "", "", ""
            elif style == "Heading2":
                subsection = text
            if text.startswith("Signed in account:"):
                account = text.split(":", 1)[1].strip()
            if text.startswith("Page shown:"):
                page_label = text.split(":", 1)[1].strip()
            block = {"index": index, "style": style, "section": section, "text": text}
            blocks.append(block)
            if etree.QName(node).localname == "tbl":
                tables.append({
                    "index": index,
                    "section": section,
                    "rows": [[text_of(cell) for cell in row.findall("w:tc", NS)]
                             for row in node.findall("w:tr", NS)],
                })
            ids = node.xpath(".//a:blip/@r:embed | .//v:imagedata/@r:id", namespaces=NS)
            for rid in ids:
                target = targets[rid]
                blob = package.read(target)
                with Image.open(io.BytesIO(blob)) as image:
                    size = list(image.size)
                extent = node.xpath(".//wp:extent", namespaces=NS)
                figures.append({
                    "figure": len(figures) + 1,
                    "block_index": index,
                    "section": section,
                    "subsection": subsection,
                    "signed_in_account": account,
                    "page_shown": page_label,
                    "relationship_id": rid,
                    "package_target": target,
                    "pixels": size,
                    "display_emu": dict(extent[0].attrib) if extent else None,
                    "preceding_text": [b["text"] for b in blocks[-5:-1] if b["text"]],
                })
                images[target] = {"pixels": size, "sha256": hashlib.sha256(blob).hexdigest()}
        media = [entry.filename for entry in package.infolist() if entry.filename.startswith("word/media/")]
        xml_hits = []
        for name in package.namelist():
            if not name.endswith((".xml", ".rels")):
                continue
            raw = package.read(name).decode("utf-8")
            hits = sorted(set(ACCOUNT.findall(raw)))
            if hits:
                xml_hits.append({"part": name, "accounts_or_prefixes": hits})
        summary = {
            "source": str(source.resolve()),
            "source_sha256": hashlib.sha256(source.read_bytes()).hexdigest(),
            "body_blocks": len(blocks),
            "top_level_sections": [b["text"] for b in blocks if b["style"] == "Heading1"],
            "tables": len(tables),
            "figure_occurrences": len(figures),
            "referenced_images": len(images),
            "packaged_media": len(media),
            "unreferenced_body_media": sorted(set(media) - set(images)),
            "accounts": sorted(set(ACCOUNT.findall("\n".join(b["text"] for b in blocks)))),
            "image_relationships": targets,
        }
    return {"summary": summary, "blocks": blocks, "tables": tables,
            "figures": figures, "images": images, "xml_account_hits": xml_hits}


def write_contact_sheets(source, inventory, output):
    # These thumbnails are an inventory aid, not the final page-render QA.
    figures = inventory["figures"]
    with ZipFile(source) as package:
        for start in range(0, len(figures), 12):
            sheet = Image.new("RGB", (1600, 1320), "#e5e7eb")
            draw = ImageDraw.Draw(sheet)
            for offset, figure in enumerate(figures[start:start + 12]):
                x, y = (offset % 4) * 400, (offset // 4) * 440
                with Image.open(io.BytesIO(package.read(figure["package_target"]))) as image:
                    thumb = image.convert("RGB")
                    thumb.thumbnail((390, 345))
                    sheet.paste(thumb, (x + 5, y + 5))
                label = (f"{figure['figure']:02} {figure['relationship_id']} "
                         f"{figure['package_target'].split('/')[-1]}")
                draw.text((x + 5, y + 354), label, fill="black")
                draw.text((x + 5, y + 372), figure["section"][:53], fill="black")
                draw.text((x + 5, y + 390), figure["signed_in_account"][:53], fill="black")
            sheet.save(output / f"existing-figures-{start + 1:03}.png")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", type=Path, default=GUIDE)
    parser.add_argument("--output-dir", type=Path,
                        default=ROOT / ".tmp-docx/document-reset-20261113")
    parser.add_argument("--contact-sheets", action="store_true")
    args = parser.parse_args()
    source = args.source.resolve(strict=True)
    output = args.output_dir.resolve()
    if output == source.parent or source.parent in output.parents:
        parser.error("Private inventory output must not be in deliverables.")
    inventory = inspect(source)
    output.mkdir(parents=True, exist_ok=True)
    (output / "inventory.json").write_text(json.dumps(inventory, indent=2) + "\n", encoding="utf-8")
    lines = []
    for block in inventory["blocks"]:
        lines.append(f"{block['index']:03} [{block['style']}] {block['text']}")
    (output / "body.txt").write_text("\n".join(lines) + "\n", encoding="utf-8")
    manifest = {
        "status": "AWAITING_FRESH_CAPTURES_NOT_READY_FOR_EDIT",
        "source_sha256": inventory["summary"]["source_sha256"],
        "captured_at": None,
        "environment": None,
        "figures": [{**figure, "replacement_path": None, "new_signed_in_account": None,
                     "new_record_label": None, "url": None, "confirmed_fictional_only": False}
                    for figure in inventory["figures"]],
    }
    template = output / "screenshot-manifest.template.json"
    if not template.exists():
        template.write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    if args.contact_sheets:
        write_contact_sheets(source, inventory, output)
    print(json.dumps(inventory["summary"], indent=2))


if __name__ == "__main__":
    main()
