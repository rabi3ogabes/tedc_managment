"""
Builds the centre's wayfinding map (docs/wayfinding.md) from the two floor plans in web/public/wayfinding/.

The corridors are the yellow floor of each plan. They become a walkable grid (one cell = CELL pixels of the 1290 x 942 plan). Every
place (rooms, labs, prayer rooms, restrooms, stairs, lifts, entrances) gets a door: the walkable cell where its side meets the corridor
nearest to its middle. The map is written twice, for the website and for the API that serves the mobile app:

    web/src/features/wayfinding/centre-map.json
    backend/resources/wayfinding/centre-map.json

Run again whenever a plan or a place changes:  python -I scripts/wayfinding/build_map.py [--debug <folder>]
Needs numpy, scipy and Pillow. Fails when a place cannot be reached, so a broken map is never written.
"""

import argparse
import base64
import json
import sys
from collections import deque
from pathlib import Path

import numpy as np
from PIL import Image, ImageDraw
from scipy import ndimage

ROOT = Path(__file__).resolve().parents[2]
PLANS = ROOT / 'web' / 'public' / 'wayfinding'
OUT = [ROOT / 'web' / 'src' / 'features' / 'wayfinding' / 'centre-map.json', ROOT / 'backend' / 'resources' / 'wayfinding' / 'centre-map.json']
W, H = 1290, 942
CELL = 6
COLS, ROWS = (W + CELL - 1) // CELL, (H + CELL - 1) // CELL
# The plans are drawn at about ten pixels to the metre (a corridor is ~3 m wide, a training room ~12 m long).
PX_PER_M = 10

