import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/l10n/strings.dart';
import 'package:tedc_mobile/features/wayfinding/engine.dart';
import 'package:tedc_mobile/features/wayfinding/wayfinding_data.dart';

/// The centre guide on the phone (docs/wayfinding.md): the same map and algorithm as the website, so every room can be reached.
void main() {
  final raw = jsonDecode(File('../backend/resources/wayfinding/centre-map.json').readAsStringSync()) as Map<String, dynamic>;
  final data = WayData({'map': raw, 'entrances': raw['entrances'], 'signs': const [], 'rooms': const [], 'centre': null});
  final engine = data.engine;

  test('every place is reached from every entrance, by the stairs and, avoiding stairs, by a lift', () {
    for (final e in data.entrances) {
      for (final p in data.map.places) {
        for (final avoid in [false, true]) {
          final r = engine.route(e.floor, e.at, Target(p.floor, p.door, rect: p.rect, id: p.id), avoidStairs: avoid);
          expect(r, isNotNull, reason: '${e.id} → ${p.id}');
          expect(r!.steps.first.kind, 'start');
          expect(r.steps.last.kind, 'arrive');
          expect(r.metres, inInclusiveRange(1, 400));
          if (p.floor != e.floor) {
            expect(r.legs.length, 2);
            if (avoid) expect(r.connector!.kind, 'lift');
            expect(r.steps.firstWhere((s) => s.kind == 'stairs' || s.kind == 'lift').up, isTrue);
          }
        }
      }
    }
  });

  test('a route reads as sentences and names which hand the door is on', () {
    final dest = data.destination('f-room-14')!;
    final origin = data.defaultOrigin;
    final r = engine.route(origin.floor, origin.at, dest.target!)!;
    final s = S('ar');
    final texts = r.steps.map((x) => stepText(x, s, data.map, dest.ar, origin.ar)).toList();
    expect(texts.first, startsWith('ابدأ من'));
    expect(texts.last, contains('قاعة التدريب 14'));
    expect(texts.any((t) => t.contains('الطابق الأول')), isTrue);
    expect(texts.every((t) => !t.contains('{')), isTrue);
  });

  test('a sign\'s QR code gives the starting point, and a tapped point snaps to the corridor', () {
    expect(signKey('https://tedc-managment.vercel.app/map?from=entrance-west'), 'entrance-west');
    expect(signKey('g-room-5'), 'g-room-5');
    expect(signKey('javascript:alert(1)'), isNull);
    final o = data.origin('ground:5,5')!;
    expect(o.floor, 'ground');
    expect(data.origin('stairs-west@first')!.floor, 'first');
    expect(data.origin('nowhere'), isNull);
    expect(o.at.x > 5 || o.at.y > 5, isTrue);
  });
}
