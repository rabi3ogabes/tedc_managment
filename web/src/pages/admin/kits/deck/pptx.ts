import JSZip from 'jszip'
import { H, W, clone, imageEl, para, shapeEl, textEl, uid, DEFAULT_THEME, type Deck, type El, type Paragraph, type Slide, type Theme } from './model'

const EMU_PX = 9525
const hex = (c: string | null | undefined) => (c ?? '000000').replace('#', '').toUpperCase()

// ------------------------------------------------------------------------------------------------
// Export (deck -> .pptx) using pptxgenjs
// ------------------------------------------------------------------------------------------------

const IN = 13.333 / W // inches per canvas px
const PT = 0.75 // points per canvas px

async function toDataUri(url: string, box?: { w: number; h: number }): Promise<string | null> {
  try {
    const blob = await (await fetch(url)).blob()
    if (blob.type === 'image/svg+xml') return await rasterizeSvg(blob, box ?? { w: 1280, h: 720 })
    return await new Promise<string>((resolve, reject) => {
      const r = new FileReader()
      r.onload = () => resolve(String(r.result))
      r.onerror = reject
      r.readAsDataURL(blob)
    })
  } catch {
    return null
  }
}

async function rasterizeSvg(blob: Blob, box: { w: number; h: number }): Promise<string | null> {
  const url = URL.createObjectURL(blob)
  try {
    const img = await new Promise<HTMLImageElement>((resolve, reject) => {
      const i = new Image()
      i.onload = () => resolve(i)
      i.onerror = reject
      i.src = url
    })
    const scale = 2
    const canvas = document.createElement('canvas')
    canvas.width = Math.round(box.w * scale)
    canvas.height = Math.round(box.h * scale)
    const ctx = canvas.getContext('2d')
    if (!ctx) return null
    ctx.drawImage(img, 0, 0, canvas.width, canvas.height)
    return canvas.toDataURL('image/png')
  } catch {
    return null
  } finally {
    URL.revokeObjectURL(url)
  }
}

/** The file as a data URI, unless it is bigger than the limit (then null: the slide links to it instead). */
async function toDataUriLimited(url: string, limit: number): Promise<string | null> {
  try {
    const res = await fetch(url)
    if (!res.ok) return null
    const blob = await res.blob()
    if (blob.size > limit) return null
    return await new Promise<string>((resolve, reject) => { const r = new FileReader(); r.onload = () => resolve(String(r.result)); r.onerror = reject; r.readAsDataURL(blob) })
  } catch { return null }
}

