"""Read the roles table of الصلاحيات.docx into a structure: for each role, the sections (bold heading + its lines) of the two columns."""
import zipfile
import xml.etree.ElementTree as ET

W = '{http://schemas.openxmlformats.org/wordprocessingml/2006/main}'

# The roles of the document, in its order, and the role of the system each one is.
ROLES = [
    ('المتدرب', 'employee'), ('مدير المدرسة', 'school_admin'), ('مسؤول التطوير المهني (النائب الأكاديمي)', 'academic_deputy'),
    ('رئيس قسم التدريب', 'training_head'), ('مشرف التدريب (قسم التدريب التربوي)', 'program_coordinator'),
    ('قيادات المركز وواضعو السياسات', 'center_leadership'), ('المدربون', 'trainer'), ('مسؤول النظام', 'super_admin'),
    ('معد الحقيبة', 'kit_developer'), ('رئيس قسم التخطيط', 'planning_head'), ('أخصائي التخطيط', 'planning_specialist'),
    ('مسؤول الدعم اللوجستي', 'logistics_officer'),
]


def text_of(p):
    return ' '.join(''.join(t.text or '' for t in p.iter(W + 't')).split())


def is_heading(p):
    runs = [r for r in p.iter(W + 'r') if ''.join(t.text or '' for t in r.iter(W + 't')).strip()]
    flags = [r.find(W + 'rPr') is not None and (r.find(W + 'rPr').find(W + 'b') is not None or r.find(W + 'rPr').find(W + 'bCs') is not None) for r in runs]
    return bool(flags) and all(flags)


def sections(cell):
    out = []
    for p in cell.findall(W + 'p'):
        txt = text_of(p)
        if not txt:
            continue
        if is_heading(p) or not out:
            out.append({'title': txt, 'items': []})
        else:
            out[-1]['items'].append(txt)
    return out


def read(path):
    root = ET.fromstring(zipfile.ZipFile(path).read('word/document.xml'))
    rows = list(next(root.iter(W + 'tbl')).iter(W + 'tr'))[1:]
    assert len(rows) == len(ROLES), (len(rows), len(ROLES))
    doc = []
    for row, (name, slug) in zip(rows, ROLES):
        cells = row.findall(W + 'tc')
        assert len(cells) == 3
        title = ' '.join(text_of(p) for p in cells[2].findall(W + 'p') if text_of(p))
        assert title.replace(' / ', ' ') == name or title == name, (title, name)
        doc.append({'slug': slug, 'name': name, 'permissions': sections(cells[0]), 'responsibilities': sections(cells[1])})
    return doc


if __name__ == '__main__':
    import sys
    for role in read(sys.argv[1]):
        print(f"##### {role['slug']} — {role['name']}")
        for col in ('permissions', 'responsibilities'):
            print(f"  == {col}")
            for i, s in enumerate(role[col]):
                print(f"  [{i}] {s['title']}")
                for j, it in enumerate(s['items']):
                    print(f"      ({j}) {it}")
