import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { relativeTime } from './relativeTime.ts'

const now = new Date('2026-10-14T12:00:00Z')
const ago = (ms: number) => new Date(now.getTime() - ms).toISOString()
const MIN = 60_000
const HOUR = 60 * MIN
const DAY = 24 * HOUR

describe('relative time: how long ago, in the reader\'s language and with Western digits', () => {
  it('says minutes, hours and days in English', () => {
    assert.equal(relativeTime(ago(5 * MIN), now, 'en-GB'), '5 minutes ago')
    assert.equal(relativeTime(ago(3 * HOUR), now, 'en-GB'), '3 hours ago')
    assert.equal(relativeTime(ago(2 * DAY), now, 'en-GB'), '2 days ago')
  })

  it('says it in Arabic with Latin digits', () => {
    const text = relativeTime(ago(3 * HOUR), now, 'ar-QA-u-nu-latn')
    assert.match(text, /3/, 'the digit is Western')
    assert.doesNotMatch(text, /[٠-٩]/)
    assert.match(text, /[؀-ۿ]/, 'the words are Arabic')
  })

  it('does not count seconds: anything under a minute is "just now"', () => {
    assert.match(relativeTime(ago(20_000), now, 'en-GB'), /now|moment|second/i)
  })

  it('rounds down to the largest whole unit', () => {
    assert.equal(relativeTime(ago(90 * MIN), now, 'en-GB'), '1 hour ago')
    assert.equal(relativeTime(ago(36 * HOUR), now, 'en-GB'), '1 day ago')
    assert.equal(relativeTime(ago(40 * DAY), now, 'en-GB'), '1 month ago')
  })

  it('gives nothing for a value that is not a date, and never a future time', () => {
    assert.equal(relativeTime('not a date', now, 'en-GB'), '')
    assert.equal(relativeTime(null, now, 'en-GB'), '')
    assert.match(relativeTime(new Date(now.getTime() + 5 * MIN).toISOString(), now, 'en-GB'), /now|moment|second/i, 'a clock a little ahead is still "just now"')
  })
})
