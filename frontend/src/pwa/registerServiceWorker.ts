export function registerServiceWorker(enabled = import.meta.env.PROD): void {
  if (!enabled || !('serviceWorker' in navigator)) {
    return
  }

  const register = () => {
    void navigator.serviceWorker.register('/service-worker.js', { scope: '/' }).catch(() => undefined)
  }

  if (document.readyState === 'complete') {
    register()
    return
  }

  window.addEventListener('load', register, { once: true })
}