# (id, kind, number, ar, en, (x, y, w, h)). Rooms printed in one block on the plan ("قاعات التدريب 3 و4") are split in two, in the
# order the plan prints their numbers.
PLACES = {
    'ground': [
        ('room-5', 'train', '5', 'قاعة التدريب 5', 'Training Room 5', (554, 38, 140, 88)),
        ('room-4', 'train', '4', 'قاعة التدريب 4', 'Training Room 4', (716, 112, 92, 135)),
        ('room-3', 'train', '3', 'قاعة التدريب 3', 'Training Room 3', (716, 247, 92, 135)),
        ('room-7', 'train', '7', 'قاعة التدريب 7', 'Training Room 7', (192, 398, 125, 92)),
        ('room-6', 'train', '6', 'قاعة التدريب 6', 'Training Room 6', (317, 398, 125, 92)),
        ('room-8', 'train', '8', 'قاعة التدريب 8', 'Training Room 8', (192, 627, 250, 86)),
        ('room-1', 'train', '1', 'قاعة التدريب 1', 'Training Room 1', (824, 630, 134, 94)),
        ('room-2', 'train', '2', 'قاعة التدريب 2', 'Training Room 2', (960, 630, 132, 94)),
        ('chemistry-lab', 'lab', None, 'معمل الكيمياء', 'Chemistry Lab', (434, 126, 90, 124)),
        ('science-lab', 'lab', None, 'معمل العلوم', 'Science Lab', (434, 254, 90, 128)),
        ('excellence', 'lab', None, 'قاعة التميز', 'Excellence Room', (824, 396, 134, 98)),
        ('creativity', 'lab', None, 'قاعة الإبداع', 'Creativity Room', (960, 396, 132, 98)),
        ('library', 'public', None, 'المكتبة', 'Library', (947, 506, 228, 114)),
        ('cafeteria', 'public', None, 'الكافتيريا', 'Cafeteria', (520, 440, 236, 236)),
        ('cafeteria-north', 'public', None, 'كافتيريا الجناح الشمالي', 'North Wing Cafeteria', (556, 134, 130, 130)),
        ('administration', 'office', None, 'الإدارة', 'Administration', (487, 744, 288, 138)),
        ('prayer-men', 'pray', None, 'مصلى الرجال', 'Men’s Prayer Room', (878, 38, 142, 80)),
        ('prayer-women', 'pray', None, 'مصلى النساء', 'Women’s Prayer Room', (1062, 120, 88, 110)),
        ('wc-north-west', 'wc', None, 'دورات المياه — الجناح الشمالي', 'Restrooms — North Wing', (434, 36, 90, 88)),
        ('wc-north', 'wc', None, 'دورات المياه — قرب القاعة 4', 'Restrooms — by Room 4', (716, 38, 92, 72)),
        ('wc-north-east', 'wc', None, 'دورات المياه — قرب مصلى الرجال', 'Restrooms — by the Men’s Prayer Room', (1022, 38, 128, 80)),
        ('wc-east', 'wc', None, 'دورات المياه — قرب مصلى النساء', 'Restrooms — by the Women’s Prayer Room', (1062, 232, 88, 142)),
        ('wc-west-1', 'wc', None, 'دورات المياه — قرب القاعة 7', 'Restrooms — by Room 7', (110, 398, 80, 92)),
        ('wc-west-2', 'wc', None, 'دورات المياه — قرب القاعة 8', 'Restrooms — by Room 8', (110, 627, 80, 86)),
        ('wc-creativity', 'wc', None, 'دورات المياه — قرب قاعة الإبداع', 'Restrooms — by the Creativity Room', (1094, 396, 82, 98)),
        ('wc-room-2', 'wc', None, 'دورات المياه — قرب القاعة 2', 'Restrooms — by Room 2', (1094, 630, 80, 94)),
    ],
    'first': [
        ('room-10', 'train', '10', 'قاعة التدريب 10', 'Training Room 10', (428, 134, 88, 125)),
        ('room-9', 'train', '9', 'قاعة التدريب 9', 'Training Room 9', (428, 259, 88, 125)),
        ('room-11', 'train', '11', 'قاعة التدريب 11', 'Training Room 11', (548, 48, 138, 76)),
        ('room-13', 'train', '13', 'قاعة التدريب 13', 'Training Room 13', (713, 126, 90, 118)),
        ('room-12', 'train', '12', 'قاعة التدريب 12', 'Training Room 12', (713, 244, 90, 118)),
        ('room-20', 'train', '20', 'قاعة التدريب 20', 'Training Room 20', (872, 48, 140, 80)),
        ('room-19', 'train', '19', 'قاعة التدريب 19', 'Training Room 19', (1058, 236, 92, 140)),
        ('room-14', 'train', '14', 'قاعة التدريب 14', 'Training Room 14', (824, 396, 150, 100)),
        ('room-15', 'train', '15', 'قاعة التدريب 15', 'Training Room 15', (974, 396, 150, 100)),
        ('room-18', 'train', '18', 'قاعة التدريب 18', 'Training Room 18', (1079, 509, 103, 110)),
        ('room-16', 'train', '16', 'قاعة التدريب 16', 'Training Room 16', (827, 630, 145, 92)),
        ('room-17', 'train', '17', 'قاعة التدريب 17', 'Training Room 17', (972, 630, 145, 92)),
        ('computer-lab-2', 'lab', '2', 'معمل الحاسب 2', 'Computer Lab 2', (1014, 48, 136, 80)),
        ('computer-room-1', 'lab', '1', 'معمل الحاسب 1', 'Computer Room 1', (1058, 130, 92, 104)),
        ('computer-lab-3', 'lab', '3', 'معمل الحاسب الآلي 3', 'Computer Lab 3', (677, 746, 98, 138)),
        ('leadership', 'meet', None, 'قاعة اجتماعات القيادة', 'Leadership Meeting Room', (466, 604, 164, 120)),
        ('innovation', 'meet', None, 'قاعة اجتماعات الريادة', 'InnovaGen Meeting Room', (640, 604, 160, 120)),
        ('knowledge-oasis', 'public', None, 'واحة المعرفة', 'Knowledge Oasis', (487, 746, 186, 138)),
        ('cafeteria-first', 'public', None, 'الكافتيريا', 'Cafeteria', (548, 126, 138, 138)),
        ('offices-west', 'office', None, 'المكاتب — الجناح الغربي', 'Offices — West Wing', (96, 400, 342, 310)),
        ('prayer-men-first', 'pray', None, 'مصلى الرجال', 'Men’s Prayer Room', (1015, 509, 62, 55)),
        ('prayer-women-first', 'pray', None, 'مصلى النساء', 'Women’s Prayer Room', (953, 560, 62, 59)),
        ('wc-first-north-west', 'wc', None, 'دورات المياه — قرب القاعة 10', 'Restrooms — by Room 10', (428, 48, 88, 84)),
        ('wc-first-north', 'wc', None, 'دورات المياه — قرب القاعة 13', 'Restrooms — by Room 13', (713, 48, 90, 76)),
        ('wc-first-east', 'wc', None, 'دورات المياه — قرب القاعة 19', 'Restrooms — by Room 19', (917, 296, 139, 80)),
        ('wc-first-15', 'wc', None, 'دورات المياه — قرب القاعة 15', 'Restrooms — by Room 15', (1126, 396, 56, 100)),
        ('wc-first-17', 'wc', None, 'دورات المياه — قرب القاعة 17', 'Restrooms — by Room 17', (1119, 630, 64, 92)),
    ],
}

