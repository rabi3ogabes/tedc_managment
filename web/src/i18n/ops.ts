/** System administration: signing in as a user, the error log and the room-screen template. */
export const opsAr = {
  impersonation: {
    signInAs: 'الدخول بحسابه', confirm: 'ستفتح حساب «{{name}}» ({{email}}) وتتصرف باسمه لمدة ساعة. تُسجَّل البداية والنهاية في سجل التدقيق. متابعة؟',
    banner: 'أنت داخل حساب {{name}} ({{email}})', left: 'متبقٍ {{minutes}} د', stop: 'العودة إلى حسابي',
  },
}

export const opsEn: typeof opsAr = {
  impersonation: {
    signInAs: 'Sign in as', confirm: 'You will open the account of “{{name}}” ({{email}}) and act as them for one hour. The start and the end are written to the audit log. Continue?',
    banner: 'You are inside the account of {{name}} ({{email}})', left: '{{minutes}} min left', stop: 'Return to my account',
  },
}
