import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/features/help/help_screens.dart';

void main() {
  test('article HTML becomes plain blocks: headings, paragraphs, list items and quotes', () {
    final blocks = helpBlocks('<p>Intro &amp; more</p><h3>Steps</h3><ol><li>One</li><li><strong>Two</strong></li></ol><blockquote>Tip</blockquote><script>x</script>');
    expect(blocks.map((b) => b.$1).toList(), ['p', 'h3', 'li', 'li', 'blockquote']);
    expect(blocks.map((b) => b.$2).toList(), ['Intro & more', 'Steps', '1. One', '2. Two', 'Tip']);
  });

  test('empty and tag-only HTML gives no blocks', () {
    expect(helpBlocks(''), isEmpty);
    expect(helpBlocks('<p>  </p>'), isEmpty);
  });
}
