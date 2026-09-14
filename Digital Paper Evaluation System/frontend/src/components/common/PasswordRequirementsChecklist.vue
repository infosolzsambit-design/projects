<script setup>
import { computed } from 'vue'
import { PASSWORD_RULES } from '../../utils/passwordRules'

// Live checklist shown under a "new password" field — each rule lights up
// green the instant it's satisfied, instead of only surfacing what's
// missing after a failed submit. Used by ChangePasswordModal and
// ResetPasswordView.
const props = defineProps({ password: { type: String, default: '' } })
const ruleStatus = computed(() => PASSWORD_RULES.map((rule) => ({ label: rule.label, met: rule.test(props.password) })))
</script>

<template>
  <ul class="mt-0.5 grid grid-cols-1 sm:grid-cols-2 gap-x-3 gap-y-1">
    <li
      v-for="rule in ruleStatus"
      :key="rule.label"
      class="flex items-center gap-1.5 text-[11.5px] transition-colors"
      :class="rule.met ? 'text-success' : 'text-muted'"
    >
      <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" :stroke-width="rule.met ? 3 : 2.5">
        <polyline v-if="rule.met" points="20 6 9 17 4 12" />
        <circle v-else cx="12" cy="12" r="9" />
      </svg>
      {{ rule.label }}
    </li>
  </ul>
</template>
