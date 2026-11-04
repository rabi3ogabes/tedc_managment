import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/features/wayfinding/wayfinding_data.dart';

/// The administrator's side of the guide reaches the phone: a place's main door is where the walk ends, and the people of an office can be found by name.
Map<String, dynamic> _raw() => jsonDecode(File('../backend/resources/wayfinding/centre-map.json').readAsStringSync()) as Map<String, dynamic>;

WayData _data(Object? places) {
  final raw = _raw();
  return WayData({'map': raw, 'entrances': raw['entrances'], 'signs': const [], 'rooms': const [], 'centre': null, 'places': places});
}

void main() {
  test('a place keeps the plan\'s door until the administrator sets its main door', () {
    final plain = _data(const []);
    final office = plain.destination('f-offices-west')!;
    final planDoor = office.target!.at;

    final set = _data({
      'f-offices-west': {
        'door': [300, 700],
        'people': [
          {'id': 'p1', 'name_ar': 'خالد المري', 'name_en': 'Khalid Al-Marri', 'title_ar': 'منسق التدريب', 'title_en': 'Training coordinator', 'desk': 'مكتب 12'},
          {'id': 'p2', 'name_ar': 'سارة الهاجري', 'name_en': '', 'title_ar': '', 'title_en': 'Planning', 'desk': ''},
        ],
      },
    });
    final moved = set.destination('f-offices-west')!;
    expect(moved.target!.at.x, 300);
    expect(moved.target!.at.y, 700);
    expect(moved.target!.at, isNot(planDoor));
    // The walk starts from a place's main door too.
    expect(set.origin('f-offices-west')!.at.x, 300);
    // Other places are untouched.
    expect(set.destination('g-library')!.target!.at, plain.destination('g-library')!.target!.at);
    // A route to the office ends at the new door.
    final from = set.defaultOrigin;
    expect(set.engine.route(from.floor, from.at, moved.target!), isNotNull);
  });

  test('the people of an office come with the place, in the visitor\'s language, and a search can match any of it', () {
    final data = _data({
      'f-offices-west': {
        'door': null,
        'people': [
          {'id': 'p1', 'name_ar': 'خالد المري', 'name_en': 'Khalid Al-Marri', 'title_ar': 'منسق التدريب', 'title_en': 'Training coordinator', 'desk': 'مكتب 12'},
          {'id': 'p2', 'name_ar': 'سارة الهاجري', 'name_en': '', 'title_ar': '', 'title_en': 'Planning', 'desk': ''},
        ],
      },
    });
    final people = data.destination('f-offices-west')!.people;
    expect(people.length, 2);
    expect(people[0].line(false), 'خالد المري · منسق التدريب · مكتب 12');
    expect(people[0].line(true), 'Khalid Al-Marri · Training coordinator · مكتب 12');
    // a missing English name falls back on the Arabic one, a missing Arabic title on the English one
    expect(people[1].line(true), 'سارة الهاجري · Planning');
    expect(people[1].line(false), 'سارة الهاجري · Planning');
    expect(people[0].haystack, contains('Al-Marri'));
    expect(people[0].haystack, contains('مكتب 12'));
    // a place without people has none
    expect(data.destination('g-library')!.people, isEmpty);
  });

  test('a server with nothing set sends an empty list, not a map: the guide still loads', () {
    expect(_data(const []).placeInfo, isEmpty);
    expect(_data(null).placeInfo, isEmpty);
    expect(_data(const {}).destinations, isNotEmpty);
  });
}