# Stairs and lifts join the floors at the same spot. (id, kind, ar, en, {floor: (x, y, w, h)}): the block on the plan.
CONNECTORS = [
    ('stairs-north', 'stairs', 'الدرج الشمالي', 'North stairs', {'ground': (600, 276, 64, 88), 'first': (597, 278, 66, 86)}),
    ('stairs-west', 'stairs', 'الدرج الغربي', 'West stairs', {'ground': (343, 526, 90, 78), 'first': (335, 528, 98, 82)}),
    ('stairs-east', 'stairs', 'الدرج الشرقي', 'East stairs', {'ground': (836, 520, 90, 80), 'first': (832, 524, 92, 80)}),
    ('stairs-north-east', 'stairs', 'الدرج الشمالي الشرقي', 'North-east stairs', {'ground': (950, 186, 70, 64), 'first': (948, 188, 68, 68)}),
    ('stairs-centre', 'stairs', 'الدرج الأوسط', 'Central stairs', {'ground': (551, 454, 48, 48), 'first': (524, 456, 48, 48)}),
    ('lift-north', 'lift', 'المصعد الشمالي', 'North lift', {'ground': (622, 426, 24, 22), 'first': (620, 429, 24, 22)}),
    ('lift-west', 'lift', 'المصعد الغربي', 'West lift', {'ground': (504, 537, 24, 22), 'first': (500, 539, 24, 22)}),
    ('lift-east', 'lift', 'المصعد الشرقي', 'East lift', {'ground': (743, 538, 24, 22), 'first': (745, 541, 24, 22)}),
]

# Structural shapes drawn on the map but never walked to: the building's cores, the atrium void; and outlines for places that are not
# rectangles. (kind, rect or polygon, chamfer)
SHAPES = {
    'ground': [
        ('core', (920, 292, 138, 82), 0), ('core', (106, 502, 218, 112), 0),
        ('core', (473, 404, 115, 95), 22), ('core', (692, 404, 105, 98), 22), ('core', (473, 602, 112, 116), 22), ('core', (690, 604, 108, 118), 22),
    ],
    'first': [
        ('core', (473, 404, 115, 98), 22), ('core', (690, 404, 110, 98), 22),
        ('void', [[575, 470], [690, 470], [725, 505], [725, 545], [690, 572], [575, 572], [548, 545], [548, 505]], 0),
    ],
}
OUTLINES = {
    'g-cafeteria': [[565, 425], [709, 425], [772, 488], [772, 642], [709, 690], [565, 690], [502, 642], [502, 488]],
    'f-leadership': [[488, 604], [630, 604], [630, 724], [466, 724], [466, 626]],
    'f-innovation': [[640, 604], [778, 604], [800, 626], [800, 724], [640, 724]],
}

# Floor the plan's perspective hides behind a block (the corridor under the library, the gallery round the first-floor atrium, which
# meets the corridors at the lifts): (x, y, w, h) marked as walkable.
BRIDGES = {
    'ground': [(820, 619, 356, 11)],
    'first': [(822, 619, 364, 11), (498, 544, 48, 14), (718, 544, 54, 14), (625, 440, 16, 46)],
}

# Where visitors come in (editable in the dashboard; these are the defaults).
ENTRANCES = [
    ('entrance-west', 'ground', 'المدخل الغربي (بجانب الإدارة)', 'West entrance (beside the Administration)', (462, 788)),
    ('entrance-east', 'ground', 'المدخل الشرقي (بجانب الإدارة)', 'East entrance (beside the Administration)', (798, 788)),
]


