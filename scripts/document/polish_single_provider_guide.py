"""Apply the small numbered-step corrections found during final editorial QA."""
import ast
import hashlib
import json
from pathlib import Path

from docx import Document
from docx.text.paragraph import Paragraph

ROOT = Path(__file__).resolve().parents[2]
GUIDE = ROOT / 'deliverables/Platform_Process_Guide_With_Accounts_Expanded.docx'
AREA = ROOT / '.tmp-docx/single-provider-guide-20261114'
tree = ast.parse((ROOT/'scripts/document/update_single_provider_guide.py').read_text())
updates = next(ast.literal_eval(node.value) for node in ast.walk(tree) if isinstance(node,ast.Assign) and any(isinstance(target,ast.Name) and target.id=='updates' for target in node.targets))
doc = Document(GUIDE)
blocks = list(doc.element.body)
for index in [234,239,443,455,467,488]:
    assert not blocks[index].xpath('.//w:drawing')
    Paragraph(blocks[index],doc).text = updates[index]
doc.save(GUIDE)
record = json.loads((AREA/'updated.json').read_text())
record['sha256'] = hashlib.sha256(GUIDE.read_bytes()).hexdigest()
(AREA/'updated.json').write_text(json.dumps(record,indent=2))
print('Final numbered-step corrections applied to the same guide.')