export async function exportPptx(deck: Deck, title: string): Promise<Blob> {
  const PptxGenJS = (await import('pptxgenjs')).default
  const pptx = new PptxGenJS()
  pptx.defineLayout({ name: 'TEDC169', width: 13.333, height: 7.5 })
  pptx.layout = 'TEDC169'
  pptx.title = title
  pptx.author = 'TEDC'
  const rtl = deck.theme.dir === 'rtl'
  const font = deck.theme.font || 'Calibri'

  for (const slide of deck.slides) {
    const s = pptx.addSlide()
    if (slide.background.src) {
      const data = await toDataUri(slide.background.src)
      if (data) s.background = { data }
      else s.background = { color: hex(slide.background.color) }
    } else s.background = { color: hex(slide.background.color) }

    for (const el of slide.elements) {
      const pos = { x: el.x * IN, y: el.y * IN, w: el.w * IN, h: el.h * IN }
      if (el.type === 'shape') {
        const type = el.shape === 'ellipse' ? pptx.ShapeType.ellipse : el.shape === 'line' ? pptx.ShapeType.line : el.shape === 'round' ? pptx.ShapeType.roundRect : pptx.ShapeType.rect
        s.addShape(type, {
          ...pos, rotate: el.rotation || undefined,
          fill: el.fill ? { color: hex(el.fill), transparency: Math.round((1 - el.opacity) * 100) } : { type: 'none' },
          line: el.stroke && el.strokeWidth > 0 ? { color: hex(el.stroke), width: el.strokeWidth * PT } : { type: 'none' },
          rectRadius: el.shape === 'round' ? Math.min(0.5, (el.radius || 16) * IN) : undefined,
        })
      } else if (el.type === 'video' || el.type === 'audio') {
        // Embedded when the file is a reasonable size; otherwise a labelled tile that links to it.
        if (!el.src) continue
        const media = await toDataUriLimited(el.src, 25 * 1024 * 1024)
        const options = { ...pos, rotate: el.rotation || undefined }
        if (media) {
          ;(s as unknown as { addMedia: (o: Record<string, unknown>) => void }).addMedia({ type: el.type, data: media, ...options })
        } else {
          s.addShape('roundRect', { ...options, fill: { color: '8A1538' }, line: { type: 'none' }, rectRadius: 0.1 })
          s.addText([{ text: `${el.type === 'video' ? '▶ ' : '♪ '}${el.alt || ''}`, options: { color: 'FFFFFF', fontSize: 16, hyperlink: { url: el.src } } }], { ...options, align: 'center', valign: 'middle' })
        }
      } else if (el.type === 'image') {
        if (!el.src) continue
        const data = await toDataUri(el.src, { w: el.w, h: el.h })
        if (!data) continue
        s.addImage({ data, ...pos, rotate: el.rotation || undefined, altText: el.alt || undefined, sizing: { type: el.fit === 'contain' ? 'contain' : 'cover', w: pos.w, h: pos.h } })
      } else {
        const runs = el.paragraphs.map((p) => ({
          text: p.text || ' ',
          options: {
            bold: p.bold, italic: p.italic, color: hex(p.color), fontSize: Math.max(6, Math.round(p.size * PT)), align: p.align, fontFace: el.style.fontFamily || font,
            bullet: p.bullet ? { indent: 18 } : undefined, indentLevel: p.bullet ? p.level : undefined, breakLine: true, rtlMode: rtl, lang: rtl ? 'ar-QA' : 'en-US',
          },
        }))
        s.addText(runs, {
          ...pos, rotate: el.rotation || undefined, valign: el.style.valign, margin: (el.style.padding || 0) * PT, lineSpacingMultiple: el.style.lineHeight || 1.2,
          fill: el.style.fill ? { color: hex(el.style.fill) } : undefined, rtlMode: rtl, fit: 'none', wrap: true,
        })
      }
    }
    if (slide.notes) s.addNotes(slide.notes)
  }
  return (await pptx.write({ outputType: 'blob' })) as Blob
}

// ------------------------------------------------------------------------------------------------
// Import (.pptx -> deck) by reading the OOXML package
// ------------------------------------------------------------------------------------------------

type Xf = { x: number; y: number; w: number; h: number; rot: number }
type Grp = { ox: number; oy: number; sx: number; sy: number }
const ID: Grp = { ox: 0, oy: 0, sx: 1, sy: 1 }

const kids = (el: Element | null | undefined, tag: string) => (el ? Array.from(el.children).filter((c) => c.tagName === tag) : [])
const kid = (el: Element | null | undefined, tag: string) => kids(el, tag)[0] ?? null
const path = (el: Element | null | undefined, ...tags: string[]) => tags.reduce<Element | null>((cur, t) => kid(cur, t), el ?? null)
const num = (v: string | null | undefined, d = 0) => (v == null || v === '' || Number.isNaN(Number(v)) ? d : Number(v))
const isArabic = (t: string) => /[؀-ۿ]/.test(t)

function parseXml(text: string): Document {
  return new DOMParser().parseFromString(text, 'application/xml')
}