def corridor_mask(floor: str) -> np.ndarray:
    rgb = np.asarray(Image.open(PLANS / f'{floor}.webp').convert('RGB')).astype(int)
    r, g, b = rgb[..., 0], rgb[..., 1], rgb[..., 2]
    mask = (r > 140) & (g > 95) & (b < 125) & (r - b > 75) & (r >= g)
    # Rooms, including the yellow office blocks of the first floor, are never corridor.
    for *_, (x, y, w, h) in PLACES[floor]:
        mask[y:y + h, x:x + w] = False
    for *_, blocks in CONNECTORS:
        x, y, w, h = blocks[floor]
        if w > 30:                                   # a stair block (a lift is an icon on the corridor floor)
            mask[y:y + h, x:x + w] = False
    mask = ndimage.binary_opening(mask, structure=np.ones((3, 3)))
    mask = ndimage.binary_closing(mask, structure=np.ones((7, 7)))
    # The lift icons and the labels printed on the corridor floor leave holes: small holes are filled, the voids are kept.
    holes, n = ndimage.label(~mask)
    sizes = ndimage.sum(np.ones_like(holes), holes, range(1, n + 1))
    for i, size in enumerate(sizes, start=1):
        if size < 1800:
            mask[holes == i] = True
    for *_, blocks in CONNECTORS:
        x, y, w, h = blocks[floor]
        if w <= 30:
            yy, xx = np.ogrid[:H, :W]
            mask |= (xx - (x + w / 2)) ** 2 + (yy - (y + h / 2)) ** 2 <= 16 ** 2
    for x, y, w, h in BRIDGES[floor]:
        mask[y:y + h, x:x + w] = True
    for _, f, *_rest, (x, y) in ENTRANCES:
        if f == floor:
            mask[max(0, y - 10):y + 10, max(0, x - 14):x + 14] = True
    labels, n = ndimage.label(mask)
    if n > 1:
        sizes = ndimage.sum(mask, labels, range(1, n + 1))
        mask = labels == (int(np.argmax(sizes)) + 1)
    return mask


def to_grid(mask: np.ndarray) -> np.ndarray:
    # The side wings are reached by corridors the plan's perspective draws only a few pixels wide: they are widened slightly so they
    # survive the grid, and a cell counts as floor when a third of it is.
    padded = np.zeros((ROWS * CELL, COLS * CELL), bool)
    padded[:H, :W] = ndimage.binary_dilation(mask, structure=np.ones((3, 3)))
    share = padded.reshape(ROWS, CELL, COLS, CELL).mean(axis=(1, 3))
    grid = share >= 0.34
    labels, n = ndimage.label(grid, structure=np.ones((3, 3)))
    if n > 1:
        sizes = ndimage.sum(grid, labels, range(1, n + 1))
        grid = labels == (int(np.argmax(sizes)) + 1)
    return grid


