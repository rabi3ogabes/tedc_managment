import { todayIso, type Day } from '@/pages/RoomScreen'

/** A believable live session so the template can be judged without waiting for a real class. */
export function sampleDay(now: Date): Day {
  const at = (h: number, m = 0) => { const d = new Date(now); d.setHours(h, m, 0, 0); return d.toISOString() }
  const hour = now.getHours()
  const startH = Math.min(Math.max(hour - 1, 7), 11)
  const names = ['سارة المنصوري', 'خالد الكواري', 'منى النعيمي', 'أحمد الهاجري', 'نورة الكعبي', 'يوسف المري', 'هند الدوسري', 'ناصر العبيدلي']
  return {
    room: { name: 'قاعة الابتكار', code: 'R-01', building: 'المبنى الرئيسي', floor: 'الطابق الثاني', capacity: 40 }, date: todayIso(), is_today: true, now: now.toISOString(),
    sessions: [{
      id: 'sample', title: 'الجلسة الأولى', sequence: 1, mode: 'in_person', program: { code: 'LDR-101', title: 'القيادة التربوية الفعّالة' }, trainer: { name: 'د. نورة المهندي', photo: null },
      starts_at: at(startH), ends_at: at(startH + 5), minutes: 300, state: 'live', counts: { expected: names.length, present: 5, late: 1, absent: 0 },
      trainees: names.map((name, i) => ({ name, school: 'مدرسة الريان الابتدائية للبنات', status: i < 4 ? 'present' : i === 4 ? 'late' : 'expected', check_in_at: i < 5 ? at(startH, 5 + i) : null })),
    }],
  }
}

