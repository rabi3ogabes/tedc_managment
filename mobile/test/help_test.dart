import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/features/help/help_screens.dart';

void main() {
  test('article HTML becomes plain blocks: headings, paragraphs, list items and quotes', () {
    final blocks = HelpArticleScreen.blocks('<p>Intro &amp; more</p><h3>Steps</h3><ol><li>One</li><li><strong>Two</strong></li></ol><blockquote>Tip</blockquote><script>x</script>');
    expect(blocks.map((b) => b.$1).toList(), ['p', 'h3', 'li', 'li', 'blockquote']);
    expect(blocks.map((b) => b.$2).toList(), ['Intro & more', 'Steps', 'One', 'Two', 'Tip']);
  });

  test('empty and tag-only HTML gives no blocks', () {
    expect(HelpArticleScreen.blocks(''), isEmpty);
    expect(HelpArticleScreen.blocks('<p>  </p>'), isEmpty);
  });
}
