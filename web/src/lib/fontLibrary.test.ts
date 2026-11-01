import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { addFiles, familyFromName, familyInfo, fontFaceCss, formatOf, readSfnt, weightFromName, type FontFamily } from './fontLibrary.ts'
import { stacksFor } from './fontStacks.ts'

describe('font library: reading uploaded files', () => {
  it('reads the weight and family from the file name', () => {
    assert.equal(weightFromName('Lusail-Light.woff2'), 300)
    assert.equal(weightFromName('Lusail-Regular.otf'), 400)
    assert.equal(weightFromName('Lusail-Medium.woff'), 500)
    assert.equal(weightFromName('Cairo-SemiBold.ttf'), 600)
    assert.equal(weightFromName('Lusail-Bold.woff2'), 700)
    assert.equal(weightFromName('Cairo-ExtraBold.ttf'), 800)
    assert.equal(weightFromName('Font-Black.ttf'), 900)
    assert.equal(weightFromName('Plain.woff2'), 400)
    assert.equal(familyFromName('Lusail-Bold.woff2'), 'Lusail')
    assert.equal(familyFromName('IBMPlexSansArabic_SemiBold.ttf'), 'IBMPlexSansArabic')
    assert.equal(formatOf('Lusail-Bold.OTF'), 'otf')
    assert.equal(formatOf('x.eot'), null)
  })

  it('reads the family and weight inside an OpenType file', () => {
    // A minimal sfnt: header, a name table with family "Lusail" (Windows, UTF-16BE) and an OS/2 table with weight 500.
    const family = 'Lusail'
    const nameLen = 6 + 12 + family.length * 2
    const os2Len = 64
    const buf = new ArrayBuffer(12 + 2 * 16 + nameLen + os2Len)
    const v = new DataView(buf)
    v.setUint32(0, 0x4f54544f); v.setUint16(4, 2)
    const nameOff = 12 + 32
    const os2Off = nameOff + nameLen
    const rec = (i: number, tag: string, off: number, len: number) => { for (let k = 0; k < 4; k++) v.setUint8(12 + i * 16 + k, tag.charCodeAt(k)); v.setUint32(12 + i * 16 + 8, off); v.setUint32(12 + i * 16 + 12, len) }
    rec(0, 'name', nameOff, nameLen); rec(1, 'OS/2', os2Off, os2Len)
    v.setUint16(nameOff + 2, 1); v.setUint16(nameOff + 4, 18)
    v.setUint16(nameOff + 6, 3); v.setUint16(nameOff + 12, 1); v.setUint16(nameOff + 14, family.length * 2); v.setUint16(nameOff + 16, 0)
    for (let k = 0; k < family.length; k++) v.setUint16(nameOff + 18 + k * 2, family.charCodeAt(k))
    v.setUint16(os2Off + 4, 500)
    assert.deepEqual(readSfnt(buf), { family: 'Lusail', weight: 500, italic: false })
    assert.equal(readSfnt(new ArrayBuffer(16)), null)
  })
})

describe('font library: what the browser and the app receive', () => {
  const lusail: FontFamily = {
    id: 'lusail', family: 'Lusail', files: [
      { weight: 400, style: 'normal', format: 'otf', url: 'https://cdn.qa/Lusail-Regular.otf' },
      { weight: 400, style: 'normal', format: 'woff2', url: 'https://cdn.qa/Lusail-Regular.woff2' },
      { weight: 700, style: 'normal', format: 'woff2', url: 'https://cdn.qa/Lusail-Bold.woff2' },
      { weight: 700, style: 'normal', format: 'woff2', url: 'javascript:alert(1)' },
    ],
  }

  it('writes one rule per weight, the smallest format first, and never an unsafe address', () => {
    const css = fontFaceCss([lusail])
    assert.equal(css.split('@font-face').length - 1, 2)
    assert.ok(css.indexOf('Lusail-Regular.woff2') < css.indexOf('Lusail-Regular.otf'))
    assert.ok(css.includes('format("opentype")'))
    assert.ok(!css.includes('javascript'))
  })

  it('knows whether a family works in the app and merges new weights into it', () => {
    assert.deepEqual(familyInfo(lusail).weights, [400, 700])
    assert.equal(familyInfo(lusail).app, true)
    const more = addFiles([lusail], 'lusail', [{ weight: 300, style: 'normal', format: 'otf', url: 'https://cdn.qa/Lusail-Light.otf' }])
    assert.equal(more.length, 1)
    assert.deepEqual(familyInfo(more[0]).weights, [300, 400, 700])
    assert.equal(addFiles([lusail], 'Cairo', []).length, 2)
  })

  it('lets headings take a family of their own', () => {
    assert.ok(stacksFor('Lusail', 'Lusail', 'ar', true, 'Amiri').display.startsWith('"Amiri", "Lusail"'))
    assert.ok(stacksFor('Lusail', 'Lusail', 'ar', true).display.startsWith('"Lusail"'))
  })
})
