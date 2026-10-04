#!/usr/bin/env swift
// Regenerates the app icon set and the launch-screen logo from the Ministry emblem (assets/brand/moe-emblem.png):
// a maroon (Al Adaam) icon with the white emblem — iOS icons, Android adaptive/legacy icons and the native splash logo.
// Run from the mobile/ folder:  swift tool/generate_brand_assets.swift
import CoreGraphics
import Foundation
import ImageIO
import UniformTypeIdentifiers

let root = FileManager.default.currentDirectoryPath
let res = "\(root)/android/app/src/main/res"
let space = CGColorSpaceCreateDeviceRGB()

func load(_ path: String) -> CGImage {
  let source = CGImageSourceCreateWithURL(URL(fileURLWithPath: path) as CFURL, nil)!
  return CGImageSourceCreateImageAtIndex(source, 0, nil)!
}

func save(_ image: CGImage, _ path: String) {
  try? FileManager.default.createDirectory(atPath: (path as NSString).deletingLastPathComponent, withIntermediateDirectories: true)
  let dest = CGImageDestinationCreateWithURL(URL(fileURLWithPath: path) as CFURL, UTType.png.identifier as CFString, 1, nil)!
  CGImageDestinationAddImage(dest, image, nil)
  guard CGImageDestinationFinalize(dest) else { fatalError("could not write \(path)") }
}

func context(_ size: Int) -> CGContext {
  let ctx = CGContext(data: nil, width: size, height: size, bitsPerComponent: 8, bytesPerRow: 0, space: space, bitmapInfo: CGImageAlphaInfo.premultipliedLast.rawValue)!
  ctx.interpolationQuality = .high
  ctx.setShouldAntialias(true)
  return ctx
}

/// The emblem as a white silhouette: colour dropped, transparency kept.
func whiteEmblem() -> CGImage {
  let src = load("\(root)/assets/brand/moe-emblem.png")
  let w = src.width, h = src.height
  let ctx = CGContext(data: nil, width: w, height: h, bitsPerComponent: 8, bytesPerRow: w * 4, space: space, bitmapInfo: CGImageAlphaInfo.premultipliedLast.rawValue)!
  ctx.draw(src, in: CGRect(x: 0, y: 0, width: w, height: h))
  let p = ctx.data!.bindMemory(to: UInt8.self, capacity: w * h * 4)
  for i in stride(from: 0, to: w * h * 4, by: 4) { let a = p[i + 3]; p[i] = a; p[i + 1] = a; p[i + 2] = a }   // premultiplied white
  return ctx.makeImage()!
}

let emblem = whiteEmblem()

func color(_ hex: UInt32, _ a: CGFloat = 1) -> CGColor {
  CGColor(colorSpace: space, components: [CGFloat((hex >> 16) & 255) / 255, CGFloat((hex >> 8) & 255) / 255, CGFloat(hex & 255) / 255, a])!
}

/// Maroon background: a deep diagonal gradient with a soft light from the top.
func paintBackground(_ ctx: CGContext, _ size: Int) {
  let s = CGFloat(size)
  let gradient = CGGradient(colorsSpace: space, colors: [color(0xA3204D), color(0x8A1538), color(0x5A0E24)] as CFArray, locations: [0, 0.5, 1])!
  ctx.drawLinearGradient(gradient, start: CGPoint(x: 0, y: s), end: CGPoint(x: s, y: 0), options: [])
  let glow = CGGradient(colorsSpace: space, colors: [color(0xFFFFFF, 0.16), color(0xFFFFFF, 0)] as CFArray, locations: [0, 1])!
  ctx.drawRadialGradient(glow, startCenter: CGPoint(x: s * 0.5, y: s * 0.95), startRadius: 0, endCenter: CGPoint(x: s * 0.5, y: s * 0.95), endRadius: s * 0.75, options: [])
}

/// Draws the emblem centred, `fraction` of the canvas wide, with a soft shadow for depth.
func paintEmblem(_ ctx: CGContext, _ size: Int, fraction: CGFloat, shadow: Bool) {
  let s = CGFloat(size), w = s * fraction
  let rect = CGRect(x: (s - w) / 2, y: (s - w) / 2 - s * 0.01, width: w, height: w)
  ctx.saveGState()
  if shadow { ctx.setShadow(offset: CGSize(width: 0, height: -s * 0.012), blur: s * 0.03, color: color(0x2A0511, 0.45)) }
  ctx.draw(emblem, in: rect)
  ctx.restoreGState()
}

