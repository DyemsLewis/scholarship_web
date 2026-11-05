"""Build the local map guard from pinned boundary and shoreline source files.

See docs/philippine-map-validation.md for source downloads and licensing.
Only this build-time script uses the source archives; production reads the JSON.
"""
import hashlib
import json
import pathlib
import struct
import tarfile
import zipfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
AREA = ROOT / 'storage/deployment'
OUT = ROOT / 'public/maps'


def simplify(points, tolerance=0.00010):
    # Iterative Douglas-Peucker; about 11 metres, with all islands retained.
    if len(points) <= 4:
        return points
    keep = {0, len(points) - 1}
    stack = [(0, len(points) - 1)]
    while stack:
        start, end = stack.pop()
        ax, ay = points[start]
        bx, by = points[end]
        dx, dy = bx - ax, by - ay
        length = dx * dx + dy * dy
        maximum, selected = tolerance * tolerance, None
        for i in range(start + 1, end):
            px, py = points[i]
            t = max(0, min(1, ((px - ax) * dx + (py - ay) * dy) / length)) if length else 0
            distance = (px - ax - t * dx) ** 2 + (py - ay - t * dy) ** 2
            if distance > maximum:
                maximum, selected = distance, i
        if selected is not None:
            keep.add(selected)
            stack.extend([(start, selected), (selected, end)])
    result = [points[i] for i in sorted(keep)]
    return result if len(result) >= 4 else points


def polygon(rings):
    rings = [[[round(x, 6), round(y, 6)] for x, y in simplify(ring)] for ring in rings]
    xs, ys = zip(*rings[0])
    return {'bounds': [min(xs), min(ys), max(xs), max(ys)], 'rings': [encode(ring) for ring in rings]}


def encode(ring):
    result, previous = [], [0, 0]
    for point in ring:
        for axis in (0, 1):
            number = round(point[axis] * 1e6)
            delta = number - previous[axis]
            previous[axis] = number
            value = ~(delta << 1) if delta < 0 else delta << 1
            while value >= 32:
                result.append(chr((32 | (value & 31)) + 63))
                value >>= 5
            result.append(chr(value + 63))
    return ''.join(result)


def build():
    source = AREA / 'philippines-adm0-source.geojson'
    assert hashlib.sha256(source.read_bytes()).hexdigest() == '7c3a7a39dacb5fb0c14d150061306fa5839455fbf93231854af3c6d74a1fa24f'
    geometry = json.loads(source.read_text(encoding='utf-8'))['features'][0]['geometry']
    assert geometry['type'] == 'MultiPolygon'
    data = {'version': 1, 'encoding': 'polyline6-xy', 'land': [polygon(rings) for rings in geometry['coordinates']],
            'water': [], 'lakeIslands': [], 'ponds': []}
    archive_path = AREA / 'gshhg-bin-2.3.7.zip'
    with zipfile.ZipFile(archive_path) as archive:
        name = next(n for n in archive.namelist() if n.endswith('gshhs_f.b'))
        with archive.open(name) as stream:
            while header := stream.read(44):
                _, count, flags, west, east, south, north, *_ = struct.unpack('>11i', header)
                level = flags & 255
                raw = stream.read(count * 8)
                if level not in (2, 3, 4) or east < 116_000_000 or west > 128_000_000 or north < 4_000_000 or south > 22_000_000:
                    continue
                ring = [[x / 1e6, y / 1e6] for x, y in struct.iter_unpack('>ii', raw)]
                data[{2: 'water', 3: 'lakeIslands', 4: 'ponds'}[level]].append(polygon([ring]))
        OUT.mkdir(parents=True, exist_ok=True)
        for suffix in ['COPYING.LESSERv3', 'LICENSE.TXT']:
            name = next(n for n in archive.namelist() if n.endswith(suffix))
            (OUT / ('GSHHG-' + suffix)).write_bytes(archive.read(name))
    with tarfile.open(AREA / 'gshhg-gmt-2.3.7.tar.gz') as archive:
        (OUT / 'GSHHG-COPYINGv3').write_bytes(archive.extractfile('gshhg-gmt-2.3.7/COPYINGv3').read())
    output = OUT / 'philippines-land-v1.json'
    output.write_text(json.dumps(data, separators=(',', ':')) + '\n', encoding='utf-8')
    print(json.dumps({'bytes': output.stat().st_size, 'sha256': hashlib.sha256(output.read_bytes()).hexdigest(),
                      'polygons': {key: len(data[key]) for key in ['land', 'water', 'lakeIslands', 'ponds']}}))


if __name__ == '__main__':
    build()
