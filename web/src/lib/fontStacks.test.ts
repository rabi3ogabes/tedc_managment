import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { safeFamily, stacksFor } from './fontStacks.ts'

const first = (stack: string) => stack.split(',')[0].trim()

describe('font stacks: where the Ministry typeface leads', () => {
  it('leads in Arabic once its files are in place, when the brand kept the default font', () => {
    assert.equal(first(stacksFor('Qatar Sans', 'Qatar Sans', 'ar', true).sans), '"Lusail"')
    assert.equal(first(stacksFor('Qatar Sans', 'Qatar Sans', 'en', true).sans), '"Lusail"')
    assert.equal(first(stacksFor('Qatar Sans', 'Qatar Sans', 'ar', true).display), '"Lusail"')
  })

  it('never leads without its files, so nothing is requested and the fallback stands', () => {
    assert.equal(first(stacksFor('Qatar Sans', 'Qatar Sans', 'ar', false).sans), '"Qatar Sans"')
    assert.equal(first(stacksFor('Qatar Sans', 'Qatar Sans', 'en', false).display), '"Qatar Sans"')
  })

  it('leaves the brand\'s own choice in front: an administrator who picked a font keeps it', () => {
    assert.equal(first(stacksFor('Cairo', 'Poppins', 'ar', true).sans), '"Cairo"')
    assert.equal(first(stacksFor('Cairo', 'Poppins', 'en', true).sans), '"Poppins"')
  })

  it('keeps Lusail in every stack as a fallback for the glyphs the first font lacks, and the bundled fonts behind it', () => {
    for (const lang of ['ar', 'en']) {
      const { sans, display } = stacksFor('Cairo', 'Poppins', lang, false)
      assert.ok(sans.includes('"Lusail"') && sans.includes('"Tajawal"'), `${lang} sans`)
      assert.ok(display.includes('"Lusail"') && display.includes('"El Messiri"'), `${lang} display`)
    }
  })

  it('refuses characters that could break out of a CSS value', () => {
    assert.equal(safeFamily('Evil";}body{display:none'), 'Evilbodydisplaynone')
    assert.equal(safeFamily('  '), 'Qatar Sans')
    assert.equal(safeFamily('IBM Plex Sans Arabic'), 'IBM Plex Sans Arabic')
  })
})
