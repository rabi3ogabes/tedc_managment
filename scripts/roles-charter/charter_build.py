"""Build backend/resources/roles/charter.json from الصلاحيات.docx.

The Arabic text is taken from the document as it is, never retyped. What is added here is, for each heading of «الصلاحيات»:
  rights - the rights of the system the role must hold for that heading to be true (empty for a person's own records and for
           things the system does by itself, such as sending a notification);
  to     - the page where the heading is done, and `needs`, the right that page asks for (the web shows the link only then).
Usage: python charter_build.py <docx> <out.json>
"""
import json
import sys

sys.path.insert(0, __import__('os').path.dirname(__file__))
from charter_parse import read  # noqa: E402

# role slug -> one entry per «الصلاحيات» heading, in the order of the document: (rights, to, needs)
META = {
    'employee': [
        ([], '/programs', None), ([], '/calendar', None), ([], '/portal/notifications', None), ([], '/portal/growth', None), ([], '/portal/growth', None),
        ([], '/portal/training', None), ([], '/portal/training', None), ([], '/portal/communities', None), ([], '/portal/surveys', None),
        ([], '/portal/certificates', None), ([], '/portal/growth', None),
    ],
    'school_admin': [
        (['dashboard.view', 'reports.view'], '/admin/reports', None), ([], None, None),
    ],
    'academic_deputy': [
        (['calendar.view', 'nominations.school'], '/admin/calendar', 'calendar.view'),
        (['pd.approve', 'registrations.approve_manager'], '/admin/approvals', 'registrations.approve_manager'),
        (['nominations.school'], '/admin/registrations', 'registrations.view'),
        (['dashboard.view', 'reports.view'], '/admin/reports', None),
        ([], None, None),
        (['workshops.internal', 'plc.internal.submit', 'plc.attendance.manage'], '/admin/internal-workshops', 'workshops.internal'),
    ],
    'training_head': [
        (['saber.supervisor.assign', 'trainers.assign'], '/admin/saber', 'saber.view'),
        (['program_grants.manage'], '/admin/programs', 'programs.view'),
        (['dashboard.view', 'analytics.view', 'reports.view'], '/admin/reports', None),
        (['attendance.manage'], '/admin/absence', 'attendance.manage'),
    ],
    'program_coordinator': [
        (['attendance.manage', 'leaves.manage', 'announcements.manage'], '/admin/absence', 'attendance.manage'),
        (['materials.manage'], '/admin/programs', 'programs.view'),
        (['trainers.assign', 'saber.trainer.assign'], '/admin/saber', 'saber.view'),
        (['kits.review', 'kits.publish', 'kits.manage'], '/admin/kits', 'kits.view'),
        (['dashboard.view', 'reports.view'], '/admin/reports', None),
        (['dashboard.view', 'live_training.view'], '/admin/live-training', 'live_training.view'),
    ],
    'center_leadership': [
        (['analytics.executive', 'dashboard.view'], '/admin/analytics', 'analytics.executive'),
        ([], None, None),
        (['reports.view'], '/admin/reports', None),
        (['trainers.approve'], '/admin/trainers', 'programs.view'),
    ],
    'trainer': [
        (['attendance.manage'], '/admin/teaching', 'trainers.respond'),
        (['assessments.grade', 'tasks.review'], '/admin/teaching', 'trainers.respond'),
        ([], '/admin/reports', None),
        ([], '/admin/profile', None),
    ],
    'super_admin': [
        (['programs.manage', 'banks.manage', 'assessments.manage', 'passing.manage'], '/admin/programs', 'programs.view'),
        (['roles.manage', 'users.manage', 'program_grants.manage', 'announcements.manage'], '/admin/settings', 'settings.manage'),
        (['pass_exceptions.grant'], '/admin/passing-policy', 'passing.manage'),
        (['reports.view', 'dashboard.view', 'search.global'], '/admin/reports', None),
    ],
    'kit_developer': [
        (['kits.manage'], '/admin/kits', 'kits.view'), (['kits.manage'], '/admin/kits', 'kits.view'), (['kits.view'], '/admin/reports', None),
    ],
    'planning_head': [
        (['plans.approve', 'plans.manage'], '/admin/plans', 'plans.view'),
        (['instruments.approve'], '/admin/needs-hub', 'needs.cycles'),
        ([], None, None),
        (['plans.approve'], '/admin/plans', 'plans.view'),
        (['dashboard.view'], None, None),
    ],
    'planning_specialist': [
        (['needs.manage', 'evaluations.respond_planning'], '/admin/needs-hub', 'needs.cycles'),
        (['evaluation_reports.prepare'], '/admin/satisfaction', 'evaluation_reports.prepare'),
        (['dashboard.view'], None, None),
    ],
    'logistics_officer': [
        (['rooms.manage'], '/admin/rooms', 'programs.view'), (['rooms.book'], '/admin/room-ops', 'rooms.book'), (['dashboard.view'], None, None),
    ],
}

ENGLISH_NAME = {
    'employee': 'Trainee', 'school_admin': 'School principal', 'academic_deputy': 'Professional development officer (academic deputy)',
    'training_head': 'Head of the training section', 'program_coordinator': 'Training supervisor (educational training section)',
    'center_leadership': 'Centre leadership and policy makers', 'trainer': 'Trainers', 'super_admin': 'System administrator',
    'kit_developer': 'Kit developer', 'planning_head': 'Head of the planning section', 'planning_specialist': 'Planning specialist',
    'logistics_officer': 'Logistics support officer',
}

doc = read(sys.argv[1])
roles = {}
for role in doc:
    meta = META[role['slug']]
    assert len(meta) == len(role['permissions']), (role['slug'], len(meta), len(role['permissions']))
    sections = []
    for sec, (rights, to, needs) in zip(role['permissions'], meta):
        assert sec['items'], (role['slug'], sec['title'])
        entry = {'title': sec['title'], 'items': sec['items'], 'rights': rights}
        if to:
            entry['to'] = to
            if needs:
                entry['needs'] = needs
        sections.append(entry)
    for sec in role['responsibilities']:
        assert sec['items'], (role['slug'], sec['title'])
    roles[role['slug']] = {
        'name': {'ar': role['name'], 'en': ENGLISH_NAME[role['slug']]},
        'permissions': sections,
        'responsibilities': [{'title': s['title'], 'items': s['items']} for s in role['responsibilities']],
    }

out = {
    'source': 'الصلاحيات.docx',
    'note': 'The Arabic text of every title and item is the text of the document, unchanged. Regenerate with scripts/roles-charter (see docs/roles-charter.md); do not edit by hand.',
    'roles': roles,
}
with open(sys.argv[2], 'w', encoding='utf-8', newline='\n') as f:
    json.dump(out, f, ensure_ascii=False, indent=2)
    f.write('\n')
n_sec = sum(len(r['permissions']) + len(r['responsibilities']) for r in roles.values())
n_items = sum(len(s['items']) for r in roles.values() for col in ('permissions', 'responsibilities') for s in r[col])
print('roles', len(roles), 'sections', n_sec, 'items', n_items)