function hslAdjust(rgb: string, lumMod = 1, lumOff = 0): string {
  const r = parseInt(rgb.slice(0, 2), 16) / 255, g = parseInt(rgb.slice(2, 4), 16) / 255, b = parseInt(rgb.slice(4, 6), 16) / 255
  const max = Math.max(r, g, b), min = Math.min(r, g, b)
  let h = 0, s = 0
  const l = (max + min) / 2
  if (max !== min) {
    const d = max - min
    s = l > 0.5 ? d / (2 - max - min) : d / (max + min)
    h = max === r ? (g - b) / d + (g < b ? 6 : 0) : max === g ? (b - r) / d + 2 : (r - g) / d + 4
    h /= 6
  }
  const l2 = Math.max(0, Math.min(1, l * lumMod + lumOff))
  const f = (p: number, q: number, t: number) => { let x = t; if (x < 0) x += 1; if (x > 1) x -= 1; return x < 1 / 6 ? p + (q - p) * 6 * x : x < 1 / 2 ? q : x < 2 / 3 ? p + (q - p) * (2 / 3 - x) * 6 : p }
  let rr = l2, gg = l2, bb = l2
  if (s !== 0) {
    const q = l2 < 0.5 ? l2 * (1 + s) : l2 + s - l2 * s
    const p = 2 * l2 - q
    rr = f(p, q, h + 1 / 3); gg = f(p, q, h); bb = f(p, q, h - 1 / 3)
  }
  const to = (v: number) => Math.round(v * 255).toString(16).padStart(2, '0')
  return (to(rr) + to(gg) + to(bb)).toUpperCase()
}

class PptxReader {
  zip!: JSZip
  scale = 1
  offX = 0
  offY = 0
  theme: Record<string, string> = {}
  masterStyles: { title?: number; body: Record<number, number>; other?: number } = { body: {} }
  warnings: string[] = []
  images = new Map<string, string>()

  async read(file: File): Promise<{ deck: Deck; warnings: string[] }> {
    this.zip = await JSZip.loadAsync(await file.arrayBuffer())
    const presentation = parseXml(await this.text('ppt/presentation.xml'))
    const sz = presentation.getElementsByTagName('p:sldSz')[0]
    const cx = num(sz?.getAttribute('cx'), 12192000), cy = num(sz?.getAttribute('cy'), 6858000)
    this.scale = Math.min((W * EMU_PX) / cx, (H * EMU_PX) / cy) / EMU_PX // px per EMU... computed below
    const s = Math.min(W / (cx / EMU_PX), H / (cy / EMU_PX))
    this.scale = s / EMU_PX
    this.offX = (W - (cx / EMU_PX) * s) / 2
    this.offY = (H - (cy / EMU_PX) * s) / 2

    await this.loadTheme()
    const rels = await this.rels('ppt/_rels/presentation.xml.rels')
    const order = Array.from(presentation.getElementsByTagName('p:sldId')).map((n) => rels.get(n.getAttribute('r:id') ?? '')).filter((p): p is string => !!p)

    const slides: Slide[] = []
    const allText: string[] = []
    for (const target of order) {
      const slidePath = 'ppt/' + target.replace(/^\/?(ppt\/)?/, '')
      const slide = await this.slide(slidePath, slides.length + 1)
      slides.push(slide)
      slide.elements.forEach((e) => e.type === 'text' && allText.push(...e.paragraphs.map((p) => p.text)))
    }
    const joined = allText.join('')
    const arabic = joined.length > 0 && (joined.match(/[؀-ۿ]/g)?.length ?? 0) / joined.length > 0.3
    const theme: Theme = { ...DEFAULT_THEME, dir: arabic ? 'rtl' : 'ltr', primary: this.theme.accent1 ? '#' + this.theme.accent1 : DEFAULT_THEME.primary, accent: this.theme.accent2 ? '#' + this.theme.accent2 : DEFAULT_THEME.accent, font: this.theme.font || DEFAULT_THEME.font }
    if (!arabic) slides.forEach((sl) => sl.elements.forEach((e) => e.type === 'text' && e.paragraphs.forEach((p) => { if (p.align === 'right' && !isArabic(p.text)) p.align = 'left' })))
    return { deck: { version: 1, size: { w: W, h: H }, theme, slides }, warnings: this.warnings }
  }

  private async text(p: string): Promise<string> {
    const f = this.zip.file(p)
    if (!f) throw new Error(`Missing ${p}`)
    return f.async('string')
  }

