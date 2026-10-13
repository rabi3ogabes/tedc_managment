import 'dart:io';
import 'dart:math';

import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/offline/content_cipher.dart';

List<int> _bytes(int n, [int seed = 7]) {
  final r = Random(seed);
  return List<int>.generate(n, (_) => r.nextInt(256));
}

void main() {
  final key = _bytes(32, 1);

  test('bytes come back exactly, and the sealed form does not contain them', () async {
    final cipher = ContentCipher(key, chunkSize: 64);
    final plain = _bytes(1000);
    final sealed = await cipher.encryptBytes(plain);
    expect(sealed.length, greaterThan(plain.length));
    expect(String.fromCharCodes(sealed).contains(String.fromCharCodes(plain.sublist(0, 32))), isFalse);
    expect(await cipher.decryptBytes(sealed), plain);
    expect(await cipher.decryptBytes(await cipher.encryptBytes(const [])), isEmpty);
  });

  test('a file is sealed in chunks and read back the same', () async {
    final dir = await Directory.systemTemp.createTemp('tedc-cipher');
    addTearDown(() => dir.delete(recursive: true));
    final plain = File('${dir.path}/plain.bin')..writeAsBytesSync(_bytes(5 * 1024 + 17));
    final sealed = File('${dir.path}/sealed.bin');
    final back = File('${dir.path}/back.bin');
    final cipher = ContentCipher(key, chunkSize: 1024);

    await cipher.encryptFile(plain, sealed);
    await cipher.decryptFile(sealed, back);

    expect(back.readAsBytesSync(), plain.readAsBytesSync());
    expect(sealed.lengthSync(), greaterThan(plain.lengthSync()));
  });

  test('a changed, cut or reordered file is refused, and another key cannot read it', () async {
    final cipher = ContentCipher(key, chunkSize: 64);
    final sealed = await cipher.encryptBytes(_bytes(300));

    final flipped = [...sealed]..[40] ^= 0x01;
    await expectLater(cipher.decryptBytes(flipped), throwsA(isA<ContentTampered>()));
    await expectLater(cipher.decryptBytes(sealed.sublist(0, sealed.length - 92)), throwsA(isA<ContentTampered>()));   // the last chunk removed
    await expectLater(ContentCipher(_bytes(32, 2)).decryptBytes(sealed), throwsA(isA<ContentTampered>()));
    expect(() => ContentCipher(_bytes(16)), throwsArgumentError);
  });
}
