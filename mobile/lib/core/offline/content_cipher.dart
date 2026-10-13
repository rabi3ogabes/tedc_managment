import 'dart:io';
import 'dart:math';
import 'dart:typed_data';

import 'package:cryptography/cryptography.dart';

/// A sealed file was changed, cut short, reordered or sealed under another key.
class ContentTampered implements Exception {
  const ContentTampered([this.reason = 'tampered']);

  final String reason;

  @override
  String toString() => 'ContentTampered($reason)';
}

/// Content kept for offline use is encrypted at rest with AES-256-GCM under the per-device content key the server
/// issued (kept only in the platform keystore). Large files are cut into chunks, each sealed on its own, so a video is
/// sealed and read back piece by piece without holding it in memory. Each chunk is bound to its position and to
/// whether it is the last one, so chunks cannot be reordered, dropped or cut off unnoticed.
///
/// Format: "TEDC1", the chunk size (4 bytes, big-endian), then per chunk: nonce (12) + ciphertext + MAC (16).
class ContentCipher {
  ContentCipher(List<int> key, {this.chunkSize = 1 << 20}) : _key = SecretKey(List<int>.unmodifiable(key)) {
    if (key.length != 32) throw ArgumentError('A 256-bit key is needed.');
    if (chunkSize < 16) throw ArgumentError('The chunk size is too small.');
  }

  static const _magic = [0x54, 0x45, 0x44, 0x43, 0x31]; // TEDC1
  static const _header = 9;
  static const _nonce = 12;
  static const _mac = 16;

  final SecretKey _key;
  final int chunkSize;
  final AesGcm _aes = AesGcm.with256bits();

  List<int> _aad(int index, bool last) {
    final b = ByteData(9)
      ..setUint32(0, index >> 32)
      ..setUint32(4, index & 0xFFFFFFFF)
      ..setUint8(8, last ? 1 : 0);
    return b.buffer.asUint8List();
  }

  List<int> _headerBytes() => [..._magic, ...(ByteData(4)..setUint32(0, chunkSize)).buffer.asUint8List()];

  int _readHeader(List<int> head) {
    if (head.length < _header) throw const ContentTampered('header');
    for (var i = 0; i < _magic.length; i++) {
      if (head[i] != _magic[i]) throw const ContentTampered('header');
    }
    final size = ByteData.sublistView(Uint8List.fromList(head.sublist(5, 9))).getUint32(0);
    if (size < 16) throw const ContentTampered('header');
    return size;
  }

  Future<List<int>> _seal(List<int> plain, int index, bool last) async {
    final box = await _aes.encrypt(plain, secretKey: _key, nonce: _aes.newNonce(), aad: _aad(index, last));
    return [...box.nonce, ...box.cipherText, ...box.mac.bytes];
  }

  Future<List<int>> _open(List<int> record, int index, bool last) async {
    if (record.length < _nonce + _mac) throw const ContentTampered('short');
    final box = SecretBox(record.sublist(_nonce, record.length - _mac), nonce: record.sublist(0, _nonce), mac: Mac(record.sublist(record.length - _mac)));
    try {
      return await _aes.decrypt(box, secretKey: _key, aad: _aad(index, last));
    } on SecretBoxAuthenticationError {
      throw const ContentTampered('mac');
    }
  }

  Future<List<int>> encryptBytes(List<int> plain) async {
    final out = BytesBuilder(copy: false)..add(_headerBytes());
    var offset = 0;
    var index = 0;
    do {
      final end = min(offset + chunkSize, plain.length);
      out.add(await _seal(plain.sublist(offset, end), index++, end >= plain.length));
      offset = end;
    } while (offset < plain.length);
    return out.takeBytes();
  }

  Future<List<int>> decryptBytes(List<int> sealed) async {
    final size = _readHeader(sealed);
    final out = BytesBuilder(copy: false);
    var pos = _header;
    var index = 0;
    if (pos >= sealed.length) throw const ContentTampered('empty');
    while (pos < sealed.length) {
      final len = min(_nonce + size + _mac, sealed.length - pos);
      out.add(await _open(sealed.sublist(pos, pos + len), index++, pos + len == sealed.length));
      pos += len;
    }
    return out.takeBytes();
  }

  /// Seals [plain] into [out] chunk by chunk.
  Future<void> encryptFile(File plain, File out) async {
    final input = await plain.open();
    final sink = out.openWrite();
    try {
      final length = await input.length();
      sink.add(_headerBytes());
      var offset = 0;
      var index = 0;
      do {
        final n = min(chunkSize, length - offset);
        final chunk = await input.read(n);
        offset += chunk.length;
        sink.add(await _seal(chunk, index++, offset >= length));
      } while (offset < length);
    } finally {
      await input.close();
      await sink.close();
    }
  }

  /// Opens a sealed file into [out]; throws [ContentTampered] and leaves no partial output when anything is wrong.
  Future<void> decryptFile(File sealed, File out) async {
    final input = await sealed.open();
    final sink = out.openWrite();
    var ok = false;
    try {
      final length = await input.length();
      final size = _readHeader(await input.read(_header));
      var pos = _header;
      var index = 0;
      if (pos >= length) throw const ContentTampered('empty');
      while (pos < length) {
        final len = min(_nonce + size + _mac, length - pos);
        final record = await input.read(len);
        if (record.length != len) throw const ContentTampered('short');
        sink.add(await _open(record, index++, pos + len == length));
        pos += len;
      }
      ok = true;
    } finally {
      await input.close();
      await sink.close();
      if (!ok && await out.exists()) await out.delete();
    }
  }
}