  private async rels(p: string): Promise<Map<string, string>> {
    const f = this.zip.file(p)
    const map = new Map<string, string>()
    if (!f) return map
    const doc = parseXml(await f.async('string'))
    Array.from(doc.getElementsByTagName('Relationship')).forEach((r) => {
      const base = p.replace(/_rels\/[^/]+\.rels$/, '')
      let target = r.getAttribute('Target') ?? ''
      if (!r.getAttribute('TargetMode')) {
        target = target.startsWith('/') ? target.slice(1) : this.resolve(base, target)
        map.set(r.getAttribute('Id') ?? '', target.replace(/^ppt\//, ''))
        map.set('type:' + (r.getAttribute('Type') ?? '').split('/').pop() + ':' + (r.getAttribute('Id') ?? ''), target.replace(/^ppt\//, ''))
      }
    })
    return map
  }

  private resolve(base: string, rel: string): string {
    const parts = (base + rel).split('/')
    const out: string[] = []
    for (const part of parts) {
      if (part === '..') out.pop()
      else if (part !== '.' && part !== '') out.push(part)
    }
    return out.join('/')
  }

  private async loadTheme() {
    const themePath = Object.keys(this.zip.files).find((f) => /^ppt\/theme\/theme\d+\.xml$/.test(f))
    if (!themePath) return
    const doc = parseXml(await this.text(themePath))
    const scheme = doc.getElementsByTagName('a:clrScheme')[0]
    Array.from(scheme?.children ?? []).forEach((c) => {
      const v = c.firstElementChild
      const val = v?.getAttribute('val') ?? v?.getAttribute('lastClr')
      if (val && /^[0-9a-f]{6}$/i.test(val)) this.theme[c.tagName.replace('a:', '')] = val.toUpperCase()
    })
    const latin = doc.getElementsByTagName('a:latin')[0]?.getAttribute('typeface')
    if (latin && !latin.startsWith('+')) this.theme.font = latin
    const masterPath = Object.keys(this.zip.files).find((f) => /^ppt\/slideMasters\/slideMaster1\.xml$/.test(f))
    if (masterPath) {
      const m = parseXml(await this.text(masterPath))
      const styles = m.getElementsByTagName('p:txStyles')[0]
      const sz = (parent: Element | null, lvl: number) => num(path(parent, `a:lvl${lvl}pPr`, 'a:defRPr')?.getAttribute('sz'), 0)
      const titleStyle = path(styles, 'p:titleStyle'), bodyStyle = path(styles, 'p:bodyStyle'), otherStyle = path(styles, 'p:otherStyle')
      this.masterStyles = { title: sz(titleStyle, 1) || undefined, body: Object.fromEntries([1, 2, 3, 4, 5].map((l) => [l, sz(bodyStyle, l)]).filter(([, v]) => v)), other: sz(otherStyle, 1) || undefined }
    }
  }

  private color(node: Element | null | undefined): string | null {
    if (!node) return null
    const fill = node.tagName === 'a:solidFill' ? node : kid(node, 'a:solidFill')
    const c = fill?.firstElementChild
    if (!c) return null
    let rgb: string | null = null
    if (c.tagName === 'a:srgbClr') rgb = c.getAttribute('val')
    else if (c.tagName === 'a:sysClr') rgb = c.getAttribute('lastClr')
    else if (c.tagName === 'a:schemeClr') {
      const map: Record<string, string> = { tx1: 'dk1', bg1: 'lt1', tx2: 'dk2', bg2: 'lt2' }
      const key = c.getAttribute('val') ?? ''
      rgb = this.theme[map[key] ?? key] ?? null
    }
    if (!rgb || !/^[0-9a-f]{6}$/i.test(rgb)) return null
    const mod = kid(c, 'a:lumMod'), off = kid(c, 'a:lumOff')
    if (mod || off) rgb = hslAdjust(rgb.toUpperCase(), mod ? num(mod.getAttribute('val'), 100000) / 100000 : 1, off ? num(off.getAttribute('val'), 0) / 100000 : 0)
    return '#' + rgb.toUpperCase()
  }

  private xfrm(node: Element | null | undefined): Xf | null {
    const x = node
    if (!x) return null
    const off = kid(x, 'a:off') ?? kid(x, 'p:off'), ext = kid(x, 'a:ext') ?? kid(x, 'p:ext')
    if (!off || !ext) return null
    return { x: num(off.getAttribute('x')), y: num(off.getAttribute('y')), w: num(ext.getAttribute('cx')), h: num(ext.getAttribute('cy')), rot: num(x.getAttribute('rot')) / 60000 }
  }

  private px(v: number, axis: 'x' | 'y', g: Grp): number {
    const gv = axis === 'x' ? g.ox + v * g.sx : g.oy + v * g.sy
    return Math.round(gv * this.scale + (axis === 'x' ? this.offX : this.offY))
  }

  private box(xf: Xf, g: Grp) {
    return { x: this.px(xf.x, 'x', g), y: this.px(xf.y, 'y', g), w: Math.max(1, Math.round(xf.w * g.sx * this.scale)), h: Math.max(1, Math.round(xf.h * g.sy * this.scale)) }
  }

  private async layoutFor(slideRels: Map<string, string>): Promise<{ layout: Document | null; master: Document | null }> {
    const layoutPath = [...slideRels.entries()].find(([k]) => k.startsWith('type:slideLayout:'))?.[1]
    if (!layoutPath || !this.zip.file('ppt/' + layoutPath)) return { layout: null, master: null }
    const layout = parseXml(await this.text('ppt/' + layoutPath))
    const lrels = await this.rels('ppt/' + layoutPath.replace(/([^/]+)$/, '_rels/$1.rels'))
    const masterPath = [...lrels.entries()].find(([k]) => k.startsWith('type:slideMaster:'))?.[1]
    const master = masterPath && this.zip.file('ppt/' + masterPath) ? parseXml(await this.text('ppt/' + masterPath)) : null
    return { layout, master }
  }

  private phXfrm(doc: Document | null, ph: Element): Xf | null {
    if (!doc) return null
    const type = ph.getAttribute('type'), idx = ph.getAttribute('idx')
    for (const sp of Array.from(doc.getElementsByTagName('p:sp'))) {
      const p = sp.getElementsByTagName('p:ph')[0]
      if (!p) continue
      const same = idx != null && p.getAttribute('idx') === idx ? true : type != null && p.getAttribute('type') === type
      if (same) return this.xfrm(path(sp, 'p:spPr', 'a:xfrm'))
    }
    return null
  }

  private async slide(slidePath: string, index: number): Promise<Slide> {
    const doc = parseXml(await this.text(slidePath))
    const rels = await this.rels(slidePath.replace(/([^/]+)$/, '_rels/$1.rels'))
    const { layout, master } = await this.layoutFor(rels)
    const tree = doc.getElementsByTagName('p:spTree')[0]
    const elements: El[] = []
    await this.walk(Array.from(tree?.children ?? []), ID, elements, rels, layout, master, index)

    let bg = await this.background(doc, rels)
    if (!bg && layout) bg = await this.background(layout, await this.rels('ppt/slideLayouts/_rels/x.rels'))
    if (!bg && master) bg = await this.background(master, new Map())

    const notesPath = [...rels.entries()].find(([k]) => k.startsWith('type:notesSlide:'))?.[1]
    let notes = ''
    if (notesPath && this.zip.file('ppt/' + notesPath)) {
      const nd = parseXml(await this.text('ppt/' + notesPath))
      for (const sp of Array.from(nd.getElementsByTagName('p:sp'))) {
        if (sp.getElementsByTagName('p:ph')[0]?.getAttribute('type') === 'body') notes = Array.from(sp.getElementsByTagName('a:p')).map((p) => Array.from(p.getElementsByTagName('a:t')).map((t) => t.textContent ?? '').join('')).join('\n').trim()
      }
    }
    const hasTitle = elements.some((e) => e.type === 'text' && e.y < 240 && (e.paragraphs[0]?.size ?? 0) >= 30)
    return { id: uid('s'), rev: 1, layout: index === 1 && hasTitle && elements.filter((e) => e.type === 'text').length <= 3 ? 'title' : 'imported', background: { color: bg?.color ?? '#FFFFFF', asset_id: null, src: bg?.image ?? null }, notes, elements }
  }

  private async background(doc: Document, rels: Map<string, string>): Promise<{ color?: string; image?: string } | null> {
    const bgPr = doc.getElementsByTagName('p:bgPr')[0]
    if (!bgPr) return null
    const color = this.color(bgPr)
    if (color) return { color }
    const blip = bgPr.getElementsByTagName('a:blip')[0]
    const target = blip ? rels.get(blip.getAttribute('r:embed') ?? '') : null
    if (target) {
      const uri = await this.image('ppt/' + target)
      if (uri) return { image: uri }
    }
    return null
  }

  private async image(zipPath: string): Promise<string | null> {
    if (this.images.has(zipPath)) return this.images.get(zipPath) ?? null
    const f = this.zip.file(zipPath)
    if (!f) return null
    const ext = zipPath.split('.').pop()?.toLowerCase() ?? ''
    const mime = ({ png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif', webp: 'image/webp', svg: 'image/svg+xml' } as Record<string, string>)[ext]
    if (!mime) {
      this.warnings.push(`Unsupported picture format .${ext} was skipped.`)
      return null
    }
    const blob = new Blob([await f.async('arraybuffer')], { type: mime })
    const uri = await shrink(blob)
    this.images.set(zipPath, uri)
    return uri
  }

  private async walk(nodes: Element[], g: Grp, out: El[], rels: Map<string, string>, layout: Document | null, master: Document | null, slideNo: number): Promise<void> {
    for (const node of nodes) {
      const tag = node.tagName
      if (tag === 'p:sp') this.shape(node, g, out, layout, master)
      else if (tag === 'p:cxnSp') this.connector(node, g, out)
      else if (tag === 'p:pic') await this.picture(node, g, out, rels)
      else if (tag === 'p:graphicFrame') this.frame(node, g, out, slideNo)
      else if (tag === 'p:grpSp') {
        const xf = path(node, 'p:grpSpPr', 'a:xfrm')
        const off = kid(xf, 'a:off'), ext = kid(xf, 'a:ext'), choff = kid(xf, 'a:chOff'), chext = kid(xf, 'a:chExt')
        let next = g
        if (off && ext && choff && chext) {
          const sx = num(ext.getAttribute('cx')) / (num(chext.getAttribute('cx')) || 1), sy = num(ext.getAttribute('cy')) / (num(chext.getAttribute('cy')) || 1)
          next = { sx: g.sx * sx, sy: g.sy * sy, ox: g.ox + (num(off.getAttribute('x')) - num(choff.getAttribute('x')) * sx) * g.sx, oy: g.oy + (num(off.getAttribute('y')) - num(choff.getAttribute('y')) * sy) * g.sy }
        }
        await this.walk(Array.from(node.children), next, out, rels, layout, master, slideNo)
      }
    }
  }

  private geometry(sp: Element, layout: Document | null, master: Document | null): Xf | null {
    const own = this.xfrm(path(sp, 'p:spPr', 'a:xfrm'))
    if (own) return own
    const ph = sp.getElementsByTagName('p:ph')[0]
    if (!ph) return null
    return this.phXfrm(layout, ph) ?? this.phXfrm(master, ph)
  }

  private shape(sp: Element, g: Grp, out: El[], layout: Document | null, master: Document | null) {
    const xf = this.geometry(sp, layout, master)
    if (!xf) return
    const b = this.box(xf, g)
    const spPr = kid(sp, 'p:spPr')
    const geom = kid(spPr, 'a:prstGeom')?.getAttribute('prst') ?? 'rect'
    const fill = this.color(kid(spPr, 'a:solidFill')) ?? (kid(spPr, 'a:noFill') ? null : null)
    const ln = kid(spPr, 'a:ln')
    const stroke = ln && !kid(ln, 'a:noFill') ? this.color(kid(ln, 'a:solidFill')) : null
    const strokeWidth = stroke ? Math.max(1, Math.round(num(ln?.getAttribute('w'), 9525) * this.scale)) : 0
    const hasText = !!Array.from(sp.getElementsByTagName('a:t')).some((t) => (t.textContent ?? '').trim())

    if (fill || stroke) {
      const kind = geom === 'ellipse' ? 'ellipse' : geom === 'roundRect' ? 'round' : geom === 'line' || geom === 'straightConnector1' ? 'line' : 'rect'
      out.push(shapeEl(kind, b.x, b.y, b.w, b.h, fill, { stroke, strokeWidth, rotation: xf.rot, radius: kind === 'round' ? Math.min(b.w, b.h) * 0.12 : 0 }))
    }
    if (!hasText) return

    const ph = sp.getElementsByTagName('p:ph')[0]
    const phType = ph?.getAttribute('type') ?? (ph ? 'body' : null)
    const body = kid(sp, 'p:txBody')
    const bodyPr = kid(body, 'a:bodyPr')
    const autofit = kid(bodyPr, 'a:normAutofit')
    const fontScale = autofit ? num(autofit.getAttribute('fontScale'), 100000) / 100000 : 1
    const anchor = bodyPr?.getAttribute('anchor')
    const valign = anchor === 'ctr' ? 'middle' : anchor === 'b' ? 'bottom' : 'top'
    const pad = Math.round(num(bodyPr?.getAttribute('lIns'), 91440) * this.scale)
    const paragraphs: Paragraph[] = []

    for (const p of kids(body, 'a:p')) {
      const pPr = kid(p, 'a:pPr')
      const lvl = num(pPr?.getAttribute('lvl'), 0)
      const rtlAttr = pPr?.getAttribute('rtl') === '1'
      const algn = pPr?.getAttribute('algn')
      const align: Paragraph['align'] = algn === 'ctr' ? 'center' : algn === 'r' ? 'right' : algn === 'l' ? 'left' : rtlAttr ? 'right' : 'left'
      const noBullet = !!kid(pPr, 'a:buNone')
      const bullet = !noBullet && (!!kid(pPr, 'a:buChar') || !!kid(pPr, 'a:buAutoNum') || (phType === 'body' || phType === 'subTitle' ? phType === 'body' : false))
      const runs = kids(p, 'a:r')
      const text = Array.from(p.children).map((c) => (c.tagName === 'a:r' ? (kid(c, 'a:t')?.textContent ?? '') : c.tagName === 'a:br' ? '\n' : c.tagName === 'a:fld' ? (kid(c, 'a:t')?.textContent ?? '') : '')).join('')
      const first = runs[0] ? kid(runs[0], 'a:rPr') : kid(p, 'a:endParaRPr')
      const styleSz = phType === 'title' || phType === 'ctrTitle' ? this.masterStyles.title : phType === 'body' ? this.masterStyles.body[lvl + 1] : this.masterStyles.other
      const szPt = (num(first?.getAttribute('sz'), 0) || styleSz || (phType === 'title' || phType === 'ctrTitle' ? 4400 : 1800)) / 100
      const size = Math.max(8, Math.round(szPt * 12700 * this.scale * fontScale))
      const color = this.color(first) ?? (this.theme.dk1 ? '#' + this.theme.dk1 : '#1A1A1A')
      if (text.length === 0 && paragraphs.length === 0) continue
      paragraphs.push(para(text, { size, bold: first?.getAttribute('b') === '1' || phType === 'title', italic: first?.getAttribute('i') === '1', color, align, bullet, level: lvl }))
    }
    while (paragraphs.length && !paragraphs[paragraphs.length - 1].text) paragraphs.pop()
    if (!paragraphs.length) return
    const font = kid(kid(body, 'a:p') && kid(kid(body, 'a:p'), 'a:r') ? kid(kid(body, 'a:p'), 'a:r') : null, 'a:rPr')
    const family = kid(font, 'a:latin')?.getAttribute('typeface') ?? null
    const el = textEl(b.x, b.y, b.w, b.h, paragraphs, { valign, padding: Math.min(24, Math.max(2, pad)), fontFamily: family && !family.startsWith('+') ? family : null })
    el.rotation = xf.rot
    out.push(el)
  }

  private connector(node: Element, g: Grp, out: El[]) {
    const xf = this.xfrm(path(node, 'p:spPr', 'a:xfrm'))
    if (!xf) return
    const b = this.box(xf, g)
    const ln = path(node, 'p:spPr', 'a:ln')
    const stroke = this.color(kid(ln, 'a:solidFill')) ?? '#6B7280'
    out.push(shapeEl('line', b.x, b.y, b.w, b.h, null, { stroke, strokeWidth: Math.max(1, Math.round(num(ln?.getAttribute('w'), 12700) * this.scale)), rotation: xf.rot }))
  }

  private async picture(node: Element, g: Grp, out: El[], rels: Map<string, string>) {
    const xf = this.xfrm(path(node, 'p:spPr', 'a:xfrm'))
    const blip = node.getElementsByTagName('a:blip')[0]
    const target = blip ? rels.get(blip.getAttribute('r:embed') ?? '') : null
    if (!xf || !target) return
    const uri = await this.image('ppt/' + target)
    if (!uri) {
      this.warnings.push('A picture in an unsupported format was skipped.')
      return
    }
    const b = this.box(xf, g)
    const alt = path(node, 'p:nvPicPr', 'p:cNvPr')?.getAttribute('descr') ?? ''
    out.push(imageEl(b.x, b.y, b.w, b.h, { src: uri, alt, rotation: xf.rot, fit: 'contain' }))
  }

  private frame(node: Element, g: Grp, out: El[], slideNo: number) {
    const xf = this.xfrm(kid(node, 'p:xfrm'))
    if (!xf) return
    const b = this.box(xf, g)
    const tbl = node.getElementsByTagName('a:tbl')[0]
    if (tbl) {
      const rows = kids(tbl, 'a:tr').map((tr) => kids(tr, 'a:tc').map((tc) => Array.from(tc.getElementsByTagName('a:t')).map((t) => t.textContent ?? '').join(' ')).join('   |   '))
      out.push(textEl(b.x, b.y, b.w, b.h, rows.map((r) => para(r, { size: 20 })), { padding: 6 }))
      this.warnings.push(`Slide ${slideNo}: a table was converted to plain text.`)
    } else {
      out.push(shapeEl('rect', b.x, b.y, b.w, b.h, '#F3F4F6', { stroke: '#9CA3AF', strokeWidth: 1 }))
      this.warnings.push(`Slide ${slideNo}: a chart or diagram is not supported and was replaced by a frame.`)
    }
  }
}

/** Downscales large pictures so a deck stays light to store and edit. */
async function shrink(blob: Blob): Promise<string> {
  const read = (b: Blob) => new Promise<string>((resolve, reject) => { const r = new FileReader(); r.onload = () => resolve(String(r.result)); r.onerror = reject; r.readAsDataURL(b) })
  if (blob.type === 'image/svg+xml' || blob.type === 'image/gif' || blob.size < 500_000) return read(blob)
  try {
    const bitmap = await createImageBitmap(blob)
    const ratio = Math.min(1, 1600 / Math.max(bitmap.width, bitmap.height))
    const canvas = document.createElement('canvas')
    canvas.width = Math.round(bitmap.width * ratio)
    canvas.height = Math.round(bitmap.height * ratio)
    const ctx = canvas.getContext('2d')
    if (!ctx) return read(blob)
    ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height)
    return canvas.toDataURL(blob.type === 'image/png' && blob.size < 1_500_000 ? 'image/png' : 'image/jpeg', 0.86)
  } catch {
    return read(blob)
  }
}

export async function importPptx(file: File): Promise<{ deck: Deck; warnings: string[] }> {
  const reader = new PptxReader()
  const result = await reader.read(file)
  result.deck.slides.forEach((s) => { if (!s.elements.length) s.layout = 'blank' })
  return { deck: clone(result.deck), warnings: [...new Set(result.warnings)] }
}
