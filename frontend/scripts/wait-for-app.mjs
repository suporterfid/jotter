import { lookup } from 'node:dns/promises'

const target = new URL(process.env.PLAYWRIGHT_BASE_URL ?? 'http://jotter-web')
const attempts = 20

console.log(`Resolving ${target.hostname}:`, await lookup(target.hostname, { all: true }))

for (let attempt = 1; attempt <= attempts; attempt += 1) {
  try {
    const response = await fetch(new URL('/up', target))

    if (response.ok) {
      console.log(`Application probe succeeded on attempt ${attempt}.`)
      process.exit(0)
    }

    console.error(`Application probe returned HTTP ${response.status} on attempt ${attempt}.`)
  } catch (error) {
    console.error(`Application probe failed on attempt ${attempt}:`, error)
  }

  await new Promise((resolve) => setTimeout(resolve, 1_000))
}

console.error(`Application did not become reachable at ${target.origin}.`)
process.exit(1)
