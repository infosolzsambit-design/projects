// Mirrors backend/app/Helpers/PasswordPolicy.php exactly (min 8, mixed
// case, a number, a symbol) — single source of truth for every password
// field's client-side rule checklist in this app (ChangePasswordModal,
// ResetPasswordView, TeacherFormView) so nothing shown as "met" here can
// still fail on submit.
export const PASSWORD_RULES = [
  { test: (v) => v.length >= 8, label: 'At least 8 characters' },
  { test: (v) => /[a-z]/.test(v), label: 'One lowercase letter' },
  { test: (v) => /[A-Z]/.test(v), label: 'One uppercase letter' },
  { test: (v) => /[0-9]/.test(v), label: 'One number' },
  { test: (v) => /[^A-Za-z0-9]/.test(v), label: 'One special character (e.g. ! @ # $ %)' },
]