func icon(size: Int, rounded: Bool = false) -> CGImage {
  let ctx = context(size)
  if rounded { ctx.addPath(CGPath(roundedRect: CGRect(x: 0, y: 0, width: size, height: size), cornerWidth: CGFloat(size) * 0.22, cornerHeight: CGFloat(size) * 0.22, transform: nil)); ctx.clip() }
  paintBackground(ctx, size)
  paintEmblem(ctx, size, fraction: 0.64, shadow: true)
  return ctx.makeImage()!
}

/// White emblem alone on transparency (adaptive foreground, themed icon, native splash).
func emblemOnly(size: Int, fraction: CGFloat) -> CGImage {
  let ctx = context(size)
  paintEmblem(ctx, size, fraction: fraction, shadow: false)
  return ctx.makeImage()!
}

func resized(_ image: CGImage, _ size: Int) -> CGImage {
  let ctx = context(size)
  ctx.draw(image, in: CGRect(x: 0, y: 0, width: size, height: size))
  return ctx.makeImage()!
}

/// iOS needs an opaque square (no transparency).
func opaque(_ image: CGImage) -> CGImage {
  let size = image.width
  let ctx = CGContext(data: nil, width: size, height: size, bitsPerComponent: 8, bytesPerRow: 0, space: space, bitmapInfo: CGImageAlphaInfo.noneSkipLast.rawValue)!
  ctx.interpolationQuality = .high
  ctx.draw(image, in: CGRect(x: 0, y: 0, width: size, height: size))
  return ctx.makeImage()!
}

// Masters
let master = icon(size: 1024)
save(opaque(master), "\(root)/assets/brand/app-icon.png")
save(emblemOnly(size: 1024, fraction: 0.84), "\(root)/assets/brand/app-icon-foreground.png")
save(emblemOnly(size: 1024, fraction: 0.84), "\(root)/assets/brand/app-icon-monochrome.png")
save(emblemOnly(size: 512, fraction: 1.0), "\(root)/assets/brand/emblem-white.png")

// Android adaptive icon layers: 108 dp canvases
for (dir, px) in [("mdpi", 108), ("hdpi", 162), ("xhdpi", 216), ("xxhdpi", 324), ("xxxhdpi", 432)] {
  save(emblemOnly(size: px, fraction: 0.84), "\(res)/drawable-\(dir)/ic_launcher_foreground.png")
  save(emblemOnly(size: px, fraction: 0.84), "\(res)/drawable-\(dir)/ic_launcher_monochrome.png")
}
// Android legacy icons (before 8.0)
for (dir, px) in [("mdpi", 48), ("hdpi", 72), ("xhdpi", 96), ("xxhdpi", 144), ("xxxhdpi", 192)] {
  save(icon(size: px * 4, rounded: true).downscaled(to: px), "\(res)/mipmap-\(dir)/ic_launcher.png")
}
// Native launch screen (Android < 12): the emblem on the maroon window background
save(emblemOnly(size: 480, fraction: 1.0), "\(res)/drawable-xxxhdpi/splash_logo.png")

// iOS app icons
let ios = "\(root)/ios/Runner/Assets.xcassets/AppIcon.appiconset"
let sizes: [(String, Int)] = [
  ("20x20@1x", 20), ("20x20@2x", 40), ("20x20@3x", 60), ("29x29@1x", 29), ("29x29@2x", 58), ("29x29@3x", 87), ("40x40@1x", 40), ("40x40@2x", 80), ("40x40@3x", 120),
  ("50x50@1x", 50), ("50x50@2x", 100), ("57x57@1x", 57), ("57x57@2x", 114), ("60x60@2x", 120), ("60x60@3x", 180), ("72x72@1x", 72), ("72x72@2x", 144),
  ("76x76@1x", 76), ("76x76@2x", 152), ("83.5x83.5@2x", 167), ("1024x1024@1x", 1024),
]
for (name, px) in sizes { save(opaque(resized(master, px)), "\(ios)/Icon-App-\(name).png") }
print("Brand assets written.")

extension CGImage {
  func downscaled(to size: Int) -> CGImage {
    let ctx = CGContext(data: nil, width: size, height: size, bitsPerComponent: 8, bytesPerRow: 0, space: CGColorSpaceCreateDeviceRGB(), bitmapInfo: CGImageAlphaInfo.premultipliedLast.rawValue)!
    ctx.interpolationQuality = .high
    ctx.draw(self, in: CGRect(x: 0, y: 0, width: size, height: size))
    return ctx.makeImage()!
  }
}