def cell_of(x: float, y: float) -> tuple[int, int]:
    return min(COLS - 1, max(0, int(x // CELL))), min(ROWS - 1, max(0, int(y // CELL)))


def centre(c: int, r: int) -> list[int]:
    return [c * CELL + CELL // 2, r * CELL + CELL // 2]


def door_for(grid: np.ndarray, rect: tuple[int, int, int, int], reach: int = 36) -> tuple[int, int]:
    """The walkable cell next to a place, nearest the middle of the place (so the door sits on the corridor side it faces)."""
    x, y, w, h = rect
    cx, cy = x + w / 2, y + h / 2
    best, best_d = None, 1e9
    for r in range(ROWS):
        for c in range(COLS):
            if not grid[r, c]:
                continue
            px, py = centre(c, r)
            gap = max(x - px, 0, px - (x + w)) ** 2 + max(y - py, 0, py - (y + h)) ** 2
            if gap > reach ** 2:
                continue
            d = (px - cx) ** 2 + (py - cy) ** 2 + gap * 4
            if d < best_d:
                best, best_d = (c, r), d
    if best is None and reach < 90:
        return door_for(grid, rect, reach + 18)
    if best is None:
        raise SystemExit(f'No corridor next to {rect}')
    return best


def reachable(grid: np.ndarray, start: tuple[int, int]) -> np.ndarray:
    seen = np.zeros_like(grid)
    q = deque([start])
    seen[start[1], start[0]] = True
    while q:
        c, r = q.popleft()
        for dc in (-1, 0, 1):
            for dr in (-1, 0, 1):
                nc, nr = c + dc, r + dr
                if 0 <= nc < COLS and 0 <= nr < ROWS and grid[nr, nc] and not seen[nr, nc]:
                    seen[nr, nc] = True
                    q.append((nc, nr))
    return seen


def outline(mask: np.ndarray) -> str:
    """The floor's edge as a smooth SVG path (marching squares over a softened copy of the corridors, then simplified)."""
    soft = ndimage.gaussian_filter(ndimage.binary_dilation(mask, structure=np.ones((3, 3))).astype(float), 2.2)[::2, ::2]
    h, w = soft.shape
    pad = np.zeros((h + 2, w + 2))
    pad[1:-1, 1:-1] = soft
    lvl = 0.5
    segs = []
    def lerp(a, b):
        return 0.5 if a == b else (lvl - a) / (b - a)
    for y in range(h + 1):
        row0, row1 = pad[y], pad[y + 1]
        for x in range(w + 1):
            a, b, c, d = row0[x], row0[x + 1], row1[x + 1], row1[x]   # corners: top-left, top-right, bottom-right, bottom-left
            idx = (a > lvl) * 8 + (b > lvl) * 4 + (c > lvl) * 2 + (d > lvl)
            if idx in (0, 15):
                continue
            top = (x + lerp(a, b), y)
            right = (x + 1, y + lerp(b, c))
            bottom = (x + lerp(d, c), y + 1)
            left = (x, y + lerp(a, d))
            table = {1: [(left, bottom)], 2: [(bottom, right)], 3: [(left, right)], 4: [(top, right)], 5: [(left, top), (bottom, right)],
                     6: [(top, bottom)], 7: [(left, top)], 8: [(top, left)], 9: [(top, bottom)], 10: [(top, right), (left, bottom)],
                     11: [(top, right)], 12: [(left, right)], 13: [(bottom, right)], 14: [(left, bottom)]}
            segs.extend(table[idx])
    key = lambda p: (round(p[0], 3), round(p[1], 3))
    ends = {}
    for i, (p, q) in enumerate(segs):
        ends.setdefault(key(p), []).append(i)
        ends.setdefault(key(q), []).append(i)
    used = [False] * len(segs)
    loops = []
    for i in range(len(segs)):
        if used[i]:
            continue
        used[i] = True
        line = [segs[i][0], segs[i][1]]
        while True:
            nxt = [j for j in ends.get(key(line[-1]), []) if not used[j]]
            if not nxt:
                break
            j = nxt[0]
            used[j] = True
            p, q = segs[j]
            line.append(q if key(p) == key(line[-1]) else p)
        if len(line) > 8:
            loops.append(line)
    def rdp(pts, eps):
        if len(pts) < 3:
            return pts
        a, b = np.array(pts[0]), np.array(pts[-1])
        ab = b - a
        n = np.hypot(*ab) or 1.0
        d = [abs(ab[0] * (a[1] - p[1]) - (a[0] - p[0]) * ab[1]) / n for p in pts[1:-1]]
        i = int(np.argmax(d)) + 1
        return rdp(pts[:i + 1], eps)[:-1] + rdp(pts[i:], eps) if d[i - 1] > eps else [pts[0], pts[-1]]
    sys.setrecursionlimit(10000)
    out = []
    for loop in loops:
        mid = len(loop) // 2
        simple = rdp(loop[:mid + 1], 0.45)[:-1] + rdp(loop[mid:], 0.45)
        out.append('M' + 'L'.join(f'{x * 2 - 2:.1f} {y * 2 - 2:.1f}' for x, y in simple) + 'Z')
    return ''.join(out)


def encode(grid: np.ndarray) -> str:
    return base64.b64encode(np.packbits(grid.astype(np.uint8).ravel()).tobytes()).decode()


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument('--debug', help='write an overlay of each floor to this folder')
    args = ap.parse_args()

    out = {'version': 1, 'size': {'w': W, 'h': H}, 'cell': CELL, 'cols': COLS, 'rows': ROWS, 'px_per_m': PX_PER_M, 'floors': [], 'places': [], 'connectors': [], 'entrances': [], 'shapes': []}
    for floor, shapes in SHAPES.items():
        for kind, geom, chamfer in shapes:
            out['shapes'].append({'floor': floor, 'kind': kind, **({'rect': list(geom)} if isinstance(geom, tuple) else {'points': geom}), **({'chamfer': chamfer} if chamfer else {})})
    grids = {}
    for level, floor in enumerate(['ground', 'first']):
        mask = corridor_mask(floor)
        grid = to_grid(mask)
        grids[floor] = grid
        out['floors'].append({'id': floor, 'level': level, 'ar': 'الطابق الأرضي' if level == 0 else 'الطابق الأول', 'en': 'Ground floor' if level == 0 else 'First floor', 'image': f'/wayfinding/{floor}.webp', 'grid': encode(grid), 'outline': outline(mask)})

    problems = []
    for floor, places in PLACES.items():
        grid = grids[floor]
        for pid, kind, number, ar, en, rect in places:
            c, r = door_for(grid, rect)
            item = {'id': f'{floor[0]}-{pid}', 'floor': floor, 'kind': kind, 'number': number, 'ar': ar, 'en': en, 'rect': list(rect), 'door': centre(c, r)}
            if item['id'] in OUTLINES:
                item['outline'] = OUTLINES[item['id']]
            out['places'].append(item)
    for cid, kind, ar, en, blocks in CONNECTORS:
        at = {}
        for floor, rect in blocks.items():
            c, r = door_for(grids[floor], rect)
            at[floor] = centre(c, r)
        out['connectors'].append({'id': cid, 'kind': kind, 'ar': ar, 'en': en, 'rect': {f: list(b) for f, b in blocks.items()}, 'at': at})
    for eid, floor, ar, en, (x, y) in ENTRANCES:
        c, r = cell_of(x, y)
        if not grids[floor][r, c]:
            c, r = door_for(grids[floor], (x - 2, y - 2, 4, 4))
        out['entrances'].append({'id': eid, 'floor': floor, 'ar': ar, 'en': en, 'at': centre(c, r)})

    # Every place must be reachable from every entrance (on its floor, or through a connector on the other one).
    for floor in grids:
        start = out['entrances'][0]['at'] if floor == 'ground' else out['connectors'][0]['at']['first']
        seen = reachable(grids[floor], cell_of(*start))
        for p in [*out['places'], *[{'id': c['id'], 'floor': f, 'door': xy} for c in out['connectors'] for f, xy in c['at'].items()], *out['entrances']]:
            if p['floor'] == floor and not seen[cell_of(*p['door'] if 'door' in p else p['at'])[::-1]]:
                problems.append(f"{p['id']} ({floor}) cannot be reached")

    if args.debug:
        Path(args.debug).mkdir(parents=True, exist_ok=True)
        for floor, grid in grids.items():
            im = Image.open(PLANS / f'{floor}.webp').convert('RGBA')
            over = Image.new('RGBA', im.size, (0, 0, 0, 0))
            d = ImageDraw.Draw(over)
            for r in range(ROWS):
                for c in range(COLS):
                    if grid[r, c]:
                        d.rectangle([c * CELL, r * CELL, c * CELL + CELL - 1, r * CELL + CELL - 1], fill=(0, 160, 255, 90))
            for p in out['places']:
                if p['floor'] == floor:
                    x, y = p['door']
                    d.ellipse([x - 5, y - 5, x + 5, y + 5], fill=(220, 0, 60, 255))
                    d.line([x, y, p['rect'][0] + p['rect'][2] / 2, p['rect'][1] + p['rect'][3] / 2], fill=(220, 0, 60, 200), width=2)
            for cn in out['connectors']:
                x, y = cn['at'][floor]
                d.ellipse([x - 6, y - 6, x + 6, y + 6], fill=(0, 170, 80, 255))
            for e in out['entrances']:
                if e['floor'] == floor:
                    x, y = e['at']
                    d.rectangle([x - 7, y - 7, x + 7, y + 7], fill=(120, 0, 200, 255))
            Image.alpha_composite(im, over).save(Path(args.debug) / f'{floor}-debug.png')

    if problems:
        print('\n'.join(problems))
        sys.exit(1)
    text = json.dumps(out, ensure_ascii=False, separators=(',', ':'))
    for path in OUT:
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text + '\n', encoding='utf-8', newline='\n')
    print(f"Wrote {len(out['places'])} places, {len(out['connectors'])} connectors, {len(out['entrances'])} entrances; {len(text) // 1024} KB")


if __name__ == '__main__':
    main()
