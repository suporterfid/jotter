import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'
import PlanBanner from './components/PlanBanner.vue'
import i18n from './i18n'
import { resetBrand, setBrand } from './services/brand'
import type { TenantPlan } from './services/types'

function plan(overrides: Partial<TenantPlan>): TenantPlan {
  return { status: 'self_hosted', name: null, seats: null, trial_ends_at: null, trial_days_left: null, read_only: false, ...overrides }
}

describe('PlanBanner', () => {
  beforeEach(() => {
    resetBrand()
    i18n.global.locale.value = 'en'
  })

  it('renders nothing for self-hosted or active plans', () => {
    expect(mount(PlanBanner, { props: { plan: plan({}) } }).find('[data-testid="plan-banner"]').exists()).toBe(false)
    expect(mount(PlanBanner, { props: { plan: plan({ status: 'active' }) } }).find('[data-testid="plan-banner"]').exists()).toBe(false)
    expect(mount(PlanBanner, { props: { plan: null } }).find('[data-testid="plan-banner"]').exists()).toBe(false)
  })

  it('shows the remaining trial days in English and Portuguese', () => {
    const trial = plan({ status: 'trial', trial_days_left: 5 })

    expect(mount(PlanBanner, { props: { plan: trial } }).text()).toBe('Trial ends in 5 days')

    i18n.global.locale.value = 'pt-BR'
    expect(mount(PlanBanner, { props: { plan: trial } }).text()).toBe('O período de teste termina em 5 dias')
  })

  it('shows a read-only notice with a support link when configured', () => {
    setBrand({ support_url: 'https://cadernia.example.com/support' })
    const wrapper = mount(PlanBanner, { props: { plan: plan({ status: 'read_only', read_only: true }) } })

    expect(wrapper.find('[data-testid="plan-banner"]').classes()).toContain('plan-banner--read-only')
    expect(wrapper.text()).toContain('This account is read-only')
    expect(wrapper.find('[data-testid="plan-banner-support"]').attributes('href')).toBe('https://cadernia.example.com/support')
  })

  it('treats an expired trial and past_due as read-only', () => {
    expect(mount(PlanBanner, { props: { plan: plan({ status: 'trial', trial_days_left: 0, read_only: true }) } }).text()).toContain('read-only')
    expect(mount(PlanBanner, { props: { plan: plan({ status: 'past_due', read_only: true }) } }).text()).toContain('read-only')
  })
})
